#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Build the Persian user guide PDF for Mojoodyar (for Zhaket marketplace).
ReportLab + arabic_reshaper + python-bidi. Brand: teal #0e5c55, amber #f2a93b.
"""
import os
import arabic_reshaper
from bidi.algorithm import get_display

from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_RIGHT, TA_CENTER, TA_JUSTIFY
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
    Image, PageBreak, KeepTogether, HRFlowable, ListFlowable, ListItem,
)

ROOT = os.path.dirname(os.path.abspath(__file__))
FONTS = '/tmp/fonts'
ASSETS = os.path.join(ROOT, 'guide-assets')
OUT = os.path.join(ROOT, 'mojoodyar-user-guide-fa.pdf')

TEAL = HexColor('#0e5c55')
TEAL_DARK = HexColor('#0a4741')
TEAL_LIGHT = HexColor('#14857b')
AMBER = HexColor('#f2a93b')
INK = HexColor('#14241f')
INK_SOFT = HexColor('#5b6b66')
BG = HexColor('#eef4f2')
LINE = HexColor('#d7e4e0')
WARN_BG = HexColor('#fff8ec')
WARN_LINE = HexColor('#f6dcae')
WARN_INK = HexColor('#8a5a10')
OK_BG = HexColor('#e2f6ec')
OK_INK = HexColor('#10664a')

pdfmetrics.registerFont(TTFont('Vazirmatn', os.path.join(FONTS, 'Vazirmatn-Regular.ttf')))
pdfmetrics.registerFont(TTFont('Vazirmatn-Med', os.path.join(FONTS, 'Vazirmatn-Medium.ttf')))
pdfmetrics.registerFont(TTFont('Vazirmatn-Bold', os.path.join(FONTS, 'Vazirmatn-Bold.ttf')))
pdfmetrics.registerFontFamily('Vazirmatn', normal='Vazirmatn', bold='Vazirmatn-Bold', italic='Vazirmatn')

_reshaper = arabic_reshaper.ArabicReshaper(configuration={
    'delete_harakat': False, 'support_ligatures': True,
})

def fa(text):
    """Shape + bidi-order Persian text for ReportLab."""
    if text is None:
        return ''
    return get_display(_reshaper.reshape(str(text)))

def P(text, style='body'):
    return Paragraph(fa(text), STYLES[style])

def B(text):
    """Bold inline Persian."""
    return f'<b>{fa(text)}</b>'

def LTR(text):
    """Left-to-right snippet (code / shortcode / URL) inside RTL text."""
    return f'<font face="Vazirmatn">{text}</font>'

STYLES = {
    'body': ParagraphStyle('body', fontName='Vazirmatn', fontSize=11.5, leading=21,
                           alignment=TA_JUSTIFY, textColor=INK, spaceAfter=6),
    'body_r': ParagraphStyle('body_r', fontName='Vazirmatn', fontSize=11.5, leading=21,
                             alignment=TA_RIGHT, textColor=INK, spaceAfter=6),
    'small': ParagraphStyle('small', fontName='Vazirmatn', fontSize=9.5, leading=17,
                            alignment=TA_RIGHT, textColor=INK_SOFT, spaceAfter=4),
    'h1': ParagraphStyle('h1', fontName='Vazirmatn-Bold', fontSize=19, leading=28,
                         alignment=TA_RIGHT, textColor=TEAL, spaceBefore=16, spaceAfter=10),
    'h2': ParagraphStyle('h2', fontName='Vazirmatn-Bold', fontSize=14.5, leading=23,
                         alignment=TA_RIGHT, textColor=TEAL_DARK, spaceBefore=12, spaceAfter=7),
    'h3': ParagraphStyle('h3', fontName='Vazirmatn-Bold', fontSize=12.5, leading=20,
                         alignment=TA_RIGHT, textColor=TEAL, spaceBefore=10, spaceAfter=5),
    'bullet': ParagraphStyle('bullet', fontName='Vazirmatn', fontSize=11.5, leading=21,
                             alignment=TA_RIGHT, textColor=INK, spaceAfter=3,
                             rightIndent=14, firstLineIndent=0, bulletIndent=6),
    'cell': ParagraphStyle('cell', fontName='Vazirmatn', fontSize=10.5, leading=18,
                           alignment=TA_RIGHT, textColor=INK),
    'cell_b': ParagraphStyle('cell_b', fontName='Vazirmatn-Bold', fontSize=10.5, leading=18,
                             alignment=TA_RIGHT, textColor=TEAL_DARK),
    'cell_c': ParagraphStyle('cell_c', fontName='Vazirmatn', fontSize=10.5, leading=18,
                             alignment=TA_CENTER, textColor=INK),
    'code': ParagraphStyle('code', fontName='Vazirmatn', fontSize=10.5, leading=19,
                           alignment=TA_RIGHT, textColor=TEAL_DARK),
    'toc': ParagraphStyle('toc', fontName='Vazirmatn', fontSize=12, leading=24,
                          alignment=TA_RIGHT, textColor=INK),
    'cover_title': ParagraphStyle('cover_title', fontName='Vazirmatn-Bold', fontSize=34,
                                  leading=44, alignment=TA_CENTER, textColor=white),
    'cover_sub': ParagraphStyle('cover_sub', fontName='Vazirmatn', fontSize=15, leading=26,
                                alignment=TA_CENTER, textColor=white),
    'cover_meta': ParagraphStyle('cover_meta', fontName='Vazirmatn', fontSize=11.5, leading=20,
                                 alignment=TA_CENTER, textColor=HexColor('#cfe6e1')),
}

def bullets(items, style='bullet'):
    lis = [ListItem(Paragraph(fa(t), STYLES[style]), leftIndent=16, value='•') for t in items]
    return ListFlowable(lis, bulletType='bullet', start='•', leftIndent=16,
                        bulletFontName='Vazirmatn', bulletFontSize=11)

def numbered(items, style='bullet'):
    lis = [ListItem(Paragraph(fa(t), STYLES[style]), leftIndent=20) for t in items]
    return ListFlowable(lis, bulletType='1', leftIndent=20)

def callout(text, kind='warn'):
    bg, line, ink = (WARN_BG, WARN_LINE, WARN_INK) if kind == 'warn' else (OK_BG, HexColor('#b7e6cf'), OK_INK)
    inner = [[Paragraph(fa(text), ParagraphStyle('co', fontName='Vazirmatn', fontSize=10.5,
                                                  leading=19, alignment=TA_RIGHT, textColor=ink))]]
    t = Table(inner, colWidths=[170 * mm])
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), bg),
        ('BOX', (0, 0), (-1, -1), 0.8, line),
        ('LINEBELOW', (0, 0), (-1, 0), 0, bg),
        ('LEFTPADDING', (0, 0), (-1, -1), 10),
        ('RIGHTPADDING', (0, 0), (-1, -1), 10),
        ('TOPPADDING', (0, 0), (-1, -1), 7),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 7),
        ('ROUNDEDCORNERS', [4, 4, 4, 4]),
    ]))
    return t

def table(headers, rows, widths=None, align_center_cols=()):
    data = [[Paragraph(fa(h), STYLES['cell_b']) for h in headers]]
    for r in rows:
        row = []
        for i, c in enumerate(r):
            st = 'cell_c' if i in align_center_cols else 'cell'
            row.append(Paragraph(fa(c), STYLES[st]))
        data.append(row)
    t = Table(data, colWidths=widths, repeatRows=1)
    style = [
        ('BACKGROUND', (0, 0), (-1, 0), HexColor('#e6f0ee')),
        ('LINEBELOW', (0, 0), (-1, 0), 1, TEAL),
        ('GRID', (0, 0), (-1, -1), 0.4, LINE),
        ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
        ('LEFTPADDING', (0, 0), (-1, -1), 7),
        ('RIGHTPADDING', (0, 0), (-1, -1), 7),
        ('TOPPADDING', (0, 0), (-1, -1), 5),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
        ('ROWBACKGROUNDS', (0, 1), (-1, -1), [white, HexColor('#f7fbfa')]),
    ]
    t.setStyle(TableStyle(style))
    return t

def code_block(text):
    inner = [[Paragraph(fa(text), STYLES['code'])]]
    t = Table(inner, colWidths=[170 * mm])
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), HexColor('#f2f7f5')),
        ('BOX', (0, 0), (-1, -1), 0.6, LINE),
        ('LEFTPADDING', (0, 0), (-1, -1), 10),
        ('RIGHTPADDING', (0, 0), (-1, -1), 10),
        ('TOPPADDING', (0, 0), (-1, -1), 7),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 7),
        ('ROUNDEDCORNERS', [4, 4, 4, 4]),
    ]))
    return t

def hr():
    return HRFlowable(width='100%', thickness=0.7, color=LINE, spaceBefore=8, spaceAfter=10)

# ---------------------------------------------------------------------------
# Page furniture
# ---------------------------------------------------------------------------
def on_page(canvas, doc):
    canvas.saveState()
    w, h = A4
    if doc.page > 1:
        canvas.setFont('Vazirmatn', 8.5)
        canvas.setFillColor(INK_SOFT)
        canvas.drawRightString(w - 18 * mm, h - 12 * mm, fa('راهنمای کاربری موجودیار — نسخهٔ ۱.۰.۰'))
        canvas.setStrokeColor(LINE)
        canvas.line(18 * mm, h - 14 * mm, w - 18 * mm, h - 14 * mm)
    canvas.setFont('Vazirmatn', 8.5)
    canvas.setFillColor(INK_SOFT)
    fa_digits = str(doc.page).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))
    canvas.drawCentredString(w / 2, 11 * mm, fa(f'صفحهٔ {fa_digits}'))
    canvas.restoreState()

def cover_page(canvas, doc):
    canvas.saveState()
    w, h = A4
    canvas.setFillColor(TEAL_DARK)
    canvas.rect(0, 0, w, h, stroke=0, fill=1)
    canvas.setFillColor(TEAL)
    canvas.rect(0, h - 6 * mm, w, 6 * mm, stroke=0, fill=1)
    canvas.setFillColor(AMBER)
    canvas.rect(0, 0, w, 6 * mm, stroke=0, fill=1)
    canvas.restoreState()

# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------
def build():
    doc = SimpleDocTemplate(OUT, pagesize=A4, rightMargin=18 * mm, leftMargin=18 * mm,
                            topMargin=20 * mm, bottomMargin=16 * mm,
                            title='راهنمای کاربری موجودیار ۱.۰.۰', author='اتحاد وردپرس')
    story = []
    W = 174 * mm  # usable width

    # ---- Cover -------------------------------------------------------------
    # Logo sits on a white card so the teal bell reads on the dark cover.
    logo_img = Image(os.path.join(ASSETS, 'mojoodyar-logo.png'), width=46 * mm, height=46 * mm)
    logo_card = Table([[logo_img]], colWidths=[62 * mm], rowHeights=[62 * mm])
    logo_card.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), white),
        ('ROUNDEDCORNERS', [10, 10, 10, 10]),
        ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
        ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
        ('LEFTPADDING', (0, 0), (-1, -1), 8 * mm),
        ('RIGHTPADDING', (0, 0), (-1, -1), 8 * mm),
        ('TOPPADDING', (0, 0), (-1, -1), 8 * mm),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 8 * mm),
    ]))
    logo_card.hAlign = 'CENTER'
    story.append(Spacer(1, 30 * mm))
    story.append(logo_card)
    story.append(Spacer(1, 10 * mm))
    story.append(Paragraph(fa('موجودیار'), STYLES['cover_title']))
    story.append(Spacer(1, 4 * mm))
    story.append(Paragraph(fa('لیست انتظار و اعلان بازگشت موجودی برای ووکامرس'), STYLES['cover_sub']))
    story.append(Spacer(1, 6 * mm))
    story.append(Paragraph(fa('راهنمای کامل کاربری — نسخهٔ ۱.۰.۰'), STYLES['cover_sub']))
    story.append(Spacer(1, 16 * mm))
    story.append(Paragraph(fa('نصب و راه‌اندازی · فرم «خبرم کن» و شورت‌کد · پیامک و ایمیل · پیگیری تلفنی · آمار و گزارش‌ها'),
                           STYLES['cover_meta']))
    story.append(Spacer(1, 6 * mm))
    story.append(Paragraph(fa('سازنده: اتحاد وردپرس'), STYLES['cover_meta']))
    story.append(Spacer(1, 10 * mm))
    # Zhaket marketplace logo on a white card (its grey/orange mark needs a light ground).
    zh_img = Image(os.path.join(ASSETS, 'zhaket-logo.png'), width=34 * mm, height=24.4 * mm)
    zh_card = Table([[zh_img]], colWidths=[46 * mm], rowHeights=[32 * mm])
    zh_card.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), white),
        ('ROUNDEDCORNERS', [8, 8, 8, 8]),
        ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
        ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
        ('LEFTPADDING', (0, 0), (-1, -1), 6 * mm),
        ('RIGHTPADDING', (0, 0), (-1, -1), 6 * mm),
        ('TOPPADDING', (0, 0), (-1, -1), 4 * mm),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 4 * mm),
    ]))
    zh_card.hAlign = 'CENTER'
    story.append(zh_card)
    story.append(Spacer(1, 3 * mm))
    story.append(Paragraph(fa('ویژهٔ عرضه در ژاکت'), STYLES['cover_meta']))
    story.append(PageBreak())

    # ---- TOC ---------------------------------------------------------------
    story.append(P('فهرست مطالب', 'h1'))
    toc_items = [
        '۱. موجودیار چیست و چه می‌کند؟',
        '۲. نصب و پیش‌نیازها',
        '۳. سه قدم نخست',
        '۴. جای فرم و شورت‌کد',
        '۵. تنظیمات',
        '      فرم',
        '      کانال‌ها (ایمیل و پیامک)',
        '      درگاه‌های پیامک',
        '      خطوط خدماتی: ارسال با «الگو»',
        '      تآیید دومرحله‌ای',
        '      نام و نام خانوادگی و پیگیری تلفنی',
        '      کدام فیلدها در فرم دیده شوند',
        '      «اعلام به تعداد موجودی»',
        '      صف ارسال و پیشرفته',
        '۶. کار روزمره: لیست انتظارها، داشبورد، گزارش‌ها، آمار و ابزارها',
        '۷. حریم خصوصی',
        '۸. پرسش‌های پرتکرار',
        '۹. پشتیبانی و اطلاعات نسخه',
    ]
    for t in toc_items:
        story.append(Paragraph(fa(t), STYLES['toc']))
    story.append(Spacer(1, 6 * mm))
    story.append(callout('این راهنما بر اساس نسخهٔ ۱.۰.۰ نوشته شده است. اگر جایی با متن داخل پنل فرق داشت، متن داخل پنل (موجودیار ← راهنما) ملاک است.', 'ok'))

    # ---- 1. What is Mojoodyar ---------------------------------------------
    story.append(P('۱. موجودیار چیست و چه می‌کند؟', 'h1'))
    story.append(P('موجودیار یک افزونهٔ وردپرس و ووکامرس است که وقتی کالایی تمام می‌شود، مشتری را از دست نمی‌دهد. مشتری شمارهٔ موبایل یا نشانی ایمیل خود را می‌گذارد و به‌محض بازگشت موجودی، خبر می‌گیرد. نتیجه این است که مشتریِ آمادهٔ خرید، به‌جای رفتن به رقیب، منتظر شما می‌ماند.'))
    story.append(P('امکانات اصلی', 'h2'))
    story.append(bullets([
        'فرم «خبرم کن» که به‌صورت خودکار زیر پیام «ناموجود» قرار می‌گیرد؛',
        'شورت‌کد برای قراردادن فرم در هر جای دلخواه (صفحهٔ محصول یا هر صفحهٔ دیگر)؛',
        'انتخاب‌گر مستقل «تنوع ناموجود» برای محصولات متغیر، با هم‌گام‌سازی با فرم خرید ووکامرس؛',
        'اعلان بازگشت موجودی با ایمیل و پیامک (ملی‌پیامک، آی‌پی‌پنل، کاوه‌نگار و درگاه عمومی REST)؛',
        'تآیید دومرحله‌ای اختیاری پیش از ارسال اعلان؛',
        'پیگیری تلفنی مرحله‌ای برای تماس با مشتریان در انتظار؛',
        'صف ارسال پس‌زمینه با سقف نرخ و بازهٔ زمانی مجاز؛',
        'پنل فارسی راست‌چین با تاریخ شمسی، ارقام فارسی، فیلتر و خروجی CSV؛',
        'بدون کلید لایسنس، بدون Composer و npm، بدون تماس اجباری با سرور بیرونی.',
    ]))
    story.append(callout('موجودیار هیچ درخواستی به سرور سازنده نمی‌فرستد و برای کارکردن به اینترنت یا کلید فعال‌سازی نیاز ندارد. فونت وزیرمتن داخل خودِ افزونه است و از CDN گرفته نمی‌شود.'))

    # ---- 2. Install --------------------------------------------------------
    story.append(P('۲. نصب و پیش‌نیازها', 'h1'))
    story.append(numbered([
        'فایل mojoodyar-1.0.0.zip را از بخش «افزونه‌ها ← افزودن ← بارگذاری افزونه» نصب کنید.',
        'افزونه را فعال کنید.',
        'تمام. فرم با کانال ایمیل، بدون هیچ تنظیمی کار می‌کند.',
    ]))
    story.append(P('پیش‌نیازها', 'h2'))
    story.append(table(['مورد', 'حداقل نسخه'], [
        ['PHP', '۷.۴ (روی ۸.۳ تست شده است)'],
        ['وردپرس', '۶.۴'],
        ['ووکامرس', '۹.۰'],
    ], widths=[60 * mm, 114 * mm]))

    # ---- 3. First steps ----------------------------------------------------
    story.append(P('۳. سه قدم نخست', 'h1'))
    story.append(numbered([
        'یک محصول را «ناموجود» کنید (محصول ← موجودی ← وضعیت: در انبار نیست).',
        'صفحهٔ آن محصول را در فروشگاه باز کنید؛ فرم «خبرم کن» معمولاً بلافاصله زیر پیام ناموجود قرار می‌گیرد. اگر قالب آن پیام را تولید نکند، جای جایگزین در خلاصهٔ محصول یا پاورقی است.',
        'از مسیر «موجودیار ← ابزارها ← ارسال ایمیل آزمایشی» رسیدن ایمیل را بررسی کنید.',
    ]))

    # ---- 4. Placement & shortcode -----------------------------------------
    story.append(P('۴. جای فرم و شورت‌کد', 'h1'))
    story.append(P('نمایش خودکار برای صفحهٔ محصول منتشرشدهٔ ناموجود یا محصول متغیری است که دست‌کم یک تنوع ناموجودِ فعال و قابل نمایش دارد. حالت پیش‌سفارش ووکامرس معمولاً «موجود» حساب می‌شود. شورت‌کد نیز همین شرط‌های موجودی، انتشار و دسترسی را دارد؛ برای محصول موجود یا شناسهٔ نامعتبر خروجی نمی‌دهد.'))
    story.append(bullets([
        'در محتوای همان صفحهٔ محصول: [mojoodyar_form]',
        'در صفحهٔ دیگر، با شناسهٔ محصول والد: [mojoodyar_form product_id="123"]',
        'id نام جایگزین است: [mojoodyar_form id="123"]. شناسه را از فهرست محصولات بردارید؛ برای محصول متغیر شناسهٔ والد را بنویسید، نه شناسهٔ تنوع.',
        'در قالب فرزند، خارج از هر فرم HTML دیگر: do_shortcode(\'[mojoodyar_form]\')',
    ]))
    story.append(P('اگر شورت‌کدِ همان محصول روی صفحه باشد، به‌جای فرم خودکار قرار می‌گیرد؛ چند شورت‌کدی که خودتان عمداً گذاشته‌اید حذف نمی‌شوند. شورت‌کد را داخل فرم خرید، تسویه یا هیچ تگ form دیگری نگذارید. نمایش خودکار به wp_footer و جاوااسکریپت نیاز دارد؛ ثبت فروشگاهی بدون جاوااسکریپت انجام نمی‌شود و دکمه غیرفعال می‌ماند.'))
    story.append(P('محصول متغیر', 'h2'))
    story.append(P('برای محصول متغیر، انتخاب‌گر مستقل «تنوع ناموجود» در فرم هست؛ اگر فرم خرید همان محصول انتخاب تنوع را اعلام کند، انتخاب‌ها هم‌گام می‌شوند. وقتی فروشگاه «پنهان‌کردن محصولات ناموجود» را در ووکامرس روشن کرده باشد، تنوع ناموجود در انتخاب‌گر انتظار همچنان قابل انتخاب است، اما تنظیم ووکامرس و گزینه‌های خرید عوض نمی‌شوند. تنوع خصوصی، غیرفعال یا نامریی طبق سیاست ووکامرس نمایش داده نمی‌شود. اگر گزینه‌ای تازه موجود یا ناموجود شده، صفحه و کش را تازه کنید.'))

    # ---- 5. Settings -------------------------------------------------------
    story.append(P('۵. تنظیمات', 'h1'))
    story.append(P('همهٔ تنظیمات از مسیر «موجودیار ← تنظیمات» انجام می‌شود. در ادامه، هر بخش را جداگانه توضیح می‌دهیم.'))

    story.append(P('۵.۱. فرم', 'h2'))
    story.append(bullets([
        'عنوان / متن معرفی / متن دکمه: همان چیزی که مشتری می‌بیند.',
        'رضایت اجباری: اگر روشن باشد، چک‌باکس رضایت اجباری می‌شود و زمان رضایت ذخیره می‌گردد. پیش‌فرض: خاموش.',
        'سیاست حریم خصوصی: نشانی صفحهٔ حریم خصوصی؛ کنار چک‌باکس لینک می‌شود.',
    ]))

    story.append(P('۵.۲. کانال‌ها (ایمیل و پیامک)', 'h2'))
    story.append(bullets([
        'ایمیل: روشن به‌صورت پیش‌فرض و از wp_mail استفاده می‌کند.',
        'نشانی فرستنده: در نصب تازه خالی است. وقتی خالی باشد، افزونه هنگام ارسال از ایمیل مدیریت همان سایت (تنظیمات عمومی وردپرس) استفاده می‌کند، اگر معتبر باشد؛ وگرنه تعیین فرستنده را به وردپرس می‌سپارد. می‌توانید نشانی تآییدشدهٔ خود را وارد کنید. افزونه یا سرویس SMTP ممکن است فرستنده را تغییر دهد. نشانی فرستنده‌ای که قبلاً ذخیره کرده‌اید خودکار پاک نمی‌شود؛ برای استفاده از ایمیل مدیریت فعلی سایت، کادر را خالی و تنظیمات را ذخیره کنید.',
        'پیامک: خاموش به‌صورت پیش‌فرض. برای فعال‌سازی، کلید درگاه را پر کنید.',
        'حالت انتخاب: فقط ایمیل / فقط پیامک / هر دو (مشتری خودش انتخاب می‌کند).',
    ]))

    story.append(P('۵.۳. درگاه‌های پیامک', 'h2'))
    story.append(table(['درگاه', 'چه چیزی لازم است'], [
        ['ملی‌پیامک', 'نام کاربری، رمز، شمارهٔ خط، (اختیاری) شناسهٔ الگو'],
        ['آی‌پی‌پنل', 'توکن، شمارهٔ خط، (اختیاری) الگو'],
        ['کاوه‌نگار', 'API Key، شمارهٔ فرستنده'],
        ['درگاه عمومی REST', 'نشانی https، توکن (اختیاری)، قالب JSON با {to} و {message}'],
    ], widths=[52 * mm, 122 * mm]))
    story.append(P('درگاه عمومی برای هر سرویسی است که API ساده دارد. نشانی باید https و عمومی باشد؛ localhost، http و آی‌پی خصوصی رد می‌شوند.'))

    story.append(P('۵.۴. خطوط خدماتی: ارسال با «الگو»', 'h2'))
    story.append(P('بسیاری از خطوط خدماتی ایران اجازهٔ ارسال متن آزاد نمی‌دهند و فقط «الگو»ی تآییدشده را می‌فرستند. متن الگو در پنل درگاه ذخیره می‌شود و افزونه فقط مقدارها را می‌فرستد. سه کار لازم است:'))
    story.append(numbered([
        'در پنل ملی‌پیامک ← «ارسال الگو» ← «متن‌های من» یک متن بسازید و تآیید بگیرید.',
        'در تنظیمات ← پیامک، «متن تآییدشدهٔ الگو» را عیناً همان متن بنویسید و شناسهٔ آن الگو را در «شناسهٔ الگو (bodyId)» بگذارید.',
        'یک‌بار «ارسال آزمایشی پیامک» را بزنید.',
    ]))
    story.append(P('متغیرهایی که افزونه پر می‌کند:', 'h3'))
    story.append(table(['متغیر', 'مقدار'], [
        ['{0}', 'نام مشتری؛ اگر ناشناس باشد «مشتری»'],
        ['{1}', 'نام محصول'],
        ['{2}', 'نشانی صفحهٔ محصول'],
        ['{3}', 'قیمت محصول'],
    ], widths=[40 * mm, 134 * mm], align_center_cols=(0,)))
    story.append(P('قواعدی که درگاه اعمال می‌کند (و افزونه پیش از ارسال بررسی می‌کند):', 'h3'))
    story.append(bullets([
        'شماره‌گذاری از {0} و بدون پرش؛ {1} تنها، یا {0} و {2} بی‌{1}، خطاست.',
        'متن نباید با متغیر تمام شود؛ نام فروشگاه یا یک جملهٔ ثابت را آخر بگذارید.',
        'متن باید محتوای واقعی داشته باشد؛ «{0} باتشکر» یا «{0} گوگل» تآیید نمی‌شود.',
        'ترتیب مقدارها از روی شمارهٔ متغیر است، نه جای ظاهری آن: اگر بنویسید «کالای {1} برای {0}»، باز هم {0} مقدار اول را می‌گیرد.',
    ]))
    story.append(P('متن پیشنهادی افزونه (با نام فروشگاه شما در انتها):', 'h3'))
    story.append(code_block('{0} عزیز، {1} دوباره موجود شد. مشاهده و خرید: {2} — نام فروشگاه شما'))
    story.append(P('اگر فیلد متن خالی بماند، همین متن مبنا قرار می‌گیرد؛ پس اگر الگوی تآییدشدهٔ شما با آن فرق دارد، حتماً متن خودتان را بنویسید.'))

    story.append(P('۵.۵. تآیید دومرحله‌ای', 'h2'))
    story.append(bullets([
        'خاموش (پیش‌فرض): مشتری یک‌بار ثبت می‌کند و اعلان موجودی مستقیم می‌رود. هیچ پیام تآییدی در میان نیست، پس هیچ‌کس «منتظر تآیید» نمی‌ماند.',
        'روشن: پیام تآیید در صف قرار می‌گیرد و تا کلیک روی پیوند آن، اعلان موجودی ارسال نمی‌شود. این روش دسترسی به نشانی را پیش از اعلان بررسی می‌کند، اما مانع درخواست شخص دیگر یا هزینهٔ پیام تآیید نمی‌شود؛ ردیف تا تآیید pending می‌ماند.',
        'انقضا: پیش‌فرض ۴۸ ساعت (فقط در حالت روشن).',
    ]))

    story.append(P('۵.۶. نام و نام خانوادگی و پیگیری تلفنی', 'h2'))
    story.append(P('فرم به‌طور پیش‌فرض سه چیز می‌پرسد: نام و نام خانوادگی، و بر اساس تنظیمات، ایمیل و/یا موبایل. نام فقط برای ثبت نیست:'))
    story.append(bullets([
        'در پیام‌ها استفاده می‌شود — متغیر {{customer_name}} در ایمیل و {0} در الگوی پیامک.',
        'در لیست انتظارها ستون «مشتری» را پر می‌کند.',
        'در خروجی CSV ستون «نام و نام خانوادگی» را می‌دهد.',
    ]))
    story.append(P('در تنظیمات ← کانال‌ها دو کلید هست: «فیلد نام و نام خانوادگی» (روشن/خاموش) و «نام اجباری باشد؟». اگر اجباری باشد، ثبت بدون نام با خطای «نام و نام خانوادگی را کامل بنویسید» رد می‌شود. اگر اختیاری باشد، ردیف‌های بی‌نام ساخته می‌شوند و در جدول با «بی‌نام» علامت می‌خورند.'))
    story.append(P('نام پاک‌سازی می‌شود: فقط حروف (فارسی/عربی/لاتین)، فاصله، نیم‌فاصله، خط تیره، آپاستروف و نقطه می‌ماند. شمارهٔ موبایل یا متن‌های فرمولی به‌عنوان نام پذیرفته نمی‌شوند.'))
    story.append(P('متغیر نام در پیامک', 'h3'))
    story.append(table(['حالت', 'متغیری که در متن می‌گذارید', 'چه چیزی جایش می‌آید'], [
        ['خط خدماتی با الگو (bodyId پر است)', '{0}', 'نام ثبت‌شدهٔ مشتری'],
        ['متن آزاد (bodyId خالی است)', '{customer_name}', 'نام ثبت‌شدهٔ مشتری'],
    ], widths=[62 * mm, 52 * mm, 60 * mm]))
    story.append(P('در حالت الگو، ترتیب متغیرها ثابت است: {0} نام مشتری، {1} نام محصول، {2} نشانی صفحهٔ محصول، {3} قیمت. شماره‌گذاری باید از صفر و بدون پرش باشد و متن نباید با متغیر تمام شود. اگر مشتری نامی نداده باشد (وقتی «نام اجباری» خاموش است)، جای نام «همراه گرامی» می‌آید تا پیام خالی و بی‌معنا نرود.'))
    story.append(P('پیگیری تلفنی (مرحله‌ای)', 'h3'))
    story.append(P('در لیست انتظارها، ستون «تماس» یک دکمه دارد: پیگیری تلفنی. با کلیک روی آن یک پنجره باز می‌شود که همهٔ تماس‌ها را مرحله‌به‌مرحله نگه می‌دارد.'))
    story.append(table(['مرحله', 'کِی ساخته می‌شود'], [
        ['تماس اول', 'اولین باری که نتیجه را ثبت می‌کنید'],
        ['تماس دوم', 'دومین ثبت — و به همین ترتیب'],
    ], widths=[50 * mm, 124 * mm]))
    story.append(P('شمارهٔ مرحله از تعداد تماس‌های ثبت‌شده ساخته می‌شود، پس دو نفر که همزمان ثبت کنند هر دو «تماس دوم» نمی‌سازند.'))
    story.append(P('داخل پنجره چه هست:', 'h3'))
    story.append(bullets([
        'نام مشتری و شمارهٔ کامل به‌صورت پیوند تماس (روی موبایل با یک لمس زنگ می‌خورد). در جدول شماره پوشانده می‌شود، در پنجره کامل است — چون برای تماس لازم است.',
        'تاریخچهٔ همهٔ تماس‌های قبلی با تاریخ شمسی.',
        'نتیجهٔ تماس از هفت گزینه: پاسخ داد، پاسخ نداد، مشغول بود، بعداً تماس بگیرم، سفارش داد، شماره اشتباه بود، درخواست لغو کرد.',
        'توضیح تایپی تا ۵۰۰ کاراکتر — همان چیزی که بعداً یادتان می‌آورد مشتری چه گفت.',
        'دکمهٔ «ثبت تماس بعدی» و دکمهٔ «خاتمهٔ پیگیری».',
    ]))
    story.append(P('خاتمه و بازکردن دوباره: برای خاتمه باید دلیل انتخاب کنید (سفارش داد، شماره اشتباه، درخواست لغو، دیگر پاسخ نمی‌دهد، ردیف تکراری، سایر). ردیف بسته در جدول با «بسته — دلیل» نشان داده می‌شود و از لیست کار بیرون می‌رود. هر وقت لازم شد، همان پنجره دکمهٔ «بازکردن دوبارهٔ پیگیری» دارد و تاریخچه پاک نمی‌شود.'))
    story.append(P('بستن خودکار: سه نتیجه — «سفارش داد»، «شماره اشتباه بود» و «درخواست لغو کرد» — پیگیری را خودکار می‌بندند تا این مشتری فردا در لیست تماس نماند.'))
    story.append(P('بدون جاوااسکریپت: دکمه یک پیوند واقعی است و فرم را در یک صفحهٔ کامل باز می‌کند؛ همان فرم، همان تاریخچه.'))
    story.append(callout('چیزهایی که این بخش انجام نمی‌دهد: افزونه تماس نمی‌گیرد، به سامانهٔ تلفنی وصل نمی‌شود، تماس را ضبط نمی‌کند و گزارش تماس واقعی نمی‌سازد. «تماس اول، دوم…» یعنی «کسی این مرحله‌ها را ثبت کرده است».'))
    story.append(bullets([
        'فیلتر «تماس»: همه / تماس گرفته نشده / تماس گرفته شده — برای اینکه هر روز فقط لیست تماس‌نگرفته‌ها را ببینید.',
        'عملیات گروهی: «علامت‌گذاری: تماس گرفته شد» و «برداشتن تیک تماس».',
        'خروجی CSV ستون‌های «تماس گرفته شد» و «تاریخ تماس (شمسی)» را دارد؛ برای تحویل به تیم فروش یا ایمپورت در اکسل.',
    ]))

    story.append(P('۵.۷. کدام فیلدها در فرم دیده شوند', 'h2'))
    story.append(bullets([
        'فیلد ایمیل در فرم و فیلد موبایل در فرم: هر کدام را خاموش کنید، همان کادر روی سایت نمایش داده نمی‌شود. اگر فقط شمارهٔ موبایل می‌خواهید، ایمیل را خاموش کنید؛ اگر فقط ایمیل می‌خواهید، موبایل را.',
        'اگر هر دو روشن باشند، مشتری خودش انتخاب می‌کند و «اولویت کانال» تعیین می‌کند کدام گزینه از قبل انتخاب شده باشد (پیامک در بازار ایران پیش‌فرض است).',
        'نکتهٔ مهم: فیلد هر کانالی که درگاهش آماده نباشد، خودبه‌خود نمایش داده نمی‌شود. اگر پیامک را روشن کرده‌اید ولی کلید درگاه را خالی گذاشته‌اید، فیلد موبایل روی سایت نمی‌آید تا شماره‌ای جمع نشود که هرگز پیام نمی‌گیرد. کلید درگاه را در بخش «درگاه‌های پیامک» پر کنید.',
        'اگر هر دو کلید را خاموش کنید، هنگام ذخیره، فیلد ایمیل دوباره روشن می‌شود و هشدار می‌گیرید؛ فرم بی‌ورودی یعنی هیچ درخواستی ثبت نمی‌شود.',
    ]))

    story.append(P('۵.۸. «اعلام به تعداد موجودی»', 'h2'))
    story.append(P('اگر روشن باشد (پیش‌فرض) و موجودی مدیریت شود، حداکثر به تعداد واحد قابل تخصیص از قدیمی‌ترین منتظران تآییدشده در صف اعلان قرار می‌گیرند. برای موجودی مدیریت‌نشده یا با تنظیم خاموش، سقف تعداد واحد اعمال نمی‌شود. صف‌شدن به معنی تحویل نیست و صرف افزایش تعداد بدون گذار ناموجود به موجود الزاماً اعلان تازه ایجاد نمی‌کند.'))

    story.append(P('۵.۹. صف ارسال و پیشرفته', 'h2'))
    story.append(bullets([
        'اندازهٔ دسته: پیش‌فرض ۵۰.',
        'سقف در دقیقه: پیش‌فرض ۶۰. اگر درگاه پیامک محدودیت دارد، کم کنید.',
        'بازهٔ مجاز: مثلاً ۰۸:۰۰–۲۲:۰۰. بیرون بازه، پیام‌ها در صف می‌مانند، گم نمی‌شوند.',
        'نگهداشت داده: پیش‌فرض ۱۸۰ روز از زمان ثبت دورهٔ جاری برای ردیف‌های پایان‌یافته؛ پاک‌سازی به اجرای زمان‌بندی وابسته است.',
        'حذف داده هنگام حذف افزونه: پیش‌فرض خاموش.',
    ]))

    # ---- 6. Daily work -----------------------------------------------------
    story.append(P('۶. کار روزمره', 'h1'))
    story.append(P('لیست انتظارها', 'h2'))
    story.append(P('فیلتر بر اساس محصول/وضعیت، جست‌وجو، عملیات گروهی (تآیید دستی، لغو، حذف) و خروجی CSV. ستون «تماس» برای پیگیری تلفنی و ستون «مشتری» برای نام ثبت‌شده است.'))
    story.append(P('داشبورد', 'h2'))
    story.append(P('تعداد منتظران، تآییدشده‌ها، اعلان‌های امروز، وضعیت صف و آخرین اجرای پایش.'))
    story.append(P('گزارش رویدادها', 'h2'))
    story.append(P('همهٔ اتفاق‌های افزونه؛ هر ردیف یک جملهٔ فارسی («درخواست رد شد — کد امنیتی فرم منقضی شده بود») و کنارش «لاگ اصلی» با کد خام و متن context برای پشتیبانی. فیلتر بر اساس سطح (اطلاع/هشدار/خطا/امنیتی)، رویداد، بازه و جست‌وجو؛ خروجی CSV؛ و دکمهٔ پاک کردن لاگ. برای هر خطای مهم، یک جملهٔ «چه کار کنم» هم زیر همان ردیف نوشته می‌شود. صفحه‌بندی کامل دارد: ۲۵ ردیف به‌طور پیش‌فرض (۲۵/۵۰/۱۰۰ قابل انتخاب)، شمارهٔ صفحه‌ها با پرش و حفظ فیلترها در لینک.'))
    story.append(P('آمار', 'h2'))
    story.append(P('گزارش بازه‌های ۷، ۳۰ و ۹۰ روزه: ثبت روزانه (نمودار میله‌ای)، اعلان روزانه، تفکیک پیامک/ایمیل، وضعیت همهٔ ردیف‌ها، پرتقاضاترین محصول‌ها، نتیجهٔ تماس‌های پیگیری، و مهم‌تر از همه «ثبت‌های ردشده»: فهرست دلیل‌هایی که یک درخواست را رد کرده و هیچ ردیفی نساخته است (نامعتبر، تکراری، کد امنیتی منقضی، محدودیت نرخ…). اگر فرم را پر می‌کنید و ردیفی در لیست انتظار نمی‌بینید، همین بخش جواب را می‌دهد. خروجی CSV هم دارد.'))
    story.append(P('ابزارها', 'h2'))
    story.append(bullets([
        'پایش موجودی الان: گذارهای موجودی را همین حالا بررسی می‌کند.',
        'بازسازی تصویر موجودی: پس از مهاجرت یا تغییر انبوه انبار.',
        'پردازش صف: بدون انتظار برای cron؛ در پاسخ می‌نویسد چند ردیف آماده، زمان‌بندی‌شده، در حال ارسال، ناموفق یا ردشده‌اند و چرا ارسال متوقف شد.',
        'آزادکردن صفِ گیرکرده: ردیف رهاشده را پس از مهلت قفل به صف برمی‌گرداند؛ اجرای بعدی صف نیز پیش از پردازش همین بررسی را انجام می‌دهد.',
        'پاک‌سازی صف: پیام‌های در انتظار را دور می‌ریزد.',
        'ارسال آزمایشی ایمیل/پیامک: با نمایش خطای واقعی درگاه.',
    ]))
    story.append(P('نشانی محصول در پیامک', 'h2'))
    story.append(P('چهار حالت دارد و پیش‌فرض «نشانی کوتاه محصول» است: https://دامنه/?p=1234 (نشانی کامل معمولاً دو برابر طول دارد و در الگوهای سه‌متغیره از سقف اپراتور رد می‌شود). اگر دامنهٔ کوتاهی دارید که به همین فروشگاه اشاره می‌کند، آن را در «دامنهٔ کوتاه» بگذارید. هیچ سرویس کوتاه‌کنندهٔ بیرونی استفاده نمی‌شود و پیوند روی همین سایت ساخته می‌شود. ایمیل همیشه نشانی کامل می‌گیرد.'))
    story.append(P('حالت دیگر «نشانی اصلی سایت» است: کوتاه‌ترین گزینه (https://دامنه حدود ۲۰ کاراکتر) که همیشه زیر سقف جا می‌شود، ولی خریدار به صفحهٔ اصلی می‌رود و باید کالا را خودش پیدا کند. اگر این را می‌خواهید، بهتر است همان نشانی را متن ثابت داخل الگوی پنل بگذارید و {2} را بردارید؛ این‌طور لینک متغیر نیست و هیچ محدودیت طولی ندارد.'))
    story.append(P('سقف کاراکتر هر متغیر', 'h2'))
    story.append(P('۱۵ تا ۷۰، پیش‌فرض ۲۵. عددی را بگذارید که اپراتور خودتان می‌پذیرد. در همان صفحه، طول واقعی هر مقدار روی سایت شما اندازه‌گیری و نشان داده می‌شود. اگر مقداری از سقف بیشتر باشد، ارسال پیش از تماس با درگاه متوقف می‌شود و پیام می‌گوید کدام متغیر چند کاراکتر است.'))

    # ---- 7. Privacy --------------------------------------------------------
    story.append(P('۷. حریم خصوصی', 'h1'))
    story.append(bullets([
        'IP خام ذخیره نمی‌شود؛ فقط هش SHA-256 با نمک سایت.',
        'هیچ داده‌ای به سرور سازنده نمی‌رود.',
        'ایمیل اعلان پیوند لغو دارد؛ در پیامک، وجود آن به متن یا الگوی انتخابی بستگی دارد.',
        'حذف افزونه، به‌صورت پیش‌فرض داده را نگه می‌دارد.',
    ]))

    # ---- 8. FAQ ------------------------------------------------------------
    story.append(P('۸. پرسش‌های پرتکرار', 'h1'))
    faqs = [
        ('فرم در قالب من دیده نمی‌شود.',
         'علت را بدون بررسی سایت نمی‌توان تعیین کرد. وضعیت انتشار و موجودی، وجود wp_footer، خطای جاوااسکریپت و کش را بررسی کنید. برای محل دلخواه از [mojoodyar_form] یا شناسهٔ صریح استفاده کنید.'),
        ('مشتری می‌گوید پیام تآیید نیامده.',
         'ابزارها ← ارسال ایمیل آزمایشی. اگر آن هم نرسید، مشکل هاست (SPF/DKIM) است، نه افزونه.'),
        ('چرا برای همهٔ منتظران پیام نرفت؟',
         'تنظیم «اعلام به تعداد موجودی»، مدیریت موجودی، تعداد واحد قابل تخصیص، وضعیت تآیید مشترکان و صف را بررسی کنید. صف‌شدن تحویل پیام را تضمین نمی‌کند.'),
        ('موجودی را با اکسل عوض کردم و اعلانی نرفت.',
         'هوک ووکامرس در نوشتن مستقیم شلیک نمی‌شود. پایش تطبیقی با فاصلهٔ پیش‌فرض ۱۵ دقیقه، در صورت اجرای WP-Cron، تغییر وضعیت را بررسی می‌کند؛ برای فوریت، ابزارها ← «پایش موجودی الان».'),
        ('پیام‌ها شب می‌رسند.',
         'بازهٔ مجاز ارسال را روشن کنید (مثلاً ۸ تا ۲۲).'),
        ('با HPOS کار می‌کند؟',
         'کد به wp_posts وابسته نیست و از wc_get_products استفاده می‌کند، اما این ادعا روی نصب واقعی HPOS تست نشده است.'),
        ('چند سایت روی یک نصب (network)؟',
         'روی نصب شبکه تست نشده است. هر سایت جدول‌های خودش را با پیشوند خودش می‌سازد.'),
        ('اگر افزونه را حذف کنم، لیست مشتریان می‌رود؟',
         'نه، مگر گزینهٔ «حذف داده هنگام حذف افزونه» را خودتان روشن کرده باشید.'),
    ]
    for q, a in faqs:
        story.append(P(q, 'h3'))
        story.append(P(a, 'body'))

    # ---- 9. Support --------------------------------------------------------
    story.append(P('۹. پشتیبانی و اطلاعات نسخه', 'h1'))
    story.append(table(['مورد', 'جزئیات'], [
        ['نام افزونه', 'موجودیار | لیست انتظار و اعلان بازگشت موجودی'],
        ['نسخه', '۱.۰.۰'],
        ['سازنده', 'اتحاد وردپرس'],
        ['پروانه', 'GPLv2 یا بالاتر'],
        ['پیش‌نیاز', 'وردپرس ۶.۴ · PHP ۷.۴ · ووکامرس ۹.۰'],
        ['فونت', 'وزیرمتن (داخل افزونه، بدون CDN)'],
    ], widths=[50 * mm, 124 * mm]))
    story.append(Spacer(1, 4 * mm))
    story.append(P('برای پشتیبانی، پیش از تماس این سه مورد را آماده کنید: نسخهٔ افزونه، متن دقیق خطا از «گزارش رویدادها»، و نتیجهٔ «ارسال آزمایشی» در ابزارها. این سه مورد معمولاً علت را مشخص می‌کنند.'))
    story.append(Spacer(1, 8 * mm))
    story.append(HRFlowable(width='40%', thickness=1, color=AMBER, spaceBefore=4, spaceAfter=8, hAlign='CENTER'))
    story.append(Paragraph(fa('موجودیار — نگهبانِ خبرهای خوب فروشگاه شما'), STYLES['small']))

    # Build with cover handling: first page uses cover_page, rest on_page.
    def all_pages(canvas, doc):
        if doc.page == 1:
            cover_page(canvas, doc)
        else:
            on_page(canvas, doc)

    doc.build(story, onFirstPage=all_pages, onLaterPages=all_pages)
    print(f'PDF written: {OUT} ({os.path.getsize(OUT)} bytes)')

if __name__ == '__main__':
    build()
