# پرامپت APEX PRO ∞ — بنر تبلیغاتی نویدبان (۱۸۸۳ × ۸۳۵)

> پروندهٔ توسعه — در بستهٔ انتشار نمی‌آید.
> این پرونده سه چیز می‌دهد: پرامپت تولید تصویر، مشخصات لایهٔ متن، و مشخصات فنی
> وب. یک هشدار صادقانه در بخش ۰ هست که قبل از هر کاری باید بخوانید.

---

## ۰) هشدار صادقانه: متن فارسی را به مدل تصویر نسپارید

مدل‌های تصویری (Midjourney، Flux، DALL·E، SDXL) حروف فارسی را **نمی‌توانند
درست بنویسند**: یا شکل عربی/اردو می‌دهند، یا حروف را جدا می‌نویسند، یا کلمه را
جعل می‌کنند. حتی مدل‌هایی که «متن‌فهم» هستند (Ideogram، GPT-Image) در فارسی
تقریباً همیشه غلط املایی دارند.

بنابراین مسیر درست دو مرحله است:

1. **مدل تصویر** فقط صحنه، نور، متریال و فضای خالی متن را می‌سازد (پرامپت B).
2. **متن فارسی** در Figma / Photoshop / Affinity روی همان فایل گذاشته می‌شود
   با فونت **Vazirmatn** و مختصات دقیقی که در بخش ۳ آمده است.

اگر باز هم خواستید همه‌چیز را یک‌جا به مدل بدهید، پرامپت A هست — ولی بعد از
تولید باید **حرف‌به‌حرف** غلط‌گیری شود؛ یک بنر با «نویدبان» غلط، بدتر از بنر
بدون متن است.

---

## ۱) مشخصات بوم (Canvas)

| مورد | مقدار |
|---|---|
| ابعاد نهایی | **۱۸۸۳ × ۸۳۵ پیکسل** |
| نسبت | ۲.۲۵۵ : ۱ (تقریباً ۹:۴) |
| رزولوشن | ۷۲ ppi برای وب (فایل طراحی: ۲× یعنی ۳۷۶۶ × ۱۶۷۰) |
| محدودهٔ امن متن | ۹۶ px از هر طرف |
| ناحیهٔ امن کلیدی (CTA و لوگو) | ۱۴۰ px از لبه‌ها |
| رنگ زمینهٔ نشست | `#EEF4F2` (همان `--nvb-bg` پنل) |

**نکتهٔ تولید:** بیشتر مدل‌ها نسبت دلخواه ۲.۲۵۵ نمی‌دهند. از این یکی استفاده
کنید و بعد کراپ کنید:

- `--ar 9:4` (۲.۲۵) → کراپ ۱۸۸۳×۸۳۵ تقریباً بدون هدر رفتن
- `--ar 21:9` (۲.۳۳) → ۲۰ px از هر طرف کراپ شود
- هرگز `16:9` نگیرید؛ آن‌قدر باریک می‌شود که CTA و لوگو هر دو از دست می‌روند.

خروجی مدل را بزرگ‌تر بگیرید (مثلاً ۲۰۴۸ × ۹۰۸) و بعد به ۱۸۸۳×۸۳۵ برسانید؛
کوچک‌کردن تصویر همیشه بهتر از بزرگ‌کردن است.

---

## ۲) چیدمان (RTL — متن راست، تصویر چپ)

```
┌──────────────────────────────────────────────────────────────────────┐
│  ← 96px امن                                                            │
│                                                                      │
│                                ┌───────────────────────┐  ┌────────┐ │
│                                │  لوگو + نام برند      │  │ ۱.۰.۹  │ │
│                                └───────────────────────┘  └────────┘ │
│                                                                      │
│                                نویدبان                               │
│                    لیست انتظار و اعلان بازگشت موجودی                  │
│                                                                      │
│           ┌─────────────────────────┐                                │
│           │  دکمه: همین حالا ببینید │  ← AMBER #F2A93B              │
│           └─────────────────────────┘                                │
│                                                                      │
│   ۳ چیپ شیشه‌ای: صفر بایت · ۵ واحد؟ ۵ نفر · پیامک الگویی               │
│   ────────────────────────────────────────────────────────           │
│   ردیف اعتماد: PHP 7.4–8.3 · بدون Composer · بدون تماس با سرور ما     │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
               ↑ نیمهٔ چپ: صحنهٔ تصویری (۴۵٪ عرض)
```

**قانون تقسیم:** ۵۵٪ راست = متن و CTA، ۴۵٪ چپ = صحنه. صحنه نباید زیر متن
بیاید؛ اگر آمد، بنر شلوغ می‌شود و روی موبایل (که کراپ می‌شود) متن ناخوانا
می‌ماند.

**سه ناحیهٔ کراپ موبایل:** در عرض < ۷۶۸px سایت اتحاد، بنر معمولاً به نسبت ۴:۳
کراپ می‌شود. مرکز امن: `x = 941 ± 320`, `y = 417 ± 260`. لوگو و CTA را داخل این
مستطیل نگه دارید.

---

## ۳) متن (لایهٔ فارسی — دقیقاً همین رشته‌ها)

| # | نقش | متن دقیق | فونت / وزن | اندازه | رنگ |
|---|---|---|---|---|---|
| ۱ | برند | `نویدبان` | Vazirmatn ExtraBold | ۹۶ px | `#0E5C55` |
| ۲ | تیتر | `مشتری ناموجود را برنمی‌گرداند — مگر کسی خبرش کند` | Vazirmatn Bold | ۴۴ px | `#14241F` |
| ۳ | زیرتیتر | `فرم «خبرم کن» فقط روی صفحهٔ همان محصول؛ روی بقیهٔ سایت صفر بایت` | Vazirmatn Medium | ۲۶ px | `#5B6B66` |
| ۴ | CTA | `همین حالا ببینید` | Vazirmatn Bold | ۲۸ px | `#FFFFFF` روی `#F2A93B` |
| ۵ | چیپ ۱ | `۰ بایت روی بقیهٔ سایت` | Vazirmatn Medium | ۱۸ px | `#0E5C55` |
| ۶ | چیپ ۲ | `۵ واحد؟ فقط ۵ نفر` | Vazirmatn Medium | ۱۸ px | `#0E5C55` |
| ۷ | چیپ ۳ | `پیامک الگویی ملی‌پیامک` | Vazirmatn Medium | ۱۸ px | `#0E5C55` |
| ۸ | نوار اعتماد | `PHP 7.4 تا 8.3 · بدون Composer · بدون تماس با سرور ما` | Vazirmatn Regular | ۱۶ px | `#5B6B66` |
| ۹ | نسخه | `۱.۰.۹` | Vazirmatn SemiBold | ۱۶ px | `#0E5C55` |

**قواعد متن که نباید شکسته شوند:**

- هر عددی که در بنر می‌آید باید اندازه‌گیری‌شده باشد. `۰ بایت`، `۴٬۴۸۹ بایت
  فشرده`، `۵ واحد / ۵ نفر`، `PHP 7.4.33 و 8.3.33` مجاز است.
  عدد تزئینی مثل «۹۲٪ رضایت»، «+۱۰٬۰۰۰ فروشگاه»، «۲۷ درگاه» **ممنوع** — نه
  اندازه‌گیری شده و نه در محصول هست.
- «خبرم کنید»، «دیجی‌کالا»، «YITH» نباید هیچ‌جا در بنر باشد.
- فاصله‌گذاری فارسی: بین تیتر و زیرتیتر حداقل ۲۴ px؛ ارتفاع خط ۱.۶ برای متن
  فارسی (کمتر از آن، اعراب و نقاط به خط بعد می‌چسبد).

---

## ۴) پرامپت B — فقط صحنه و فضای خالی متن (توصیه‌شده)

```
APEX PRO ∞ — BANNER BACKGROUND, 9:4, 2048×908

ROLE
You are a senior art director designing a premium SaaS hero banner for a
Persian WooCommerce plugin. You produce the visual scene and clean negative
space for the headline. You do NOT render any text, letters or numbers.

SUBJECT
A calm, expensive product-photography scene for "back in stock" alerts.
Left 45% of the canvas: a floating glass smartphone at 12° tilt, screen
glowing softly, showing an abstract, blurred notification UI (no readable
text — soft rounded shapes, a teal pill button, a small amber badge).
Behind it, a second slightly blurred glass panel like a browser window edge,
suggesting a dashboard. Both float in space with soft shadow, no visible
stand, no hand.

RIGHT 55% of the canvas: intentionally empty. A smooth continuous gradient
plane, softly lit, no objects, no texture noise, no pattern — this is the
safe zone where Persian type will be placed later.

MATERIALS and LIGHT
Frosted glass with 18px blur and 1px white inner highlight (iOS-18 style
glassmorphism), champagne-clean surfaces, no chrome, no neon. Light source
is a large softbox from top-right with a subtle TEAL rim (#14857B) along
object edges, plus one warm AMBER practical light (#F2A93B) inside the phone
screen area. Shadows are long, soft, and low-contrast.

PALETTE (strict)
Background base: #EEF4F2 to #FFFFFF gradient.
Primary: #0E5C55 (deep petrol green), 20% of visual weight.
Secondary/accent: #14857B (teal) and #F2A93B (amber), 10% combined.
Ink: #14241F. Nothing else. No purple, no blue, no pink.

COMPOSITION
Asymmetric balance: heavy on the left, breathing on the right.
Rule of thirds anchor for the phone at x≈26%, y≈52%.
Top-right corner must stay clean for a small version chip.
Bottom 15% is a soft gradient fade to #EEF4F2 (lets the trust row sit legibly).
Leave a 96px empty margin around the whole frame.

MOOD
Apple keynote product shot meets Linear/Vercel marketing calm. Editorial,
confident, uncluttered. Premium, not playful. No stock-photo people.

RENDER
Photorealistic 3D, octane/cycles feel, 50mm lens equivalent, shallow depth
of field on the back panel only, fine film grain at 3%, crisp micro-detail
on the glass edges, no chromatic aberration, no vignette.

NEGATIVE
text, letters, numbers, Persian script, Arabic script, Latin words, logo
lockups, watermarks, UI labels, keyboard, mouse, human hands, faces, clutter,
busy background, multiple phones, drop shadows that are pure black, neon
glow, cyberpunk, lens flare, purple gradient, rainbow, mesh gradients that
look like 2019, heavy HDR, cartoon, 3D emoji, isometric illustration,
stock-photo people, badge stickers, price tags, QR codes.

OUTPUT
2048×908, sRGB, high detail, safe for a 1883×835 downscale.
```

برای Midjourney: `--ar 9:4 --style raw --stylize 180 --quality 2`
برای Flux / SDXL: steps ۳۰–۴۰، guidance ۳.۵–۴.۵ (بالاتر = شلوغ‌تر و بدتر)

---

## ۵) پرامپت A — تولید یک‌جا با متن (فقط اگر مدل فارسی‌بلد است)

قبل از استفاده، بدانید که احتمال غلط املایی بالا است. بعد از تولید، کلمه‌به‌کلمه
بخوانید.

```
APEX PRO ∞ — FULL BANNER WITH PERSIAN TYPE, 9:4, 2048×908

Design a premium RTL advertising banner. Right-to-left layout: Persian copy on
the right 55%, product scene on the left 45%.

Render this exact Persian text, correctly shaped and joined, with a modern
Persian sans (Vazirmatn-like), no diacritics:

Headline (largest, deep petrol green #0E5C55, extra bold):
  نویدبان
Subheadline (44px equivalent, near-black #14241F):
  مشتری ناموجود را برنمی‌گرداند — مگر کسی خبرش کند
Support line (26px, muted #5B6B66):
  فرم «خبرم کن» فقط روی صفحهٔ همان محصول؛ روی بقیهٔ سایت صفر بایت
CTA button (rounded 16px, amber #F2A93B fill, white bold label):
  همین حالا ببینید
Three small glass chips under the CTA (18px, petrol green text):
  ۰ بایت روی بقیهٔ سایت
  ۵ واحد؟ فقط ۵ نفر
  پیامک الگویی ملی‌پیامک
Fine print row at the bottom (16px, muted):
  PHP 7.4 تا 8.3 · بدون Composer · بدون تماس با سرور ما

Style: glassmorphism, soft studio light, light #EEF4F2 background, teal rim
light #14857B, one amber accent, generous whitespace, editorial and calm.
Left side: floating frosted-glass phone at slight tilt with an abstract
notification UI, soft shadow, no hands.
Do not add any other text, logos, or numbers. Persian must be right-aligned
and correctly shaped.
```

---

## ۶) متن جایگزین برای A/B (تیترهای دیگر)

همه با ادعاهای واقعی و اندازه‌گیری‌شده:

1. `مشتری ناموجود را برنمی‌گرداند — مگر کسی خبرش کند` (پیشنهاد اول)
2. `۵ واحد برگشت؟ فقط ۵ نفر خبردار می‌شوند`
3. `روی بقیهٔ سایت، صفر بایت. روی همان صفحه، یک فرم.`
4. `پیامک ایرانی، مستقیم به درگاه خودتان — بدون واسطه`
5. `حتی وقتی موجودی را با اکسل عوض می‌کنید، نویدبان می‌فهمد`

برای هر کدام یک بنر جدا نسازید؛ **همان پرامپت B** را با تیتر عوض کنید.

---

## ۷) مشخصات فنی تحویل برای صفحهٔ اصلی اتحاد وردپرس

| مورد | مقدار |
|---|---|
| فرمت اصلی | **WebP** (کیفیت ۸۲) + نسخهٔ JPG (کیفیت ۸۵) برای مرورگرهای قدیمی |
| حجم هدف | ≤ ۱۸۰ KB برای ۱۸۸۳×۸۳۵ |
| نسخهٔ ۲× | ۳۷۶۶ × ۱۶۷۰ — فقط اگر سایت `srcset` دارد؛ وگرنه لازم نیست |
| نسخهٔ موبایل | کراپ ۹۰۰×۱۲۰۰ یا ۱۰۸۰×۱۰۸۰ با چیدمان بازچینی‌شده، نه کراپ ساده |
| فضای رنگ | sRGB (نه CMYK، نه P3) |
| کلیدواژهٔ فایل | `navidban-banner-1883x835.webp` |
| `alt` | `نویدبان — افزونهٔ لیست انتظار و اعلان بازگشت موجودی برای ووکامرس` |
| CLS | `width="1883" height="835"` حتماً در تگ تصویر |
| LCP | این بنر اگر بالای صفحه است، **lazy-load نکنید**؛ `fetchpriority="high"` |

**قاعدهٔ تیتر و متن در تصویر:** هرچه متن داخل تصویر بیشتر باشد، در جست‌وجو
بی‌فایده است و در موبایل ناخوانا. فقط «نویدبان» + یک تیتر + CTA را داخل تصویر
نگه دارید و بقیه را به‌صورت HTML روی صفحه بگذارید.

---

## ۸) ⭐ چه چیزی در بنر **نباید** باشد

| ممنوع | چرا |
|---|---|
| درصد رضایت، تعداد فروشگاه، «۲۷ درگاه» | اندازه‌گیری نشده / در محصول نیست |
| تصویر فردی که با گوشی تماس می‌گیرد | حس «تبلیغ تلویزیونی» می‌دهد و به محصول ربط ندارد |
| اسکرین‌شات واقعی پنل با دادهٔ مشتری | نشت اطلاعات و متن ریز ناخوانا |
| لوگوی ووکامرس به‌عنوان برند اصلی | گمراه‌کننده؛ ووکامرس فقط بستر است |
| «به‌زودی»، «نسخهٔ آزمایشی» | تعهد بدون تاریخ |
| کد QR | روی بنر وب کلیک‌خوردن ندارد و جای CTA را می‌گیرد |

---

## ۹) چک‌لیست پیش از تحویل

- [ ] ابعاد دقیقاً ۱۸۸۳×۸۳۵ (نه ۱۸۸۲، نه ۱۸۸۴ — کراپ نیم‌پیکسلی لبه‌ها را نرم می‌کند)
- [ ] متن فارسی با Vazirmatn و بدون غلط املایی، خوانده‌شده توسط یک فارسی‌زبان
- [ ] «نویدبان» درست نوشته شده (نه «نوید بان»، نه «نویدبن»)
- [ ] هیچ عددی در تصویر نیست که در `docs/PERFORMANCE.md` یا متن فروش نباشد
- [ ] در کراپ ۴:۳ موبایل، تیتر و CTA کامل دیده می‌شوند
- [ ] در پس‌زمینهٔ سفید و در پس‌زمینهٔ تیرهٔ سایت، خوانا است (کنتراست متن ≥ ۴.۵:۱)
- [ ] حجم WebP زیر ۱۸۰ KB
- [ ] فونت روی تصویر **outline** شده (اگر لایه‌های باز تحویل می‌دهید، فونت هم ضمیمه باشد)
- [ ] نسخهٔ بنر ۱۰۵۰×۵۲۰ هم از همین طرح بازچینی شده، نه کراپ‌شده
