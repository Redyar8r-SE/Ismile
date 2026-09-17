# iSmile 2026 website

## Folder structure

```
index.html                 Page markup only (no inline CSS or JS)

css/
  base/tokens.css          Colors, fonts, radii, dark theme
  base/base.css            Reset, typography, section spacing
  components/              Reusable pieces: buttons, brand logo, tier card
  layout/                  Header/navigation and footer
  sections/                One file per page section, in page order

js/
  main.js                  Entry point: loads data, starts every section
  i18n.js                  Translation helper (t, setLang)
  config/icons.js          SVG icons used by JS-rendered sections
  utils/                   Small helpers (JSON loading, initials)
  components/nav.js        Mobile menu
  sections/                Program, workshops, speakers, sponsors, partners, registration

data/                      Content you edit without touching code
  program.json             Days and sessions (add topic / speaker when known)
  workshops.json           Workshop titles and seat counts
  speakers.json            Speakers (null = "Coming soon" placeholder)
  sponsors.json            Sponsor tiers and open spots
  partners.json            Trusted partners, tier, logo file, logo background
  i18n/en.json             English strings used by JavaScript

assets/logos/              Partner logo images (.webp)
```

## Running locally

The page loads JSON with `fetch` and uses JavaScript modules, so browsers block it
when you double-click `index.html`. Serve the folder instead, for example:

- VS Code: install **Live Server**, right-click `index.html` → *Open with Live Server*
- or in a terminal in this folder: `npx serve` or `python -m http.server 8000`
  then open http://localhost:8000

## Common edits

- **Add a speaker:** fill `name`, `role`, `photo` (e.g. `assets/speakers/name.jpg`) in `data/speakers.json`.
- **Add a partner:** put the logo in `assets/logos/` and add an entry to `data/partners.json`.
- **Change seats:** edit `seatsLeft` in `data/workshops.json`.
- **Change colors:** edit `css/base/tokens.css`.
