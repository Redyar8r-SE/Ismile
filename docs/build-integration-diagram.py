"""Create the vector PDF explaining the proposed partner integration."""
from pathlib import Path
import math
import pymupdf as fitz

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "iSmile-Payment-Checkin-API-Webhook-Diagram.pdf"
W, H = 842, 595
C = {
    'ink': '#142B45', 'muted': '#576B80', 'line': '#D6E0EA',
    'paper': '#F6F8FC', 'white': '#FFFFFF', 'teal': '#087F81',
    'tealbg': '#E5F5F2', 'purple': '#6954BD', 'purplebg': '#EFEAFB',
    'blue': '#2565A7', 'bluebg': '#EAF2FC', 'gold': '#95651E',
    'goldbg': '#FFF2DB',
}
doc = fitz.open()
overflow = []

def color(key):
    value = C.get(key, key).lstrip('#')
    return tuple(int(value[i:i+2], 16)/255 for i in (0, 2, 4))

def rect(p, x, y, w, h, fill='white', stroke=None, radius=0):
    # Crisp rectangles retain their appearance at any PDF zoom level.
    p.draw_rect(fitz.Rect(x,y,x+w,y+h), color=color(stroke) if stroke else None,
                fill=color(fill), width=.8)

def txt(p, x, y, w, h, s, size=11, bold=False, ink='ink', align=0, mono=False):
    font = 'cour' if mono else ('hebo' if bold else 'helv')
    r = p.insert_textbox(fitz.Rect(x,y,x+w,y+h), s, fontsize=size,
                        fontname=font, color=color(ink), align=align,
                        lineheight=1.24)
    if r < -0.05:
        overflow.append((len(doc), s[:75], r))

def line(p, points, ink='muted', dashed=False, width=1.4, arrow=True):
    shape = p.new_shape()
    shape.draw_polyline([fitz.Point(*pt) for pt in points])
    shape.finish(color=color(ink), width=width, closePath=False,
                 dashes='[4 3] 0' if dashed else None)
    shape.commit()
    if arrow:
        (x0,y0),(x1,y1) = points[-2:]
        a = math.atan2(y1-y0,x1-x0)
        tip = [(x1,y1), (x1-7*math.cos(a-.45),y1-7*math.sin(a-.45)),
               (x1-7*math.cos(a+.45),y1-7*math.sin(a+.45)), (x1,y1)]
        sh=p.new_shape()
        sh.draw_polyline([fitz.Point(*pt) for pt in tip])
        sh.finish(color=color(ink),fill=color(ink),width=.6)
        sh.commit()

def box(p,x,y,w,h,title,body,accent='teal',small=None):
    bg={'teal':'tealbg','purple':'purplebg','blue':'bluebg','gold':'goldbg'}[accent]
    rect(p,x,y,w,h,bg)
    rect(p,x,y,3,h,accent)
    txt(p,x+13,y+12,w-26,27,title,12,True,accent)
    txt(p,x+13,y+39,w-26,h-43,body,10.5)
    if small:
        txt(p,x+13,y+h-22,w-26,18,small,8.5,False,'muted')

def pill(p,x,y,w,s,accent='teal'):
    rect(p,x,y,w,23,accent)
    txt(p,x+7,y+5,w-14,16,s,8.8,True,'white',1)

def page(n,title,subtitle):
    p=doc.new_page(width=W,height=H)
    rect(p,0,0,W,H,'white')
    rect(p,0,0,W,7,'teal')
    txt(p,38,24,500,18,'iSMILE 2026  /  PARTNER INTEGRATION',9,True,'teal')
    txt(p,38,51,770,39,title,25,True)
    txt(p,38,96,766,34,subtitle,11,False,'muted')
    line(p,[(38,558),(804,558)],'line',arrow=False,width=.8)
    txt(p,38,569,640,16,'Proposed design | Partner capabilities to confirm | 07 October 2026',8,False,'muted')
    txt(p,740,568,64,18,f'{n} / 4',9,True,'teal',2)
    return p

# 1: a business overview that can be understood without technical terminology.
p=page(1,'One connection. Two confirmed events.',
       'Their team operates payment and the door. Your website keeps attendance and prepares certificates.')
rect(p,38,140,766,44,'ink')
txt(p,53,151,736,27,
    'API = the interface. Webhook = an automatic event notification sent to that interface.',
    12,True,'white')
txt(p,38,197,195,18,'THEIR SYSTEM',9,True,'purple')
txt(p,298,197,230,18,'YOUR WEBSITE / APPLICATION',9,True,'blue')
txt(p,591,197,213,18,'YOUR DATABASE + RESULT',9,True,'teal')

box(p,38,225,195,105,'1. Payment confirmed',
    'Guest pays in their system.\nSend the shared reference\nand payment reference.','purple')
box(p,298,225,230,105,'Verify the payment',
    'Authenticate the sender.\nConfirm payment with the provider.\nCheck the expected amount + currency.','blue')
box(p,591,225,213,105,'Save payment + issue ticket',
    'Update payment / registration.\nIssue the guest\'s ticket + QR.\nCertificate waits for attendance.','teal')
line(p,[(233,275),(298,275)],'purple')
line(p,[(528,275),(591,275)],'blue')
txt(p,237,250,57,18,'HTTPS',8,True,'purple',1)
txt(p,531,250,57,18,'commit',8,True,'blue',1)

box(p,38,357,195,105,'2. Guest checked in',
    'Their staff confirms arrival.\nSend your ticket / shared reference,\nevent day and original arrival time.','purple')
box(p,298,357,230,105,'Verify the attendance',
    'Authenticate and match the guest.\nRequire a valid ticket.\nRecord each event day once.','blue')
box(p,591,357,213,105,'Save attendance + certificate',
    'Store the confirmed event day.\nPrepare the named certificate.\nYou choose when to send emails.','teal')
line(p,[(233,407),(298,407)],'purple')
line(p,[(528,407),(591,407)],'blue')
txt(p,237,382,57,18,'HTTPS',8,True,'purple',1)
txt(p,531,382,57,18,'commit',8,True,'blue',1)
line(p,[(590,478),(266,478),(266,341),(233,341)],'muted',True)
txt(p,298,479,290,20,'Success response after the update is saved',8.8,False,'muted')
rect(p,38,510,766,32,'goldbg')
txt(p,51,519,740,18,'Shared identity is essential: use your reference / ticket number. Payment alone does not earn a certificate.',10,True,'gold')

# 2: the direct API request and response option.
p=page(2,'Option A / Direct API calls',
       'Their application calls your endpoint and waits for a result. Both operations use the same internal services.')
xs=[123,324,526,730]
titles=['Their application','Your API receiver','Your business logic','Your MySQL database']
acc=['purple','blue','blue','teal']
for x,t,a in zip(xs,titles,acc):
    rect(p,x-83,145,166,35,{'purple':'purplebg','blue':'bluebg','teal':'tealbg'}[a])
    txt(p,x-78,156,156,18,t,10,True,a,1)
    line(p,[(x,184),(x,450)],'line',True,arrow=False,width=1)

pill(p,38,193,133,'PAYMENT CONFIRMATION','purple')
txt(p,138,228,175,18,'POST payment confirmation',9.5,True,'purple',1)
line(p,[(123,250),(324,250)],'purple')
txt(p,334,227,182,24,'Check sender + payment details',9.2,False,'blue',1)
line(p,[(324,250),(526,250)],'blue')
txt(p,536,227,184,24,'Save payment + ticket atomically',9.2,False,'teal',1)
line(p,[(526,250),(730,250)],'teal')
line(p,[(730,280),(526,280)],'teal',True)
line(p,[(526,280),(324,280)],'blue',True)
line(p,[(324,303),(123,303)],'purple',True)
txt(p,131,281,184,18,'2xx: saved / already applied',9.2,False,'purple',1)

pill(p,38,326,133,'CHECK-IN CONFIRMATION','purple')
txt(p,138,360,175,18,'POST confirmed attendance',9.5,True,'purple',1)
line(p,[(123,382),(324,382)],'purple')
txt(p,334,358,182,26,'Match guest + validate ticket / day',9.2,False,'blue',1)
line(p,[(324,382),(526,382)],'blue')
txt(p,536,358,184,26,'Save attendance + certificate',9.2,False,'teal',1)
line(p,[(526,382),(730,382)],'teal')
line(p,[(730,412),(526,412)],'teal',True)
line(p,[(526,412),(324,412)],'blue',True)
line(p,[(324,435),(123,435)],'purple',True)
txt(p,131,413,184,18,'2xx: saved / already applied',9.2,False,'purple',1)

rect(p,38,466,370,73,'paper')
txt(p,51,477,344,20,'Proposed endpoint examples',10,True,'blue')
txt(p,51,501,344,32,'POST /api/integrations/payment.php\nPOST /api/integrations/checkin.php',9,False,'ink',mono=True)
rect(p,422,466,382,73,'goldbg')
txt(p,435,477,356,19,'When the request times out',10,True,'gold')
txt(p,435,499,356,34,'Retry with the same request ID. The receiver returns the\nexisting result instead of creating another ticket or arrival.',9.5)

# 3: webhook notification, persistence, retry and reconciliation.
p=page(3,'Option B / Automatic webhooks',
       'Recommended if supported: their system sends confirmed events automatically, with retry delivery.')
pill(p,38,140,193,'payment.confirmed','purple')
pill(p,244,140,193,'attendance.checked_in','purple')
txt(p,460,145,344,28,'Two event types. Two routes or one dispatcher.',10,True,'muted',2)

box(p,38,199,173,113,'Their system',
    'Payment or arrival is saved\nin their own database.\nCreate a stable event ID.','purple')
box(p,260,199,173,113,'Delivery queue',
    'Send the notification\nover HTTPS.\nSign the event payload.','purple')
box(p,482,199,173,113,'Your receiver',
    'Verify sender + event ID.\nValidate payment / ticket.\nUse shared business logic.','blue')
box(p,704,199,100,113,'Your DB',
    'Save update\nand event ID\ntogether.','teal')
line(p,[(211,253),(260,253)],'purple')
line(p,[(433,253),(482,253)],'purple')
line(p,[(655,253),(704,253)],'blue')
txt(p,435,232,45,15,'POST',8,True,'purple',1)

line(p,[(754,313),(754,339),(347,339),(347,313)],'teal',True)
txt(p,404,319,290,17,'2xx acknowledgement after durable success',9,True,'teal',1)
box(p,38,355,240,87,'If delivery fails',
    'Timeout / temporary error: retry the same event.\nInvalid data: log and correct before resending.','gold')
box(p,302,355,240,87,'If an event is repeated',
    'Recognize its event ID. Return success.\nOne arrival per ticket per day; one certificate.','teal')
box(p,566,355,238,87,'If events arrive out of order',
    'Hold an unmatched arrival for review / retry.\nApply it after a valid ticket is available.','blue')
rect(p,38,457,766,83,'paper')
txt(p,51,469,740,18,'Recommended for this project',11,True,'teal')
txt(p,51,492,740,41,
    'Payment: existing POST /api/webhook.php, using the configured provider adapter and payment verification.\n'
    'Attendance: proposed POST /api/checkin-webhook.php. Add partner authentication and a shared attendance service.\n'
    'Recovery: compare with their attendance / payment API or export, if available, to recover missed notifications.',
    10)

# 4: concise handoff contract, limits and prerequisites.
p=page(4,'What both developers should agree on',
       'This is a design proposal. Endpoint names and partner event fields must be confirmed before implementation.')

cols=[38,217,506,804]
rows=[142,178,226,274,322,370]
rect(p,38,142,766,36,'ink')
for x,w,s in [(51,155,'Contract'),(230,260,'Payment confirmation'),(519,271,'Check-in confirmation')]:
    txt(p,x,152,w,20,s,11,True,'white')
data=[
    ('Identity','Your checkout / registration reference\n+ their payment reference','Your ticket number or shared reference\n+ their attendance event ID'),
    ('Event details','Confirmed status, amount, currency,\noriginal payment time','Event day (1 or 2), original check-in time\nwith timezone, confirmed arrival'),
    ('Validation','Verify provider status; match the\nexpected order, amount and currency','Require a current, valid ticket.\nMatch the guest and event day.'),
    ('Duplicate protection','Stable request / event ID\n+ unique provider payment reference','Stable request / event ID\n+ unique ticket / event-day attendance'),
]
for i,(label,pay,att) in enumerate(data):
    y=178+i*48
    rect(p,38,y,766,48,'paper' if i%2==0 else 'white')
    txt(p,51,y+10,153,34,label,10,True)
    txt(p,230,y+8,263,37,pay,10)
    txt(p,519,y+8,272,37,att,10)
for x in cols[1:-1]:
    line(p,[(x,142),(x,370)],'line',arrow=False,width=.7)
line(p,[(38,370),(804,370)],'line',arrow=False,width=.7)

box(p,38,386,242,103,'Trust + delivery',
    'API: partner credentials / scoped token.\nWebhook: provider signature + freshness.\nAcknowledge only saved or duplicate events.\nKeep an audit log and retry failed delivery.','blue')
box(p,300,386,242,103,'Guest + day matching',
    'Share your reference before payment.\nMap their ticket to your registration.\nUse event time in Iraq\'s timezone.\nPreserve the original day on delayed events.','purple')
box(p,562,386,242,103,'Certificate rule',
    'Current rule: attendance on either day\nqualifies once the ticket is valid.\nPayment does not create a certificate.\nCertificate email is sent on your command.','teal')
rect(p,38,505,766,36,'goldbg')
txt(p,51,514,740,25,
    'Next: obtain their API / webhook docs, sample events and a test account; test payment, arrival, retries and missed events.',
    10,True,'gold')

if overflow:
    raise RuntimeError(f'Text overflow: {overflow}')
doc.set_metadata({
    'title':'iSmile | Payment and Check-in API & Webhook Integration',
    'author':'iSmile',
    'subject':'Proposed payment and attendance integration with partner system',
    'keywords':'iSmile, payment, check-in, API, webhook, certificates',
})
doc.save(OUT,garbage=4,deflate=True)
doc.close()
check=fitz.open(OUT)
for i,p in enumerate(check):
    p.get_pixmap(matrix=fitz.Matrix(1.4,1.4)).save(ROOT/f'integration-diagram-page-{i+1}.png')
print(f'Created {OUT} ({len(check)} pages, {OUT.stat().st_size:,} bytes)')
for i,p in enumerate(check):
    print(f'Page {i+1}: {len(p.get_text())} text characters')
check.close()
