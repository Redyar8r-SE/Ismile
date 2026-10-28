"""Create a PDF and high-resolution diagrams explaining the proposed integration."""
from pathlib import Path
import math
import pymupdf as fitz

ROOT = Path(__file__).resolve().parent
OUT = ROOT / 'iSmile-Partner-Integration-Next-Steps.pdf'
W, H = 842, 595
COLORS = {
    'navy': '#123B4A', 'ink': '#173A47', 'muted': '#56727C',
    'teal': '#0E7F7B', 'tealbg': '#EAF5F4', 'purple': '#6548A3',
    'purplebg': '#F1EDF9', 'blue': '#27639D', 'bluebg': '#EDF4FC',
    'line': '#D7E5E9', 'paper': '#F7FAFC', 'white': '#FFFFFF',
    'gold': '#896019', 'goldbg': '#FFF5DF',
}
doc = fitz.open()
overflow = []

def color(name):
    value = COLORS.get(name, name).lstrip('#')
    return tuple(int(value[i:i+2], 16)/255 for i in (0, 2, 4))

def rect(p, x, y, w, h, fill='white', stroke=None):
    p.draw_rect(fitz.Rect(x,y,x+w,y+h), fill=color(fill),
                color=color(stroke) if stroke else None, width=.8)

def text(p, x, y, w, h, value, size=11, bold=False, ink='ink', align=0, mono=False):
    result = p.insert_textbox(fitz.Rect(x,y,x+w,y+h), value,
        fontname='cour' if mono else ('hebo' if bold else 'helv'),
        fontsize=size, lineheight=1.22, color=color(ink), align=align)
    if result < -.05:
        overflow.append((len(doc), value[:90], result))

def arrow(p, points, ink='teal', dashed=False):
    sh = p.new_shape()
    sh.draw_polyline([fitz.Point(*point) for point in points])
    sh.finish(color=color(ink), width=1.5, dashes='[4 3] 0' if dashed else None)
    sh.commit()
    (x0,y0),(x1,y1) = points[-2:]
    angle = math.atan2(y1-y0, x1-x0)
    sh = p.new_shape()
    sh.draw_polyline([fitz.Point(x1,y1),
        fitz.Point(x1-6*math.cos(angle-.5),y1-6*math.sin(angle-.5)),
        fitz.Point(x1-6*math.cos(angle+.5),y1-6*math.sin(angle+.5)),
        fitz.Point(x1,y1)])
    sh.finish(color=color(ink), fill=color(ink), width=.5)
    sh.commit()

def panel(p, x, y, w, h, title, body, accent='teal', size=10.5):
    rect(p,x,y,w,h,accent+'bg')
    rect(p,x,y,3,h,accent)
    text(p,x+12,y+10,w-24,28,title,12,True,accent)
    text(p,x+12,y+39,w-24,h-44,body,size)

def page(number, title, subtitle):
    p = doc.new_page(width=W,height=H)
    rect(p,0,0,W,H)
    rect(p,0,0,W,7,'teal')
    text(p,32,21,750,18,'iSMILE 2026  /  PAYMENT + ATTENDANCE',10,True,'teal')
    text(p,32,48,778,42,title,25,True)
    text(p,32,91,778,33,subtitle,11,ink='muted')
    p.draw_line((32,557),(810,557),color=color('line'),width=.8)
    text(p,32,569,700,17,'Proposed connection | Partner documentation required | 07 October 2026',8,ink='muted')
    text(p,746,568,64,18,f'{number} / 2',9,True,'teal',2)
    return p

p = page(1,'How their system updates your database',
         'Your website keeps the registration. Their system reports confirmed payment and attendance automatically.')

# The four next steps in the user's own order.
steps = [
    ('1  GET THEIR DOCS', 'Ask for API / webhook docs,\nsample events and a test account.'),
    ('2  SHARE ONE REFERENCE', 'Example: ISM26-000123.\nUse it in both systems.'),
    ('3  SAVE THEIR UPDATES', 'Payment confirmation +\ncheck-in for each event day.'),
    ('4  TEST THE JOURNEY', 'Register, pay, receive ticket,\ncheck in, receive certificate.'),
]
for i,(title,body) in enumerate(steps):
    x = 32+i*197
    rect(p,x,136,187,81,'paper')
    text(p,x+11,147,165,26,title,10,True,'teal')
    text(p,x+11,176,165,36,body,10)

centers = [144,421,698]
labels = [('YOUR iSMILE BACKEND','Website + registration','blue'),
          ('PARTNER SYSTEM','Payment + check-in','purple'),
          ('YOUR DATABASE','Records + ticket + certificate','teal')]
for x,(title,body,accent) in zip([32,309,586],labels):
    rect(p,x,239,224,49,accent+'bg')
    text(p,x+10,249,204,18,title,11,True,accent,1)
    text(p,x+10,268,204,17,body,9.5,ink='muted',align=1)
for x in centers:
    p.draw_line((x,295),(x,483),color=color('line'),width=1,dashes='[3 3] 0')

text(p,32,300,95,22,'A. REGISTER',9,True,'blue')
text(p,162,298,238,26,'API request: create payment / share reference',9.2,True,'blue',1)
arrow(p,[(144,329),(421,329)],'blue')
text(p,456,299,235,25,'Store registration + the same reference',9.2,True,'teal',1)
arrow(p,[(144,341),(698,341)],'teal')

text(p,32,354,95,24,'B. PAYMENT',9,True,'purple')
text(p,164,357,236,25,'Webhook: payment confirmed + reference',9.2,True,'purple',1)
arrow(p,[(421,387),(144,387)],'purple')
text(p,438,387,249,22,'Verify payment, save PAID, issue ticket + QR',9.2,True,'teal',1)
arrow(p,[(144,414),(698,414)],'teal')

text(p,32,429,95,24,'C. CHECK-IN',9,True,'purple')
text(p,164,427,236,25,'Webhook: arrival + reference + day + time',9.2,True,'purple',1)
arrow(p,[(421,453),(144,453)],'purple')
text(p,439,456,248,23,'Validate ticket and save daily attendance',9.2,True,'teal',1)
arrow(p,[(144,480),(698,480)],'teal')

rect(p,32,500,778,41,'goldbg')
text(p,44,510,754,26,'CERTIFICATE: valid paid ticket + the agreed attendance rule. Payment alone is not enough.',11,True,'gold',1)

p = page(2,'What we exchange and what we test',
         'All references below are examples. The partner must confirm their real field names, routes and authentication.')
panel(p,32,136,378,112,'API: your backend asks their system',
      'Send: your registration reference, expected amount and\ncurrency; ticket ID when their check-in system needs it.\nSend name / email only if their documented flow requires it.\nThey return: their payment ID / payment link or ticket mapping.','blue',10.3)
panel(p,432,136,378,112,'WEBHOOK: their system tells your backend',
      'They send a signed notification when something happens.\nYour backend checks the sender, reference and event details.\nIt saves the update, then acknowledges success.\nA retry with the same event ID must not create duplicates.','purple',10.3)

panel(p,32,268,378,131,'PAYMENT CONFIRMATION - example',
      'event_id:       PAY-EVENT-987\nreference:      ISM26-000123\npayment_id:     PARTNER-PAY-456\nstatus:         confirmed\namount/currency: agreed ticket total',
      'purple',11)
panel(p,432,268,378,131,'CHECK-IN CONFIRMATION - example',
      'event_id:       CHECKIN-EVENT-321\nreference:      ISM26-000123\nticket_id:      linked ticket / QR reference\nevent_day:      20 November 2026\nchecked_in_at:  original arrival time + timezone',
      'teal',11)

rect(p,32,417,778,62,'paper')
text(p,44,427,754,18,'THE TEST: ONE PERSON, ONE REFERENCE, FROM START TO FINISH',10.5,True,'blue')
text(p,44,450,754,22,'Registration  >  confirmed payment  >  ticket + QR  >  Day 1 / Day 2 attendance  >  eligible certificate',11,True)

rect(p,32,494,778,47,'goldbg')
text(p,44,504,754,29,'Also test: failed payment, unknown reference, repeated webhook and a delayed check-in.\nAgree before launch: does the certificate require attendance on one day or both days?',10.3,ink='gold')

if overflow:
    raise RuntimeError(f'Text overflow: {overflow}')
doc.set_metadata({'title':'iSmile - Partner Integration Next Steps',
                  'author':'iSmile','subject':'Proposed payment and daily attendance data flow'})
doc.save(OUT,garbage=4,deflate=True)
doc.close()
with fitz.open(OUT) as check:
    for i,p in enumerate(check):
        output = ROOT / ('iSmile-Partner-Integration-Diagram.png' if i==0 else 'iSmile-Partner-Integration-Details.png')
        pix = p.get_pixmap(matrix=fitz.Matrix(4,4),alpha=False)
        pix.save(output)
        print(f'{output.name}: {pix.width} x {pix.height}')
    for expected in ['GET THEIR DOCS','SHARE ONE REFERENCE','SAVE THEIR UPDATES','TEST THE JOURNEY','ISM26-000123']:
        assert expected in check[0].get_text(), expected
    print(f'{OUT.name}: {len(check)} pages, selectable text, no text overflow')
