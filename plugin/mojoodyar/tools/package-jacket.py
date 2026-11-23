#!/usr/bin/env python3
"""
package-jacket.py — بستهٔ آمادهٔ ارسال به ژاکت.

ژاکت (و هر بازار ایرانی دیگری) همان zip وردپرسی می‌خواهد، به‌علاوهٔ چند فایل
کنارش که بازبین می‌خواهد و مشتری لازم دارد. این اسکریپت آن بسته را می‌سازد:

    plugin/release/jacket/
      mojoodyar-1.0.0.zip      بستهٔ نصب افزونه (همان چیزی که verify-release می‌سازد)
      راهنمای-نصب.md            راهنمای فارسی یک‌صفحه‌ای برای خریدار
      مجوز-فونت.txt             متن مجوز Vazirmatn (SIL OFL 1.1) برای بازبین
      اطلاعات-فایل.txt          حجم و SHA-256 برای کپی‌کردن در فرم ارسال
      فهرست-فایل‌ها.txt         همهٔ فایل‌های داخل zip، برای بازبینی سریع

چرا «راهنمای-نصب» و «مجوز-فونت» را جدا می‌گذاریم: بازبین ژاکت دنبال مجوز
دارایی‌های شخص ثالث است و خریدار دنبال «از کجا شروع کنم». هر دو در بستهٔ نصب
هم هستند، ولی در فرم ارسال باید جدا دیده شوند.

Usage:
    python3 tools/package-jacket.py            # بساز
    python3 tools/package-jacket.py --check    # فقط بررسی اینکه بستهٔ zip هست
"""

from __future__ import annotations

import argparse
import hashlib
import os
import shutil
import sys
import zipfile

PLUGIN_SLUG = "mojoodyar"
OUT_NAME = "jacket"


def plugin_version(root: str) -> str:
    with open(os.path.join(root, f"{PLUGIN_SLUG}.php"), encoding="utf-8") as handle:
        for line in handle:
            if "Version:" in line:
                return line.split("Version:")[1].strip().split()[0].rstrip("*").strip()
    return ""


def read(path: str) -> str:
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def install_guide(root: str, version: str) -> str:
    return f"""# راهنمای نصب و راه‌اندازی — موجودیار {version}

> یک صفحه، بدون اصطلاح فنی اضافه. اگر جایی گیر کردید، بخش «اگر کار نکرد» در
> انتهای همین فایل.

## ۱) پیش‌نیازها

| مورد | حداقل |
|---|---|
| وردپرس | ۶.۴ |
| ووکامرس | ۹.۰ |
| PHP | ۷.۴ (هارنس PHP-WASM روی ۷.۴.۳۳ و ۸.۳.۳۶؛ هاست شما آزمون نشده) |
| حافظهٔ PHP | ۶۴ مگابایت |

هیچ کتابخانهٔ اضافه، هیچ نصبی خارج از وردپرس و هیچ کلید لایسنسی لازم نیست.

## ۲) نصب

۱. از داده‌ها نسخهٔ پشتیبان بگیرید؛ از پیشخوان به **افزونه‌ها → افزودن →
   بارگذاری افزونه** بروید.
۲. فایل `mojoodyar-{version}.zip` را انتخاب و **اکنون نصب کن** را بزنید.
   اگر همین نسخه نصب است، در درخواست وردپرس تأیید کنید که فایل فعلی جایگزین شود.
۳. **فعال کردن** را بزنید و کش صفحه، افزونهٔ کش، CDN و مرورگر را پاک کنید.
   در نصب تازه جدول‌ها ساخته و پایش زمان‌بندی می‌شود؛ نخستین پایش فقط وضعیت
   اولیه را ثبت می‌کند و بابت محصولات ازقبل موجود پیام نمی‌فرستد.

## ۳) سه تنظیمی که باید پر کنید

**الف) درگاه پیامک** — منوی «موجودیار → تنظیمات → پیامک»:
نام پنل پیامکی، نام کاربری و رمز (یا کلید API)، و شمارهٔ خط ارسال.
اگر پنل شما الگو (bodyId) دارد، شناسهٔ الگو را هم وارد کنید.

> مهم: در پنل پیامکی خودتان، الگو باید **تأیید شده** باشد. متن الگو را با
> همان چیزی که در تنظیمات می‌نویسید یکی نگه دارید و شمارهٔ خط را درست وارد کنید.

**ب) کانال خبر دادن** — «تنظیمات → فرم»: پیامک، ایمیل یا هر دو.
تأیید دومرحله‌ای پیش‌فرض خاموش است؛ اگر روشنش کنید مشتری باید یک بار دیگر
تأیید کند.

**ج) زمان‌بندی** — صف برای اجرای هر ۵ دقیقه زمان‌بندی می‌شود، اما اجرای
واقعی به WP-Cron سایت و تنظیمات هاست بستگی دارد. در صورت تأخیر، وضعیت cron و
صف را بررسی کنید.

## ۴) محل فرم و آزمون پیش از فروش

فرم روی صفحهٔ محصول ناموجود نزدیک پیام ناموجود قرار می‌گیرد. برای جای دلخواه،
در بلوک شورت‌کد یا محتوای همان محصول بنویسید `[mojoodyar_form]`؛ در صفحهٔ دیگر
شناسهٔ محصول ناموجود را بدهید، مثلاً `[mojoodyar_form product_id="123"]`.
فرمِ شورت‌کدِ همان محصول جای فرم خودکار را می‌گیرد. شورت‌کد را بیرون فرم
خرید یا هر فرم HTML دیگر بگذارید. فرم متغیر انتخاب‌گر مستقل تنوع ناموجود دارد؛
جاوااسکریپت و `wp_footer` قالب برای جای‌گذاری خودکار و ثبت لازم‌اند.

۱. یک محصول آزمایشی ناموجود بسازید و صفحه‌اش را باز کنید.
۲. با ایمیل یا شمارهٔ خودتان ثبت کنید و ردیف را در «لیست انتظارها» ببینید.
۳. موجودی همان محصول یا تنوع را به ۱ برگردانید؛ سپس در «ابزارها» پایش و
   پردازش صف را اجرا کنید و دریافت را بررسی کنید.

ارسال وابسته به صف، WP-Cron و تنظیمات درگاه/SMTP است و زمان رسیدن تضمین نیست.
اگر پیام نرسید، **موجودیار → گزارش رویدادها** را باز کنید: هر ردیف توضیح
فارسی و «لاگ اصلی» دارد؛ پیش از ارسال لاگ به پشتیبانی، اطلاعات شخصی را حذف کنید.

## ۵) اگر کار نکرد

| نشانه | کار |
|---|---|
| فرم روی محصول ناموجود نیست | ابتدا وضعیت انتشار/موجودی و جاوااسکریپت را بررسی و کش صفحه و فایل‌ها را پاک کنید؛ شورت‌کد و `wp_footer` را طبق `docs/TROUBLESHOOTING.md` چک کنید |
| پیامک نمی‌رسد | «گزارش رویدادها» ← خطای درگاه ← اعتبار پنل و تأیید الگو |
| صف «خالی» است ولی منتظر دارید | ابزارها ← «آزادکردن صفِ گیرکرده» |
| با گذشت زمان چیزی ارسال نمی‌شود | وضعیت WP-Cron، زمان‌بندی و درگاه را بررسی کنید؛ در صورت نیاز کرون سرور تنظیم کنید |

## ۶) حذف

**افزونه‌ها → حذف**. داده‌ها (لیست انتظار، لاگ) به‌صورت پیش‌فرض **پاک
نمی‌شوند**؛ اگر می‌خواهید پاک شوند، در «تنظیمات → پیشرفته» گزینه‌اش را روشن
کنید. این انتخاب عمدی است: حذف اتفاقی دادهٔ مشتری قابل بازگشت نیست.
"""


def info_file(root: str, zip_path: str, version: str) -> str:
    size = os.path.getsize(zip_path)
    digest = hashlib.sha256(open(zip_path, "rb").read()).hexdigest()
    with zipfile.ZipFile(zip_path) as archive:
        names = [n for n in archive.namelist() if not n.endswith("/")]
    return f"""اطلاعات فایل برای فرم ارسال — موجودیار {version}

نام فایل       : {os.path.basename(zip_path)}
نسخه           : {version}
حجم            : {size:,} بایت ({size / 1024:.1f} کیلوبایت)
تعداد فایل     : {len(names)}
SHA-256        : {digest}

نسخهٔ وردپرس    : ۶.۴ یا بالاتر
نسخهٔ PHP      : ۷.۴ تا ۸.۳ (هارنس PHP-WASM روی ۷.۴.۳۳ و ۸.۳.۳۶)
ووکامرس        : ۹.۰ یا بالاتر (آزمون محدود Playground با ۹.۰.۰؛ جزئیات در گزارش آزمون)
پروانه         : GPLv2 یا بالاتر
کتابخانهٔ خارجی: ندارد
تماس با سرور بیرونی: ندارد (به‌جز درگاه پیامک/ایمیل خود مشتری)

برای بررسی سلامت فایل در ویندوز:
    certutil -hashfile {os.path.basename(zip_path)} SHA256

در لینوکس/مک:
    shasum -a 256 {os.path.basename(zip_path)}
"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="only report the current bundle")
    parser.add_argument("--plugin-dir", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    args = parser.parse_args()

    root = os.path.abspath(args.plugin_dir)
    version = plugin_version(root)
    if not version:
        print("cannot read plugin version", file=sys.stderr)
        return 2

    release = os.path.join(os.path.dirname(root), "release")
    zip_path = os.path.join(release, f"{PLUGIN_SLUG}-{version}.zip")
    if not os.path.exists(zip_path):
        print(f"missing bundle: {zip_path}\nrun tools/verify-release.py --build first", file=sys.stderr)
        return 2

    out = os.path.join(release, OUT_NAME)
    os.makedirs(out, exist_ok=True)

    if not args.check:
        shutil.copy2(zip_path, os.path.join(out, os.path.basename(zip_path)))

        with open(os.path.join(out, "راهنمای-نصب.md"), "w", encoding="utf-8") as handle:
            handle.write(install_guide(root, version))

        font_license = os.path.join(root, "assets", "fonts", "OFL.txt")
        if os.path.exists(font_license):
            shutil.copy2(font_license, os.path.join(out, "مجوز-فونت.txt"))

        with open(os.path.join(out, "اطلاعات-فایل.txt"), "w", encoding="utf-8") as handle:
            handle.write(info_file(root, zip_path, version))

        with zipfile.ZipFile(zip_path) as archive:
            names = sorted(n for n in archive.namelist() if not n.endswith("/"))
        with open(os.path.join(out, "فهرست-فایل‌ها.txt"), "w", encoding="utf-8") as handle:
            handle.write(f"# موجودیار {version} — {len(names)} فایل\n\n")
            for name in names:
                handle.write(f"{name}\n")

    print(f"=== بستهٔ ارسال ({version}) ===")
    total = 0
    for name in sorted(os.listdir(out)):
        path = os.path.join(out, name)
        total += os.path.getsize(path)
        print(f"  {name:<40} {os.path.getsize(path):>10,} بایت")
    print(f"  {'مجموع':<40} {total:>10,} بایت")
    print(f"\nمسیر: {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
