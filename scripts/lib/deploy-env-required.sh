#!/usr/bin/env bash
# Shared required-key checks for DEPLOY_ENV / deploy.env.
# Bash 3.2–compatible (macOS /bin/bash).

# Pushing code does NOT update the GitHub secret — run: npm run deploy:sync-env
# deploy.env must NOT be committed for this repo.
REQUIRED_DEPLOY_SECRETS="
DEPLOY_HOST
DEPLOY_USER
DEPLOY_PASSWORD
DEPLOY_SSH_PORT
PM2_APP_NAME
TARGET
APP_PORT
ADMIN_EMAIL
ADMIN_PASSWORD_HASH
SESSION_SECRET
GITHUB_TOKEN
GITHUB_REPO
ALLOWED_ORIGIN
"

require_keys() {
	local context="$1"
	local keys="$2"
	local missing=0
	local key
	# shellcheck disable=SC2086
	for key in $keys; do
		if [[ -z "${!key:-}" ]]; then
			echo "error: ${key} is required in ${context} (sync with: npm run deploy:sync-env)" >&2
			missing=1
		fi
	done
	if [[ "$missing" -ne 0 ]]; then
		exit 1
	fi
}
