#!/bin/bash
# Puts the project from this Git folder onto the server.
#
#   bash backend/tools/deploy.sh test    ->  test.ismile.krd   (+ ~/ismile-backend-test)
#   bash backend/tools/deploy.sh live    ->  ismile.krd        (+ ~/ismile-backend)
#
# Run by cPanel's Git "Deploy" button through .cpanel.yml, or by hand in the
# cPanel Terminal. Always deploy to test first, check, then live.
#
# What it never touches on the server: data/ files the admin has edited,
# assets/uploads/, backend config.php, backend storage/ (photos, backups).

set -euo pipefail

TARGET="${1:-}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
case "$TARGET" in
  live) SITE="${ISMILE_SITE:-$HOME/public_html}";      BACKEND="${ISMILE_BACKEND_DIR:-$HOME/ismile-backend}" ;;
  test) SITE="${ISMILE_SITE:-$HOME/test.ismile.krd}";  BACKEND="${ISMILE_BACKEND_DIR:-$HOME/ismile-backend-test}" ;;
  *) echo "Usage: deploy.sh test|live"; exit 1 ;;
esac
# The PHP used on the command line must be 8.1+. On cPanel the plain "php"
# can be an older version than the website uses, so the cPanel builds are
# tried first. Set PHP=/path/to/php to choose one by hand.
if [ -z "${PHP:-}" ]; then
  for candidate in /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php php; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; then
      PHP="$candidate"
      break
    fi
  done
fi
if [ -z "${PHP:-}" ] || ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
  echo "STOPPED before changing anything: PHP 8.1 or newer was not found. Run with PHP=/path/to/php8.1 bash deploy.sh $TARGET" >&2
  exit 1
fi
echo "Deploying $TARGET with $("$PHP" -r 'echo PHP_VERSION;'): website -> $SITE, backend -> $BACKEND"
mkdir -p "$SITE" "$BACKEND"

# ---- Backend first (outside the web folder), so the new pages never run
#      against old backend code ----
for item in src cron database tools tests bootstrap.php composer.json composer.lock config.sample.php .htaccess; do
  [ -e "$REPO/backend/$item" ] && cp -R "$REPO/backend/$item" "$BACKEND/"
done
mkdir -p "$BACKEND/storage"
chmod 750 "$BACKEND/storage" || true

# The PDF/QR library. cPanel usually has Composer; otherwise upload vendor/ once by hand.
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$BACKEND" --quiet
elif [ -x /opt/cpanel/composer/bin/composer ]; then
  "$PHP" /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$BACKEND" --quiet
elif [ ! -d "$BACKEND/vendor" ]; then
  echo "WARNING: Composer not found and $BACKEND/vendor is missing. Upload vendor/ (see backend/README.md)."
fi

# ---- Website code (pages, design, scripts, API, admin) ----
for item in index.html register.html workshops.html sponsor.html admin.html payment.html favicon.ico .htaccess css js api admin server; do
  [ -e "$REPO/$item" ] && cp -R "$REPO/$item" "$SITE/"
done
# Pictures and video, but not assets/uploads (the admin's own uploads live there on the server).
mkdir -p "$SITE/assets/uploads"
for item in "$REPO"/assets/*; do
  [ "$(basename "$item")" = "uploads" ] && continue
  cp -R "$item" "$SITE/assets/"
done
# Content: only new files and new translation keys; nothing the admin changed is overwritten.
mkdir -p "$SITE/data"
"$PHP" "$REPO/backend/tools/merge-data.php" "$REPO/data" "$SITE/data"

# The API finds its backend through this one-line file (not in Git).
if [ ! -f "$SITE/api/_backend.php" ]; then
  printf "<?php return '%s';\n" "$BACKEND" > "$SITE/api/_backend.php"
fi

if [ ! -f "$BACKEND/config.php" ]; then
  echo "FIRST TIME: copy $BACKEND/config.sample.php to $BACKEND/config.php, fill it in, then run:"
  echo "  $PHP $BACKEND/tools/install.php --owner you@example.com \"Your Name\""
else
  # New tables, views and upgrades (database/migrations), if this update brought any.
  if ! "$PHP" "$BACKEND/tools/install.php"; then
    echo "DATABASE UPDATE FAILED: the files were updated but the database was not. Fix this before using the site." >&2
    exit 1
  fi
fi
echo "Done: $TARGET"
