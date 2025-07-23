from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics
from bidi.algorithm import get_display
import arabic_reshaper
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

# ثبت فونت فارسی
pdfmetrics.registerFont(TTFont('IRANSans', 'IRANSans.ttf'))

# ✅ آماده‌سازی نمودار پیتزا
labels = ['Fereshteh', 'Arad', 'Users/Auth', 'Frontend', 'API/Webhook', 'Test/Security', 'Docs']
sizes = [30, 20, 15, 10, 10, 10, 5]
colors = ['#ff9999', '#66b3ff', '#99ff99', '#ffcc99', '#c2c2f0', '#ffb3e6', '#b3ffff']

plt.figure(figsize=(5, 5))
plt.pie(sizes, labels=labels, autopct='%1.1f%%', startangle=140, colors=colors, textprops={'fontsize': 8})
plt.axis('equal')
chart_path = 'chart_hpc_persian.png'
plt.savefig(chart_path, dpi=150, bbox_inches='tight')
plt.close()

# ✅ ساخت PDF
pdf_path = "گزارش_تحلیلی_پروژه_HPC_کامل.pdf"
c = canvas.Canvas(pdf_path, pagesize=A4)
width, height = A4
margin = 40
line_height = 18
y = height - margin

def draw_text(text, font_size=12, offset=0):
    global y
    reshaped_text = arabic_reshaper.reshape(text)
    bidi_text = get_display(reshaped_text)
    c.setFont("IRANSans", font_size)
    c.drawRightString(width - margin - offset, y, bidi_text)
    y -= line_height

# ▪ عنوان
draw_text("📘 گزارش تحلیلی پروژه HPC", 14)
draw_text(" ")

# ▪ ماژول‌های پروژه
draw_text("🔹 ماژول‌های پروژه:", 13)
draw_text("• ماژول Fereshteh (۳۰٪)")
draw_text("• پروژه جانبی Arad (۲۰٪)")
draw_text("• کاربران و احراز هویت (۱۵٪)")
draw_text("• رابط کاربری HTML (۱۰٪)")
draw_text("• API و Webhook (۱۰٪)")
draw_text("• تست و امنیت (۱۰٪)")
draw_text("• مستندسازی (۵٪)")
draw_text(" ")

# ▪ نمودار
chart_height = 200
c.drawImage(chart_path, width / 2 - 100, y - chart_height + 40, width=200, height=chart_height, mask='auto')
y -= chart_height + 20

# ▪ جدول هزینه‌ها
draw_text("💰 جدول برآورد هزینه‌ها:", 13)
draw_text("• فریلنسر ایرانی: ۹۰۰ میلیون تا ۱.۵ میلیارد تومان (~۱۱,۰۰۰ – ۱۸,۰۰۰ دلار)")
draw_text("• شرکت ایرانی: ۲.۲ تا ۳.۲ میلیارد تومان (~۲۷,۰۰۰ – ۳۹,۰۰۰ دلار)")
draw_text("• شرکت خارجی: ۴۳,۰۰۰ – ۵۵,۰۰۰ دلار (معادل ۳.۵ تا ۴.۵ میلیارد تومان)")
draw_text(" ")

# ▪ تخمین زمان و پیچیدگی
draw_text("⏱ برآورد زمان توسعه:", 13)
draw_text("• تحلیل و طراحی: ۵ روز")
draw_text("• توسعه Fereshteh: ۱۵–۱۸ روز")
draw_text("• توسعه Arad و سایر ماژول‌ها: ۱۲–۱۵ روز")
draw_text("• رابط کاربری، تست و امنیت: ۸–۱۰ روز")
draw_text("• مستندسازی و نهایی‌سازی: ۳–۵ روز")
draw_text("• مجموع: ۴۵ تا ۵۸ روز کاری")
draw_text(" ")
draw_text("📈 تخمین پیچیدگی: ۸۵٪")

c.save()
print(f"✅ فایل PDF ساخته شد: {pdf_path}")
