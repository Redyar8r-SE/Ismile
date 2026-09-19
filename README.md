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

## Languages (English, Arabic, Kurdish)

The header has an **EN / العربية / کوردی** switch. Arabic and Kurdish (Sorani)
turn the whole page right-to-left and use the Noto Kufi Arabic font. The
visitor's choice is remembered in their browser.

- **Page text:** every text in `index.html` carries `data-i18n="key"`
  (`data-i18n-ph` for a field placeholder, `data-i18n-label` for an aria-label).
  The English text stays in the HTML; the Arabic lives in `data/i18n/ar.json`
  and the Kurdish in `data/i18n/ku.json`, under the same key.
- **Strings only JavaScript uses:** `data/i18n/en.json`, `ar.json`, `ku.json`.
- **Content files** (`program.json`, `workshops.json`, `sponsors.json`,
  `partners.json`): a translatable value is written per language, for example
  `"title": { "en": "Opening ceremony", "ar": "حفل الافتتاح", "ku": "ئاهەنگی کردنەوە" }`.
  A plain string still works and shows in every language.
- **Adding text:** add the English with a `data-i18n` key, then add the same key
  to `ar.json` and `ku.json`. A missing key falls back to English, so nothing
  breaks.
- To add another language: write `data/i18n/<code>.json`, load it in
  `js/main.js` the way Arabic and Kurdish are loaded, and add a button next to
  the others. Right-to-left languages are listed in `RTL` in `js/i18n.js`.

## Admin page

`admin.html` edits every text, list and photo on the site. **Open it at the
Netlify address**, for example `https://ismile-2026.netlify.app/admin.html`,
because that is where the server lives. Worth a bookmark.

The public site on GitHub Pages has a small **Admin** link in its footer, but it
opens the GitHub Pages copy of the page, which has no server behind it — it will
say so and refuse to save. To make that link work too, put the Netlify address
in `data/admin-server.json` (step 4 below).

### Who can open the admin

`data/admin-accounts.json` lists the people who may open the admin page. While
the list is empty the admin opens without asking, so you cannot lock yourself
out. Add somebody and the page asks for an email and password first.

The password is asked for every time the admin page is opened or refreshed —
nothing is remembered between visits, so walking away from the computer leaves
the admin locked.

Manage the list in the admin under **16. Password**: add a person, change a
password by adding the same email again, or press **Remove**. Then either press
**Save to the website** (needs saving to be working) or **Copy the file** and
paste it into `data/admin-accounts.json` on github.com yourself.

Passwords are never stored — only a salted PBKDF2 hash, which cannot be turned
back into the password. Be clear about what this is, though: the check happens
in the browser, so somebody who knows how a web page works can get past it. It
keeps ordinary visitors out of the admin screen; it is not a safe for secrets,
and the file is readable by anyone since the repository is public. Use a
password you do not use anywhere else.

### When the admin code changes

`admin.html` loads its code as `main.js?v=8`, and the modules import each other
the same way. Browsers cache these files hard, and a browser holding an old
`main.js` that imports a file since deleted loads nothing at all: the admin
appears as an empty page. **After changing anything in `js/admin/`, raise the
number in every `?v=` in `admin.html` and `js/admin/*.js`** so every visitor
gets the new code instead of a broken mixture of old and new.

### Saving changes

Saving needs an **email and password**. A Netlify function
(`netlify/functions/api.mjs`) checks the sign-in against settings kept on the
server, then writes to GitHub with a token the browser never sees. The function
only accepts writes under `data/` and `assets/uploads/`.

Until you are signed in you can still edit every text and use **Download
files**, but nothing reaches the website. If the server is missing or its
settings are not finished, the admin says so at the bottom of the page.

### Setting up the email and password (free)

1. Make the `ADMIN_PASSWORD_HASH` value. In a terminal in this folder, with
   your own password in place of `your-password`:

   ```
   node -e "const c=require('crypto'),s=c.randomBytes(16);console.log('pbkdf2$150000$'+s.toString('base64')+'$'+c.pbkdf2Sync('your-password',s,150000,32,'sha256').toString('base64'))"
   ```

   `ADMIN_EMAIL` is simply the address you want to sign in with. The password
   itself is never stored, only the hash this prints.
2. Create a free Netlify site from this repository
   (Add new site → Import an existing project → GitHub → Ismile).
3. Netlify → Site configuration → Environment variables, add:
   `ADMIN_EMAIL`, `ADMIN_PASSWORD_HASH`, `SESSION_SECRET` (any long random
   text), `GITHUB_TOKEN` (Contents: read and write on this repository),
   `GITHUB_REPO` (`Redyar8r-SE/Ismile`). Then redeploy.
4. Optional: put the Netlify address in `data/admin-server.json`, for example
   `{ "api": "https://ismile-2026.netlify.app/api" }`. You do not need this when
   you open the admin at the Netlify address — it finds its own server. Fill it
   in only if you also want the footer **Admin** link on the GitHub Pages site
   to work.

To change the password later, repeat step 1 and update the two settings. The
password itself is never stored — only a PBKDF2 hash of it, which lives in the
Netlify settings and never in this repository.


## Common edits

- **Add a speaker:** fill `name`, `role`, `photo` (e.g. `assets/speakers/name.jpg`) in `data/speakers.json`.
- **Add a partner:** put the logo in `assets/logos/` and add an entry to `data/partners.json`.
- **Change seats:** edit `seatsLeft` in `data/workshops.json`.
- **Change colors:** edit `css/base/tokens.css`.
