#!/usr/bin/env bash
# Checks that a site's MCP endpoint is reachable, authenticated, and exposes this plugin's abilities.
# Usage: bin/mcp-smoke-test.sh https://example.com wp_username "app password"
# Read-only: it only lists abilities and runs audit-site. Use it on staging first.
set -euo pipefail
SITE="${1:?site url}"; USER_NAME="${2:?username}"; PASS="${3:?application password}"
URL="${SITE%/}/wp-json/mcp/mcp-adapter-default-server"
rpc() { curl -sS -u "$USER_NAME:$PASS" -H "Content-Type: application/json" -H "MCP-Protocol-Version: 2025-06-18" ${SID:+-H "Mcp-Session-Id: $SID"} -X POST "$URL" -d "$1"; }

echo "1. unauthenticated request should be refused:"
curl -s -o /dev/null -w "   HTTP %{http_code}\n" -X POST "$URL" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

echo "2. initialize:"
HDR="$(mktemp)"
curl -sS -u "$USER_NAME:$PASS" -D "$HDR" -H "Content-Type: application/json" -X POST "$URL" \
	-d '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' | head -c 300; echo
SID="$(grep -i '^mcp-session-id' "$HDR" | awk '{print $2}' | tr -d '\r' || true)"; rm -f "$HDR"

echo "3. abilities this plugin exposes:"
rpc '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' | grep -o 'synapsefabric-seo\\/[a-z-]*' | sort -u | sed 's/\\//' | sed 's/^/   /'

echo "4. audit-site (read-only):"
rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"synapsefabric-seo/audit-site","parameters":{"max_per_type":1}}}}' | head -c 700; echo
