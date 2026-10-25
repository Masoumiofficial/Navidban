# نسخهٔ وب مجموعهٔ APEX Ω∞ — نویدبان

خروجی `build.py` — از ریشهٔ ریپو بازسازی می‌شود:

    python3 research/site/build.py

## صفحه‌ها

| پرونده | محتوا | منبع |
|---|---|---|
| `index.html` | خانه، راهنمای مطالعه، چهار سند تحویلی | — |
| `report.html` | گزارش نهایی تحقیق بازار | `research/APEX-report.md` |
| `evidence.html` | دفترچهٔ شواهد خام | `research/data/evidence-log.md` |
| `brand.html` | بستهٔ برند «نویدبان» | `research/brand/BRAND.md` |
| `build-prompt.html` | پرامپت ساخت APEX Ω∞ BUILD 1.0 | `research/build-prompt.md` |

پرونده‌های `.md` هم‌نام در همین پوشه کپی می‌شوند تا پیوند «دانلود Markdown» کار کند.

`build.py` **هیچ وابستگی بیرونی ندارد** — فقط کتابخانهٔ استاندارد پایتون ۳.
`.nojekyll` حاضر است تا در GitHub Pages پردازش Jekyll روی پرونده‌های زیرخط‌دار اجرا نشود.
