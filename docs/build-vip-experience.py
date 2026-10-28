"""Build an interactive VIP concept without changing the registration website."""
from pathlib import Path
import json
import re
from html import escape

ROOT = Path(__file__).resolve().parent.parent
source = (ROOT / 'register.html').read_text(encoding='utf-8')
tickets = json.loads((ROOT / 'data/tickets.json').read_text(encoding='utf-8'))
footer_data = json.loads((ROOT / 'data/footer.json').read_text(encoding='utf-8'))
lunch_price = 42

def step(number, next_comment):
    match = re.search(rf'<div class="reg-step" data-step="{number}"[^>]*>.*?(?=\s*<!-- {next_comment})', source, re.S)
    if not match:
        raise RuntimeError(f'Registration step {number} not found')
    return match.group(0).strip()

details = step(1, 'Step 2:')
lunch = step(2, 'Step 3:')
lunch = lunch[lunch.index('>') + 1:lunch.rfind('</div>')]
lunch = re.sub(r'(<em class="lunch-price" id="lunchPrice[12]">).*?(</em>)', rf'\g<1>${lunch_price}\g<2>', lunch)
payments = re.search(r'<fieldset class="pay-options">.*?</fieldset>', source, re.S).group(0)
hero = re.search(r'<div class="reg-page-hero">.*?(?=\n  </div>\n</section>)', source, re.S).group(0)
hero = hero.replace('class="reg-page-hero"', 'class="reg-page-hero experience-heading"')
header = re.search(r'<header class="top reg-top".*?</header>', source, re.S).group(0)
header = header.replace('class="top reg-top"', 'class="top reg-top experience-header"')
footer = re.search(r'<footer>.*?</footer>', source, re.S).group(0)
footer = footer.replace('<footer>', '<footer class="experience-site-footer">', 1)
footer_links = ''.join(f'<p><a href="{escape("index.html" + item["href"] if item["href"].startswith("#") else item["href"], quote=True)}">{escape(item["label"]["en"])}</a></p>' for item in footer_data['links'])
footer_contact = ''.join(f'<p class="ltr" dir="ltr"><a href="{escape(item["href"], quote=True)}">{escape(item["text"])}</a></p>' for item in footer_data['contact'])
footer = footer.replace('<div id="footLinks"></div>', f'<div id="footLinks">{footer_links}</div>')
footer = footer.replace('<div id="footContact"></div>', f'<div id="footContact">{footer_contact}</div>')
to_top = re.search(r'<button class="to-top".*?</button>', source, re.S).group(0)

icons = {
    'person': '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.8-4 3.8-6 7.5-6s6.7 2 7.5 6"/>',
    'student': '<path d="m2 9 10-5 10 5-10 5zM6 11v5c0 4 12 4 12 0v-5M22 9v8"/>',
    'crown': '<path d="m3 6 4 4 5-7 5 7 4-4-2 12H5L3 6ZM5 21h14"/>',
    'access': '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/>',
    'lounge': '<path d="M4 10V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4M4 10H2v8h20v-8h-2M4 10v4h16v-4M5 18v3M19 18v3"/>',
    'lunch': '<circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="3"/><path d="M2 3v6M4 3v6M3 9v12M22 3v18M22 3c-3 3-3 7 0 7"/>',
    'workshop': '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 2v4M17 2v4M3 9h18m-12 6 2 2 4-4"/>',
    'gift': '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13"/><path d="M12 8H8a3 3 0 1 1 3-3l1 3Zm0 0h4a3 3 0 1 0-3-3l-1 3Z"/>',
    'recognition': '<path d="m12 3 2.8 5.8 6.4.9-4.6 4.5 1.1 6.3-5.7-3-5.7 3 1.1-6.3-4.6-4.5 6.4-.9Z"/>',
    'home': '<path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-7h6v7"/>',
    'arrow': '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    'check': '<path d="m5 12 4 4L19 6"/>',
}

def icon(name):
    return f'<svg class="xp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{icons[name]}</svg>'

benefits = [
    ('access', 'VIP Priority Access', 'Priority entry, dedicated VIP check-in and reserved 2nd–3rd row seats at the main scientific sessions.'),
    ('lounge', 'Exclusive VIP Lounge', 'A private space to relax, network and connect throughout the summit.'),
    ('lunch', 'VIP Hosted Lunch', 'One hosted lunch with speakers, dental and industry leaders, sponsors and special guests. Choose either day.'),
    ('workshop', 'Priority Workshop Registration', 'Early registration for selected workshops. Workshop fees are separate.'),
    ('gift', 'Premium VIP Gift Package', 'Selected iSmile gifts and premium items from participating partners.'),
    ('recognition', 'VIP Recognition & Experiences', 'Personal VIP recognition, exclusive updates and priority access to selected summit experiences.'),
    ('home', 'Badges Delivered to Your Home', 'We’ll call after registration to arrange your badge delivery.'),
]
benefits_html = ''.join(f'<li style="--benefit-order:{i}"><span class="benefit-icon">{icon(sym)}</span><div><h3>{escape(title)}</h3><p>{escape(copy)}</p></div></li>' for i, (sym, title, copy) in enumerate(benefits))
choices = []
for value, name, symbol, price, caption in [
    ('student', 'Student', 'student', f'{tickets["student"]:,} IQD', 'For dental students'),
    ('professional', 'Professional', 'person', f'{tickets["professional"]:,} IQD', 'For dental professionals'),
    ('vip', 'VIP', 'crown', '$100', 'The elevated experience'),
]:
    checked = ' checked' if value == 'professional' else ''
    choices.append(f'<label class="experience-option" data-choice="{value}"><input type="radio" name="experience" value="{value}"{checked}><span class="choice-icon">{icon(symbol)}</span><span class="choice-copy"><strong>{name}</strong><small>{caption}</small></span><span class="choice-price">{price}</span><span class="choice-check">{icon("check")}</span></label>')

config = json.dumps({'vipPrice': 100, 'professionalPrice': tickets['professional'], 'studentPrice': tickets['student'], 'lunchPrice': lunch_price, 'footer': footer_data})

html = '''<!doctype html>
<html lang="en" dir="ltr" data-theme="dark" data-title-key="reg_page_title">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="../"><title>iSmile · The VIP Experience</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/base/tokens.css?v=93"><link rel="stylesheet" href="css/base/base.css?v=93">
<link rel="stylesheet" href="css/components/buttons.css?v=93"><link rel="stylesheet" href="css/sections/register.css?v=93">
<link rel="stylesheet" href="css/components/brand.css?v=93"><link rel="stylesheet" href="css/sections/hero.css?v=93"><link rel="stylesheet" href="css/sections/register-page.css?v=93">
<link rel="stylesheet" href="css/layout/header.css?v=93">
<link rel="stylesheet" href="css/layout/footer.css?v=93">
<link rel="stylesheet" href="docs/vip-experience.css">
</head>
<body>
<div class="prototype-tools"><span>Interactive registration preview</span><div><button type="button" id="fillDemo">Use sample details</button><button type="button" id="replayLogo">Replay logo</button><button type="button" id="replayExperience">Replay VIP</button></div></div>
<div class="experience-root" id="experienceStage">
REGISTRATION_HEADER
<main class="wrap">
REGISTRATION_HERO
<fieldset class="experience-choices"><legend class="sr-only">Choose your ticket type</legend>CHOICES</fieldset>
<div class="reg experience-grid">
<aside class="experience-aside">
<section class="vip-experience" id="vipExperience" hidden>
<div class="vip-topline"><span class="vip-eyebrow">CROWN THE VIP EXPERIENCE</span><span class="vip-edition">iSmile</span></div>
<h2>An Elevated Way<br>to Experience iSmile</h2><p class="vip-lead">A closer seat. More comfort.<br>A summit experience made for you.</p>
<div class="vip-price-row"><strong>$100</strong><span>VIP PACKAGE<br><b>One hosted lunch included</b></span></div>
<div class="vip-quick-benefits"><span>Rows 2–3</span><span>Private lounge</span><span>Home badge delivery</span></div>
<button type="button" class="benefits-toggle" id="toggleBenefits" aria-expanded="false" aria-controls="vipBenefits">See all 7 VIP benefits <span>＋</span></button>
<ul class="vip-benefits" id="vipBenefits">BENEFITS</ul>
<div class="vip-footnote">One lunch, on your choice of Day 1 or Day 2.<br>Add the other day if you’d like.</div>
</section>
<section class="reg-panel standard-experience" id="standardExperience"><p class="xp-eyebrow">YOUR SUMMIT STARTS HERE</p><h2 id="standardTitle">The professional experience.</h2><p class="standard-intro" id="standardCopy">Connect with the dental community and enjoy the scientific sessions at iSmile 2026.</p><h3>Three simple steps</h3><ol class="how-steps"><li><span>1</span><div><b>Your details</b><small>For your ticket and attendance certificate.</small></div></li><li><span>2</span><div><b>Choose your lunch</b><small>Add Day 1, Day 2 or both.</small></div></li><li><span>3</span><div><b>Review &amp; pay</b><small>Check everything before payment.</small></div></li></ol><div class="standard-support">Need a hand? <a href="mailto:ismile@italk.krd">ismile@italk.krd</a></div></section>
</aside>
<div class="reg-card" id="regCard">
<div class="reg-progress"><ol class="stepper" id="experienceStepper"><li class="is-active"><span class="st-dot">1</span><span class="st-label">Your details</span></li><li><span class="st-dot">2</span><span class="st-label" id="extrasStepLabel">Lunch</span></li><li><span class="st-dot">3</span><span class="st-label">Review &amp; pay</span></li></ol><div class="st-bar" aria-hidden="true"><i id="experienceBar"></i></div></div>
<form id="regForm" novalidate>
<!-- REGISTRATION_DETAILS -->
<div class="reg-step" data-step="2" hidden>
<div id="standardLunch">STANDARD_LUNCH</div>
<div id="vipExtras" hidden>
<div class="step-head"><p class="xp-eyebrow">YOUR VIP DETAILS</p><h3 tabindex="-1">Make your VIP experience yours.</h3><p>Choose either day for your included hosted lunch.</p></div>
<fieldset class="included-lunch"><legend>Your included hosted lunch <span>One day included with VIP</span></legend><div class="included-lunch-grid"><label class="included-day"><input type="radio" name="includedLunch" value="day1" checked><span class="day-copy"><b>Day 1</b><small>Friday · 20 November</small></span><span class="included-tag">Included</span><span class="day-check">CHECK</span></label><label class="included-day"><input type="radio" name="includedLunch" value="day2"><span class="day-copy"><b>Day 2</b><small>Saturday · 21 November</small></span><span class="included-tag">Included</span><span class="day-check">CHECK</span></label></div></fieldset>
<label class="extra-lunch extra-lunch-card"><input type="checkbox" id="extraLunch" aria-label="Add Day 2 hosted lunch for $42" aria-describedby="extraLunchDescription extraLunchPrice"><div class="extra-lunch-topline"><span>LUNCH ON BOTH DAYS</span><span class="extra-optional">Optional</span></div><div class="extra-lunch-body"><div class="extra-lunch-intro"><span class="extra-lunch-icon">LUNCH_ICON</span><span class="extra-lunch-copy"><strong id="extraLunchTitle">Add Day 2 hosted lunch</strong><small id="extraLunchDescription">Day 2 · Saturday 21 November</small></span></div><div class="extra-lunch-cost"><strong><span class="extra-plus">+</span><span id="extraLunchPrice">$42</span></strong><small>one extra day</small></div></div><div class="extra-lunch-foot"><span id="extraLunchStatus">Add to my VIP package</span><span class="extra-lunch-switch" aria-hidden="true"><i></i></span></div></label>
<div class="badge-delivery"><div class="delivery-heading"><span>HOME</span><div><h4>We’ll take care of the details.</h4><p>Our team will call you after registration to arrange your VIP experience and home badge delivery.</p></div></div></div>
<p class="vip-extras-note">Your chosen lunch is included in the $100 package. Workshop fees are separate.</p>
</div>
</div>
<div class="reg-step" data-step="3" hidden><div class="step-head"><p class="xp-eyebrow">ALMOST THERE</p><h3 tabindex="-1">Your experience, at a glance.</h3><p>Check your details and package before continuing.</p></div><div class="experience-review"><div class="review-package"><span id="reviewTier">Professional</span><b id="reviewPrice">40,000 IQD</b></div><dl id="experienceReview"></dl><div class="review-total"><span>Total</span><strong id="reviewTotal"></strong></div><button type="button" class="review-edit" id="editExperience">Edit my details</button></div>PAYMENTS</div>
<p class="experience-error" id="experienceError" role="alert" hidden></p>
<div class="reg-nav"><button type="button" class="btn btn-outline reg-back is-hidden" id="experienceBack">Back</button><span class="reg-count" id="experienceCount">Step 1 of 3</span><button type="button" class="btn btn-primary reg-next" id="experienceNext"><span>Continue</span>ARROW</button><button type="button" class="btn btn-primary reg-next" id="experiencePayment" hidden>Preview payment</button></div>
</form></div></div>
</main>
REGISTRATION_FOOTER
BACK_TO_TOP
</div>
<dialog class="preview-complete" id="previewComplete"><h2>Preview complete.</h2><p>You’ve explored the registration flow. No payment has been taken.</p><button type="button" id="closePreview">Back to my experience</button></dialog>
<script type="application/json" id="experienceConfig">CONFIG</script><script type="module" src="docs/vip-experience-header.js"></script>
</body></html>'''
for key, value in [('REGISTRATION_HEADER', header), ('REGISTRATION_HERO', hero), ('REGISTRATION_FOOTER', footer), ('BACK_TO_TOP', to_top), ('CHOICES', ''.join(choices)), ('BENEFITS', benefits_html), ('<!-- REGISTRATION_DETAILS -->', details), ('STANDARD_LUNCH', lunch), ('PAYMENTS', payments), ('CONFIG', config), ('LUNCH_ICON', icon('lunch')), ('CROWN', icon('crown')), ('HOME', icon('home')), ('CHECK', icon('check')), ('ARROW', icon('arrow'))]:
    html = html.replace(key, value)
destination = ROOT / 'docs/iSmile-VIP-Experience.html'
copy = json.loads((ROOT / 'data/i18n/registration-en.json').read_text(encoding='utf-8'))
html = html.replace('<h2>An Elevated Way<br>to Experience iSmile</h2>', '<h2 data-i18n="xp_vip_headline">' + copy['xp_vip_headline'] + '</h2>')
for cls, keys in [('vip-lead', ['xp_vip_lead', 'xp_vip_lead_second']), ('vip-footnote', ['xp_footnote_first', 'xp_footnote_second'])]:
    tag = 'p' if cls == 'vip-lead' else 'div'
    replacement = '<' + tag + ' class="' + cls + '">' + '<br>'.join('<span data-i18n="' + key + '">' + escape(copy[key]) + '</span>' for key in keys) + '</' + tag + '>'
    html = re.sub(r'<' + tag + ' class="' + cls + '">.*?</' + tag + '>', lambda _: replacement, html, count=1)
html = html.replace(' THE VIP EXPERIENCE</span>', ' <span data-i18n="xp_vip_eyebrow">THE VIP EXPERIENCE</span></span>')
html = html.replace('>VIP PACKAGE<br>', '><span data-i18n="xp_vip_package">VIP PACKAGE</span><br>')
html = html.replace('<legend>Your included hosted lunch ', '<legend><b data-i18n="xp_included_lunch">Your included hosted lunch</b> ')
html = html.replace('>See all 7 VIP benefits <span>', '><span data-i18n="xp_show_benefits">See all 7 VIP benefits</span><span>')
html = html.replace('class="event-slogan" lang="en" dir="ltr"', 'class="event-slogan" data-i18n="xp_slogan" dir="auto"')
for key, text in copy.items():
    if '{' in text:
        continue
    pattern = r'<(p|h2|h3|h4|span|small|strong|b|legend|button)([^>]*)>' + re.escape(escape(text)) + r'</\1>'
    def translate_node(match):
        if 'data-i18n' in match[2]:
            return match[0]
        return '<' + match[1] + match[2] + ' data-i18n="' + key + '">' + escape(text) + '</' + match[1] + '>'
    html = re.sub(pattern, translate_node, html)
destination.write_text(html, encoding='utf-8')
print(destination)
