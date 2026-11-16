# Mojoodyar / موجودیار

لیست انتظار و اعلان بازگشت موجودی برای ووکامرس — ساخته‌شده برای فروشگاه‌های
ایرانی. بدون Composer، بدون npm، بدون build، بدون IonCube، بدون لایسنس.

```
Navidban/             پژوهش و اسناد طراحی پیشین
plugin/mojoodyar/     سورس افزونه + تست‌ها + ابزارها + مستندات
plugin/release/      بستهٔ انتشار ساخته‌شده
```

## بستهٔ انتشار

`plugin/release/mojoodyar-1.0.0.zip` — همین را در وردپرس نصب کنید.

بستهٔ آمادهٔ ارسال به ژاکت (نصب + راهنمای فارسی + مجوز فونت + اطلاعات فایل):
`plugin/release/jacket/`.

فرم در صفحهٔ محصول واجد شرایط خودکار قرار می‌گیرد. برای جای‌گذاری دلخواه از
`[mojoodyar_form]` در همان محصول یا `[mojoodyar_form product_id="123"]` در
صفحهٔ دیگر استفاده کنید. ثبت به جاوااسکریپت و چاپ اسکریپت‌های پاورقی نیاز دارد؛
جزئیات و محدودیت قالب‌ها در `plugin/mojoodyar/docs/USER-GUIDE.md` است.

بازسازی و بازبینی:

```bash
cd plugin/mojoodyar
python3 tools/verify-release.py --build     # ۲۹ بررسی، باید ۰ خطا باشد
python3 tools/package-jacket.py             # بستهٔ ارسال به بازار
```

## آزمون‌ها

```bash
# با PHP نصب‌شده
php tests/run.php            # ۴۱۱ assertion
php tests/integration.php    # ۵۴۹ assertion با stub
php tests/admin-render.php   # ۱۷۹ assertion (رندر پنل با stub)
php tests/load.php           # ۱۰٬۰۰۰ مشترک
python3 tests/test_release_email_audit.py  # گیت ایمیل ثابت

# بدون PHP (wasm)
npm install --no-save @php-wasm/node @php-wasm/universal
node tools/smoke-php.mjs --engine=wasm --php=7.4 tests/run.php
```

نتایج ثبت‌شده: **۴۱۱/۴۱۱**، **۶۲۵/۶۲۵** و **۱۸۰/۱۸۰** روی PHP-WASM
**8.3.36** و **7.4.33** با stub؛ به‌علاوهٔ ۸۷ بررسی DOM و ۱۳۷ بررسی محدود
WordPress/WooCommerce در Playground با SQLite و jsdom. MySQL، مرورگر واقعی و
تحویل پیام آزمایش نشده‌اند. جزئیات: `plugin/mojoodyar/docs/TEST-REPORT.md`.

## مستندات

| سند | مسیر |
|---|---|
| مشخصات | `plugin/mojoodyar/docs/SPEC.md` |
| معماری | `plugin/mojoodyar/docs/ARCHITECTURE.md` |
| ساختار داده | `plugin/mojoodyar/docs/DATA-STRUCTURE.md` |
| سیاست زمان‌بندی | `plugin/mojoodyar/docs/SCHEDULER-POLICY.md` |
| عملکرد (تولیدشده) | `plugin/mojoodyar/docs/PERFORMANCE.md` |
| امنیت | `plugin/mojoodyar/docs/SECURITY.md` |
| عیب‌یابی | `plugin/mojoodyar/docs/TROUBLESHOOTING.md` |
| چک‌لیست QA | `plugin/mojoodyar/docs/QA-CHECKLIST.md` |
| گزارش آزمون | `plugin/mojoodyar/docs/TEST-REPORT.md` |
| راهنمای کاربر | `plugin/mojoodyar/docs/USER-GUIDE.md` |
| متن صفحهٔ فروش | `plugin/mojoodyar/docs/dev/listing-fa.md` |
| پاسخ به بازبینی راست‌چین | `plugin/mojoodyar/docs/dev/SUBMISSION-RTL-THEME.md` |
| آزمون‌هایی که فقط صاحب محصول می‌تواند اجرا کند | `plugin/mojoodyar/docs/dev/LIVE-TESTS-FA.md` |
| چک‌لیست ارسال به ژاکت | `plugin/mojoodyar/docs/dev/JACKET-SUBMISSION-FA.md` |
| پرامت مادر ساخت لندینگ و دمو | `plugin/mojoodyar/docs/dev/APEX-INFINITY-MASTER-PROMPT-FA.md` |
| بستهٔ معرفی محصول برای مارکت‌پلیس | `plugin/mojoodyar/docs/dev/MARKETPLACE-PRESENTATION-FA.md` |

## قاعدهٔ حاکم

هیچ عددی بدون اندازه‌گیری در این مخزن نیست. هر جا اندازه‌گیری ممکن نبود،
عبارت **«اندازه‌گیری نشد»** یا **«داده در دسترس نیست»** نوشته شده است —
از جمله TTFB، عملکرد MySQL و ماتریس سازگاری وردپرس/ووکامرس.
