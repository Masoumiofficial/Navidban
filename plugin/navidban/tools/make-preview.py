#!/usr/bin/env python3
"""Turn the JSON produced by tools/preview-admin.php into openable HTML files.

This repository has no browser and no screenshot tool, so the glass redesign
cannot be verified visually here. This script does the next best thing: it
takes the *real* markup produced by the real views, inlines the *real*
stylesheet, and writes standalone HTML files a human can open.

It asserts nothing and is not part of the release (tools/ is excluded from the
ZIP). If the markup looks wrong, the preview will look wrong — that is the
point.

Usage:
    node tools/smoke-php.mjs --engine=wasm tools/preview-admin.php \\
        --json-out=docs/dev/preview.json
    python3 tools/make-preview.py docs/dev/preview.json docs/dev/preview
"""

import html
import json
import pathlib
import sys

TITLES = {
    "dashboard": "داشبورد",
    "waitlists": "لیست انتظارها",
    "settings": "تنظیمات",
    "tools": "ابزارها",
    "help": "راهنما",
    "form": "فرم فروشگاه (هر دو کانال)",
    "form-sms": "فرم فروشگاه (فقط پیامک)",
    "email": "ایمیل اعلان موجودی",
}

# Only the parts of wp-admin a preview needs: page background, base font size and
# the link colours the plugin inherits.
ADMIN_BASE = """
body {
    margin: 0;
    padding: 20px 20px 60px;
    background: #f0f0f1;
    font-family: "Segoe UI", Tahoma, Arial, sans-serif;
    font-size: 13px;
    color: #1d2327;
}
.nvb-preview-note {
    max-width: 1180px;
    margin: 0 0 14px;
    padding: 10px 14px;
    border-radius: 12px;
    background: #fffbe6;
    border: 1px solid #f0d98a;
    font-size: 12px;
    line-height: 1.9;
    color: #6b5200;
    direction: rtl;
    font-family: Vazirmatn, Tahoma, Arial, sans-serif;
}
"""

FRONT_BASE = """
body {
    margin: 0;
    padding: 40px 16px 80px;
    background: #f4f7f6;
    font-family: Vazirmatn, IRANSansX, Tahoma, Arial, sans-serif;
    font-size: 15px;
    color: #10201e;
}
.nvb-demo {
    max-width: 520px;
    margin: 0 auto;
}
.nvb-preview-note {
    max-width: 520px;
    margin: 0 auto 18px;
    padding: 10px 14px;
    border-radius: 12px;
    background: #fffbe6;
    border: 1px solid #f0d98a;
    font-size: 12px;
    line-height: 1.9;
    color: #6b5200;
    direction: rtl;
}
"""

PAGE = """<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>نویدبان {version} — {title}</title>
<style>
/* --- wordpress-ish page shell (preview only) --- */
{base}
/* --- the real plugin stylesheet, verbatim --- */
{plugin}
</style>
</head>
<body>
<p class="nvb-preview-note">
    پیش‌نمایش استاتیک از خروجی واقعی ویوها در نسخهٔ {version}.
    این فایل برای بازبینی چشمی است و بخشی از بستهٔ انتشار نیست.
    فرم‌ها و دکمه‌ها در اینجا کار نمی‌کنند (بدون وردپرس).
</p>
{body}
</body>
</html>
"""


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2

    source = pathlib.Path(sys.argv[1])
    target = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else "docs/dev/preview")

    data = json.loads(source.read_text(encoding="utf-8"))
    version = data.get("version", "?")
    screens = data.get("screens", {})

    plugin_root = pathlib.Path(__file__).resolve().parent.parent
    admin_css = (plugin_root / "assets/css/navidban-admin.css").read_text(encoding="utf-8")
    front_css = (plugin_root / "assets/css/navidban-front.css").read_text(encoding="utf-8")

    target.mkdir(parents=True, exist_ok=True)

    for key, markup in screens.items():
        is_form = key in ("form", "form-sms")
        is_email = key == "email"
        page = PAGE.format(
            version=html.escape(str(version)),
            title=html.escape(TITLES.get(key, key)),
            base=FRONT_BASE if is_form else ("body{padding:0;background:#fff}" if is_email else ADMIN_BASE),
            plugin=front_css if is_form else ("" if is_email else admin_css),
            body=('<div class="nvb-demo">' + markup + "</div>") if is_form else markup,
        )
        if is_email:
            # The skin already returns a complete document; nest it nowhere and
            # put the preview notice inside its <body> instead.
            note = (
                '<p style="max-width:560px;margin:0 auto 14px;padding:10px 14px;'
                'border-radius:12px;background:#fffbe6;border:1px solid #f0d98a;'
                'font:12px/1.9 Tahoma,Arial,sans-serif;color:#6b5200;direction:rtl;">'
                "پیش‌نمایش قالب ایمیل. این فایل بخشی از بستهٔ انتشار نیست.</p>"
            )
            import re as _re
            page = _re.sub(r"(<body[^>]*>)", lambda m: m.group(1) + note, markup, count=1)
        (target / f"{key}.html").write_text(page, encoding="utf-8")
        print(f"wrote {target / (key + '.html')}")

    index = ["<!DOCTYPE html><html lang=\"fa\" dir=\"rtl\"><head><meta charset=\"utf-8\" />",
             "<title>پیش‌نمایش پنل نویدبان</title><style>body{font-family:Tahoma,Arial,sans-serif;",
             "padding:40px;background:#f0f0f1;direction:rtl;line-height:2}a{color:#0e5c55}</style></head><body>",
             f"<h1>پیش‌نمایش نویدبان {html.escape(str(version))}</h1>",
             "<p>هر صفحه از خروجی واقعی ویو ساخته شده است.</p><ul>"]
    for key in screens:
        index.append(f'<li><a href="{key}.html">{html.escape(TITLES.get(key, key))}</a></li>')
    index.append("</ul></body></html>")
    (target / "index.html").write_text("\n".join(index), encoding="utf-8")
    print(f"wrote {target / 'index.html'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
