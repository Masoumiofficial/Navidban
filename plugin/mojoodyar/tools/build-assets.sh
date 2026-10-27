#!/usr/bin/env bash
# build-assets.sh — assets are hand-written; this script does not build them.
#
# Why there is no build step: a customer must be able to read, diff and patch
# the CSS/JS they install. A minifier in the release pipeline would mean either
# shipping unreadable code or shipping a toolchain the customer cannot run.
#
# What this script does do:
#   1. verifies every asset referenced from PHP actually exists;
#   2. enforces the §6.4 size budget (12 KB gzip for front CSS+JS);
#   3. writes assets/manifest.json with sha256 + byte sizes, so a release can
#      be checked against what was audited;
#   4. warns about `!important` in front-end CSS (allowed only with a comment
#      explaining which theme it overrides).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BUDGET=12288
fail=0

front_css="assets/css/mojoodyar-front.css"
front_js="assets/js/mojoodyar-front.js"
admin_css="assets/css/mojoodyar-admin.css"
admin_js="assets/js/mojoodyar-admin.js"

echo "=== Mojoodyar asset check ==="

for f in "$front_css" "$front_js" "$admin_css" "$admin_js"; do
	if [ ! -f "$f" ]; then
		echo "[FAIL] missing $f"
		fail=1
	fi
done

# Every asset path used in PHP must exist on disk.
while IFS= read -r rel; do
	if [ ! -f "$rel" ]; then
		echo "[FAIL] enqueued but missing: $rel"
		fail=1
	fi
done < <(grep -rhoE "assets/(css|js)/[a-z0-9.-]+\.(css|js)" includes admin 2>/dev/null | sort -u)

raw_total=0
gz_total=0
for f in "$front_css" "$front_js"; do
	[ -f "$f" ] || continue
	raw=$(wc -c < "$f" | tr -d ' ')
	gz=$(gzip -9 -c "$f" | wc -c | tr -d ' ')
	raw_total=$((raw_total + raw))
	gz_total=$((gz_total + gz))
	printf '  %-34s %7s B raw  %7s B gzip\n' "$f" "$raw" "$gz"
done

echo "  front total: ${raw_total} B raw / ${gz_total} B gzip (budget ${BUDGET} B)"

if [ "$gz_total" -gt "$BUDGET" ]; then
	echo "[FAIL] front assets exceed the gzip budget"
	fail=1
else
	echo "[ok  ] front assets within budget"
fi

if grep -v '^\s*\*' "$front_css" 2>/dev/null | grep -q '!important'; then
	echo "[warn] !important found in front CSS — each one needs a comment naming the theme it overrides:"
	grep -vw '^\s*\*' "$front_css" | grep -n '!important' | sed 's/^/       /'
else
	echo "[ok  ] no !important in front CSS"
fi

# Manifest: sha256 + sizes for the shipped assets.
if command -v sha256sum >/dev/null 2>&1; then
	{
		echo '{'
		echo '  "generated_by": "tools/build-assets.sh",'
		printf '  "front_gzip_bytes": %s,\n' "$gz_total"
		printf '  "front_raw_bytes": %s,\n' "$raw_total"
		printf '  "budget_gzip_bytes": %s,\n' "$BUDGET"
		echo '  "files": {'
		first=1
		for f in "$front_css" "$front_js" "$admin_css" "$admin_js"; do
			[ -f "$f" ] || continue
			sum=$(sha256sum "$f" | cut -d' ' -f1)
			size=$(wc -c < "$f" | tr -d ' ')
			gz=$(gzip -9 -c "$f" | wc -c | tr -d ' ')
			[ "$first" -eq 1 ] || echo ','
			first=0
			printf '    "%s": {"sha256": "%s", "bytes": %s, "gzip_bytes": %s}' "$f" "$sum" "$size" "$gz"
		done
		echo ''
		echo '  }'
		echo '}'
	} > assets/manifest.json
	echo "[ok  ] wrote assets/manifest.json"
else
	echo "[warn] sha256sum not available — manifest not written"
fi

exit "$fail"
