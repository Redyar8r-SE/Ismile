// What the admin page can edit.
// Every field is one key inside data/i18n/en.json, ar.json and ku.json.
// The English value in those files wins over the English written in index.html,
// so editing here changes all three languages without touching the page markup.

export const LANGS = [
  { code: "en", label: "English", dir: "ltr" },
  { code: "ar", label: "العربية", dir: "rtl" },
  { code: "ku", label: "کوردی", dir: "rtl" },
];

export const GROUPS = [
  {
    id: "speakers",
    title: "Speakers",
    kind: "speakers",
    hint: "Add a speaker, write the name and specialty in the three languages, and give a photo. Empty rows show as “Coming soon” on the website.",
    fields: [],
  },
  {
    id: "photos",
    title: "Photos",
    kind: "photos",
    hint: "Paste a picture with Ctrl + V, drop it here, or click to choose a file. Big pictures are made smaller automatically so the site stays fast.",
    fields: [],
  },
  {
    id: "hero",
    title: "Top of the page",
    hint: "The big title visitors see first, and the two buttons under it.",
    fields: [
      { key: "hero_title", label: "Main title", type: "text" },
      { key: "hero_sub", label: "Subtitle", type: "area" },
      { key: "cta_register", label: "Button 1 (register)", type: "text" },
      { key: "cta_program", label: "Button 2 (program)", type: "text" },
    ],
  },
  {
    id: "ticket",
    title: "Ticket card",
    hint: "The card beside the title that looks like an event pass.",
    fields: [
      { key: "t_pass", label: "Small label above the name", type: "text" },
      { key: "t_edition", label: "Badge (edition)", type: "text" },
      { key: "t_when", label: "Row 1 label", type: "text" },
      { key: "t_when_v", label: "Row 1 value (date)", type: "text" },
      { key: "t_len", label: "Row 2 label", type: "text" },
      { key: "t_len_v", label: "Row 2 value (length)", type: "text" },
      { key: "t_where", label: "Row 3 label", type: "text" },
      { key: "t_venue", label: "Row 3 value (venue)", type: "text" },
      { key: "org_by", label: "Organised by (label)", type: "text" },
      { key: "org_italk", label: "Organiser name", type: "text" },
      { key: "org_with", label: "In collaboration with (label)", type: "text" },
      { key: "org_kda", label: "Partner name", type: "text" },
    ],
  },
  {
    id: "stats",
    title: "Proven reach",
    hint: "The dark panel with the three numbers.",
    fields: [
      { key: "stat_title", label: "Panel title", type: "text" },
      { key: "stat_sub", label: "Panel subtitle", type: "area" },
      { key: "stat1_n", label: "Number 1", type: "text", short: true },
      { key: "stat1", label: "Number 1 — text under it", type: "area" },
      { key: "stat2_n", label: "Number 2", type: "text", short: true },
      { key: "stat2", label: "Number 2 — text under it", type: "area" },
      { key: "stat3_n", label: "Number 3", type: "text", short: true },
      { key: "stat3", label: "Number 3 — text under it", type: "area" },
    ],
  },
];

// The English text that lives in index.html, used when en.json has no value yet.
export const HTML_FALLBACK = {
  hero_title: "International Dental Summit 2026",
  hero_sub: "The 2nd International iSmile Summit for Dental Science & Oral Healthcare",
  cta_register: "Register now",
  cta_program: "See the program",
  t_pass: "Event pass",
  t_edition: "2nd edition",
  t_when: "When",
  t_when_v: "November 2026",
  t_len: "Length",
  t_len_v: "2 full days",
  t_where: "Where",
  t_venue: "Grand Millennium Sulaimani",
  org_by: "Organized by",
  org_italk: "italk Foundation",
  org_with: "In collaboration with",
  org_kda: "Kurdistan Dental Association",
  stat_title: "Proven reach",
  stat_sub: "What the first iSmile summit achieved in 2021.",
  stat1_n: "1,500",
  stat1: "Dentists and dental professionals attended",
  stat2_n: "40",
  stat2: "Exhibition booths, sponsors and partners",
  stat3_n: "20",
  stat3: "Scientific and clinical workshops delivered",
};
