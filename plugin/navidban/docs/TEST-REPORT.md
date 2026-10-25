# گزارش آزمون — نویدبان ۱.۰.۰

> این پرونده از خروجی **واقعی** سه هارنس ساخته شده است. هیچ عددی دستی وارد
> نشده. بازتولید در بخش ۷.

## ۱) خلاصه

| مجموعه | PHP 8.3.33 | PHP 7.4.33 |
|---|---|---|
| `tests/run.php` (منطق خالص) | ۱۹۷ assertion · ۰ خطا · ۱۰۰٪ | ۱۹۷ assertion · ۰ خطا · ۱۰۰٪ |
| `tests/integration.php` (با stub وردپرس/ووکامرس + SQLite) | ۹۹ assertion · ۰ خطا · ۱۰۰٪ | ۹۹ assertion · ۰ خطا · ۱۰۰٪ |
| `tests/admin-render.php` (رندر واقعی ۵ صفحهٔ پنل) | ۴۷ assertion · ۰ خطا · ۱۰۰٪ | ۴۷ assertion · ۰ خطا · ۱۰۰٪ |
| `tests/load.php` (۱۰٬۰۰۰ مشترک، صف دسته‌ای) | ۷ بررسی · ۰ خطا | اجرا نشد (به بخش ۶) |

منابع خام: `tests/results.json`, `tests/results-php74.json`,
`tests/integration-results.json`, `tests/integration-results-php74.json`,
`tests/admin-render-results.json`, `tests/admin-render-results-php74.json`,
`tests/load-results.json`.

## ۲) آزمون واحد — ۱۴ گروه، ۱۹۷ assertion

| گروه | assertion | خطا |
|---|---|---|
| Validator / موبایل | ۲۹ | ۰ |
| Validator / ایمیل | ۲۲ | ۰ |
| Validator / کد ملی | ۱۰ | ۰ |
| Validator / متن | ۳ | ۰ |
| Jalali / تبدیل | ۲۰ | ۰ |
| Persian / ارقام | ۴ | ۰ |
| CSV / تزریق فرمول | ۲۰ | ۰ |
| Masking / حداقل‌سازی داده | ۴ | ۰ |
| FairSending / اعلام به تعداد موجودی | ۱۶ | ۰ |
| Reconcile / پایش تطبیقی | ۱۳ | ۰ |
| QueuePolicy / تلاش دوباره | ۹ | ۰ |
| QueuePolicy / نرخ و پنجرهٔ ارسال | ۲۱ | ۰ |
| Template / جای‌نگهدارها | ۸ | ۰ |
| HttpGuard / SSRF | ۱۸ | ۰ |

نمونه‌هایی از آنچه پوشش داده می‌شود: نرمال‌سازی `+98`/`0098`/ارقام فارسی به
`09xxxxxxxxx`؛ رد `0912 123 4567` با فاصله در ورودی خام و پذیرش پس از
نرمال‌سازی؛ الگوریتم کد ملی با بردارهای معتبر و نامعتبر؛ تبدیل کبیسهٔ شمسی؛
پیشوند `'` برای هر سلول CSV که با `=`, `+`, `-`, `@`, TAB, CR شروع شود؛
۱۵/۶۰/۳۶۰ دقیقه backoff؛ مرزهای پنجرهٔ ارسال (۰۸:۰۰ و ۲۲:۰۰)؛
بلاک `169.254.169.254`, `localhost`, `file://`, `http://` (غیر https).

## ۳) آزمون یکپارچه — ۸ گروه، ۹۹ assertion

| گروه | assertion | چه چیزی اجرا می‌شود |
|---|---|---|
| نصب و چیدمان پایگاه داده | ۱۲ | `Activator::activate()` واقعی، چهار جدول InnoDB، کلیدهای یکتا، زمان‌بندی cron |
| بارگذاری شرطی | ۸ | خانه/فید/ادمین/محصول موجود/محصول ناموجود/متغیر — صفر یا دو دارایی |
| ثبت درخواست | ۲۰ | مسیر کامل REST/AJAX با stub: nonce، هانی‌پات، rate limit، consent، کدهای خطا |
| تأیید و لغو اشتراک | ۷ | توکن، انقضا، `navidban_confirm` / `navidban_unsubscribe` |
| موتور موجودی | ۱۲ | هوک‌های ووکامرس، گذار ناموجود→موجود، `claim_transition` ضد تکرار |
| پایش تطبیقی | ۹ | اجرای اول بدون اعلان، تشخیص نوشتن مستقیم در دیتابیس |
| صف ارسال | ۱۹ | claim اتمی، backoff، قفل، بازهٔ ارسال، skip، ارسال منصفانهٔ دودسته‌ای |
| کانال پیامک | ۱۲ | payload JSON درگاه عمومی با نقل‌قول داخل پیام، شماره در جای درست |

«ارسال منصفانه» با آزمون واقعی سنجیده می‌شود: ۸ مشترک، موجودی برگشتی ۵،
دستهٔ ۵ نفره؛ سپس موجودی ۳ (۸ = ۵+۳) و دستهٔ ۳ نفره. بررسی می‌شود که
هیچ‌کس دو بار در صف نباشد، ترتیب `created_at` رعایت شود و دستهٔ دوم دقیقاً
بعد از اولی بیاید.

## ۳.۵) رندر پنل مدیریت — ۴۷ assertion

`tests/admin-render.php` هر پنج صفحه (داشبورد، لیست انتظارها، تنظیمات،
ابزارها، راهنما) را واقعاً رندر می‌کند و خروجی HTML را بازرسی می‌کند:

- ۶ فایل/صفحه: منوی اصلی + چهار زیرمنوی درست ثبت شده‌اند؛
- ۲۰ بررسی رندر: خروجی غیرخالی، نشانگر متن درست، پوشش `nvb-wrap`،
  نبود `<script>` تزریقی؛
- ۱۸ بررسی قرارداد: فرم تنظیمات به `options.php` و `navidban_settings[…]`
  وصل است، هفت ابزار با `value="…"` در فرم حاضرند، ردیف نمونه دیده می‌شود،
  ایمیل **ماسک‌شده** نمایش داده می‌شود، مقدار خطرناک به `&lt;` تبدیل شده؛
- ۳ بررسی سلامت: با `set_error_handler` هیچ notice/warning در رندر پنل
  تولید نشد (این آزمون یک `undefined array key` واقعی را هم گرفته است)؛
- ۲ بررسی مجوز: بدون `navidban_manage`، خروجی خالی است و `wp_die` صدا زده می‌شود.

## ۴) آزمون بار

`tests/load.php` روی SQLite (هارنس)، نه MySQL:

| سنجه | مقدار |
|---|---|
| مشترک ثبت‌شده | ۱۰٬۰۰۰ |
| زمان درج ۱۰٬۰۰۰ ردیف | ۹۰۵ ms |
| موجودی برگشتی | ۵ (۱۳ ms) |
| اعلان تولیدشده | **۵** (نه ۱۰٬۰۰۰) |
| پیام‌های صف‌شده برای رویداد دوم | ۱٬۰۰۰ (۶۶ ms) |
| ارسال در یک دسته | ۵۰ (سقف دسته رعایت شد) |
| باقی‌ماندهٔ صف | ۹۵۰ (گم نشد) |
| مدت یک دسته | ۳۳ ms |

**این اعداد بنچمارک MySQL نیستند.** SQLite در حافظه است. آنچه اثبات می‌شود
*رفتار* است: رعایت سقف دسته، رعایت «اعلام به تعداد موجودی»، و نبود گم‌شدن
پیام.

## ۵) ابزارهای ایستا

| ابزار | نتیجه |
|---|---|
| `tools/lint-php.js` (گرامر 7.4 + 8.3 روی ۵۰ فایل) | ۱۰۰ parse، ۰ خطا |
| `tools/check-methods.js` (۲۹۰ فراخوانی متد) | ۰ ارجاع شکسته |
| `tools/har-audit.sh` | CSS+JS فشرده ۳٬۴۶۹ بایت (بودجه ۱۲٬۲۸۸) |
| `tools/verify-release.py` | بخش ۸ |

`check-methods.js` خودش هم اعتبارسنجی شده است: با افزودن یک فایل موقت حاوی
`$this->no_such_method()` و `Settings::also_missing()` هر دو را گرفت و با
حذف فایل، به صفر برگشت.

## ۶) آنچه اندازه‌گیری نشد

| مورد | دلیل |
|---|---|
| روی MySQL واقعی | در این محیط MySQL در دسترس نیست. `tests/load.php` صریحاً بنچمارک MySQL نیست |
| `tests/load.php` روی PHP 7.4 | اجرا نشد؛ فقط 8.3 اجرا شد. کد آزمون از syntax 7.4 استفاده می‌کند و `lint-php.js` آن را می‌پذیرد، اما **اجرا** نشده |
| نصب واقعی WordPress/WooCommerce | هارنس stub است. رفتار وردپرس واقعی (cron, REST, DB) شبیه‌سازی شده، نه اجرا |
| HPOS روشن/خاموش | روی نصب واقعی اجرا نشد |
| چندسایتی | اجرا نشد |
| WordPress 7.0/7.1، WooCommerce 11 | نسخه‌ها در زمان تست در دسترس نبودند |
| TTFB | نیاز به سایت واقعی؛ روش در `docs/PERFORMANCE.md` |
| PHP 8.4 | گرامر پروژه 8.3 است؛ 8.4 اجرا نشد |

## ۷) بازتولید

```bash
# بدون PHP نصب‌شده (wasm):
npm install --no-save @php-wasm/node @php-wasm/universal
node tools/smoke-php.mjs --engine=wasm --php=8.3 tests/run.php --json --json-out=tests/results.json
node tools/smoke-php.mjs --engine=wasm --php=7.4 tests/run.php --json --json-out=tests/results-php74.json
node tools/smoke-php.mjs --engine=wasm --php=8.3 tests/integration.php --json --json-out=tests/integration-results.json
node tools/smoke-php.mjs --engine=wasm --php=7.4 tests/integration.php --json --json-out=tests/integration-results-php74.json
node tools/smoke-php.mjs --engine=wasm tests/load.php --json --json-out=tests/load-results.json

# با PHP نصب‌شده:
php tests/run.php
php tests/integration.php
php tests/load.php
```

## ۸) دروازهٔ انتشار

`python3 tools/verify-release.py --build` بسته را می‌سازد و می‌سنجد:
نبود `tests/`/`tools/`/`docs/dev/`/`.github/`، تطابق `Version:` با
CHANGELOG، وجود `license.txt`، نبود `MyISAM`/`eval`/`base64_decode`،
نبود نام‌های تجاری ممنوع، و نبود `$wpdb` بدون `prepare`.
