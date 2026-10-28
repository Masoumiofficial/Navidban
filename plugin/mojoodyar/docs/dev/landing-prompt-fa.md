# پرامپت APEX PRO ∞ — صفحهٔ فرود (Landing Page) موجودیار

> پروندهٔ توسعه — در بستهٔ انتشار نمی‌آید.
> این پرونده سه بخش دارد: پرامپت ساخت (بخش ۲)، کارت متن آمادهٔ کپی (بخش ۴)،
> و چک‌لیست پذیرش (بخش ۱۰). بخش ۳ اعداد مجاز و ممنوع است و **قبل از هر
> ویرایشی** باید خوانده شود.

---

## ۰) هدف صفحه در یک جمله

بازدیدکننده‌ای که از اتحاد وردپرس یا جست‌وجوی گوگل می‌آید، در کمتر از ۹۰ ثانیه
بفهمد «این افزونه دقیقاً چه کاری می‌کند، چه چیزی **ندارد**، و چرا روی هاست
ارزان ایرانی هم کار می‌کند» — و یکی از سه کار را بکند:
**خرید** / **دیدن تصاویر پنل** / **خواندن سؤال‌های متداول**.

سه اقدام، نه بیشتر. هر CTA اضافه‌ای که به «تماس با ما» یا «وبلاگ» برود،
نرخ تبدیل را کم می‌کند.

---

## ۱) مخاطب و لحن

| مخاطب | چه می‌خواهد بداند |
|---|---|
| مدیر فروشگاه ووکامرس (غیرفنی) | «ساده است؟ خودم نصب می‌کنم؟» |
| توسعه‌دهنده/فریلنسر | «چی به هستهٔ من اضافه می‌کند؟ صفر بایت واقعی است؟» |
| صاحب فروشگاه با هاست اشتراکی | «PHP 7.4 من را نمی‌شکند؟ کندم نمی‌کند؟» |

لحن: **مهندسی، آرام، بی‌اغراق.** نه «انقلابی»، نه «بهترین افزونهٔ ایران»، نه
ایموجی در تیترها. یک بار «ما» و بارها «شما».

---

## ۲) پرامپت ساخت (این را کپی کنید)

```
APEX PRO ∞ — BUILD A PRODUCTION LANDING PAGE FOR A PERSIAN WOOCOMMERCE PLUGIN

You are a senior front-end engineer + conversion copywriter. Build a complete,
production-ready, single-page Persian (RTL) landing page for a WordPress plugin
called "موجودیار" (Mojoodyar) — a back-in-stock waitlist and restock-notification
plugin for WooCommerce, sold in the Iranian market.

=============================================================
NON-NEGOTIABLE RULES (read before writing any code)
=============================================================
1. RTL FIRST. <html lang="fa-IR" dir="rtl">. All layout uses logical properties
   (margin-inline-start, padding-inline-end, inset-inline...). No hard-coded
   left/right. Latin fragments (PHP, Composer, WooCommerce) must render inside
   <span dir="ltr" class="ltr">…</span> so they don't break the bidi flow.
2. NO BUILD STEP. One index.html + one assets/style.css + one assets/app.js.
   No React, no Tailwind CDN, no jQuery, no npm, no bundler, no icon font.
   Icons are inline SVG (24×24, stroke-width 1.75, currentColor).
3. NO EXTERNAL REQUESTS. No Google Fonts, no CDN, no analytics, no pixels.
   Font: Vazirmatn, self-hosted woff2 (weights 400/500/700/800), preloaded,
   font-display: swap. If the woff2 files are absent, fall back gracefully to
   system-ui and DO NOT add a Google Fonts link.
4. NO FAKE NUMBERS, NO FAKE SOCIAL PROOF. Use only the facts listed in FACTS
   below, verbatim. Never invent a percentage, a customer count, a sales count,
   a testimonial, a review, a rating, a star, a logo wall or "used by X stores".
   There is no testimonial section in this page — do not create one.
   Do not add aggregateRating or review schema.
5. HONESTY IS THE BRAND. Every claim on the page must be something the product
   demonstrably does. Where something is untested, say "داده در دسترس نیست"
   instead of a green tick.
6. The page must work with JavaScript disabled: all sections readable, FAQ
   usable (<details>/<summary>), pricing visible, links clickable.
7. This is Persian copy, not translated English. Keep every Persian string in
   COPY below byte-for-byte, including ZWNJ (نیم‌فاصله), «» quotes and Persian
   digits. Do not rewrite, shorten, or "improve" the copy. Do not transliterate.

=============================================================
FACTS (the only allowed quantitative claims)
=============================================================
- Off-target pages: 0 extra HTTP requests, 0 bytes, 0 plugin DB queries.
  (measured with tools/har-audit.sh — a HAR audit against a real WordPress)
- Out-of-stock product page: 2 assets (1 CSS + 1 JS), 1,984 bytes of form HTML.
- Front CSS+JS weight: 13,577 bytes raw → 4,495 bytes gzipped (budget 12,288).
- PHP: runs and is tested on 7.4.33 and 8.3.33. No Composer, no npm, no build,
  no ionCube, no license server, no call to our servers.
- Test suite: 292 unit + 188 integration + 105 admin-render assertions, green on
  both PHP 7.4.33 and 8.3.33.
- Restock of N units notifies at most N people — the oldest waiting first.
- Two-layer stock detection: WooCommerce hooks + reconcile cron every 15 min,
  so stock changed by Excel import / ERP / direct SQL is still detected.
- SMS gateways: Melipayamak (including pattern/bodyId lines), IPPanel,
  Kavenegar, plus one generic REST adapter. SMS goes straight from the shop to
  the gateway — we are in the middle of nothing.
- Doubly-opted-in is optional and OFF by default; SMS-first.
- Admin panel: full RTL Persian, Vazirmatn, Jalali dates, glass UI, CSV export
  with BOM (Excel-safe).
- Table storage: InnoDB only, prepared statements only, IP stored as salted
  SHA-256 hash.
- Every page has: dashboard, waitlists, settings, tools, help.

DO NOT use these numbers anywhere: any percentage, any "X thousand stores",
"27 gateways", "99% uptime", "5 minutes setup".

=============================================================
FACTS THAT ARE UNTESTED — how to present them
=============================================================
For a compatibility table use these exact cells:
- WordPress 6.x  → "تست‌شده"
- PHP 7.4.33 / 8.3.33 → "تست‌شده"
- PHP 8.4 → "داده در دسترس نیست"
- WooCommerce HPOS → "داده در دسترس نیست"
- Multilingual / WPML → "داده در دسترس نیست"
- WordPress 5.x → "داده در دسترس نیست"
Never render an untested cell as a green check.

=============================================================
PAGE STRUCTURE (in this order, 12 sections)
=============================================================
0. Skip link («رفتن به محتوای اصلی») + sticky header: brand, nav
   (چطور کار می‌کند / امکانات / عملکرد / قیمت / سؤال‌ها), CTA button «خرید افزونه».
1. HERO — headline, subhead, 2 CTAs («خرید افزونه» primary, «دیدن پنل» ghost
   anchor to #shots), 3 proof chips, and a large hero visual slot.
   Hero visual: a glass panel mockup built in pure HTML/CSS (not an image):
   a product page card with «ناموجود» badge + the «خبرم کن» form + a toast
   «دوباره موجود شد». It must be built from divs so it stays sharp on retina
   and costs ~0 bytes. Do not fake a dashboard screenshot with lorem text.
2. PAIN — three short lines about the real problem (visitor leaves, no address
   captured, restock happens silently). No numbers.
3. HOW IT WORKS — 4 numbered steps.
4. FEATURES — 6 cards with inline SVG icons.
5. PERFORMANCE PROOF — the 0/0/0 claim with a small measured table and a
   footnote «اندازه‌گیری با tools/har-audit.sh؛ خروجی در docs/PERFORMANCE.md».
6. COMPATIBILITY — the table from the previous block, with a visible legend
   separating «تست‌شده» from «داده در دسترس نیست».
7. PRICING — 3 tiers, middle tier visually featured. Prices in Persian digits
   with «تومان». One line under the table about support SLA.
8. WHAT MOJODYAR DOES NOT DO — ⭐ headline exactly:
   «چیزهایی که موجودیار ندارد»
   Six honest bullets. This section is a feature, not a disclaimer: style it as
   a first-class card row, not in small grey print.
9. FAQ — 8 items using <details>. Questions are listed below; answers must stay
   short (1–3 lines) and honest.
10. FINAL CTA — one sentence + button + the ZIP/support note.
11. FOOTER — license (GPLv2+), version, links, «حریم خصوصی» note that the
    plugin sends no telemetry, and a plain note that the plugin does not call
    our servers.

=============================================================
COPY (verbatim — do not edit)
=============================================================
BRAND: موجودیار
NAV: چطور کار می‌کند · امکانات · عملکرد · قیمت · سؤال‌ها
HEADER CTA: خرید افزونه

H1: مشتریِ ناموجود را برنمی‌گرداند — مگر کسی خبرش کند
HERO SUB: موجودیار فرم «خبرم کن» را فقط روی صفحهٔ همان محصول ناموجود می‌گذارد.
روی بقیهٔ سایت، صفر بایت. و وقتی کالا برگشت، به همان تعداد که موجود شد نفر
خبردار می‌شوند — قدیمی‌ترین منتظر، اول.
HERO CTA 1: خرید افزونه
HERO CTA 2: دیدن پنل
CHIP 1: ۰ بایت روی بقیهٔ سایت
CHIP 2: PHP 7.4 تا 8.3
CHIP 3: بدون Composer، بدون build
HERO FOOTNOTE: قیمت‌ها و ادعاهای این صفحه اندازه‌گیری‌شده‌اند؛ هر جا اندازه‌گیری
نشده باشد، همان‌جا نوشته‌ایم.

PAIN TITLE: کالا که تمام می‌شود، مشتری می‌رود
PAIN 1 TITLE: مشتری برنمی‌گردد
PAIN 1 BODY: او دنبال کالا بود، نه دنبال دکمهٔ «اطلاع از موجودی». صفحه را
می‌بندد و سراغ فروشگاه بعدی می‌رود.
PAIN 2 TITLE: هیچ آدرسی از او نمی‌ماند
PAIN 2 BODY: نه شماره‌ای، نه ایمیلی. در گزارش فروش، یک بازدید بی‌نام و بی‌مقصد.
PAIN 3 TITLE: موجودی هم بی‌صدا برمی‌گردد
PAIN 3 BODY: بار جدید می‌رسد، موجودی را عوض می‌کنید، و کسی که منتظرش بود
هیچ‌وقت خبردار نمی‌شود.

HOW TITLE: چطور کار می‌کند
STEP 1 TITLE: فرم، فقط جایی که لازم است
STEP 1 BODY: روی صفحهٔ محصول ناموجود — و برای هر تنوع، جداگانه. روی هیچ
صفحهٔ دیگری نه فایلی، نه اسکریپتی.
STEP 2 TITLE: مشتری، با نام خودش
STEP 2 BODY: نام و نام خانوادگی و شمارهٔ موبایل. همان نام در پیامک می‌آید،
نه «همراه گرامی».
STEP 3 TITLE: موجودی که برمی‌گردد، دیده می‌شود
STEP 3 BODY: هوک‌های ووکامرس، به‌علاوهٔ یک پایش تطبیقی هر ۱۵ دقیقه — پس
موجودی‌ای که با اکسل یا ERP عوض شده هم از دست نمی‌رود.
STEP 4 TITLE: به تعداد موجودی، نه به تعداد لیست
STEP 4 BODY: ۵ واحد برگشت؟ ۵ نفر اول خبر می‌شوند. بعداً ۳ واحد دیگر؟ ۳ نفر
بعدی. بقیه در صف می‌مانند تا نوبتشان برسد.

FEATURES TITLE: داخل افزونه
F1: ارسال با صف و محدودیت نرخ
F1 BODY: ارسال‌ها در پس‌زمینه و پله‌پله انجام می‌شوند؛ هیچ صفحه‌ای وسط کار
قفل نمی‌کند و درگاه پیامک هم بلاک نمی‌شود.
F2: پیامک ایرانی، مستقیم
F2 BODY: ملی‌پیامک (با خطوط الگویی و bodyId)، آی‌پی‌پنل، کاوه‌نگار و یک درگاه
عمومی REST. پیام از فروشگاه شما به درگاه می‌رود؛ سروری وسط نیست.
F3: تشخیص دولایهٔ موجودی
F3 BODY: هوک ووکامرس برای تغییرهای عادی، و پایش تطبیقی هر ۱۵ دقیقه برای
تغییرهایی که از راه اکسل، ERP یا SQL مستقیم آمده‌اند.
F4: ارسال منصفانه
F4 BODY: به تعداد واحدهای برگشته اعلان می‌رود، از قدیمی‌ترین منتظر شروع
می‌شود. لیست هزارنفره با ۲ واحد موجود، هزار پیامک نمی‌فرستد.
F5: پنل فارسی، راست‌به‌چپ، با تاریخ شمسی
F5 BODY: داشبورد، لیست انتظارها با فیلتر و تیک «تماس گرفته شد»، تنظیمات،
ابزارها و راهنما. خروجی CSV با BOM، مستقیم بازشدنی در اکسل.
F6: امنیت و نگهداری داده
F6 BODY: جدول‌های InnoDB، کوئری‌های آماده‌شده، آی‌پی فقط به‌صورت هش SHA-256
نمک‌دار. حذف داده‌ها هنگام حذف افزونه، به‌صورت پیش‌فرض خاموش است.

PERF TITLE: روی بقیهٔ سایت، هیچ
PERF LEAD: این ادعا شعار نیست؛ خروجی یک بازرسی HAR روی وردپرس واقعی است.
PERF ROW 1: صفحهٔ خانه و محصول موجود → ۰ درخواست · ۰ بایت · ۰ کوئری
PERF ROW 2: صفحهٔ محصول ناموجود → ۱ CSS + ۱ JS = ۱۳٬۵۷۷ بایت خام
PERF ROW 3: همان دارایی‌ها با gzip → ۴٬۴۹۵ بایت (بودجه: ۱۲٬۲۸۸)
PERF NOTE: اندازه‌گیری با tools/har-audit.sh؛ عددها در docs/PERFORMANCE.md
نگه‌داری می‌شوند. زمان پاسخ روی هاست شما اندازه‌گیری نشده است.

COMPAT TITLE: سازگاری
COMPAT LEAD: فقط چیزهایی که واقعاً تست شده‌اند سبز هستند. بقیه را نمی‌دانیم و
همین را می‌نویسیم.

PRICING TITLE: قیمت
TIER 1: معرفی — ۴۴۹٬۰۰۰ تومان — افزونه · به‌روزرسانی ۶ ماهه · پشتیبانی ۳ ماهه
TIER 2 (featured): استاندارد — ۹۹۸٬۰۰۰ تومان — افزونه · به‌روزرسانی ۱۲ ماهه ·
پشتیبانی ۱۲ ماهه
TIER 3: با نصب و راه‌اندازی — ۱٬۱۹۸٬۰۰۰ تومان — بستهٔ استاندارد + نصب و تنظیم
درگاه پیامک روی سایت شما
PRICING NOTE: پاسخ اولیهٔ پشتیبانی حداکثر ۲۴ ساعت کاری، هدف ۴ ساعت.
LICENSE NOTE: لایسنس GPLv2 یا بالاتر. بدون سرور لایسنس، بدون قفل.

NOTS TITLE: چیزهایی که موجودیار ندارد
N1: اعلان تغییر وضعیت سفارش
N2: پیام واتساپ
N3: نوبت‌دهی و لیست انتظار خدمات (آرایشگاه، کلینیک)
N4: باشگاه مشتریان و امتیاز
N5: بیش از سه درگاه پیامک اختصاصی
NOTS BODY: اگر یکی از این‌ها را می‌خواهید، این افزونه ابزار شما نیست و ما
هم نمی‌خواهیم بفروشیمش. اگر فقط می‌خواهید مشتریِ منتظر را خبر کنید، کار
همین است.

FAQ (8 questions — write honest 1–3 line answers, no promises about dates):
Q1: روی هاست اشتراکی با PHP 7.4 کار می‌کند؟
Q2: بدون Composer و بدون build چطور نصب می‌شود؟
Q3: اگر موجودی را از اکسل عوض کنم، متوجه می‌شود؟
Q4: با ۵ واحد موجود، به چند نفر پیامک می‌رود؟
Q5: پیامک از سرور شما رد می‌شود؟
Q6: خط من فقط الگو قبول می‌کند؛ کار می‌کند؟
Q7: داده‌های مشتری‌ها کجا ذخیره می‌شود؟
Q8: اگر مشتری بعداً نخواهد پیام بگیرد؟

CTA TITLE: مشتری منتظر را رها نکنید
CTA BODY: نصب، تنظیم درگاه پیامک، و اولین فرم «خبرم کن» — همه از پنل خود
افزونه، بدون یک خط کد.
CTA BUTTON: خرید افزونه

FOOTER LEFT: موجودیار · نسخهٔ ۲.۰.۰ · لایسنس GPLv2+
FOOTER NOTE: این افزونه هیچ داده‌ای به سرور ما نمی‌فرستد و به هیچ سرور
بیرونی‌ای جز درگاه پیامکی که خودتان تنظیم می‌کنید وصل نمی‌شود.

=============================================================
DESIGN SYSTEM
=============================================================
Tokens (mirror the plugin's own CSS variables so the page and the product look
like one family):
  --mjy-primary: #0E5C55;   --mjy-primary-hover: #14857B;
  --mjy-accent: #F2A93B;    --mjy-bg: #EEF4F2;
  --mjy-ink: #14241F;       --mjy-ink-soft: #5B6B66;
  --mjy-ok: #1F8F60;        --mjy-error: #C6483C;
  --mjy-glass: rgba(255,255,255,.66);  --mjy-radius: 20px;
  --mjy-shadow: 0 18px 44px -20px rgba(14,92,85,.45);
Type scale (rem): 0.875 / 1 / 1.125 / 1.375 / 1.75 / 2.25 / 3 / 3.75.
Headings: Vazirmatn 800, letter-spacing: 0, line-height 1.35 (Persian needs
more than Latin — never below 1.3). Body: 500, line-height 1.75.
Glassmorphism recipe: background var(--mjy-glass); backdrop-filter: blur(18px)
saturate(140%); 1px inner top highlight (inset 0 1px 0 rgba(255,255,255,.7));
border 1px solid rgba(255,255,255,.55). ALWAYS add @supports not
(backdrop-filter: blur(1px)) fallback to rgba(255,255,255,.92) + solid border,
and never rely on blur for contrast.
Section rhythm: 96px vertical on desktop, 56px on mobile. Max content width
1180px. 12-column grid, but no CSS framework.
Radius: 20px cards, 14px inputs, 999px chips/pills.
Hover/focus: transform translateY(-2px) + shadow, 160ms ease-out. Nothing
bounces, nothing spins.
MUCH of the "wow" comes from: big generous whitespace, one accent color used
sparingly, a hero mockup built with real CSS, and numbers set in tabular figures
(font-variant-numeric: tabular-nums) so the proof table looks engineered.

=============================================================
MOBILE / RESPONSIVE
=============================================================
Breakpoints: 360, 480, 768, 1024, 1280.
At ≤768: single column; hero mockup goes BELOW the headline and is capped at
min(100%, 420px); nav collapses into a <details> menu (no JS dependency);
sticky bottom bar with «خرید افزونه» appears after the hero (dismissible, and
must not cover the footer).
No horizontal scroll at 320px. Test with long Persian words like
«راه‌اندازی» and «برنمی‌گرداند».
Tap targets ≥ 44×44 px. Body text ≥ 16px on mobile.

=============================================================
ACCESSIBILITY (WCAG 2.2 AA — must pass)
=============================================================
- One <h1>, then h2 per section, no skipped levels.
- Contrast ≥ 4.5:1 for text, ≥ 3:1 for large text and UI borders. Amber
  #F2A93B is NEVER used for small text on light background (fails); use it as a
  fill with dark ink (#14241F) on top, or as a border/accent only.
- :focus-visible ring: 3px solid #0E5C55, offset 2px. Never remove outlines.
- All SVG icons aria-hidden="true"; icon-only buttons need aria-label.
- <details>/<summary> for FAQ; style summary as a button but keep native
  keyboard behaviour. Do not intercept Space/Enter with JS.
- Decorative mockups: aria-hidden="true" on the whole hero visual.
- prefers-reduced-motion: reduce → remove transforms and transitions.
- Form: real <label for>, autocomplete="name"/"tel"/"email", inputmode="tel",
  type="tel", and errors announced via aria-live="polite".
- Page must be fully usable at 200% zoom.

=============================================================
SEO / META
=============================================================
<title>موجودیار — افزونهٔ لیست انتظار و اعلان بازگشت موجودی برای ووکامرس</title>
(≤ 60 Persian characters if possible; if not, drop the brand suffix.)
<meta name="description"> exactly:
«فرم «خبرم کن» فقط روی صفحهٔ محصول ناموجود؛ روی بقیهٔ سایت صفر بایت. پیامک
ایرانی مستقیم، PHP 7.4 تا 8.3، بدون Composer و بدون سرور واسط.»
Open Graph + Twitter card with og:image 1200×630 (placeholder path
/assets/og.png, with width/height set), og:locale fa_IR, og:type product.
Canonical link. <html lang="fa-IR">.
JSON-LD: one Product node (name, description, brand, offers with the three
prices in IRR, availability InStock) + one FAQPage node whose questions and
answers MUST match the visible FAQ text exactly. Absolutely no aggregateRating,
no review, no fake author.
Images: width+height attributes on every <img>, loading="lazy" for below-fold,
fetchpriority="high" on the hero visual IF it is an image (it is CSS here).

=============================================================
PERFORMANCE TARGETS (targets, not measured claims)
=============================================================
- Total page weight ≤ 350 KB including fonts and images.
- LCP ≤ 2.0 s on Slow 4G (target), CLS < 0.05, INP < 200 ms.
- CSS ≤ 22 KB gzipped, JS ≤ 3 KB gzipped. No JS framework. No polyfill.
- Only primitive JS: mobile nav toggle if not using <details>, sticky CTA
  visibility, and the optional order form. Everything else is CSS.
- Reserve space for the hero mockup so nothing shifts (aspect-ratio).

=============================================================
ORDER FORM (section 10) — honest behaviour
=============================================================
Fields: نام و نام خانوادگی، شمارهٔ موبایل، ایمیل (اختیاری)، انتخاب بسته.
Validation: Persian digits accepted and normalised; Iranian mobile pattern
09xxxxxxxxx; name must be letters and spaces only (no digits, no symbols).
On submit: POST FormData to a single endpoint constant at the top of app.js
(e.g. const ORDER_ENDPOINT = "";). If ORDER_ENDPOINT is empty, DO NOT fake a
success message — show: «این فرم هنوز به سرور وصل نشده است. برای ثبت سفارش
از دکمهٔ خرید بالا استفاده کنید.» and keep the entered values.
On success: replace the form with a confirmation card, focus it, aria-live.
Never store anything in localStorage. Never send data to a third party.

=============================================================
DELIVERABLE
=============================================================
- index.html (semantic, commented in English, no inline styles except CSS vars)
- assets/style.css (organised: tokens → reset → layout → components → utilities
  → media queries; comments in English)
- assets/app.js (ES5-compatible, IIFE, 'use strict', ~60 lines max)
- assets/og.png placeholder reference (do not generate binary)
- README.md with: how to change prices, how to wire ORDER_ENDPOINT, how to swap
  the font, and a note that every number on the page comes from
  docs/PERFORMANCE.md.

Write the full files. No placeholders like "TODO", no lorem ipsum, no
"…rest of the page here". If a section is long, write it out.
```

---

## ۳) اعداد مجاز و ممنوع (برای هر ویرایش بعدی)

| مجاز (اندازه‌گیری‌شده) | منبع |
|---|---|
| ۰ درخواست / ۰ بایت / ۰ کوئری روی صفحات غیرهدف | `docs/PERFORMANCE.md` §۳ |
| ۱۳٬۵۷۷ بایت خام → ۴٬۴۹۵ بایت gzip (بودجه ۱۲٬۲۸۸) | `docs/PERFORMANCE.md` §۲ |
| ۱٬۹۸۴ بایت HTML فرم روی محصول ساده، ۲٬۲۳۶ روی متغیر | `docs/PERFORMANCE.md` §۳ |
| PHP 7.4.33 و 8.3.33 | `docs/TEST-REPORT.md` |
| ۲۹۲ + ۱۸۸ + ۱۰۵ بررسی | `tests/*-results*.json` |
| ۵ واحد → حداکثر ۵ نفر | `docs/SCHEDULER-POLICY.md` |
| ۴ درگاه پیامک (۳ اختصاصی + REST) | `includes/Channels/` |
| قیمت‌ها ۴۴۹ / ۹۹۸ / ۱٬۱۹۸ هزار تومان | `docs/dev/listing-fa.md` |
| SLA پاسخ اولیه ۲۴ ساعت کاری (هدف ۴) | تعهد پشتیبانی |

| ممنوع | چرا |
|---|---|
| هر درصدی («۹۸٪ تحویل»، «۹۲٪ رضایت») | اندازه‌گیری نشده |
| «+۱۰٬۰۰۰ فروشگاه»، «پرفروش‌ترین» | ادعای بی‌سند |
| «۲۷ درگاه پیامک» | عدد رقیب است، نه عدد ما |
| نظر مشتری، ستاره، لوگوی مشتریان | تا نظر واقعی نداریم، ساخته نمی‌شود |
| «به‌زودی»، «نسخهٔ ۲ در راه است» | تعهد بدون تاریخ |
| ✓ سبز روی HPOS / WPML / PHP 8.4 | تست نشده |

---

## ۴) نکته‌های متن که معمولاً خراب می‌شوند

- **«موجودیار»** نه «نوید بان»، نه «نویدبن».
- **«خبرم کن»** با گیومهٔ فارسی «», نه "خبرم کن".
- **نیم‌فاصله** در «می‌گذارد»، «برنمی‌گرداند»، «می‌شود»، «هیچ‌وقت». اگر در ادیتور
  ZWNJ گم شود، متن غلط املایی می‌شود.
- **اعداد فارسی** در متن بازاریابی، **اعداد لاتین** در جدول فنی کنار `PHP 7.4`
  (به‌خاطر خوانایی نسخه).
- **`dir="ltr"`** روی «PHP 7.4.33»، «GPLv2+»، «WooCommerce»، «InnoDB»، «CSV».
- تیتر با «ـ» و «؛» تمام نشود؛ جملهٔ فارسی با فعل تمام شود.

---

## ۵) ساختار پیشنهادی فایل‌ها

```
landing/
├── index.html
├── assets/
│   ├── style.css
│   ├── app.js
│   ├── og.png
│   └── fonts/
│       ├── Vazirmatn-Regular.woff2
│       ├── Vazirmatn-Medium.woff2
│       ├── Vazirmatn-Bold.woff2
│       └── Vazirmatn-ExtraBold.woff2
└── README.md
```

فونت‌ها از مخزن رسمی Vazirmatn گرفته می‌شوند (SIL OFL). اگر فقط یک فایل
می‌توانید بگذارید، **Variable font** بگیرید: `Vazirmatn[wght].woff2` — یک
فایل، همهٔ وزن‌ها.

---

## ۶) هدر صفحه: چه چیزی در تصویر و چه چیزی در HTML

| در HTML (برای گوگل و موبایل) | در تصویر/ماکاپ (برای حس) |
|---|---|
| H1، زیرتیتر، CTA، قیمت‌ها، جدول‌ها، FAQ | ماکاپ پنل، فرم واقعی روی کارت محصول |
| همهٔ ادعاهای عددی | — |

ماکاپ قهرمان (hero mockup) را **با CSS** بسازید، نه تصویر: هم روی رتینا تیز
است، هم ۰ بایت اضافه، هم متنش برای گوگل خواناست. این تصمیم را در README
توضیح بدهید.

---

## ۷) بنر ۱۸۸۳×۸۳۵ کجای این صفحه می‌نشیند

بنر اتحاد وردپرس برای صفحهٔ **فروشگاه** است، نه این لندینگ. در این لندینگ
همان بنر را با نسبت ۲.۲۵ نگذارید؛ نسخهٔ افقی ۱۲۰۰×۶۳۰ برای `og:image` کافی
است (متنش را بزرگ‌تر و کم‌تر کنید).

---

## ۸) دو نسخهٔ تیتر برای تست A/B

1. `مشتریِ ناموجود را برنمی‌گرداند — مگر کسی خبرش کند` (پیشنهاد اول، درد)
2. `۵ واحد برگشت؟ فقط ۵ نفر خبردار می‌شوند` (مکانیزم، برای مخاطب فنی)

تست را روی ۳۰۰ بازدید یا ۲ هفته اجرا کنید و **هر بار فقط یک متغیر** عوض شود.
نتیجه را در همین پرونده ثبت کنید، نه در حافظه.

---

## ۹) نصب روی وردپرس (اگر صفحه داخل خود سایت می‌آید)

- صفحه را با یک قالب خالی (Blank/Canvas) بسازید تا هدر و فوتر قالب رویش
  سوار نشود و CSS تعارض پیدا نکند.
- پیشوند کلاس‌های لندینگ را `lp-` بگذارید تا با `.mjy-` افزونهٔ خودتان
  تصادم نکند.
- فونت را از قبل `preload` کنید؛ اگر قالب هم Vazirmatn را لود می‌کند،
  نسخهٔ لندینگ را حذف کنید و از همان استفاده کنید (دو بار لود = دو برابر بایت).
- در ووکامرس هیچ فیلتر یا هوکی از این صفحه به صفحهٔ محصول نشت نکند.

---

## ۱۰) چک‌لیست پذیرش

- [ ] در ۳۲۰px عرض هیچ اسکرول افقی نیست
- [ ] `dir="rtl"` روی `html`؛ هیچ `left/right` سخت‌کدشده در CSS نیست
- [ ] بدون جاوااسکریپت: همهٔ بخش‌ها، FAQ و قیمت‌ها کار می‌کنند
- [ ] هیچ درخواست خارجی در تب Network نیست (فونت، آیکون، آنالیتیکس)
- [ ] CSS ≤ ۲۲KB gzip، JS ≤ ۳KB gzip
- [ ] CLS صفر: ماکاپ قهرمان `aspect-ratio` دارد و تصویرها `width/height`
- [ ] کنتراست: `#F2A93B` هیچ‌جا متن ریز روی پس‌زمینهٔ روشن نیست
- [ ] `:focus-visible` روی همهٔ لینک‌ها و دکمه‌ها دیده می‌شود
- [ ] همهٔ عددهای صفحه در جدول بخش ۳ هست؛ یک عدد بیرون از آن وجود ندارد
- [ ] بخش «چیزهایی که موجودیار ندارد» با شش مورد و تیتر دقیق موجود است
- [ ] هیچ نظر، ستاره، لوگوی مشتری یا درصدی در صفحه نیست
- [ ] JSON-LD FAQPage **دقیقاً** با متن دیده‌شدهٔ FAQ یکی است
- [ ] `alt` همهٔ تصویرها توصیفی و فارسی است (نه `image1.png`)
- [ ] تیتر و متن‌ها با نیم‌فاصلهٔ درست، بازخوانی‌شده توسط یک فارسی‌زبان
- [ ] فرم سفارش، بدون endpoint، پیام «وصل نشده» می‌دهد و موفقیت جعلی نشان نمی‌دهد
