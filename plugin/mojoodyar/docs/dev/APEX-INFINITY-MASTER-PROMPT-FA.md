# APEX ∞ — پرامت مادر لندینگ و دمو موجودیار

> پروندهٔ توسعه — در بستهٔ انتشار نمی‌آید.
>
> این یک پرامپت نیست؛ **مادرِ پرامپت‌ها**ست: چهار فراخوان (Call) که با هم یک
> لندینگ + یک دموی تعاملی می‌سازند، و بعد خودشان را بازرسی می‌کنند.
>
> چرا چهار فراخوان و نه یکی: هر پرامپت غول‌آسا در نیمهٔ راه بریده می‌شود و
> مدل، بخش آخر (که معمولاً همان چک‌های کیفیت است) را حذف می‌کند. تقسیم کردن
> یعنی هیچ‌وقت چیزی «نصفه» نمی‌ماند.

---

## ۰) نحوهٔ استفاده — چهار فراخوان

| فراخوان | چه چیزی می‌دهد | کدام بخش را کپی کنید |
|---|---|---|
| **CALL 1 — SPEC** | شناسنامهٔ صفحه، انتخاب مسیر طراحی، ساختار بخش‌ها | بخش ۱ |
| **CALL 2 — BUILD LANDING** | فایل‌های لندینگ، کامل و اجراشدنی | بخش ۲ |
| **CALL 3 — BUILD DEMO** | دموی تعاملی شبیه پنل، بدون بک‌اند | بخش ۳ |
| **CALL 4 — AUDIT & REPAIR** | بازرسی خروجی با دروازه‌های قابل سنجش + اصلاح | بخش ۴ |

**قاعدهٔ طلایی:** قبل از CALL 2، خروجی CALL 1 را بخوانید. اگر مدل در CALL 1
مسیر طراحی («A/B/C») را انتخاب کرد و شما موافق بودید، در CALL 2 همان را
قفل کنید. اگر هر دو را در یک پیام بدهید، مدل هر دو را نیمه انجام می‌دهد.

**قبل از هر استفاده، بخش ۵ را بخوانید:** فهرست اعدادی که باید به‌روز باشند.

---

# بخش ۱ — CALL 1: SPEC

```
APEX ∞ / CALL 1 — SPECIFICATION, NOT CODE

You are a product designer and conversion strategist for the Iranian WordPress
market. Do NOT write HTML/CSS/JS in this call. Produce a specification only.

PRODUCT: «موجودیار» (Mojoodyar) — a back-in-stock waitlist plugin for WooCommerce.
Audience: Iranian shop owners (mostly non-technical, shared hosting, PHP 7.4) and
freelancers who install for clients. The product is honest, engineering-led, and
deliberately quiet in tone. It has no social proof, no ratings, no user counts.

Read the FACT LEDGER and INVARIANTS below. They are hard constraints.

TASK — produce, in this order:

1. ONE-SENTENCE JOB.
   What must a visitor be able to do within 90 seconds of landing? One sentence.

2. AUDIENCE SPLIT.
   Three visitor types. For each: what they fear, what single proof resolves that
   fear, and which section delivers it. No generic personas.

3. THREE DESIGN ROUTES (A, B, C).
   Each route must differ in *structure*, not in colour:
     A. "Engineering document" — typographic, dense, evidence-first, almost no imagery.
     B. "Product tour" — one interactive mock in the hero, sections act as steps.
     C. "Editorial" — long-form, one column, generous whitespace, numbers as pull-quotes.
   For each: layout logic, what the hero contains, what the page refuses to do,
   and one risk. Then pick one and justify in 3 lines. The pick is binding for
   CALL 2.

4. SECTION MAP.
   13 rows: number, section name, one-line purpose, primary element, what would
   make it a lie (the failure mode). The last column matters most: it tells the
   builder what NOT to do in that section.

5. CONVERSION PATH.
   Three actions only (buy / see the panel / read the FAQ). Where each appears,
   how many times total, and what is deliberately NOT a link (nothing to blog,
   social, "contact us" in the header).

6. DEMO DECISION.
   Should the demo be (a) a static annotated screenshot set, (b) an interactive
   HTML replica of the form + admin table, (c) a short scripted walkthrough with
   step buttons? Recommend one, and state the maintenance cost of each.

7. WHAT WE WILL NOT BUILD.
   Five things a competitor's landing page has that this page must not have
   (e.g. testimonial carousel, logo wall, countdown timer, chatbot, cookie
   banner for analytics). One line of reasoning each.

Output as Markdown. No code. No visual mockups. Maximum 900 words.
```

---

# بخش ۲ — CALL 2: BUILD LANDING

```
APEX ∞ / CALL 2 — BUILD THE LANDING PAGE

You are a senior front-end engineer with a typographic eye. Build a complete,
production-ready, single-page Persian (RTL) landing page for «موجودیار», a
WooCommerce back-in-stock plugin sold in Iran.

Follow the route chosen in CALL 1. Everything below is a hard constraint.

=====================================================================
A. INVARIANTS (violating any one = the output is rejected)
=====================================================================
A1. RTL FIRST. <html lang="fa-IR" dir="rtl">. Use logical properties only
    (margin-inline-start, padding-inline-end, inset-inline-start). Zero
    hard-coded left/right. Latin fragments (PHP 7.4, WooCommerce, CSV, bodyId,
    REST) must sit in <span dir="ltr" class="ltr">…</span>.
A2. NO BUILD STEP. Exactly three files: index.html, assets/style.css,
    assets/app.js. No React/Vue/Tailwind/jQuery/npm/bundler/icon font. Icons are
    inline SVG, 24×24, stroke-width 1.75, currentColor, aria-hidden="true".
A3. NO EXTERNAL REQUESTS. No Google Fonts, no CDN, no analytics, no pixel, no
    cookie banner. Vazirmatn is self-hosted (woff2, 400/500/700), preloaded with
    crossorigin, font-display: swap. If the font files are missing, fall back to
    system-ui and DO NOT add a Google Fonts link.
A4. NO INVENTED NUMBERS. Every number on the page comes from the FACT LEDGER
    verbatim. No percentages, no store counts, no "X thousand users", no
    "5 minutes setup", no star ratings, no testimonials, no logo wall, no
    aggregateRating / review schema. There is no testimonial section.
A5. UNTESTED = SAID SO. Where the ledger says untested, render
    «داده در دسترس نیست» in the cell. Never a green tick.
A6. PERSIAN IS THE SOURCE LANGUAGE, NOT A TRANSLATION. Copy is provided
    verbatim in section I. Keep every character — ZWNJ (نیم‌فاصله), «»
    quotes, Persian digits (۰۱۲۳۴۵۶۷۸۹). Do not rewrite, shorten, or
    "improve". Do not transliterate. Do not add emoji to headings.
A7. WORKS WITHOUT JS. All text readable, FAQ uses <details>/<summary>, all
    links work, pricing visible, no content hidden behind JS-only reveal.
    With JS on, enhancement only (in-view animation, demo interaction).
A8. NO FAKE UI. Any mock of the plugin inside the page is built from real
    HTML/CSS with plausible Persian data (product name, quantity 5, price in
    Toman) — never a stock screenshot, never lorem ipsum, never an English UI.
A9. ONE ACCESSIBILITY BASELINE. Contrast ≥ 4.5:1 for body text on its actual
    background (check against the solid fallback, not the blur). Visible
    :focus-visible ring, 2px, offset 2px. Touch targets ≥ 44×44. No keyboard
    trap. prefers-reduced-motion respected.
A10. BUDGET. Whole page ≤ 120 KB gzipped excluding fonts (HTML+CSS+JS).
    JS ≤ 8 KB gzipped. LCP < 2.0 s on a throttled 4G mobile profile with the
    page served locally. CLS < 0.02. No layout shift from font swap.

=====================================================================
B. FACT LEDGER — the only allowed quantitative claims
=====================================================================
Measured (may be stated as numbers):
  • Off-target pages (home, archive, category, search, cart, checkout):
    0 plugin assets, 0 plugin DB queries. Measured by tests/queries.php.
  • Out-of-stock product page: 2 assets (1 CSS + 1 JS); form HTML 1,984 bytes
    (2,236 bytes on a variable product).
  • Front CSS+JS: 13,577 bytes raw → 4,495 bytes gzipped (budget 12,288).
  • Test suite: 411 unit + 444 integration + 173 admin-render assertions,
    0 failures, on PHP 7.4.33 and PHP 8.3.33.
  • Release gate: 28 automated checks.
  • Release archive: 81 files, 301,599 bytes.
  • Runs on PHP 7.4–8.3 (tested on 7.4.33 and 8.3.33), WordPress 6.4+,
    WooCommerce 9.0+, declared tested up to WC 10.4.
  • Four SMS adapters: Melipayamak (plain + pattern/bodyId), Kavenegar,
    IPPanel, one generic REST adapter. Messages go from the shop to the
    customer's own gateway; there is no server in the middle.
  • Restock of N units notifies at most N waiting customers, oldest first.
  • Stock detection is two-layer: WooCommerce hooks + a reconcile pass every
    15 minutes. First run after activation only snapshots, it never sends.
  • Queue runs every 5 minutes; retry backoff 15 min / 60 min / 6 h;
    max attempts is configurable 1–5 (default 3); rate cap per minute.
  • SMS link shapes, measured on a real shop: full product URL 53 chars,
    short product URL 28, short domain 22, site root 20.
  • Double opt-in exists and is OFF by default. SMS is the primary channel.
  • Admin: full RTL Persian, Vazirmatn bundled, Jalali dates, CSV export with
    BOM (opens correctly in Persian Excel).
  • Data: InnoDB only, prepared statements only, IP stored as salted SHA-256.
  • No Composer, no npm, no build, no IonCube, no license server, no
    telemetry, no call to the vendor's servers.

Untested — render exactly «داده در دسترس نیست»:
  • PHP 8.4 · WordPress 5.x · WPML/multilingual · HPOS on a real installation
    (compatibility is *declared* in code, not verified by a live test) ·
    MySQL/MariaDB query timing · TTFB delta under 5 ms.
  • Never present the harness load numbers (10,000 subscribers, ms timings) as
    customer-facing performance. If you need them, label the section
    «در محیط آزمون» and give the harness name.

FORBIDDEN STRINGS (any presence = reject):
  «بهترین», «انقلابی», «بین‌ظیر», «۱۰۰٪», «تضمینی», «معجزه», «هوش مصنوعی»,
  «خبرم کنید», «دیجی‌کالا», «YITH», any star/rating widget, any counter that
  ticks up to a number we did not measure.

=====================================================================
C. BRAND SYSTEM (use these tokens; do not invent a new palette)
=====================================================================
:root {
  --mjy-primary: #0E5C55;      /* deep teal — the product colour */
  --mjy-primary-ink: #0A423D;  /* pressed / hover-dark */
  --mjy-accent: #F2A93B;       /* amber — used for ONE thing only: the
                                  "restocked / success" moment and the
                                  primary CTA focus ring. Never as a large
                                  background. */
  --mjy-bg: #EEF4F2;           /* page background, very cool off-white */
  --mjy-surface: #FFFFFF;
  --mjy-ink: #0B1F1C;          /* headings */
  --mjy-muted: #55706B;        /* body secondary */
  --mjy-line: rgba(14,92,85,.14);
  --mjy-danger: #B3261E;       /* only for "blocked / needs fixing" */
  --radius-lg: 20px;
  --radius-md: 14px;
  --radius-sm: 10px;
}
Type: Vazirmatn. Scale (desktop): h1 clamp(30px, 4.6vw, 54px)/1.25 · h2
clamp(24px, 2.8vw, 34px)/1.35 · h3 19px/1.6 · body 16px/1.95 · small 14px/1.8.
Body line-length 62–68 characters. NEVER letter-space Persian text. Never
justify Persian. Persian digits everywhere in copy, Latin digits only inside
code/version strings.
Glass: background: rgba(255,255,255,.72); backdrop-filter: blur(14px)
saturate(140%); border: 1px solid var(--mjy-line); box-shadow:
0 1px 2px rgba(11,31,28,.04), 0 12px 32px -12px rgba(11,31,28,.14).
ALWAYS pair every glass surface with a solid background-color fallback and
@supports not (backdrop-filter: blur(1px)) — the panel must stay readable.
Motion: 160–220 ms, ease-out; translateY ≤ 6px; no parallax, no bouncing,
no gradient shimmer.

=====================================================================
D. ANTI-GENERIC PROTOCOL (this is the difference between a page and a template)
=====================================================================
D1. ASYMMETRY BY DEFAULT. No section may be a symmetric 3-column grid of equal
    cards. Use 2+1, 5/7 splits, an offset first column, or a two-row stagger.
    If you catch yourself writing three identical .card divs, change the layout.
D2. SPECIFICITY OVER SUPERLATIVES. Replace every adjective with a number, a
    filename, or a mechanism. "سبک است" → «۴٬۴۹۵ بایت فشرده». "هوشمند است" →
    «پایش تطبیقی هر ۱۵ دقیقه». If you cannot be specific, delete the sentence.
D3. REAL DATA IN EVERY MOCK. The mock product is «کوله‌پشتی سفری ۳۰ لیتری»،
    price «۱٬۲۸۰٬۰۰۰ تومان»، stock 0 → 5, customer «سارا محمدی»، phone
    ending ۰۹۱۲…۴۷. Use these consistently across hero, demo, and screenshots.
D4. ONE HONEST NEGATIVE IS WORTH TEN POSITIVES. The section
    «چیزهایی که موجودیار ندارد» is a first-class section: normal type size,
    real card styling, placed before the FAQ. Never small grey print.
D5. NO DECORATION THAT MEANS NOTHING. No abstract blobs, no floating 3D
    shapes, no purple gradients, no "AI brain" imagery, no stock photo of a
    person at a laptop. Illustration budget: inline SVG line-art only, and
    only where it explains a mechanism (queue, layers, notice count).
D6. RHYTHM. Alternate section density: dense evidence → breathing statement →
    dense evidence. Section padding 72px/96px, never uniform everywhere.
D7. THE MECHANISM DIAGRAM. One section must show the actual path with text
    labels: فرم → جدول انتظار → هوک/پایش → صف → درگاه → مشتری. Draw it with
    CSS boxes and SVG connectors, labelled in Persian. This diagram is the
    asset a competitor's page cannot copy without explaining their own gaps.
D8. TYPOGRAPHIC HONESTY. Numbers that are measured get a footnote naming the
    measurement file. Numbers that are not, do not appear.

=====================================================================
E. PAGE ARCHITECTURE — 13 sections, in this order
=====================================================================
0.  Skip link + sticky header (blur on scroll, solid fallback): brand lockup,
    nav (چطور کار می‌کند · امکانات · عملکرد · سؤال‌ها · قیمت), one CTA
    «خرید افزونه». On mobile: nav collapses to a single row of 3 anchors +
    CTA; no hamburger overlay with 10 links.
1.  HERO. H1, subhead (2 lines max), two CTAs («خرید افزونه» primary,
    «دیدن دمو» ghost → #demo), three proof chips, and the live mock:
    a product card showing «ناموجود» + the «خبرم کن» form, which on click
    shows the real success state «ثبت شد — به‌محض موجود شدن پیامک می‌کنیم».
    Footnote under chips: «هر عدد روی این صفحه اندازه‌گیری شده است؛
    فایل اندازه‌گیری کنارش نوشته شده.»
2.  PAIN — three short statements, no numbers, one line each, set as large
    text with a thin rule between them (not cards).
3.  HOW IT WORKS — 4 steps, numbered ۱–۴ in a vertical rail with a left-aligned
    connector line; each step one sentence + one micro-detail.
4.  MECHANISM DIAGRAM (D7). Persian labels. Below it, one sentence: «هیچ
    سروری از ما وسط نیست.»
5.  FEATURES — 6 items in a 2+1 / 5-7 asymmetric grid, inline SVG icons,
    each with a one-line claim and a one-line mechanism.
6.  PERFORMANCE PROOF — the 0/0/0 claim as a table (page type | assets |
    queries | form bytes), the 4,495 B gauge against a 12,288 B budget drawn
    as a bar, and a footnote naming tests/queries.php and docs/PERFORMANCE.md.
7.  COMPATIBILITY — table with three states only: «تست‌شده» /
    «اعلام‌شده، آزمون‌نشده» / «داده در دسترس نیست». Visible legend. Untested
    cells must not use the accent colour.
8.  THE HONEST SECTION — ⭐ headline exactly «چیزهایی که موجودیار ندارد»,
    six bullets in two columns, each: what it does not do + why (one clause).
9.  DEMO — embedded interactive demo (see section F). Heading
    «دمو — بدون نصب، بدون ثبت‌نام», plus a plain line «این دمو ساختگی است و
    هیچ پیامکی نمی‌فرستد.»
10. PRICING — three tiers (toman, Persian digits), middle featured, one line
    under the table: «پاسخ اولیه حداکثر ۲۴ ساعت کاری؛ هدف ۴ ساعت.» Do not add
    a fourth "enterprise" tier.
11. FAQ — 8 items, <details>, answers 1–3 lines, honest, some answers say
    «نه» or «داده در دسترس نیست».
12. FINAL CTA + FOOTER — one sentence, one button, then: license GPLv2+,
    version 1.0.0, «هیچ داده‌ای از سایت شما به سرور ما نمی‌رود», support
    hours, links to راهنمای نصب and changelog. No social icons.

=====================================================================
F. EMBEDDED DEMO (section 9) — behaviour spec
=====================================================================
State in memory only. No fetch, no XHR, no localStorage. Deterministic: the
same clicks always produce the same numbers.
Scenario picker with 6 tabs, each a 15–40 second story:
  1. «ثبت درخواست» — fill the form (name + mobile), submit, row appears at the
     top of the waitlist table with a Persian date and status «در انتظار».
  2. «برگشت ۵ عدد از ۱۰٬۰۰۰ منتظر» — click «موجودی را ۵ کن»: exactly 5 rows
     flip to «پیام ارسال شد», the queue counter shows 5, and a line states
     «۹٬۹۹۵ نفر در صف ماندند». This is the fairness proof; no other number.
  3. «الگوی پیامک بلندتر از سقف» — the send is blocked BEFORE any gateway
     call, the row turns «متوقف‌شده» with the reason «مقدار {2} ۵۳ کاراکتر
     است؛ سقف ۲۵». Show that a retry counter did NOT burn.
  4. «خطای درگاه» — gateway returns an error string; the log row shows the
     Persian explanation above and the raw response below, in <code dir="ltr">.
     Retry count goes 1 → 2 and the row is «زمان‌بندی‌شده» with «۱۵ دقیقهٔ
     دیگر».
  5. «صف گیرکرده» — a row is stuck in «در حال ارسال»; the queue says
     «۱ ردیف رهاشده»; clicking «آزادسازی» returns it and it sends in the same
     run.
  6. «پیگیری تلفنی» — open the row's call panel: تماس اول با نتیجه «پاسخ
     نداد», then تماس دوم با «سفارش داد» → row closes automatically.
Every tab ends with a one-line takeaway in the page, phrased as a fact, not a
sale. A visible badge «دمو» stays pinned to the demo frame. Buttons are real
<button>s with aria-live announcements for state changes.

=====================================================================
G. COPY DECK (verbatim Persian — do not edit a single character)
=====================================================================
BRAND: موجودیار
NAV: چطور کار می‌کند · امکانات · عملکرد · سؤال‌ها · قیمت
HEADER CTA: خرید افزونه

H1: مشتریِ ناموجود را برنمی‌گرداند — مگر کسی خبرش کند
HERO SUB: موجودیار فرم «خبرم کن» را فقط روی صفحهٔ همان محصول ناموجود
می‌گذارد. روی بقیهٔ سایت، صفر بایت. و وقتی کالا برگشت، به همان تعداد که
موجود شد نفر خبردار می‌شوند — قدیمی‌ترین منتظر، اول.
CTA1: خرید افزونه
CTA2: دیدن دمو
CHIP1: ۰ بایت روی بقیهٔ سایت
CHIP2: PHP 7.4 تا 8.3
CHIP3: بدون Composer، بدون build

PAIN TITLE: کالا که تمام می‌شود، مشتری می‌رود
PAIN1: مشتری دنبال کالا بود، نه دنبال دکمهٔ «اطلاع از موجودی». صفحه را
می‌بندد و سراغ فروشگاه بعدی می‌رود.
PAIN2: هیچ آدرسی از او نمی‌ماند. نه شماره‌ای، نه ایمیلی. در گزارش فروش، یک
بازدید بی‌نام.
PAIN3: موجودی هم بی‌صدا برمی‌گردد. بار جدید رسیده، موجودی عوض شده، و کسی که
منتظرش بود هیچ‌وقت خبردار نمی‌شود.

HOW TITLE: چطور کار می‌کند
STEP1: فرم، فقط جایی که لازم است — صفحهٔ محصول ناموجود، و برای هر تنوع
جداگانه. روی هیچ صفحهٔ دیگری نه فایلی، نه اسکریپتی.
STEP2: مشتری، با نام خودش — نام و شمارهٔ موبایل؛ همان نام در پیامک می‌آید،
نه «همراه گرامی».
STEP3: موجودی که برمی‌گردد، دیده می‌شود — هوک‌های ووکامرس به‌علاوهٔ پایش
تطبیقی هر ۱۵ دقیقه؛ موجودی‌ای که با اکسل یا ERP عوض شده هم از دست نمی‌رود.
STEP4: به تعداد موجودی، نه به تعداد لیست — ۵ واحد برگشت؟ ۵ نفر اول خبر
می‌شوند. ۳ واحد دیگر؟ ۳ نفر بعدی.

MECHANISM TITLE: مسیر یک پیامک، از فرم تا گوشی مشتری
MECHANISM NOTE: هیچ سروری از ما وسط نیست.

FEATURES TITLE: داخل افزونه
F1: صف با محدودیت نرخ — ارسال‌ها در پس‌زمینه و پله‌پله؛ هیچ صفحه‌ای وسط کار
قفل نمی‌کند و درگاه بلاک نمی‌شود.
F2: چهار درگاه پیامک — ملی‌پیامک (ساده و الگو/bodyId)، کاوه‌نگار، آی‌پی‌پنل،
و یک درگاه عمومی REST برای بقیه.
F3: تشخیص دولایهٔ موجودی — هوک ووکامرس برای تغییرهای عادی، پایش هر ۱۵ دقیقه
برای تغییرهایی که از اکسل، ERP یا SQL مستقیم آمده‌اند.
F4: ارسال منصفانه — به تعداد واحدهای برگشته اعلان می‌رود، از قدیمی‌ترین
منتظر. لیست هزارنفره با ۲ واحد موجود، هزار پیامک نمی‌فرستد.
F5: پنل فارسی با تاریخ شمسی — داشبورد، لیست انتظار، آمار، پیگیری تلفنی
مرحله‌ای، ابزارها و گزارش رویدادها.
F6: گزارش رویدادها — هر ردیف یک توضیح فارسی «چه شد و چه کار کنم»، و متن خام
پاسخ درگاه زیرش.

PERF TITLE: عددهایی که می‌شود بازتولید کرد
PERF NOTE: اندازه‌گیری: tests/queries.php و docs/PERFORMANCE.md. عددهای همین
دو فایل، نه بیشتر.

COMPAT TITLE: سازگاری
COMPAT NOTE: سلولی که تست نشده، «داده در دسترس نیست» می‌ماند. تیک سبز جای
تست نمی‌گذاریم.

NEGATIVE TITLE: چیزهایی که موجودیار ندارد
NEG1: پیامک وضعیت سفارش نمی‌فرستد — کار آن افزونه‌های حمل‌ونقل است، نه این.
NEG2: واتس‌اپ و تلگرام ندارد — نیازمند سرور بیرونی است و ما سرور بیرونی
نداریم.
NEG3: نوبت‌دهی/وقت (آرایشگاه، کلاس) ندارد — دامنهٔ متفاوتی است.
NEG4: باشگاه مشتریان و امتیاز ندارد — به این افزونه تعلق ندارد.
NEG5: بیش از سه درگاه + یک درگاه عمومی ندارد — هر درگاه تازه، سطح پشتیبانی
تازه است.
NEG6: دموی سازندهٔ صفحه و اپ موبایل ندارد — ساخت آن‌ها یک محصول دیگر است.

DEMO TITLE: دمو — بدون نصب، بدون ثبت‌نام
DEMO NOTE: این دمو ساختگی است و هیچ پیامکی نمی‌فرستد.

PRICING TITLE: قیمت
CTA FINAL: خرید افزونه
FOOTER NOTE: هیچ داده‌ای از سایت شما به سرور ما نمی‌رود؛ افزونه هیچ تماسی با
سرور سازنده ندارد. پروانه GPLv2 یا بالاتر. نسخه ۱.۰.۰.

FAQ (questions fixed; answers 1–3 lines, honest):
1. نصبش سخت است؟ — یک zip در پیشخوان، دو دقیقه. اگر پیامک وصل نشد، «گزارش
رویدادها» می‌گوید چرا.
2. با قالب من کار می‌کند؟ — فرم روی قلاب استاندارد ووکامرس می‌نشیند؛ اگر
قالب شما آن را حذف کرده، در مستندات راه جایگزین هست.
3. فرم را هر جای دیگری می‌شود گذاشت؟ — فعلاً فقط صفحهٔ محصول؛ شورت‌کد آزاد
در نسخهٔ بعد.
4. مشتری باید تأیید کند؟ — نه؛ تأیید دومرحله‌ای هست ولی پیش‌فرض خاموش است.
5. با هاست اشتراکی و PHP 7.4 کار می‌کند؟ — روی ۷.۴.۳۳ و ۸.۳.۳۳ تست شده؛
PHP 8.4 آزمون نشده.
6. داده‌ها کجا ذخیره می‌شوند؟ — در جدول‌های خود دیتابیس شما، فقط InnoDB.
حذف افزونه داده‌ها را پاک نمی‌کند مگر خودتان روشن کنید.
7. پیامک از سرور شما می‌رود؟ — نه. از فروشگاه شما مستقیم به درگاه خودتان.
8. اگر صف گیر کند چه؟ — ابزار «آزادسازی صف» ردیف رهاشده را برمی‌گرداند؛
اجرای بعدی صف هم خودکار این کار را می‌کند.

=====================================================================
H. DELIVERABLES
=====================================================================
1. index.html — semantic, one <h1>, landmarks, no inline styles except CSS
   custom properties on :root.
2. assets/style.css — organised as: tokens → reset → layout → components →
   sections → utilities → media queries → print. Every component class
   prefixed .mjy-. No !important.
3. assets/app.js — ≤ 8 KB gzipped: sticky header state, in-view reveal
   (IntersectionObserver, disabled under reduced-motion), demo state machine,
   copy-facts buttons. No dependencies.
4. fonts/ — reference only; assume files exist at ../fonts/.
5. A short README.md: how to preview, what is measured, what is mocked.

At the end of your reply, print a self-check table with one row per directive
A1–A10 and D1–D8: PASS / FAIL / NOT APPLICABLE, and for every FAIL, the
reason. Do not claim PASS on something you did not implement.
```

---

# بخش ۳ — CALL 3: BUILD DEMO

```
APEX ∞ / CALL 3 — BUILD THE INTERACTIVE DEMO (standalone page)

Build demo/index.html — a standalone, self-contained interactive replica of
«موجودیار» for showing to buyers before they install anything. Same invariants
A1–A10 and the same brand tokens as CALL 2, plus:

D1. NO BACKEND, NO NETWORK. Zero fetch/XHR/WebSocket. Zero localStorage writes
    (read is unnecessary too). All state lives in a plain JS object and resets
    on reload. If the URL has ?reset, start clean.
D2. IT MUST BE HONESTLY A DEMO. A fixed badge «دمو — داده‌های ساختگی» in the
    frame corner. A line at the bottom of every panel: «هیچ پیامکی فرستاده
    نمی‌شود.» Never simulate a real gateway brand response as if it were live.
D3. TWO SURFACES, ONE PAGE. A segmented control tab switch:
      «فروشگاه»  → product page with the «خبرم کن» form
      «پنل»      → admin replica: dashboard tiles, waitlist table, queue panel,
                    event log, call panel
    Switching tabs must preserve the state created in the other tab (submit a
    request in «فروشگاه», then see the row appear in «پنل»).
D4. SEED DATA, PERSIAN, PLAUSIBLE. 12 rows visible by default. Product
    «کوله‌پشتی سفری ۳۰ لیتری». Names: سارا محمدی، علی رضایی، مریم احمدی،
    حسین کریمی، نگار موسوی… (mix of full names, some rows with only a phone —
    because the name field is optional). Jalali dates. Phones masked as
    ۰۹۱۲…۴۷ in tables; full only inside the row detail.
D5. THE TABLE MUST BEHAVE LIKE THE REAL ONE. Sortable client-side by date and
    status; a status filter with the exact statuses: در انتظار · در صف ·
    پیام ارسال شد · ناموفق · متوقف‌شده · لغوشده · بسته; a bulk action
    «تلاش دوباره». CSV export button that builds a Blob with a UTF-8 BOM and
    downloads — with a caption «همان چیزی که اکسل فارسی بدون کدگذاری درست
    باز می‌کند».
D6. SIX SCENARIOS (from CALL 2 section F) as a right-hand rail of buttons, each
    running a scripted state transition with a 300–600 ms delay so the user
    sees cause and effect. After each, print a one-line factual takeaway.
D7. THE LOG PANEL IS THE STAR. For every failure scenario, show the row
    expanded: Persian explanation (what happened, what to do) on top, raw
    gateway/HTTP payload in <pre dir="ltr"> below, plus the fields
    attempt=2, next_run_at, and the fact that a blocked config error did NOT
    consume an attempt.
D8. QUEUE DIAGNOSTICS. A small panel showing counts: ready / scheduled /
    processing / failed / blocked, plus «۱ ردیف رهاشده» when scenario 5 runs,
    with the «آزادسازی» button. Numbers must stay internally consistent —
    write one reducer function and derive every number from it. Do not hardcode
    a counter that contradicts the table.

Also produce demo/README.md: what the demo proves, which six claims map to
which scenario, and the exact list of things it does not prove (real delivery,
cron, your host's PHP).
```

---

# بخش ۴ — CALL 4: AUDIT & REPAIR

```
APEX ∞ / CALL 4 — AUDIT THE OUTPUT, THEN REPAIR IT

You are the reviewer, not the author. Do not defend the previous output.

STEP 1 — MACHINE CHECKS. Run/inspect and report each as PASS or FAIL with the
evidence line:
  1. No external hosts in HTML/CSS/JS: grep for http:// https:// //cdn fonts.
     Allowed: none. (Self-references and href="#…" are fine.)
  2. No left/right physical properties in CSS: grep for
     [^-]left:|[^-]right:|margin-left|padding-left|text-align: left.
  3. One <h1>. No skipped heading levels.
  4. Every <img>/<svg> decorative or labelled; every button has an accessible
     name; every input has a <label>.
  5. FAQ works with JS disabled (<details>).
  6. Page weight: gzip each of HTML/CSS/JS, print the sum; must be ≤ 120 KB
     total and JS ≤ 8 KB.
  7. Every number on the page exists in the FACT LEDGER. List any number that
     does not, with its line.
  8. Every untested compatibility cell reads «داده در دسترس نیست» or
     «اعلام‌شده، آزمون‌نشده».
  9. Forbidden strings absent (list them from CALL 2 section B).
 10. Contrast: for each text/background pair, print the ratio. Flag < 4.5.
 11. Focus ring visible on every interactive element (tab through in code
     order, not DOM order guessing).
 12. Reduced-motion: with prefers-reduced-motion: reduce, no transform/opacity
     animation runs.
 13. Demo: state reducer is single-source; no two counters can disagree.
 14. Mobile 360 px: no horizontal scroll (check for min-width, fixed px
     widths, nowrap on long Persian strings).
 15. Print stylesheet: page readable, no glass, no sticky header.

STEP 2 — DESIGN REVIEW, brutal and specific. Answer each with a line reference:
  a. Where does the page look like a template? Name the section and the reason.
  b. Which section could be deleted without losing information? Delete it and
     say what moved where.
  c. Which claim is not backed by the ledger? Rewrite it as a mechanism.
  d. Where is the typography lazy (uniform sizes, no hierarchy, bad Persian
     line-height, letter-spacing, justified text)?
  e. Which mock still looks like a placeholder (English text, lorem, grey
     boxes, fake chart)?
  f. What is the single most convincing 10 seconds on this page, and is it
     above the fold or buried?
  g. If a hostile competitor read this page, what would they attack first?

STEP 3 — REPAIR. Apply every FAIL and every actionable design finding. Output
only the changed files in full (no diffs, no "…unchanged"), then a table:
issue → what changed → how to verify. Do not add new sections while repairing.
If a fix would require a claim we cannot measure, delete the claim instead.
```

---

# بخش ۵ — پیش از هر استفاده، این اعداد را تازه کنید

هر عددی که در FACT LEDGER آمده، از این فایل‌ها می‌آید. اگر محصول را تغییر
دادید، اول این‌ها را دوباره بسازید و بعد پرامپت را به‌روز کنید:

| عدد | از کجا |
|---|---|
| ۰ فایل / ۰ کوئری خارج از صفحهٔ هدف · ۱٬۹۸۴ و ۲٬۲۳۶ بایت فرم | `tests/queries.php` |
| ۱۳٬۵۷۷ خام / ۴٬۴۹۵ فشرده · بودجه ۱۲٬۲۸۸ | `tools/build-assets.sh` → `docs/PERFORMANCE.md` |
| ۴۱۱ / ۴۴۴ / ۱۷۳ assertion | `tests/*.json` → `docs/TEST-REPORT.md` |
| ۲۸ بررسی انتشار · ۸۱ فایل · ۳۰۱٬۵۹۹ بایت | `tools/verify-release.py` |
| ۲۵/۲۸/۲۲/۵۳ کاراکتر نشانی | `docs/TROUBLESHOOTING.md` |
| ۱۵ دقیقه پایش · ۵ دقیقه صف · ۱۵/۶۰/۳۶۰ عقب‌نشینی · تلاش ۱–۵ | `docs/SCHEDULER-POLICY.md` |

**قاعده:** اگر عددی را اندازه نگرفته‌اید، در پرامپت هم نگذارید. جملهٔ
«داده در دسترس نیست» از یک عدد اشتباه قوی‌تر است — و برای بازبین راست‌چین و
ژاکت، تفاوت یک محصول دستی و یک محصول قالبی همین است.

---

# بخش ۶ — سه اشتباهی که این پرامپت را خراب می‌کند

1. **دادن هر چهار فراخوان در یک پیام.** خروجی در بخش ۲ بریده می‌شود، بخش ۴
   (بازرسی) هیچ‌وقت اجرا نمی‌شود.
2. **اجازهٔ نوشتن متن تازه به مدل.** بخش G عمداً قفل شده؛ اگر «بهترش کن»
   بگویید، جمله‌های تبلیغاتی و عددهای بی‌منبع برمی‌گردند.
3. **حذف «چیزهایی که موجودیار ندارد» چون «حال‌و‌هو را خراب می‌کند».** این
   بخش، تنها جایی است که صفحه را از یک صفحهٔ فروش معمولی جدا می‌کند و در
   بازبینی بازار بیشترین نفع را دارد.
