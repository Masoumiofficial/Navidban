# گزارش آزمون — نویدبان ۱.۰.۵

> این پرونده از خروجی **واقعی** سه هارنس ساخته شده است. هیچ عددی دستی وارد
> نشده. بازتولید در بخش ۷.

## ۱) خلاصه

| مجموعه | PHP 8.3.33 | PHP 7.4.33 |
|---|---|---|
| `tests/run.php` (منطق خالص) | ۲۵۰ assertion · ۰ خطا · ۱۰۰٪ | ۲۵۰ assertion · ۰ خطا · ۱۰۰٪ |
| `tests/integration.php` (با stub وردپرس/ووکامرس + SQLite) | ۱۳۴ assertion · ۰ خطا · ۱۰۰٪ | ۱۳۴ assertion · ۰ خطا · ۱۰۰٪ |
| `tests/admin-render.php` (رندر واقعی ۵ صفحهٔ پنل) | ۷۷ assertion · ۰ خطا · ۱۰۰٪ | ۷۷ assertion · ۰ خطا · ۱۰۰٪ |
| `tests/load.php` (۱۰٬۰۰۰ مشترک، صف دسته‌ای) | ۷ بررسی · ۰ خطا | اجرا نشد (به بخش ۶) |

منابع خام: `tests/results.json`, `tests/results-php74.json`,
`tests/integration-results.json`, `tests/integration-results-php74.json`,
`tests/admin-render-results.json`, `tests/admin-render-results-php74.json`,
`tests/load-results.json`.

## ۲) آزمون واحد — ۱۷ گروه، ۲۵۰ assertion

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
| Text / برش چندبایتی | ۷ | ۰ |
| EmailChannel / تشخیص خطای ارسال | ۲۸ | ۰ |
| EmailSkin / پوستهٔ ایمیل | ۱۸ | ۰ |
| HttpGuard / SSRF | ۱۸ | ۰ |

**افزودهٔ ۱.۰.۱:** `Text::substring()` هرگز کاراکتر UTF-8 را نصف نمی‌کند
(۱۲ نقطهٔ برش بایتی در یک متن فارسی واقعاً خراب می‌کند، برش کاراکتری هیچ‌کدام)،
و `EmailChannel::diagnose()`/`hint()` همیشه ساختار درست برمی‌گردانند.

نمونه‌هایی از آنچه پوشش داده می‌شود: نرمال‌سازی `+98`/`0098`/ارقام فارسی به
`09xxxxxxxxx`؛ رد `0912 123 4567` با فاصله در ورودی خام و پذیرش پس از
نرمال‌سازی؛ الگوریتم کد ملی با بردارهای معتبر و نامعتبر؛ تبدیل کبیسهٔ شمسی؛
پیشوند `'` برای هر سلول CSV که با `=`, `+`, `-`, `@`, TAB, CR شروع شود؛
۱۵/۶۰/۳۶۰ دقیقه backoff؛ مرزهای پنجرهٔ ارسال (۰۸:۰۰ و ۲۲:۰۰)؛
بلاک `169.254.169.254`, `localhost`, `file://`, `http://` (غیر https).

## ۳) آزمون یکپارچه — ۱۱ گروه، ۱۳۴ assertion

| گروه | assertion | چه چیزی اجرا می‌شود |
|---|---|---|
| نصب و چیدمان پایگاه داده | ۱۲ | `Activator::activate()` واقعی، چهار جدول InnoDB، کلیدهای یکتا، زمان‌بندی cron |
| بارگذاری شرطی | ۸ | خانه/فید/ادمین/محصول موجود/محصول ناموجود/متغیر — صفر یا دو دارایی |
| ثبت درخواست | ۲۰ | مسیر کامل REST/AJAX با stub: nonce، هانی‌پات، rate limit، consent، کدهای خطا (با تأیید دومرحله‌ای روشن) |
| ثبت یک‌مرحله‌ای (پیش‌فرض تازه) | ۱۰ | ردیف مستقیماً `confirmed`، نبود پیام تأیید در صف، نبود `confirm_expires_at` |
| تأیید و لغو اشتراک | ۷ | توکن، انقضا، `navidban_confirm` / `navidban_unsubscribe` |
| انتخاب کانال فرم | ۱۲ | `sms_ready`/`email_ready`، سه حالت `sms_first`/`email_first`/`both`، پیامکِ بی‌کلید هرگز پیشنهاد نمی‌شود |
| موتور موجودی | ۱۲ | هوک‌های ووکامرس، گذار ناموجود→موجود، `claim_transition` ضد تکرار |
| پایش تطبیقی | ۹ | اجرای اول بدون اعلان، تشخیص نوشتن مستقیم در دیتابیس |
| صف ارسال | ۱۹ | claim اتمی، backoff، قفل، بازهٔ ارسال، skip، ارسال منصفانهٔ دودسته‌ای |
| کانال پیامک | ۱۲ | payload JSON درگاه عمومی با نقل‌قول داخل پیام، شماره در جای درست |
| خطای ارسال ایمیل | ۱۳ | انتقال دلیل واقعی `wp_mail_failed`، نبود نشت شنونده، ذخیرهٔ خطای بلند فارسی بدون شکستن UTF-8 |

«ارسال منصفانه» با آزمون واقعی سنجیده می‌شود: ۸ مشترک، موجودی برگشتی ۵،
دستهٔ ۵ نفره؛ سپس موجودی ۳ (۸ = ۵+۳) و دستهٔ ۳ نفره. بررسی می‌شود که
هیچ‌کس دو بار در صف نباشد، ترتیب `created_at` رعایت شود و دستهٔ دوم دقیقاً
بعد از اولی بیاید.

## ۳.۵) رندر پنل مدیریت — ۷۷ assertion

`tests/admin-render.php` هر پنج صفحه (داشبورد، لیست انتظارها، تنظیمات،
ابزارها، راهنما) را واقعاً رندر می‌کند و خروجی HTML را بازرسی می‌کند:

- ۶ فایل/صفحه: منوی اصلی + چهار زیرمنوی درست ثبت شده‌اند؛
- ۲۰ بررسی رندر: خروجی غیرخالی، نشانگر متن درست، پوشش `nvb-wrap`،
  نبود `<script>` تزریقی؛
- ۹ بررسی پوسته: نوار بالای صفحه، SVG درون‌خطی برند، دقیقاً یک زبانهٔ
  `aria-current`، تطابق زبانهٔ فعال با صفحهٔ جاری، پاورقی یک‌بار، نبود
  فونت بیرونی؛
- ۱ بررسی نسخه: شمارهٔ نسخه کامل و با ارقام فارسی در نوار بالا
  (این بررسی یک باگ واقعی را گرفت: `Persian::number('1.0.4')` نسخه را
  به «۱» تبدیل می‌کرد)؛
- ۱۸ بررسی قرارداد: فرم تنظیمات به `options.php` و `navidban_settings[…]`
  وصل است، هفت ابزار با `value="…"` در فرم حاضرند، ردیف نمونه دیده می‌شود،
  ایمیل **ماسک‌شده** نمایش داده می‌شود، مقدار خطرناک به `&lt;` تبدیل شده؛
- ۱۲ بررسی پوشش استایل: برای هر نوع ورودی که در خروجی واقعی صفحه‌ها دیده
  می‌شود، یک قاعدهٔ واقعی در CSS هست (نه فقط `:focus`)، و `select`/`textarea`
  هم پوشش دارند. این گروه باگ ۱.۰.۵ را می‌گیرد: `<input>` بدون `type`
  با `input[type="text"]` مطابقت نمی‌کند. صحت آزمون با حذف عمدی قاعده و
  دیدن شکست، تأیید شد؛
- ۳ بررسی سلامت: با `set_error_handler` هیچ notice/warning در رندر پنل
  تولید نشد (این آزمون یک `undefined array key` واقعی را هم گرفته است)؛
- ۲ بررسی مجوز: بدون `navidban_manage`، خروجی خالی است و `wp_die` صدا زده می‌شود.

## ۴) آزمون بار

`tests/load.php` روی SQLite (هارنس)، نه MySQL:

| سنجه | مقدار |
|---|---|
| مشترک ثبت‌شده | ۱۰٬۰۰۰ |
| زمان درج ۱۰٬۰۰۰ ردیف | ۸۲۳ ms |
| موجودی برگشتی | ۵ (۱۲ ms) |
| اعلان تولیدشده | **۵** (نه ۱۰٬۰۰۰) |
| پیام‌های صف‌شده برای رویداد دوم | ۱٬۰۰۰ (۷۱ ms) |
| دستهٔ اول پس از بازگشت موجودی | ۵ پیام (از سقف ۵۰ عبور نکرد) |
| ارسال در یک دسته از صف ۱۰۰۰تایی | ۵۰ |
| باقی‌ماندهٔ صف | ۹۵۰ (گم نشد) |
| مدت یک دسته | ۱۹ ms |
| جمع | ۹۸۷ ms |

**این اعداد بنچمارک MySQL نیستند.** SQLite در حافظه است. آنچه اثبات می‌شود
*رفتار* است: رعایت سقف دسته، رعایت «اعلام به تعداد موجودی»، و نبود گم‌شدن
پیام.

## ۵) ابزارهای ایستا

| ابزار | نتیجه |
|---|---|
| `tools/lint-php.js` (گرامر 7.4 + 8.3 روی ۵۰ فایل) | ۱۰۰ parse، ۰ خطا |
| `tools/check-methods.js` (۲۹۰ فراخوانی متد) | ۰ ارجاع شکسته |
| `tools/har-audit.sh` | CSS+JS فشرده ۴٬۳۹۳ بایت (بودجه ۱۲٬۲۸۸) |
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
