#!/bin/bash
# api.ismile.krd on the VPS: the backend (registrations, payments, admin,
# database) with the PRETEND payment company, so no Psoola and no webhooks.
# Runs inside the "ismile" container, next to the live Node site, and never
# touches it: its own folder, its own database, its own port.
#
# Run as root inside the container (first time AND for every update):
#
#   curl -fsSL https://raw.githubusercontent.com/Redyar8r-SE/Ismile/backend/backend/tools/vps/setup-test-site.sh -o /root/ismile-test-setup.sh
#   OWNER_EMAIL=you@example.com bash /root/ismile-test-setup.sh
#
# Then, on the HOST: see host-nginx-test.conf (DNS, certificate, proxy).
# GitHub Actions: .github/workflows/deploy-api.yml re-runs this on push to backend.
#
# Safe to run again: it pulls the newest code of the branch and updates the
# database, and keeps the data, the passwords and the settings.
#
# Options (environment):
#   BRANCH=backend           which Git branch to run
#   DOMAIN=api.ismile.krd    public hostname (site_url + nginx server_name)
#   PORT=8081                the port the host's nginx forwards to
#   OWNER_EMAIL=...          an Owner account (made if it does not exist yet)
#   OWNER_PASSWORD=...       its password (12+ characters), checked on every run:
#                            the account always gets it. Without it: a made-up one.
#   SITE_PASSWORD=...        the browser password (user "ismile"); without it: made up
#   NO_SITE_PASSWORD=1       no browser password in front of the site
#   QUIET_SECRETS=1          never print passwords (GitHub Actions: logs are public)

set -euo pipefail

BRANCH="${BRANCH:-backend}"
DOMAIN="${DOMAIN:-api.ismile.krd}"
PORT="${PORT:-8081}"
REPO_URL="${REPO_URL:-https://github.com/Redyar8r-SE/Ismile.git}"
BASE=/opt/ismile-test
REPO="$BASE/repo"
SITE="$BASE/site"
BACKEND="$BASE/backend"
SECRETS="$BASE/secrets.env"          # generated passwords, root only
DB=ismile_test
DB_USER=ismile_test

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
mkdir -p "$BASE"
chmod 755 "$BASE"

wait_for_dpkg() {
  # unattended-upgrade-shutdown holds lock-frontend forever while idle; stop it.
  systemctl stop unattended-upgrades 2>/dev/null || true
  local i=0
  while true; do
    local holders
    holders="$(fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock 2>/dev/null || true)"
    # Ignore the idle shutdown waiter — it is not an active apt transaction.
    if [ -n "$holders" ]; then
      local real=0
      for pid in $holders; do
        if ! tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -q 'unattended-upgrade-shutdown'; then
          real=1
          break
        fi
      done
      [ "$real" = 0 ] && break
    else
      break
    fi
    i=$((i + 1))
    if [ "$i" -gt 90 ]; then
      echo "error: dpkg/apt still locked after 7.5 minutes" >&2
      exit 1
    fi
    echo "waiting for apt/dpkg lock (${i}/90)…"
    sleep 5
  done
}

# ---------------------------------------------------------------------------
say "1/8 Programs: PHP, MariaDB, nginx (only what is missing is installed)"
export DEBIAN_FRONTEND=noninteractive
need_pkgs=0
command -v php >/dev/null 2>&1 && command -v nginx >/dev/null 2>&1 && command -v mariadb >/dev/null 2>&1 \
  && command -v composer >/dev/null 2>&1 && command -v htpasswd >/dev/null 2>&1 || need_pkgs=1
if [ "$need_pkgs" = 1 ]; then
  wait_for_dpkg
  apt-get update -qq
  wait_for_dpkg
  apt-get install -y -qq nginx mariadb-server git unzip curl cron openssl apache2-utils \
    php-fpm php-cli php-mysql php-curl php-gd php-mbstring php-xml php-zip composer >/dev/null
else
  echo "PHP/nginx/MariaDB already present — skipping apt install"
fi
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "PHP $PHP_VERSION is too old: 8.1 or newer is needed."; exit 1; }
FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
echo "PHP $PHP_VERSION, MariaDB $(mariadb --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
# Small server (1 GB): keep PHP and MariaDB modest.
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-ismile.ini" <<'INI'
; iSmile: student ID photos up to 8 MB
upload_max_filesize = 10M
post_max_size = 12M
max_file_uploads = 2
memory_limit = 256M
expose_php = Off
date.timezone = Asia/Baghdad
INI
sed -i -E 's/^pm.max_children = .*/pm.max_children = 6/' "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"
cat > /etc/mysql/mariadb.conf.d/90-ismile.cnf <<'CNF'
[mysqld]
bind-address            = 127.0.0.1
innodb_buffer_pool_size = 128M
max_connections         = 40
character-set-server    = utf8mb4
collation-server        = utf8mb4_unicode_ci
CNF
systemctl enable --now mariadb php"$PHP_VERSION"-fpm nginx cron >/dev/null 2>&1 || true
systemctl restart mariadb php"$PHP_VERSION"-fpm

# ---------------------------------------------------------------------------
say "2/8 Passwords (made once, kept in $SECRETS)"
if [ ! -f "$SECRETS" ]; then
  umask 077
  {
    echo "DB_PASS=$(openssl rand -hex 16)"
    echo "APP_SECRET=$(openssl rand -hex 32)"
    echo "FAKE_SECRET=$(openssl rand -hex 16)"
    echo "OWNER_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"
    echo "SITE_PASS=$(openssl rand -base64 12 | tr -d '/+=' | cut -c1-12)"
    echo "VIEWER_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"
  } > "$SECRETS"
  umask 022
fi
# shellcheck disable=SC1090
. "$SECRETS"
# Until 28 Sep 2026 the GitHub deploy printed these passwords in its (public)
# log. Make new ones once; the Owner accounts that still have the old made-up
# password get the new one further down.
OLD_OWNER_PASS=""
if [ ! -f "$BASE/.passwords-renewed-1" ]; then
  OLD_OWNER_PASS="$OWNER_PASS"
  sed -i \
    -e "s#^OWNER_PASS=.*#OWNER_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)#" \
    -e "s#^SITE_PASS=.*#SITE_PASS=$(openssl rand -base64 12 | tr -d '/+=' | cut -c1-12)#" \
    -e "s#^VIEWER_PASS=.*#VIEWER_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)#" \
    "$SECRETS"
  # shellcheck disable=SC1090
  . "$SECRETS"
fi
# Secrets pasted into GitHub often end with an invisible new line or space:
# remove them at the start and end, or the password never matches what is typed.
for name in OWNER_EMAIL OWNER_PASSWORD SITE_PASSWORD; do
  value="${!name:-}"
  trimmed="${value#"${value%%[![:space:]]*}"}"
  trimmed="${trimmed%"${trimmed##*[![:space:]]}"}"
  if [ "$value" != "$trimmed" ]; then
    echo "note: $name had spaces or new lines at the start or end; they were removed."
    printf -v "$name" '%s' "$trimmed"
  fi
done
if [ -n "${SITE_PASSWORD:-}" ]; then
  # Kept in the file too: the GitHub deploy's check signs in with it.
  SITE_PASS="$SITE_PASSWORD"
  { grep -v '^SITE_PASS=' "$SECRETS"; printf 'SITE_PASS=%s\n' "$SITE_PASS"; } > "$SECRETS.new"
  chmod 600 "$SECRETS.new"
  mv "$SECRETS.new" "$SECRETS"
fi
if [ -n "${OWNER_PASSWORD:-}" ] && [ "${#OWNER_PASSWORD}" -lt 12 ]; then
  echo "ERROR: the Owner password (GitHub secret API_OWNER_PASSWORD) has only ${#OWNER_PASSWORD} characters: 12 or more are needed." >&2
  exit 1
fi
if [ -n "${OWNER_PASSWORD:-}" ]; then OWNER_PASS="$OWNER_PASSWORD"; fi
show() { [ -n "${QUIET_SECRETS:-}" ] && echo "(hidden: see $SECRETS or the GitHub secrets)" || echo "$1"; }

# ---------------------------------------------------------------------------
say "3/8 Database $DB"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
say "4/8 Code: branch $BRANCH"
if [ -d "$REPO/.git" ]; then
  git -C "$REPO" fetch -q origin "$BRANCH"
  git -C "$REPO" checkout -q -B "$BRANCH" "origin/$BRANCH"
else
  git clone -q --branch "$BRANCH" "$REPO_URL" "$REPO"
fi
echo "At commit $(git -C "$REPO" log -1 --format='%h %s')"

# ---------------------------------------------------------------------------
say "5/8 Settings file (outside the website folder)"
mkdir -p "$BACKEND" "$SITE"
if [ ! -f "$BACKEND/config.php" ]; then
  cat > "$BACKEND/config.php" <<PHP
<?php
// $DOMAIN — made by setup-test-site.sh. Holds passwords: never commit it.
return [
    'env'       => 'test',
    'site_url'  => 'https://$DOMAIN',
    'site_root' => '$SITE',
    'storage'   => __DIR__ . '/storage',
    'db'        => ['host' => 'localhost', 'port' => 3306, 'name' => '$DB', 'user' => '$DB_USER', 'pass' => '$DB_PASS'],
    'secret'    => '$APP_SECRET',
    'timezone'  => 'Asia/Baghdad',
    // The pretend payment company: no Psoola, no real money, no webhooks.
    'payments'  => ['gateway' => 'fake', 'psoola' => ['api_base' => '', 'api_key' => '', 'merchant_id' => '', 'webhook_secret' => ''], 'fake_secret' => '$FAKE_SECRET'],
    // Emails are written to storage/outbox as files, never sent.
    'mail'      => ['driver' => 'log', 'brevo_key' => '', 'from_email' => 'tickets@ismile.krd', 'from_name' => 'iSmile 2026 (test)', 'reply_to' => 'info@ismile.krd'],
    'alerts_to' => ['info@ismile.krd'],
    'office_phone' => '',
];
PHP
else
  # Keep existing secrets; only refresh the public URL if DOMAIN changed.
  php -r '
    $path = $argv[1]; $domain = $argv[2];
    $cfg = include $path;
    $cfg["site_url"] = "https://" . $domain;
    file_put_contents($path, "<?php\nreturn " . var_export($cfg, true) . ";\n");
  ' "$BACKEND/config.php" "$DOMAIN"
fi
chown root:www-data "$BACKEND/config.php"
chmod 640 "$BACKEND/config.php"

# ---------------------------------------------------------------------------
say "6/8 Website + backend files, database tables"
export COMPOSER_ALLOW_SUPERUSER=1
PHP=php ISMILE_SITE="$SITE" ISMILE_BACKEND_DIR="$BACKEND" bash "$REPO/backend/tools/deploy.sh" test
# Accounts that still have the old made-up password (printed in public logs) get the new one.
if [ -n "$OLD_OWNER_PASS" ]; then
  ISMILE_OLD="$OLD_OWNER_PASS" ISMILE_NEW="$OWNER_PASS" php -r '
    $cfg = include $argv[1];
    $db = new PDO("mysql:host=localhost;dbname={$cfg["db"]["name"]};charset=utf8mb4", $cfg["db"]["user"], $cfg["db"]["pass"]);
    foreach ($db->query("SELECT id, email, password_hash FROM admin_users") as $u) {
        if (password_verify(getenv("ISMILE_OLD"), $u["password_hash"])) {
            $db->prepare("UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?")
               ->execute([password_hash(getenv("ISMILE_NEW"), PASSWORD_DEFAULT), $u["id"]]);
            echo "New password for {$u["email"]} (the old one was in a public log).\n";
        }
    }' "$BACKEND/config.php"
fi
touch "$BASE/.passwords-renewed-1"
if [ -n "${OWNER_EMAIL:-}" ]; then
  OWNER_EMAIL="$(printf '%s' "$OWNER_EMAIL" | tr 'A-Z' 'a-z' | tr -d ' \r\n')"
  if [ -n "${OWNER_PASSWORD:-}" ]; then
    # Checked on every run: the account always has the secret's password.
    ISMILE_OWNER_PASSWORD="$OWNER_PASSWORD" php "$BACKEND/tools/install.php" --ensure-owner "$OWNER_EMAIL" | tail -1
  elif ! mariadb -N "$DB" -e "SELECT 1 FROM admin_users WHERE email = '$(printf '%s' "$OWNER_EMAIL" | sed "s/'//g")'" | grep -q 1; then
    ISMILE_OWNER_PASSWORD="$OWNER_PASS" php "$BACKEND/tools/install.php" --owner "$OWNER_EMAIL" "Owner" | tail -1
  fi
  grep -q "^OWNER_EMAIL=$OWNER_EMAIL\$" "$SECRETS" || echo "OWNER_EMAIL=$OWNER_EMAIL" >> "$SECRETS"
else
  echo "No OWNER_EMAIL: no Owner account was checked or made."
fi
# FIRST RUN ONLY: a test site needs something to test with. While the real
# prices are 0, it gets EXAMPLE ticket prices and registration is opened. The
# Owner changes both later (Settings; prices in Site content). Never on live.
if [ ! -f "$BASE/.first-run-done" ]; then
  php -r '
    $file = $argv[1];
    $p = json_decode((string) file_get_contents($file), true) ?: [];
    if ((int) ($p["professional"] ?? 0) === 0 && (int) ($p["student"] ?? 0) === 0) {
        $p = ["currency" => "IQD", "professional" => 50000, "student" => 25000, "lunchDay1" => 10000, "lunchDay2" => 10000] + $p;
        file_put_contents($file, json_encode($p, JSON_PRETTY_PRINT) . "\n");
        echo "Example prices set on the TEST site: professional 50,000, student 25,000, lunch 10,000 per day.\n";
    }' "$SITE/data/tickets.json"
  mariadb "$DB" -e "INSERT INTO settings (k, v, updated_at) VALUES ('registration_open', '1', NOW()) ON DUPLICATE KEY UPDATE v = '1', updated_at = NOW()"
  echo "Registration opened on the TEST site."
  # Email test mode (on by default) needs an address. Here emails are only
  # written to storage/outbox as files anyway (mail driver "log").
  mariadb "$DB" -e "INSERT INTO settings (k, v, updated_at) VALUES ('email_test_address', '${OWNER_EMAIL:-info@ismile.krd}', NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()"
  touch "$BASE/.first-run-done"
fi

# The web server writes the workshop/sponsor cards and the private storage.
mkdir -p "$BACKEND/storage"
chown -R www-data:www-data "$SITE/data" "$BACKEND/storage"
chmod 750 "$BACKEND/storage"

# Read-only login for MySQL Workbench: sees only the 12 simple lists.
mariadb <<SQL
CREATE USER IF NOT EXISTS 'ismile_viewer'@'localhost' IDENTIFIED BY '$VIEWER_PASS';
ALTER USER 'ismile_viewer'@'localhost' IDENTIFIED BY '$VIEWER_PASS';
SQL
for view in $(mariadb -N -e "SELECT table_name FROM information_schema.views WHERE table_schema = '$DB' AND table_name REGEXP '^[0-9]{2}_[a-z0-9_]+$'"); do
  mariadb -e "GRANT SELECT ON \`$DB\`.\`$view\` TO 'ismile_viewer'@'localhost'"
done

# ---------------------------------------------------------------------------
say "7/8 Timed jobs (emails every minute, payments every 5 minutes, nightly backup)"
cat > /etc/cron.d/ismile-test <<CRON
# iSmile test site — made by setup-test-site.sh
* * * * *   www-data php $BACKEND/cron/run.php minute  >/dev/null 2>&1
*/5 * * * * www-data php $BACKEND/cron/run.php five    >/dev/null 2>&1
30 3 * * *  www-data php $BACKEND/cron/run.php nightly >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/ismile-test

# ---------------------------------------------------------------------------
say "8/8 nginx inside the container, port $PORT"
if [ -z "${NO_SITE_PASSWORD:-}" ]; then
  htpasswd -bc "$BASE/htpasswd" ismile "$SITE_PASS" >/dev/null 2>&1
  chown root:www-data "$BASE/htpasswd"
  chmod 640 "$BASE/htpasswd"
  AUTH="auth_basic \"iSmile test site\"; auth_basic_user_file $BASE/htpasswd;"
else
  AUTH="auth_basic off;"
fi
sed -e "s#__PORT__#$PORT#g" -e "s#__SITE__#$SITE#g" -e "s#__FPM_SOCK__#$FPM_SOCK#g" -e "s#__AUTH__#$AUTH#g" -e "s#__DOMAIN__#$DOMAIN#g" \
  "$REPO/backend/tools/vps/container-nginx.conf" > /etc/nginx/sites-available/ismile-test
ln -sf /etc/nginx/sites-available/ismile-test /etc/nginx/sites-enabled/ismile-test
rm -f /etc/nginx/sites-enabled/default      # nothing else is served by this nginx
nginx -t -q
systemctl reload nginx

# ---------------------------------------------------------------------------
say "Check"
code() { curl -s -o /dev/null -w '%{http_code}' -u "ismile:$SITE_PASS" -H "Host: $DOMAIN" "http://127.0.0.1:$PORT$1"; }
printf '  %-28s %s (want 200)\n' "/" "$(code /)" "/register.html" "$(code /register.html)" "/admin/login.php" "$(code /admin/login.php)"
printf '  %-28s %s (want 404)\n' "/backend/config.php" "$(code /backend/config.php)" "/server/node-server.mjs" "$(code /server/node-server.mjs)" "/api/_backend.php" "$(code /api/_backend.php)" "/.git/config" "$(code /.git/config)"

cat <<DONE

Done. API site files: $SITE   backend: $BACKEND   (the live Node site was not touched)

Open https://$DOMAIN once the host is set up (host-nginx-test.conf).
  Browser password : ismile / $([ -z "${NO_SITE_PASSWORD:-}" ] && show "$SITE_PASS" || echo "(none)")
  Admin            : https://$DOMAIN/admin/  ${OWNER_EMAIL:-(no Owner yet: run again with OWNER_EMAIL=you@example.com)} / $(show "$OWNER_PASS")
                     (the first sign-in asks to set up the phone code)
  Workbench        : user ismile_viewer / $(show "$VIEWER_PASS"), database $DB, via "Standard TCP/IP over SSH"
All passwords are in $SECRETS (root only). Give them to people directly, never in a group chat.
DONE
