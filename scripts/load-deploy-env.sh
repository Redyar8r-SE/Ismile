#!/usr/bin/env bash
# Load the single DEPLOY_ENV secret (multiline KEY=VALUE file) into the environment.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/deploy-env-required.sh
source "$SCRIPT_DIR/lib/deploy-env-required.sh"

: "${DEPLOY_ENV:?DEPLOY_ENV secret is required}"

printf '%s\n' "$DEPLOY_ENV" >.deploy.env

set -a
# shellcheck disable=SC1091
source .deploy.env
set +a

require_keys "DEPLOY_ENV" "$REQUIRED_DEPLOY_SECRETS"

export DEPLOY_USER="${DEPLOY_USER:-root}"
export DEPLOY_SSH_PORT="${DEPLOY_SSH_PORT:-22}"
export APP_PORT="${APP_PORT:-3000}"
export PM2_APP_NAME="${PM2_APP_NAME:-ismile}"
export GITHUB_BRANCH="${GITHUB_BRANCH:-main}"

github_env_set() {
	local key="$1"
	local value="${2-}"
	{
		echo "${key}<<EOF_${key}"
		printf '%s\n' "$value"
		echo "EOF_${key}"
	} >>"$GITHUB_ENV"
}

mask_secret() {
	local value="${1-}"
	if [[ -n "$value" ]]; then
		echo "::add-mask::${value}"
	fi
}

if [[ -n "${GITHUB_ENV:-}" ]]; then
	mask_secret "$DEPLOY_PASSWORD"
	mask_secret "$ADMIN_PASSWORD_HASH"
	mask_secret "$SESSION_SECRET"
	mask_secret "$GITHUB_TOKEN"

	github_env_set DEPLOY_HOST "$DEPLOY_HOST"
	github_env_set DEPLOY_USER "$DEPLOY_USER"
	github_env_set DEPLOY_PASSWORD "$DEPLOY_PASSWORD"
	github_env_set DEPLOY_SSH_PORT "$DEPLOY_SSH_PORT"
	github_env_set PM2_APP_NAME "$PM2_APP_NAME"
	github_env_set TARGET "$TARGET"
	github_env_set APP_PORT "$APP_PORT"
	github_env_set ADMIN_EMAIL "$ADMIN_EMAIL"
	github_env_set ADMIN_PASSWORD_HASH "$ADMIN_PASSWORD_HASH"
	github_env_set SESSION_SECRET "$SESSION_SECRET"
	github_env_set GITHUB_TOKEN "$GITHUB_TOKEN"
	github_env_set GITHUB_REPO "$GITHUB_REPO"
	github_env_set GITHUB_BRANCH "$GITHUB_BRANCH"
	github_env_set ALLOWED_ORIGIN "$ALLOWED_ORIGIN"
	github_env_set PUBLIC_URL "${PUBLIC_URL:-}"
	github_env_set DOMAINS "${DOMAINS:-}"
fi
