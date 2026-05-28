#!/usr/bin/env bash
# Bootstrap a Studio site with the Studio Agent plugin.
#
# Idempotent: if the site already exists this re-installs plugins and
# refreshes the wpcom token so subsequent runs are safe.
#
# Requires:
#   - studio CLI on PATH and authenticated (`studio auth status` shows logged in)
#   - python3 (for safe JSON parsing of ~/.studio/shared.json)
#   - a local checkout of Automattic/agents-api at $AGENTS_API_PATH
#     (defaults to a sibling of this repo: ../agents-api)
#
# Usage:
#   ./bin/bootstrap.sh [site-name]
#
# Optional env:
#   AGENTS_API_PATH  Path to local agents-api checkout (default ../agents-api)
#   WP_VERSION       WordPress version to install (default 7.0)
#   PHP_VERSION      PHP version (default 8.4)
#   ADMIN_PASSWORD   Admin password to set (default "password")

set -euo pipefail

SITE_NAME="${1:-studio-agent-poc}"
SITE_PATH="$HOME/Studio/$SITE_NAME"
WP_VERSION="${WP_VERSION:-7.0}"
PHP_VERSION="${PHP_VERSION:-8.4}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-password}"

# Resolve plugin source paths relative to this script.
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_SOURCE="$( cd "$SCRIPT_DIR/.." && pwd )"
AGENTS_API_PATH="${AGENTS_API_PATH:-$( cd "$PLUGIN_SOURCE/.." && pwd )/agents-api}"

SHARED_CONFIG="$HOME/.studio/shared.json"

log()  { printf '\033[1;34m▸\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

command -v studio  >/dev/null 2>&1 || fail "studio CLI not found on PATH"
command -v python3 >/dev/null 2>&1 || fail "python3 not found on PATH"
command -v npm     >/dev/null 2>&1 || fail "npm not found on PATH (needed to build the chat block)"
[[ -d "$AGENTS_API_PATH" ]] || fail "agents-api checkout not found at $AGENTS_API_PATH (set AGENTS_API_PATH)"
[[ -f "$SHARED_CONFIG"  ]] || fail "$SHARED_CONFIG not found — log in to Studio first"

WPCOM_TOKEN="$( python3 -c "import json,sys; print(json.load(open('$SHARED_CONFIG'))['authToken']['accessToken'])" )"
[[ -n "$WPCOM_TOKEN" ]] || fail "no wpcom auth token in $SHARED_CONFIG — run 'studio auth login'"

# Build the chat block bundle. The plugin needs blocks/chat/build/* to enqueue
# the React surface; without this the admin page shows a missing-bundle notice.
if [[ ! -f "$PLUGIN_SOURCE/blocks/chat/build/view.js" ]] || [[ "$PLUGIN_SOURCE/blocks/chat/src/view.js" -nt "$PLUGIN_SOURCE/blocks/chat/build/view.js" ]]; then
	if [[ ! -d "$PLUGIN_SOURCE/node_modules" ]]; then
		log "Installing JS dependencies (first run takes a few minutes)…"
		( cd "$PLUGIN_SOURCE" && npm install --silent --no-audit --no-fund )
	fi
	log "Building chat block bundle…"
	( cd "$PLUGIN_SOURCE" && npm run build --silent )
else
	log "Chat block bundle is up to date."
fi

# 1. Create or reuse the site. Studio considers a site "in use" if its
# directory exists and contains a WP install — easier to detect than parsing
# `studio site list` which has unicode glyphs.
if [[ -f "$SITE_PATH/wp-config.php" ]]; then
	log "Site '$SITE_NAME' already exists at $SITE_PATH — reusing."
	studio site start --path "$SITE_PATH" >/dev/null 2>&1 || true
else
	log "Creating site '$SITE_NAME' (WP $WP_VERSION, PHP $PHP_VERSION)…"
	studio site create \
		--path "$SITE_PATH" \
		--name "$SITE_NAME" \
		--wp "$WP_VERSION" \
		--php "$PHP_VERSION" \
		--admin-password "$ADMIN_PASSWORD" \
		--skip-browser \
		--skip-log-details
fi

# 2. Install plugins. Copy agents-api (no symlink — it's a redistributable package);
# symlink studio-agent so dev edits are live.
PLUGINS_DIR="$SITE_PATH/wp-content/plugins"
mkdir -p "$PLUGINS_DIR"

log "Installing agents-api → $PLUGINS_DIR/agents-api"
rm -rf "$PLUGINS_DIR/agents-api"
cp -R "$AGENTS_API_PATH" "$PLUGINS_DIR/agents-api"

# Lower the agents-api version requirement so it loads on 6.9 too. Idempotent —
# the substrate itself works on 6.9 + a custom provider; only wp-ai-client is gated.
sed -i.bak -E 's/^( \* Requires at least:) 7\.0$/\1 6.5/' "$PLUGINS_DIR/agents-api/agents-api.php"
rm -f "$PLUGINS_DIR/agents-api/agents-api.php.bak"

log "Linking studio-agent → $PLUGINS_DIR/studio-agent"
ln -sfn "$PLUGIN_SOURCE" "$PLUGINS_DIR/studio-agent"

# 3. Activate plugins + inject wpcom token.
log "Activating plugins…"
( cd "$SITE_PATH" && studio wp plugin activate agents-api studio-agent ) >/dev/null

log "Injecting wpcom token as option…"
( cd "$SITE_PATH" && studio wp option update studio_wpcom_token "$WPCOM_TOKEN" ) >/dev/null

# 4. Print summary.
SITE_URL="$( cd "$SITE_PATH" && studio wp option get siteurl 2>/dev/null | tail -1 )"
ADMIN_URL="${SITE_URL%/}/wp-admin/admin.php?page=studio-agent"

cat <<EOF

Studio Agent ready.
  Site:        $SITE_URL
  Chat:        $ADMIN_URL
  Admin login: admin / $ADMIN_PASSWORD

Open the Chat URL after logging in to start talking to the agent.
EOF
