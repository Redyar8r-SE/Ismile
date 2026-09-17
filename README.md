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

## Languages (English + Arabic)

The header has an **EN / العربية** switch. Arabic turns the whole page
right-to-left and uses the Noto Kufi Arabic font. The visitor's choice is
remembered in their browser.

- **Page text:** every text in `index.html` carries `data-i18n="key"`
  (`data-i18n-ph` for a field placeholder, `data-i18n-label` for an aria-label).
  The English text stays in the HTML; the Arabic lives in `data/i18n/ar.json`
  under the same key.
- **Strings only JavaScript uses:** `data/i18n/en.json` + `data/i18n/ar.json`.
- **Content files** (`program.json`, `workshops.json`, `sponsors.json`,
  `partners.json`): a translatable value is written per language, for example
  `"title": { "en": "Opening ceremony", "ar": "حفل الافتتاح" }`. A plain string
  still works and shows in both languages.
- **Adding text:** add the English with a `data-i18n` key, then add the same key
  to `ar.json`. A missing Arabic key falls back to English, so nothing breaks.
- To add Kurdish later: write `data/i18n/ku.json`, load it in `js/main.js` the
  way Arabic is loaded, and add a `ku` button next to the others.

## Common edits

- **Add a speaker:** fill `name`, `role`, `photo` (e.g. `assets/speakers/name.jpg`) in `data/speakers.json`.
- **Add a partner:** put the logo in `assets/logos/` and add an entry to `data/partners.json`.
- **Change seats:** edit `seatsLeft` in `data/workshops.json`.
- **Change colors:** edit `css/base/tokens.css`.
