#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
سازندهٔ نسخهٔ نمایشی گزارش APEX Ω∞
فایل‌های Markdown را به یک سایت تک‌صفحه‌ای فارسی/RTL با فهرست جانبی تبدیل می‌کند.

بدون وابستگی خارجی. اجرا:
    python3 research/site/build.py
خروجی:
    research/site/index.html      — صفحهٔ اصلی (پیوند به گزارش و شواهد)
    research/site/report.html     — گزارش کامل ۱۶ بخشی
    research/site/evidence.html   — دفترچهٔ شواهد
"""

import io
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
SITE = os.path.join(ROOT, "research", "site")
DATA = os.path.join(ROOT, "research")

FONT = ("https://fonts.googleapis.com/css2"
        "?family=Vazirmatn:wght@400;500;700&display=swap")

CSS = """
:root{
  --bg:#0d1117; --panel:#151b23; --panel2:#1b232d; --line:#2a3441;
  --fg:#e6edf3; --mut:#9fb0c0; --acc:#4ea1ff; --acc2:#7ee787;
  --warn:#f0b429; --bad:#ff7b72; --code:#1f2933;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
  margin:0;background:var(--bg);color:var(--fg);
  font-family:Vazirmatn,'Segoe UI',Tahoma,'Noto Sans Arabic',sans-serif;
  font-size:15.5px;line-height:2.05;
}
a{color:var(--acc);text-decoration:none}
a:hover{text-decoration:underline}
code{
  font-family:'SFMono-Regular',Consolas,'Liberation Mono',monospace;
  background:var(--code);padding:.1em .4em;border-radius:4px;
  font-size:.86em;direction:ltr;unicode-bidi:embed;color:#a5d6ff;
}
.layout{display:flex;align-items:flex-start;gap:0}
/* ---- فهرست جانبی ---- */
.toc{
  position:sticky;top:0;width:330px;flex:0 0 330px;height:100vh;overflow-y:auto;
  background:var(--panel);border-inline-start:1px solid var(--line);
  padding:22px 18px 60px;
}
.toc h2{font-size:14px;margin:0 0 4px;color:var(--acc2);letter-spacing:.02em}
.toc .sub{font-size:11.5px;color:var(--mut);margin-bottom:16px;line-height:1.7}
.toc ol{list-style:none;margin:0;padding:0;counter-reset:s}
.toc li{counter-increment:s;margin:0}
.toc li a{
  display:block;padding:7px 10px;border-radius:7px;color:var(--mut);
  font-size:13px;line-height:1.75;border-inline-start:2px solid transparent;
}
.toc li a:hover{background:var(--panel2);color:var(--fg);text-decoration:none}
.toc li a.on{color:#fff;background:var(--panel2);border-inline-start-color:var(--acc)}
.toc .tools{margin-top:20px;padding-top:16px;border-top:1px solid var(--line);
  display:flex;flex-wrap:wrap;gap:8px}
.toc .tools a{
  font-size:12px;padding:6px 11px;border:1px solid var(--line);border-radius:20px;
  color:var(--fg);background:var(--panel2)
}
.toc .tools a:hover{border-color:var(--acc);text-decoration:none}
/* ---- محتوا ---- */
main{flex:1 1 auto;min-width:0;padding:34px 46px 120px;max-width:1180px}
.doc{background:var(--panel);border:1px solid var(--line);border-radius:14px;
  padding:34px 38px 46px;margin-bottom:26px}
h1{font-size:27px;line-height:1.6;margin:.2em 0 .8em;color:#fff;
  padding-bottom:.45em;border-bottom:2px solid var(--line)}
h2{font-size:21px;line-height:1.65;margin:1.9em 0 .8em;color:var(--acc);
  padding-bottom:.35em;border-bottom:1px solid var(--line);scroll-margin-top:16px}
h3{font-size:17px;line-height:1.7;margin:1.6em 0 .6em;color:var(--acc2);
  scroll-margin-top:16px}
h4{font-size:15.5px;margin:1.3em 0 .5em;color:#fff}
p{margin:.75em 0}
ul,ol{margin:.7em 0;padding-inline-start:1.55em}
li{margin:.34em 0}
li::marker{color:var(--acc)}
hr{border:0;border-top:1px solid var(--line);margin:2.2em 0}
strong{color:#fff;font-weight:700}
em{color:var(--warn);font-style:normal}
blockquote{
  margin:1.1em 0;padding:.75em 1.15em;background:var(--panel2);
  border-inline-start:3px solid var(--acc);border-radius:0 8px 8px 0;color:#dfe9f2
}
blockquote p{margin:.35em 0}
pre{
  background:var(--code);border:1px solid var(--line);border-radius:10px;
  padding:16px 18px;overflow-x:auto;direction:ltr;text-align:left;
  font-size:13px;line-height:1.75
}
pre code{background:none;padding:0;color:#c9d1d9;font-size:13px}
/* ---- جدول ---- */
.tw{overflow-x:auto;margin:1.1em 0;border:1px solid var(--line);border-radius:10px}
table{border-collapse:collapse;width:100%;font-size:13.4px;line-height:1.85}
th,td{padding:9px 12px;border-bottom:1px solid var(--line);
  text-align:right;vertical-align:top}
th{background:var(--panel2);color:#fff;font-weight:700;white-space:nowrap}
tbody tr:nth-child(even){background:rgba(255,255,255,.022)}
tbody tr:hover{background:rgba(78,161,255,.075)}
td:first-child{font-weight:500}
/* ---- نشان‌ها ---- */
.badge{display:inline-block;font-size:11.5px;padding:2px 9px;border-radius:20px;
  border:1px solid var(--line);background:var(--panel2);color:var(--mut)}
.top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:22px}
.kicker{font-size:12px;color:var(--mut);letter-spacing:.04em}
@media(max-width:1000px){
  .layout{display:block}
  .toc{position:static;width:auto;height:auto;flex:none;
    border-inline-start:0;border-bottom:1px solid var(--line)}
  main{padding:20px 14px 80px}
  .doc{padding:20px 16px 30px;border-radius:10px}
}
@media print{
  body{background:#fff;color:#000;font-size:11pt}
  .toc,.tools{display:none}
  main{padding:0;max-width:none}
  .doc{border:0;background:#fff;padding:0}
  h1,h2,h3,strong{color:#000}
  a{color:#000;text-decoration:underline}
  th,td{border-color:#bbb}
  blockquote,pre,.tw{border-color:#bbb;background:#f6f6f6}
}
"""

JS = """
// برجسته‌سازی بخش فعال در فهرست
(function(){
  var links=[].slice.call(document.querySelectorAll('.toc li a[href^="#"]'));
  if(!links.length) return;
  var map={};
  links.forEach(function(a){var id=a.getAttribute('href').slice(1);
    var el=document.getElementById(id); if(el) map[id]=a;});
  var ids=Object.keys(map);
  function on(){
    var best=ids[0],by=-1e9;
    ids.forEach(function(id){
      var r=document.getElementById(id).getBoundingClientRect();
      if(r.top<=140 && r.top>by){by=r.top;best=id;}
    });
    links.forEach(function(a){a.classList.remove('on')});
    if(map[best]){map[best].classList.add('on');
      map[best].scrollIntoView({block:'nearest'});}
  }
  window.addEventListener('scroll',on,{passive:true});
  window.addEventListener('resize',on);on();
})();
"""


def esc(t):
    return (t.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def inline(t):
    """تبدیل نشانه‌گذاری درون‌خطی؛ ابتدا code span ها محافظت می‌شوند."""
    store = []

    def keep(m):
        store.append(m.group(2))
        return "\x00%d\x00" % (len(store) - 1)

    t = re.sub(r"(`+)(.+?)\1", keep, t, flags=re.S)
    t = esc(t)
    # پیوند [متن](نشانی)
    t = re.sub(r"\[([^\]]+)\]\((https?://[^)\s]+)\)",
               r'<a href="\2" target="_blank" rel="noopener">\1</a>', t)
    # نشانی برهنه داخل جدول منابع
    t = re.sub(r"(?<!href=\")(?<!\">)\b(https?://[^\s<)\]]+)",
               r'<a href="\1" target="_blank" rel="noopener">\1</a>', t)
    t = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", t, flags=re.S)
    t = re.sub(r"(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)", r"<em>\1</em>",
               t, flags=re.S)

    def back(m):
        return "<code>%s</code>" % esc(store[int(m.group(1))])

    return re.sub(r"\x00(\d+)\x00", back, t)


def slug(n, text):
    s = re.sub(r"[^\w\u0600-\u06FFـ\s-]", "", text)
    s = re.sub(r"\s+", "-", s.strip())[:60]
    return "s%d-%s" % (n, s) or "s%d" % n


SEP_RE = re.compile(r"^[\s|:\-]+$")


def is_sep(ln):
    """آیا این خط جداکنندهٔ سرستون‌های جدول است؟ (الگوی خطی، بدون backtracking)"""
    t = ln.strip()
    if not t.startswith("|") or t.count("|") < 2 or "-" not in t:
        return False
    return bool(SEP_RE.match(t))


def render(md):
    """تبدیل Markdown به HTML و بازگرداندن (html, toc)."""
    lines = md.replace("\r\n", "\n").split("\n")
    out, toc = [], []
    i, n = 0, 0
    while i < len(lines):
        ln = lines[i]

        # بلوک کد
        if ln.strip().startswith("```"):
            buf, i = [], i + 1
            while i < len(lines) and not lines[i].strip().startswith("```"):
                buf.append(lines[i]); i += 1
            i += 1
            out.append("<pre><code>%s</code></pre>" % esc("\n".join(buf)))
            continue

        # جدول
        if ln.lstrip().startswith("|") and i + 1 < len(lines) \
                and is_sep(lines[i + 1]):
            head = [c.strip() for c in ln.strip().strip("|").split("|")]
            rows, i = [], i + 2
            while i < len(lines) and lines[i].lstrip().startswith("|"):
                rows.append([c.strip()
                             for c in lines[i].strip().strip("|").split("|")])
                i += 1
            w = len(head)
            rows = [(r + [""] * w)[:w] for r in rows]
            t = ['<div class="tw"><table><thead><tr>']
            t += ["<th>%s</th>" % inline(c) for c in head]
            t.append("</tr></thead><tbody>")
            for r in rows:
                t.append("<tr>" + "".join(
                    "<td>%s</td>" % (inline(c) if c else "&nbsp;")
                    for c in r) + "</tr>")
            t.append("</tbody></table></div>")
            out.append("".join(t))
            continue

        # سرنویس
        m = re.match(r"^(#{1,6})\s+(.*)$", ln)
        if m:
            lv, txt = len(m.group(1)), m.group(2).strip()
            n += 1
            sid = slug(n, txt)
            plain = re.sub(r"[`*]", "", txt)
            if lv in (1, 2, 3):
                toc.append((lv, sid, plain))
            out.append('<h%d id="%s">%s</h%d>' % (lv, sid, inline(txt), lv))
            i += 1
            continue

        # خط جدا
        if re.match(r"^\s*(-{3,}|\*{3,})\s*$", ln):
            out.append("<hr>"); i += 1; continue

        # نقل‌قول
        if ln.lstrip().startswith(">"):
            buf = []
            while i < len(lines) and lines[i].lstrip().startswith(">"):
                buf.append(re.sub(r"^\s*>\s?", "", lines[i])); i += 1
            out.append("<blockquote>%s</blockquote>"
                       % "".join("<p>%s</p>" % inline(b)
                                 for b in buf if b.strip()))
            continue

        # فهرست
        m = re.match(r"^(\s*)([-*+]|\d+[.)])\s+(.*)$", ln)
        if m:
            ordered = m.group(2)[0].isdigit()
            tag = "ol" if ordered else "ul"
            items = []
            while i < len(lines):
                mm = re.match(r"^(\s*)([-*+]|\d+[.)])\s+(.*)$", lines[i])
                if not mm or mm.group(2)[0].isdigit() != ordered:
                    break
                items.append(mm.group(3)); i += 1
            out.append("<%s>%s</%s>" % (
                tag, "".join("<li>%s</li>" % inline(x) for x in items), tag))
            continue

        # پاراگراف
        if ln.strip():
            buf = [ln]
            i += 1
            while i < len(lines) and lines[i].strip() \
                    and not re.match(r"^\s*(#{1,6}|[-*+]|\d+[.)]|>|\||```)",
                                     lines[i]) \
                    and not re.match(r"^\s*(-{3,}|\*{3,})\s*$", lines[i]):
                buf.append(lines[i]); i += 1
            out.append("<p>%s</p>" % inline(" ".join(x.strip() for x in buf)))
            continue
        i += 1

    return "\n".join(out), toc


def page(title, kicker, body, toc, tools):
    nav = ['<nav class="toc"><h2>APEX Ω∞ 1.0</h2>'
           '<div class="sub">%s</div>' % esc(kicker)]
    if toc:
        nav.append("<ol>")
        for lv, sid, txt in toc:
            pad = "" if lv <= 2 else "style=\"padding-inline-start:%dpx\"" % (
                (lv - 2) * 13)
            nav.append('<li><a href="#%s" %s>%s</a></li>'
                       % (sid, pad, esc(txt)))
        nav.append("</ol>")
    nav.append('<div class="tools">%s</div></nav>' % tools)

    return """<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>%s</title>
<meta name="description" content="گزارش تحقیق بازار و انتخاب فرصت محصول افزونه وردپرس — اتحاد وردپرس">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="%s" rel="stylesheet">
<style>%s</style>
</head>
<body>
<div class="layout">
%s
<main>
<div class="top">
  <span class="badge">۳ آبان ۱۴۰۵</span>
  <span class="badge">داده‌ها مشاهده‌شده در ۲۰۲۶-۱۰-۲۵</span>
  <span class="badge">اتحاد وردپرس / EtehadWP</span>
  <span class="kicker">نسخهٔ نمایشی — متن کامل در ریپوی GitHub</span>
</div>
<div class="doc">
%s
</div>
</main>
</div>
<script>%s</script>
</body>
</html>""" % (esc(title), FONT, CSS, "".join(nav), body, JS)


def read(p):
    with io.open(p, encoding="utf-8") as f:
        return f.read()


def main():
    if not os.path.isdir(SITE):
        os.makedirs(SITE)

    docs = [("report.html", "گزارش نهایی APEX Ω∞",
             os.path.join(DATA, "APEX-report.md")),
            ("evidence.html", "دفترچهٔ شواهد",
             os.path.join(DATA, "data", "evidence-log.md")),
            ("brand.html", "بستهٔ برند نویدبان",
             os.path.join(DATA, "brand", "BRAND.md")),
            ("build-prompt.html", "پرامپت ساخت نویدبان",
             os.path.join(DATA, "build-prompt.md"))]

    tools = ('<a href="index.html">🏠 خانه</a>'
             '<a href="report.html">📄 گزارش</a>'
             '<a href="evidence.html">🗂 شواهد</a>'
             '<a href="brand.html">🎨 برند</a>'
             '<a href="build-prompt.html">🛠 پرامپت ساخت</a>'
             '<a href="APEX-report.md" download>⬇ Markdown گزارش</a>'
             '<a href="BRAND.md" download>⬇ Markdown برند</a>'
             '<a href="build-prompt.md" download>⬇ Markdown پرامپت</a>'
             '<a href="#" onclick="window.print();return false">🖨 چاپ/PDF</a>')

    import shutil
    made = []
    for _, _, src in docs:
        if os.path.isfile(src):
            shutil.copyfile(src, os.path.join(SITE, os.path.basename(src)))
    for fn, title, src in docs:
        if not os.path.isfile(src):
            print("!! یافت نشد: %s" % src, file=sys.stderr)
            continue
        body, toc = render(read(src))
        if fn == "brand.html":
            logo = os.path.join(DATA, "brand", "logo", "navidban-logo.png")
            ban = os.path.join(DATA, "brand", "logo", "navidban-banner.png")
            if os.path.isfile(logo) and os.path.isfile(ban):
                shutil.copyfile(logo, os.path.join(SITE, "navidban-logo.png"))
                shutil.copyfile(ban, os.path.join(SITE, "navidban-banner.png"))
                body = body.replace("<h1>", """<div style="display:flex;flex-wrap:wrap;gap:22px;align-items:center;
margin:8px 0 26px"><img src="navidban-logo.png" alt="نشان نویدبان"
style="width:132px;height:132px;border-radius:26px;border:1px solid var(--line)">
<div style="flex:1 1 320px;min-width:260px"><img src="navidban-banner.png"
alt="زمینهٔ بنر نویدبان" style="width:100%;border-radius:14px;
border:1px solid var(--line);display:block">
<p style="color:var(--mut);font-size:12.5px;margin:8px 0 0">نشان خطی و زمینهٔ بنر
(تولید ۱۴۰۵-۰۸-۰۳). بنر نهایی ۱۰۵۰×۵۲۰ با تایپوگرافی وزیرمتن از
<code>research/brand/logo/banner-1050x520.html</code> صادر می‌شود.</p></div></div><h1>""", 1)
        head = re.search(r"^#\s+(.*)$", read(src), re.M)
        h1 = head.group(1).strip() if head else title
        body = "<h1>%s</h1>" % inline(h1) + re.sub(
            r"^<h1[^>]*>.*?</h1>", "", body, count=1, flags=re.S)
        with io.open(os.path.join(SITE, fn), "w", encoding="utf-8") as f:
            f.write(page("%s — اتحاد وردپرس" % h1, title, body, toc, tools))
        made.append(fn)
        print("✔ %-16s %6.1f KB  ·  %d سرفصل" % (
            fn, os.path.getsize(os.path.join(SITE, fn)) / 1024.0, len(toc)))

    # صفحهٔ اصلی
    rep = read(docs[0][2])
    ev = read(docs[1][2]) if os.path.isfile(docs[1][2]) else ""
    stats = [
        ("۱۶", "بخش گزارش، به همان ترتیب خواسته‌شده"),
        ("۲۲", "فرصت اولیهٔ شناسایی‌شده و غربال‌شده"),
        ("۵", "کارت تحلیلی با ۱۷ فیلد"),
        ("۶۰+", "محصول با سنجهٔ مستند از راست‌چین و ژاکت"),
        ("۸", "محدودیت دادهٔ ثبت‌شده (L1–L8)"),
        ("۶", "آزمون اعتبارسنجی پیش از کدنویسی"),
        ("۷", "نام ممیزی‌شده؛ «نویدبان» پاک شد"),
        ("۱۲", "مرحلهٔ پرامپت ساخت با دروازهٔ خروج"),
    ]
    cards = "".join(
        '<div class="doc" style="flex:1 1 260px;margin:0 0 18px">'
        '<h3 style="margin-top:0">%s</h3><p style="color:var(--mut);'
        'font-size:13.5px;margin:0">%s</p></div>' % (a, b) for a, b in stats)

    home = """<!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>APEX Ω∞ — نسخهٔ نمایشی گزارش</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="%s" rel="stylesheet"><style>%s
.hero{padding:40px 38px}
.hero h1{border:0;margin-bottom:.3em}
.hero .lead{color:var(--mut);font-size:15px;max-width:70ch}
.btns{display:flex;flex-wrap:wrap;gap:10px;margin:24px 0 6px}
.btns a{padding:11px 20px;border-radius:10px;border:1px solid var(--line);
background:var(--panel2);color:#fff;font-size:14px}
.btns a.p{background:var(--acc);border-color:var(--acc);color:#04121f;font-weight:700}
.btns a:hover{text-decoration:none;border-color:var(--acc)}
.grid{display:flex;flex-wrap:wrap;gap:18px;margin-top:8px}
.ans{background:linear-gradient(180deg,rgba(78,161,255,.10),transparent);
border-inline-start:3px solid var(--acc)}
</style></head><body><main style="max-width:1000px;margin:0 auto;padding:34px 20px 90px">
<div class="doc hero">
<div class="top"><span class="badge">Ω∞ 1.0</span><span class="badge">۳ آبان ۱۴۰۵</span>
<span class="badge">اتحاد وردپرس</span></div>
<h1>گزارش تحقیق بازار و انتخاب فرصت محصول</h1>
<p class="lead">خروجی موتور APEX Ω∞ برای بازار افزونهٔ وردپرس فارسی —
کشف، اعتبارسنجی، رتبه‌بندی و انتخاب یک فرصت محصول، بر پایهٔ شواهد قابل
ارجاع از راست‌چین، ژاکت و wordpress.org.</p>
<div class="btns">
<a class="p" href="report.html">📄 گزارش کامل (۱۶ بخش)</a>
<a href="evidence.html">🗂 دفترچهٔ شواهد</a>
<a class="p" href="brand.html">🎨 بستهٔ برند «نویدبان»</a>
<a class="p" href="build-prompt.html">🛠 پرامپت ساخت</a>
<a href="APEX-report.md" download>⬇ Markdown گزارش</a>
<a href="BRAND.md" download>⬇ Markdown برند</a>
<a href="build-prompt.md" download>⬇ Markdown پرامپت</a>
<a href="evidence-log.md" download>⬇ Markdown شواهد</a>
<a href="#" onclick="window.print();return false">🖨 چاپ / PDF</a>
</div>
</div>

<div class="doc ans">
<h3 style="margin-top:0">پاسخ کوتاه</h3>
<p>اگر امروز فقط یک افزونهٔ جدید برای بازار وردپرس فارسی ساخته شود:
<strong>«نویدبان» — مرکز اعلان و لیست انتظار بومی، سبک و امن برای ووکامرس</strong>،
برای فروشگاه‌های با موجودی متناوب و کسب‌وکارهای نوبتی.
امتیاز <strong>۷۷ از ۱۰۰</strong>، اطمینان <strong>متوسط</strong>،
مشروط به عبور از ۶ آزمون اعتبارسنجی ۱۴ روزهٔ پیش از کدنویسی.</p>
<p style="color:var(--mut);font-size:13.5px">نام نهایی در آزمون T5 پاک شد
(«موجودبان» در ممیزی رد شد — یک محصول زنده با همین نام در راست‌چین وجود دارد).
بستهٔ کامل برند و پرامپت ساخت خودبسنده در همین سایت منتشر شده‌اند.</p>
<p style="color:var(--mut);font-size:13.5px;margin-bottom:0">
جزئیات، استدلال، نقد خصمانه و برنامهٔ آزمون در بخش‌های ۱۰ تا ۱۴ گزارش.</p>
</div>

<div class="doc" style="margin-top:18px">
<h3 style="margin-top:0">چهار سند تحویلی</h3>
<div class="grid" style="margin-top:4px">
<div style="flex:1 1 300px;border:1px solid var(--line);border-radius:12px;padding:16px 18px;background:var(--panel2)">
<h4 style="margin:0 0 6px"><a href="report.html">📄 گزارش نهایی APEX Ω∞</a></h4>
<p style="color:var(--mut);font-size:13.5px;margin:0">۱۶ بخش + ۳ پیوست · کشف ۲۲ فرصت، غربال به ۵ کارت ۱۷فیلدی، امتیازدهی وزنی از ۱۰۰،
نقد خصمانه، طراحی MVP و انتخاب نهایی. شامل «پیوست پ» با اصلاحات پس از ممیزی نام.</p>
</div>
<div style="flex:1 1 300px;border:1px solid var(--line);border-radius:12px;padding:16px 18px;background:var(--panel2)">
<h4 style="margin:0 0 6px"><a href="evidence.html">🗂 دفترچهٔ شواهد</a></h4>
<p style="color:var(--mut);font-size:13.5px;margin:0">دادهٔ خام مشاهده‌شده با نشانی کامل، تاریخ دسترسی و نقل‌قول مستقیم.
هر ادعای گزارش به یک ردیف در این دفترچه برمی‌گردد.</p>
</div>
<div style="flex:1 1 300px;border:1px solid var(--line);border-radius:12px;padding:16px 18px;background:var(--panel2)">
<h4 style="margin:0 0 6px"><a href="brand.html">🎨 بستهٔ برند «نویدبان»</a></h4>
<p style="color:var(--mut);font-size:13.5px;margin:0">نام نهایی و ممیزی تداخل ۷ نام · جایگاه‌سازی · ۳ ستون تمایز · شعار · صدا و لحن ·
هویت بصری با پالت رنگ و مشخصات نشان · <strong>متن آمادهٔ کپی‌پیست صفحهٔ محصول مارکت</strong> ·
جدول نام‌های فنی · چک‌لیست پیش از لانچ.</p>
</div>
<div style="flex:1 1 300px;border:1px solid var(--line);border-radius:12px;padding:16px 18px;background:var(--panel2)">
<h4 style="margin:0 0 6px"><a href="build-prompt.html">🛠 پرامپت ساخت APEX Ω∞ BUILD 1.0</a></h4>
<p style="color:var(--mut);font-size:13.5px;margin:0">پرامپت خودبسندهٔ ساخت، آمادهٔ دادن به یک عامل کدنویس.
۱۰ نقش مهندسی · محدودیت‌های سخت · زمینهٔ بازار جاسازی‌شده ·
<strong>مرحلهٔ ۰ = دروازهٔ اعتبارسنجی پیش از هر خط کد</strong> · ۱۲ مرحله با دروازهٔ خروج ·
طرح کامل دیتابیس · ماتریس امنیت ۱۵ آیتمی · بودجهٔ عملکرد با الزام اثبات HAR · ۸ شرط توقف.</p>
</div>
</div>
</div>

<div class="grid">%s</div>

<div class="doc">
<h3 style="margin-top:0">راهنمای مطالعه</h3>
<ul>
<li><strong>فهرست جانبی</strong> در نسخهٔ گزارش، بخش فعال را هنگام پیمایش برجسته می‌کند.</li>
<li>نشانهٔ <strong>[D]</strong> = دادهٔ مستقیم، <strong>[I]</strong> = استنباط تحلیلی،
<strong>[H]</strong> = فرضیهٔ اثبات‌نشده. این سه در سراسر گزارش تفکیک شده‌اند.</li>
<li>عبارت <strong>«داده در دسترس نیست»</strong> در هر موردی که عدد یا شاهدی
قابل مشاهده نبود، بدون جایگزینی با برآورد، درج شده است.</li>
<li>محدودیت‌های دسترسی (L1 تا L8) در بخش ۲.۲ و منابع بررسی‌نشده در بخش ۱۶.۸
صریحاً فهرست شده‌اند.</li>
</ul>
</div>
</main></body></html>""" % (FONT, CSS, cards)

    with io.open(os.path.join(SITE, "index.html"), "w", encoding="utf-8") as f:
        f.write(home)
    made.append("index.html")
    print("✔ %-16s %6.1f KB" % ("index.html",
                                os.path.getsize(os.path.join(SITE, "index.html")) / 1024.0))
    print("\nپرونده‌های ساخته‌شده در research/site/: %s" % ", ".join(made))


if __name__ == "__main__":
    main()
