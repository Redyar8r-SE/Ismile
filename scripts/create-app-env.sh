#!/usr/bin/env bash
# Write root .env from loaded deploy env vars. SSH credentials stay out of .env.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT"

: "${ADMIN_EMAIL:?ADMIN_EMAIL required}"
: "${ADMIN_PASSWORD_HASH:?ADMIN_PASSWORD_HASH required}"
: "${SESSION_SECRET:?SESSION_SECRET required}"
: "${GITHUB_TOKEN:?GITHUB_TOKEN required}"
: "${GITHUB_REPO:?GITHUB_REPO required}"
: "${ALLOWED_ORIGIN:?ALLOWED_ORIGIN required}"

APP_PORT="${APP_PORT:-3000}"
GITHUB_BRANCH="${GITHUB_BRANCH:-main}"

umask 077
{
	echo "ADMIN_EMAIL=${ADMIN_EMAIL}"
	echo "ADMIN_PASSWORD_HASH=${ADMIN_PASSWORD_HASH}"
	echo "SESSION_SECRET=${SESSION_SECRET}"
	echo "GITHUB_TOKEN=${GITHUB_TOKEN}"
	echo "GITHUB_REPO=${GITHUB_REPO}"
	echo "GITHUB_BRANCH=${GITHUB_BRANCH}"
	echo "ALLOWED_ORIGIN=${ALLOWED_ORIGIN}"
	echo "PORT=${APP_PORT}"
	echo "APP_PORT=${APP_PORT}"
	echo "PM2_APP_NAME=${PM2_APP_NAME:-ismile}"
} >.env

echo "Wrote .env"
