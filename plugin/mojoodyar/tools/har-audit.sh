#!/usr/bin/env bash
# har-audit.sh — produce docs/PERFORMANCE.md from measurements that this
# repository can actually take, and clearly label everything it cannot take.
#
# What is measured here (reproducible on any machine with bash/python3/node):
#   1. shipped front-end bytes, raw and gzip;
#   2. number of plugin assets enqueued per page type;
#   3. number of SQL statements the plugin issues per page type.
#
# What is NOT measured here, by design:
#   4. TTFB delta on a real WordPress install (needs a live site);
#   5. bytes on the wire behind a CDN (needs the customer's stack).
# Those two are printed as «اندازه‌گیری نشد» with the exact procedure to obtain
# them. If a HAR file from a real site is supplied, requests/bytes are extracted
# from it instead of being guessed.
#
# Usage:
#   tools/har-audit.sh                     # full audit -> docs/PERFORMANCE.md
#   tools/har-audit.sh --har export.har    # also fold in real network captures
#   tools/har-audit.sh --check             # exit 1 if a budget is exceeded

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

HAR_FILE=""
CHECK_ONLY=0
while [ $# -gt 0 ]; do
	case "$1" in
		--har) HAR_FILE="${2:-}"; shift 2 ;;
		--check) CHECK_ONLY=1; shift ;;
		-h|--help) sed -n '1,30p' "$0"; exit 0 ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

DOC="docs/PERFORMANCE.md"
BUDGET_COMPRESSED=12288 # 12 KB, §6.4
BUDGET_QUERIES=3
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fail=0

# --- 1. shipped bytes ------------------------------------------------------
FRONT_CSS="assets/css/mojoodyar-front.css"
FRONT_JS="assets/js/mojoodyar-front.js"

for file in "$FRONT_CSS" "$FRONT_JS"; do
	[ -f "$file" ] || { echo "missing $file" >&2; exit 1; }
done

raw_css=$(wc -c < "$FRONT_CSS" | tr -d ' ')
raw_js=$(wc -c < "$FRONT_JS" | tr -d ' ')
gz_css=$(gzip -9 -c "$FRONT_CSS" | wc -c | tr -d ' ')
gz_js=$(gzip -9 -c "$FRONT_JS" | wc -c | tr -d ' ')
raw_total=$((raw_css + raw_js))
gz_total=$((gz_css + gz_js))

if [ "$gz_total" -gt "$BUDGET_COMPRESSED" ]; then
	echo "BUDGET EXCEEDED: compressed front assets ${gz_total} B > ${BUDGET_COMPRESSED} B" >&2
	fail=1
fi

# --- 2 & 3. per-page scenario probe ---------------------------------------
QUERY_JSON="$TMP_DIR/queries.json"
PROBE_STATUS="not-run"
PROBE_NOTE=""

if command -v node >/dev/null 2>&1 && [ -f tools/smoke-php.mjs ]; then
	if node tools/smoke-php.mjs --engine=wasm tests/queries.php >"$QUERY_JSON" 2>"$TMP_DIR/probe.err"; then
		PROBE_STATUS="ok"
	elif command -v php >/dev/null 2>&1 && php tests/queries.php >"$QUERY_JSON" 2>"$TMP_DIR/probe.err"; then
		PROBE_STATUS="ok"
		PROBE_NOTE="native php"
	else
		PROBE_STATUS="failed"
		PROBE_NOTE="$(tail -2 "$TMP_DIR/probe.err" 2>/dev/null | tr '\n' ' ')"
	fi
elif command -v php >/dev/null 2>&1; then
	if php tests/queries.php >"$QUERY_JSON" 2>"$TMP_DIR/probe.err"; then
		PROBE_STATUS="ok"
		PROBE_NOTE="native php"
	else
		PROBE_STATUS="failed"
	fi
else
	PROBE_NOTE="no PHP runtime available (install php, or npm install --no-save @php-wasm/node)"
fi

# --- 4. optional real network capture --------------------------------------
HAR_JSON="$TMP_DIR/har.json"
HAR_STATUS="not-provided"
if [ -n "$HAR_FILE" ]; then
	[ -f "$HAR_FILE" ] || { echo "HAR not found: $HAR_FILE" >&2; exit 1; }
	HAR_STATUS="ok"
fi

python3 - "$DOC" "$raw_css" "$raw_js" "$gz_css" "$gz_js" "$raw_total" "$gz_total" \
	"$BUDGET_COMPRESSED" "$BUDGET_QUERIES" "$QUERY_JSON" "$PROBE_STATUS" "$PROBE_NOTE" \
	"$HAR_FILE" "$HAR_STATUS" "$CHECK_ONLY" <<'PY'
import json
import os
import sys
from datetime import datetime, timezone

(doc, raw_css, raw_js, gz_css, gz_js, raw_total, gz_total,
 budget_compressed, budget_queries, query_json, probe_status, probe_note,
 har_file, har_status, check_only) = sys.argv[1:]

raw_css, raw_js = int(raw_css), int(raw_js)
gz_css, gz_js = int(gz_css), int(gz_js)
raw_total, gz_total = int(raw_total), int(gz_total)
budget_compressed, budget_queries = int(budget_compressed), int(budget_queries)

now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")

scenarios = []
if probe_status == "ok":
    with open(query_json, encoding="utf-8") as handle:
        scenarios = json.load(handle).get("scenarios", [])

har_report = None
if har_status == "ok":
    with open(har_file, encoding="utf-8") as handle:
        har = json.load(handle)
    pages = []
    for entry in har.get("log", {}).get("entries", []):
        request = entry.get("request", {})
        response = entry.get("response", {})
        url = request.get("url", "")
        if "mojoodyar" not in url:
            continue
        pages.append({
            "url": url,
            "status": response.get("status"),
            "bytes": max(0, int(response.get("bodySize", 0) or 0)),
        })
    har_report = {
        "entries": len(har.get("log", {}).get("entries", [])),
        "plugin_requests": len(pages),
        "plugin_bytes": sum(item["bytes"] for item in pages),
        "samples": pages[:20],
    }

lines = []
add = lines.append

add("# عملکرد موجودیار")
add("")
add("> این پرونده **تولیدشده** است: `tools/har-audit.sh`. دستی ویرایش نکنید؛")
add("> با هر تغییر در CSS/JS یا منطق بارگذاری، دوباره بسازید.")
add("")
add(f"آخرین تولید: {now}")
add("")
add("## ۱) بودجهٔ رسمی (§۶.۴)")
add("")
add("| مورد | بودجه | وضعیت |")
add("|---|---|---|")
add(f"| فایل اضافه در صفحات غیرهدف | ۰ | {'اندازه‌گیری شد' if scenarios else 'اندازه‌گیری نشد'} |")
add(f"| CSS+JS فشرده | ≤ {budget_compressed // 1024} KB | {'اندازه‌گیری شد' if gz_total else 'اندازه‌گیری نشد'} |")
add(f"| پرس‌وجوی اضافه در صفحهٔ محصول ناموجود | ≤ {budget_queries} | {'اندازه‌گیری شد' if scenarios else 'اندازه‌گیری نشد'} |")
add("| اختلاف TTFB | ≤ ۵ میلی‌ثانیه | **اندازه‌گیری نشد** |")
add("")
add("## ۲) بایت‌های ارسالی به مرورگر")
add("")
add("اندازه‌گیری با `wc -c` و `gzip -9` روی همان فایل‌هایی که در بستهٔ انتشار هستند.")
add("")
add("| فایل | خام | gzip |")
add("|---|---|---|")
add(f"| `assets/css/mojoodyar-front.css` | {raw_css:,} B | {gz_css:,} B |")
add(f"| `assets/js/mojoodyar-front.js` | {raw_js:,} B | {gz_js:,} B |")
add(f"| **جمع** | **{raw_total:,} B** | **{gz_total:,} B** |")
add("")
verdict = "در محدوده" if gz_total <= budget_compressed else "**خارج از بودجه**"
add(f"نتیجه: {gz_total:,} بایت فشرده در برابر بودجهٔ {budget_compressed:,} بایت — {verdict}.")
add("")
add("توجه: سرورهای واقعی ممکن است Brotli بفرستند که از gzip کوچک‌تر است؛")
add("عدد بالا بدترین حالتِ رایج (gzip) است.")
add("")
add("## ۳) بارگذاری به تفکیک نوع صفحه")
add("")
if scenarios:
    add("اندازه‌گیری‌شده با `tests/queries.php` روی هارنس تست (SQLite).")
    add("تعداد پرس‌وجو معتبر است؛ **زمان** اجرای پرس‌وجو گزارش نمی‌شود چون SQLite جای MySQL نیست.")
    add("")
    add("| صفحه | دارایی صف‌شده | style | script | پرس‌وجوی افزونه | بایت HTML فرم |")
    add("|---|---|---|---|---|---|")
    over = False
    for row in scenarios:
        add(f"| {row['label']} | {row['assets_enqueued']} | {row['styles']} | {row['scripts']} | {row['queries']} | {row['form_bytes']:,} |")
        if row["queries"] > budget_queries:
            over = True
    add("")
    if over:
        add("**هشدار: دست‌کم یک صفحه از بودجهٔ پرس‌وجو عبور کرده است.**")
    else:
        add("هیچ صفحه‌ای از بودجهٔ پرس‌وجو عبور نکرده است.")
else:
    add("**اندازه‌گیری نشد.** پروب اجرا نشد" + (f" ({probe_note})" if probe_note else "") + ".")
    add("")
    add("برای گرفتن این جدول: `php tests/queries.php` یا")
    add("`npm install --no-save @php-wasm/node @php-wasm/universal && node tools/smoke-php.mjs tests/queries.php`.")
add("")
add("### محدودیت این اندازه‌گیری (مهم)")
add("")
add("هارنس، `get_option()` را از حافظه می‌خواند و بنابراین کوئری خواندن آپشن")
add("را در شمارش نمی‌بیند. در وردپرس واقعی `mojoodyar_settings` با")
add("`autoload = false` ذخیره می‌شود (عمدی: تا در هیچ صفحه‌ای بارگذاری نشود)،")
add("پس نخستین خواندن آن در صفحهٔ محصول ناموجود **۱ کوئری** است.")
add("")
add("| صفحه | کوئری اندازه‌گیری‌شده در هارنس | تخمین وردپرس واقعی |")
add("|---|---|---|")
add("| صفحات غیرهدف | ۰ | ۰ (تنظیمات خوانده نمی‌شود) |")
add("| محصول ناموجود | ۰ | ۱ (خواندن آپشن؛ در بودجهٔ ≤۳ جا می‌شود) |")
add("")
add("تخمین ستون آخر **اندازه‌گیری نشده** است؛ روی نصب واقعی با")
add("Query Monitor قابل تأیید است و در `docs/QA-CHECKLIST.md` ثبت شده.")
add("")
add("## ۴) TTFB — اندازه‌گیری نشد")
add("")
add("TTFB به میزبان، نسخهٔ PHP، OPcache، کش صفحه، افزونه‌های دیگر و پیکربندی")
add("MySQL بستگی دارد. ادعای عددی برای آن بدون اندازه‌گیری روی سایت واقعی،")
add("ساختگی است؛ بنابراین این پرونده عددی اعلام نمی‌کند.")
add("")
add("روش بازتولید (روی سایت واقعی، با داده‌های واقعی):")
add("")
add("```bash")
add("# ۳ بار اندازه‌گیری صفحهٔ محصول موجود (پایه)")
add("for i in 1 2 3; do")
add("  curl -s -o /dev/null -w '%{time_starttransfer}\\n' https://shop.example/product/in-stock/")
add("done")
add("# ۳ بار اندازه‌گیری صفحهٔ محصول ناموجود (هدف)")
add("for i in 1 2 3; do")
add("  curl -s -o /dev/null -w '%{time_starttransfer}\\n' https://shop.example/product/out-of-stock/")
add("done")
add("```")
add("")
add("سپس میانهٔ دو مجموعه را کم کنید. اگر اختلاف از ۵ میلی‌ثانیه بیشتر بود، پیش از")
add("انتشار گزارش دهید — بودجه نقض شده است.")
add("")
add("## ۵) ثبت شبکهٔ واقعی")
add("")
if har_report:
    add(f"HAR داده‌شده: {har_report['entries']:,} ورودی، {har_report['plugin_requests']} درخواست مربوط به موجودیار،")
    add(f"جمع {har_report['plugin_bytes']:,} بایت پاسخ افزونه.")
    add("")
    add("| URL | وضعیت | بایت |")
    add("|---|---|---|")
    for sample in har_report["samples"]:
        add(f"| `{sample['url']}` | {sample['status']} | {sample['bytes']:,} |")
else:
    add("**اندازه‌گیری نشد.** هیچ HARای ارائه نشده است.")
    add("")
    add("روش: در Chrome → DevTools → Network، گزینهٔ «Disable cache» را بزنید،")
    add("صفحهٔ خانه، یک محصول موجود و یک محصول ناموجود را باز کنید، سپس")
    add("«Save all as HAR» و:")
    add("")
    add("```bash")
    add("tools/har-audit.sh --har ~/mojoodyar.har")
    add("```")
    add("")
    add("در آن حالت، بایت‌ها و درخواست‌ها از خودِ ضبط شبکه استخراج می‌شوند، نه از حدس.")
add("")
add("## ۶) چه چیزی این پرونده ادعا نمی‌کند")
add("")
add("- هیچ عدد TTFB یا زمان پاسخ MySQL.");
add("- هیچ مقایسه‌ای با افزونهٔ دیگری (مقایسه نیاز به دو نصب واقعی دارد).")
add("- هیچ عددی از «تعداد بازدید» یا «نرخ تبدیل».")
add("")

if check_only:
    sys.stderr.write(f"gz_total={gz_total} budget={budget_compressed}\n")

with open(doc, "w", encoding="utf-8") as handle:
    handle.write("\n".join(lines))

print(f"{doc}: {gz_total:,} B gzip / {budget_compressed:,} B budget; probe={probe_status}")

if gz_total > budget_compressed:
    sys.exit(1)
PY

if [ "$CHECK_ONLY" -eq 0 ]; then
	echo "wrote $DOC"
fi

if [ "$fail" -ne 0 ]; then
	exit 1
fi
