# Navidban / نویدبان

لیست انتظار و اعلان بازگشت موجودی برای ووکامرس — ساخته‌شده برای فروشگاه‌های
ایرانی. بدون Composer، بدون npm، بدون build، بدون IonCube، بدون لایسنس.

```
Navidban/            پژوهش بازار، برند و پرامپت ساخت (اسناد طراحی)
plugin/navidban/     سورس افزونه + تست‌ها + ابزارها + مستندات
plugin/release/      بستهٔ انتشار ساخته‌شده
```

## بستهٔ انتشار

`plugin/release/navidban-1.0.9.zip` — همین را در وردپرس نصب کنید.

بازسازی و بازبینی:

```bash
cd plugin/navidban
python3 tools/verify-release.py --build     # ۲۲ بررسی، باید ۰ خطا باشد
```

## آزمون‌ها

```bash
# با PHP نصب‌شده
php tests/run.php            # ۲۹۲ assertion
php tests/integration.php    # ۱۸۸ assertion
php tests/admin-render.php   # ۱۰۵ assertion (رندر واقعی پنل)
php tests/load.php           # ۱۰٬۰۰۰ مشترک

# بدون PHP (wasm)
npm install --no-save @php-wasm/node @php-wasm/universal
node tools/smoke-php.mjs --engine=wasm --php=7.4 tests/run.php
```

نتیجهٔ آخر: **۲۹۲/۲۹۲**، **۱۸۸/۱۸۸** و **۱۰۵/۱۰۵** روی PHP **8.3.33** و **7.4.33**.
جزئیات: `plugin/navidban/docs/TEST-REPORT.md`.

## مستندات

| سند | مسیر |
|---|---|
| مشخصات | `plugin/navidban/docs/SPEC.md` |
| معماری | `plugin/navidban/docs/ARCHITECTURE.md` |
| ساختار داده | `plugin/navidban/docs/DATA-STRUCTURE.md` |
| سیاست زمان‌بندی | `plugin/navidban/docs/SCHEDULER-POLICY.md` |
| عملکرد (تولیدشده) | `plugin/navidban/docs/PERFORMANCE.md` |
| امنیت | `plugin/navidban/docs/SECURITY.md` |
| عیب‌یابی | `plugin/navidban/docs/TROUBLESHOOTING.md` |
| چک‌لیست QA | `plugin/navidban/docs/QA-CHECKLIST.md` |
| گزارش آزمون | `plugin/navidban/docs/TEST-REPORT.md` |
| راهنمای کاربر | `plugin/navidban/docs/USER-GUIDE.md` |
| متن صفحهٔ فروش | `plugin/navidban/docs/dev/listing-fa.md` |

## قاعدهٔ حاکم

هیچ عددی بدون اندازه‌گیری در این مخزن نیست. هر جا اندازه‌گیری ممکن نبود،
عبارت **«اندازه‌گیری نشد»** یا **«داده در دسترس نیست»** نوشته شده است —
از جمله TTFB، عملکرد MySQL و ماتریس سازگاری وردپرس/ووکامرس.
