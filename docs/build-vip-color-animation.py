"""Preview a color-only VIP transition using the current registration markup."""

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent.parent
source = (ROOT / 'register.html').read_text(encoding='utf-8')
match = re.search(
    r'<section class="block reg-page-section" id="register">.*?</section>',
    source,
    re.DOTALL,
)
if not match:
    raise RuntimeError('Existing registration section not found')
registration = match.group(0).replace('reg-card is-checking', 'reg-card')
for element, price in [('tkPriceProf', '40,000 IQD'), ('tkPriceStudent', '30,000 IQD')]:
    registration = re.sub(
        rf'(<span[^>]+id="{element}"[^>]*>).*?(</span>)',
        lambda m: m.group(1) + price + m.group(2),
        registration,
    )

html = '''<!doctype html>
<html lang="en" dir="ltr" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="../">
  <title>iSmile · Original layout · VIP color animation</title>
  <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700;800&family=Noto+Kufi+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/base/tokens.css?v=93">
  <link rel="stylesheet" href="css/base/base.css?v=93">
  <link rel="stylesheet" href="css/components/buttons.css?v=93">
  <link rel="stylesheet" href="css/sections/register.css?v=93">
  <link rel="stylesheet" href="css/sections/register-page.css?v=93">
  <style>
    .preview-toolbar{height:56px;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 24px;background:#0B1F27;border-bottom:1px solid #23505D;color:#E6F2F2;font-size:12px}
    .preview-toolbar p{font-size:12px;color:#9DB8BE}
    .preview-controls{display:flex;align-items:center;gap:8px;flex:none}
    .preview-controls button{font:inherit;font-size:12px;font-weight:600;min-height:34px;padding:0 14px;border:1px solid #365B65;border-radius:8px;background:#10303B;color:#E6F2F2;cursor:pointer}
    .preview-controls button[aria-pressed="true"]{background:#19424B;border-color:#3CC4BF}
    .preview-controls button[data-ticket="vip"]{border-color:#A68B50;color:#F3D98B}
    .preview-controls button[data-ticket="vip"][aria-pressed="true"]{background:#352046;border-color:#D7B768}
    .preview-viewport{height:calc(100vh - 56px);overflow:auto;position:relative;scrollbar-width:none;background:#0B1F27}
    .preview-viewport::-webkit-scrollbar{display:none}
    .preview-page{position:relative;isolation:isolate}
    .preview-layer{background:linear-gradient(180deg,var(--surface),var(--bg) 460px)}
    .preview-layer .reg-page-section{padding-top:24px!important}
    .preview-layer .reg-aside{top:24px}
    .preview-layer .reg-step{animation:none}
    .preview-layer *{transition:none!important}
    #vip-regForm{padding:28px 32px 30px}
    .preview-layer.is-vip{position:absolute;inset:0;pointer-events:none;clip-path:inset(0 0 100% 0);--bg:#1F152B;--surface:#30213F;--surface-2:#3D2A4C;--text:#F5EFF8;--muted:#BAAEC6;--line:#59456E;--navy:#453059;--navy-2:#30203F;--teal:#D7B768;--teal-ink:#F3D98B;--mint:#402D4D;--enamel:#30213F}
    .preview-layer.is-vip .st-bar i{background:linear-gradient(90deg,#D7B768,#F3D98B)}
    .preview-layer.is-vip .cert-note-ic{color:#2B1838;background:linear-gradient(135deg,#F3D98B,#D7B768)}
    .preview-layer.is-vip .reg-help{background:linear-gradient(135deg,#352046,#523574)}
    .preview-layer.is-vip .reg-help svg{color:#F3D98B}
    .preview-layer.is-vip .reg-help a{color:#E6DAF1}
    @media(max-width:620px){.preview-toolbar{padding:0 12px}.preview-toolbar p{font-size:10px}.preview-controls{gap:5px}.preview-controls button{padding:0 9px;font-size:11px}}
    @media(max-width:560px){#vip-regForm{padding:22px 20px 24px}}
    @media(prefers-reduced-motion:reduce){.preview-layer.is-vip{will-change:auto}}
  </style>
</head>
<body data-preview-mode="original">
  <div class="preview-toolbar">
    <p>Original layout · VIP color preview</p>
    <div class="preview-controls" role="group" aria-label="Preview ticket color">
      <button type="button" data-ticket="original" aria-pressed="true">Original</button>
      <button type="button" data-ticket="vip" aria-pressed="false">Choose VIP</button>
      <button type="button" id="replayVip">Replay</button>
    </div>
  </div>
  <div class="preview-viewport" id="previewViewport">
    <div class="preview-page" id="previewPage">
      <div class="preview-layer" id="baseLayer" inert>REGISTRATION_MARKUP</div>
    </div>
  </div>
  <script src="docs/vip-color-animation.js"></script>
</body>
</html>
'''.replace('REGISTRATION_MARKUP', registration)

destination = ROOT / 'docs' / 'iSmile-VIP-Color-Animation.html'
destination.write_text(html, encoding='utf-8')
print(destination)
