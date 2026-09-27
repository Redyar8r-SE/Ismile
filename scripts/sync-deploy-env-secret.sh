#!/usr/bin/env bash
# Validate local deploy.env and push it to GitHub Actions secret DEPLOY_ENV.
# deploy.env is gitignored for this repo — never commit it.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=lib/deploy-env-required.sh
source "$ROOT/scripts/lib/deploy-env-required.sh"

if ! command -v gh >/dev/null 2>&1; then
	echo "error: gh CLI required" >&2
	exit 1
fi

file="${1:-$ROOT/deploy.env}"
secret_name="${2:-DEPLOY_ENV}"

if [[ ! -f "$file" ]]; then
	echo "error: missing $file" >&2
	echo "hint: create a local deploy.env (gitignored) and fill secrets" >&2
	exit 1
fi

(
	set -euo pipefail
	set -a
	# shellcheck disable=SC1090
	source "$file"
	set +a
	require_keys "deploy env ($file)" "$REQUIRED_DEPLOY_SECRETS"
)

gh secret set "$secret_name" --repo Redyar8r-SE/Ismile <"$file"
echo "ok: GitHub $secret_name updated from $file (file stays local / gitignored)"
