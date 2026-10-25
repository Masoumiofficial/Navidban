# ساختار داده

چهار جدول، همه InnoDB، همه با collation سرور (از `$wpdb->get_charset_collate()`)،
همه با پیشوند `{$wpdb->prefix}nvb_`.

> چرا InnoDB: قفل سطح‌ردیف (یک اجرای صف نباید فروشگاه را قفل کند)،
> تراکنش (درج ردیف انتظار + صف شدن پیام باید با هم موفق یا با هم ناموفق باشند)،
> و بازیابی پس از کرش.

## ۱) `nvb_waitlists` — اشتراک‌ها

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | کلید اصلی |
| `token` | CHAR(64) | توکن تأیید/لغو؛ یکتا |
| `product_id` | BIGINT UNSIGNED | محصول |
| `variation_id` | BIGINT UNSIGNED (0) | تنوع، یا ۰ برای محصول ساده |
| `event_type` | VARCHAR(32) | پیش‌فرض `restock` (باز برای رویدادهای آینده) |
| `channel` | VARCHAR(16) | `email` یا `sms` |
| `email` | VARCHAR(190) NOT NULL DEFAULT '' | خالی برای ردیف‌های فقط‌پیامک |
| `phone` | VARCHAR(20) NOT NULL DEFAULT '' | نرمال‌شده به `09xxxxxxxxx` |
| `user_id` | BIGINT UNSIGNED (0) | ۰ = مهمان |
| `status` | VARCHAR(16) | `pending` / `confirmed` / `unsubscribed` / `notified` / `expired` |
| `consent_at` | DATETIME NULL | زمان رضایت |
| `confirmed_at` | DATETIME NULL | زمان تأیید دومرحله‌ای |
| `notified_at` | DATETIME NULL | آخرین اعلان |
| `confirm_expires_at` | DATETIME NULL | انقضای توکن (پیش‌فرض +۴۸ ساعت) |
| `stock_at_signup` | INT | موجودی لحظهٔ ثبت (۰ = ناموجود) |
| `ip_hash` | CHAR(64) | SHA-256 نمکی IP — **IP خام هرگز ذخیره نمی‌شود** |
| `created_at` | DATETIME | مبنای ترتیب «قدیمی‌تر اول» |

کلیدها:

- `UNIQUE uniq_token (token)` — جلوگیری از تصادم توکن.
- `UNIQUE uniq_subscriber (product_id, variation_id, email, phone)` — یک نفر
  برای یک محصول/تنوع دو بار ثبت نمی‌شود. چون NULL در MySQL یکتا نیست،
  ستون‌های ایمیل/موبایل `NOT NULL DEFAULT ''` هستند.
- `KEY idx_status_product (status, product_id, variation_id)` — کوئری اصلی
  fair sending.
- `KEY idx_status_created (status, created_at)` — پیمایش «قدیمی‌تر اول».
- `KEY idx_phone`, `KEY idx_email` — جست‌وجوی ادمین.

## ۲) `nvb_stock_snapshots` — تصویر موجودی

| ستون | نوع | توضیح |
|---|---|---|
| `product_id` | BIGINT UNSIGNED | بخشی از PK |
| `variation_id` | BIGINT UNSIGNED (0) | بخشی از PK |
| `stock_status` | VARCHAR(16) | `instock` / `outofstock` / `onbackorder` |
| `stock_qty` | INT | آخرین تعداد دیده‌شده |
| `is_managed` | TINYINT(1) | آیا موجودی مدیریت می‌شود |
| `announced_qty` | INT | موجودی‌ای که برایش اعلان صادر شد |
| `announced_at` | DATETIME NULL | زمان آن اعلان |
| `checked_at` | DATETIME | آخرین بازبینی |

کلیدها: `PRIMARY KEY (product_id, variation_id)`، `KEY idx_status (stock_status, checked_at)`.

نقش `announced_qty`/`announced_at`: **ضد تکرار**. اگر ۵ واحد اعلام شد و بعد
ادمین ۳ واحد دیگر اضافه کرد (۸ = ۵+۳)، منطق می‌فهمد ۳ واحد جدید است و فقط
۳ اعلان تازه می‌فرستد؛ اگر دو هوک پشت‌سرهم برای همان تغییر شلیک شوند،
به‌روزرسانی اتمی اجازهٔ اعلان دوم را نمی‌دهد.

## ۳) `nvb_queue` — صف ارسال

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | کلید اصلی |
| `waitlist_id` | BIGINT UNSIGNED | ارجاع به اشتراک |
| `channel` | VARCHAR(16) | `email` / `sms` |
| `event_key` | VARCHAR(100) | `confirm` یا `restock:<ts>:<rand>` |
| `payload_hash` | CHAR(64) | هش محتوای پیام |
| `attempts` | TINYINT UNSIGNED | تعداد تلاش |
| `status` | VARCHAR(16) | `queued` / `processing` / `sent` / `failed` / `skipped` |
| `next_run_at` | DATETIME | زمان مجاز تلاش بعدی |
| `last_error` | VARCHAR(255) NULL | آخرین خطا (`unsubscribed`, `not_confirmed`, …) |
| `locked_at` | DATETIME NULL | **قفل نرم** برای جلوگیری از پردازش هم‌زمان |
| `sent_at` | DATETIME NULL | زمان ارسال موفق |
| `created_at` | DATETIME | — |

کلیدها:

- `UNIQUE uniq_payload (waitlist_id, payload_hash)` — همان شخص، همان پیام،
  فقط یک بار در صف.
- `KEY idx_run (status, next_run_at)` — انتخاب دسته.
- `KEY idx_waitlist (waitlist_id)` — پاک‌سازی آبشاری منطقی.
- `KEY idx_sent (status, sent_at)` — شمارش نرخ دقیقه.

## ۴) `nvb_logs` — لاگ امنیتی و شمارش نرخ

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | — |
| `level` | VARCHAR(16) | `info` / `warning` / `error` |
| `event` | VARCHAR(64) | `subscribe_rate_limited`, `queue_sent`, … |
| `context` | VARCHAR(255) | متن کوتاه، بدون دادهٔ شخصی |
| `ip_hash` | CHAR(64) | برای شمارش ۵/ساعت |
| `created_at` | DATETIME | — |

کلیدها: `KEY idx_event_created (event, created_at)`، `KEY idx_level (level)`.

لاگ **هرگز** ایمیل/موبایل خام ندارد؛ فقط هش IP و نام رویداد.

## ۵) نگهداشت و حذف

| داده | سیاست |
|---|---|
| ردیف‌های `notified`/`expired` قدیمی‌تر از `retention_days` (پیش‌فرض ۱۸۰) | حذف روزانه با cron |
| لاگ‌های قدیمی‌تر از ۳۰ روز | حذف روزانه |
| ردیف‌های صف `sent` قدیمی‌تر از ۳۰ روز | حذف روزانه |
| حذف افزونه | فقط اگر مدیر `delete_data_on_uninstall` را روشن کرده باشد |

## ۶) مهاجرت

`NAVIDBAN_DB_VERSION = '1'`. روی هر بارگذاری، `Database::maybe_upgrade()`
نسخهٔ ذخیره‌شده در آپشن `navidban_db_version` را با ثابت مقایسه می‌کند و
در صورت اختلاف `install()` را صدا می‌زند (idempotent).

`dbDelta` گاهی موتور جدول موجود را تغییر نمی‌دهد؛ `Database::force_innodb()`
پس از هر نصب، `SHOW TABLE STATUS` می‌گیرد و در صورت نیاز
`ALTER TABLE … ENGINE=InnoDB` اجرا می‌کند. اگر `SHOW` در دسترس نباشد
(بعضی هاست‌های محدود)، بی‌صدا رد می‌شود و افزونه کار می‌کند.

## ۷) آنچه اندازه‌گیری نشد

- کارایی این چهار جدول روی MySQL با ۱۰۰٬۰۰۰+ ردیف واقعی. `tests/load.php`
  ده‌هزار ردیف را روی SQLite می‌آزماید و صریحاً می‌گوید «بنچمارک MySQL نیست».
- رفتار `ALTER TABLE … ENGINE=InnoDB` روی جدولی که حجم زیادی داده دارد
  (می‌تواند کند باشد؛ روی فروشگاه‌های بزرگ یک بار اجرا می‌شود و تمام).
