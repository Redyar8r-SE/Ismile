// What the admin page can edit.
//
// Text fields are keys inside data/i18n/{en,ar,ku}.json. The English value in
// those files wins over the English written in index.html, so editing here
// changes all three languages without touching the page markup.
// A field with no label shows its English text as the label, so new keys never
// need a translation of their own.

export const LANGS = [
  { code: "en", label: "English", dir: "ltr" },
  { code: "ar", label: "العربية", dir: "rtl" },
  { code: "ku", label: "کوردی", dir: "rtl" },
];

// Short helpers so the long lists below stay readable.
const f = (key, label) => ({ key, label });
const many = (keys) => keys.map((key) => (Array.isArray(key) ? f(key[0], key[1]) : f(key)));
const numbered = (count, build) => Array.from({ length: count }, (_, i) => build(i + 1)).flat();

const PARTS = [
  // ---------- lists with their own data files ----------
  {
    id: "speakers", title: "Speakers", kind: "speakers",
    hint: "Add a speaker, write the name and specialty in the three languages, and give a photo. Empty rows show as “Coming soon” on the website.",
    fields: [],
  },
  {
    id: "program", title: "Program", kind: "program",
    hint: "Every day and its sessions. Pick the time with the hour, minutes and AM / PM boxes.",
    fields: [],
  },
  {
    id: "workshopList", title: "Workshop list", kind: "list", file: "workshops",
    hint: "Each workshop card, with how many seats are left.",
    itemName: "workshop",
    newItem: () => ({ title: { en: "", ar: "", ku: "" }, company: null, speaker: null, totalSeats: 20, seatsLeft: 20 }),
    itemFields: [
      { key: "title", label: "Workshop title", type: "i18n" },
      { key: "company", label: "Company (optional)", type: "i18n" },
      { key: "speaker", label: "Speaker (optional)", type: "i18n" },
      { key: "totalSeats", label: "Total seats", type: "number" },
      { key: "seatsLeft", label: "Seats left", type: "number" },
    ],
  },
  {
    id: "sponsorTiers", title: "Sponsor tiers", kind: "list", file: "sponsors",
    hint: "The Diamond / Gold / Silver cards and how many open places each one shows.",
    itemName: "tier",
    newItem: () => ({ name: { en: "", ar: "", ku: "" }, className: "tc-silver", subtitle: { en: "", ar: "", ku: "" }, spots: 3 }),
    itemFields: [
      { key: "name", label: "Tier name", type: "i18n" },
      { key: "subtitle", label: "Tier subtitle", type: "i18n" },
      { key: "spots", label: "Open places", type: "number" },
      { key: "className", label: "Colour", type: "select", options: [
        ["tc-dia", "Diamond (light blue)"], ["tc-gold", "Gold"], ["tc-silver", "Silver"], ["tc-bronze", "Bronze"], ["tc-plat", "Platinum"],
      ] },
    ],
  },
  {
    id: "partnerList", title: "Trusted partners", kind: "list", file: "partners", listKey: "partners",
    hint: "Companies that supported iSmile before. Add the logo with the photo button.",
    itemName: "partner",
    newItem: () => ({ name: "", tier: "Bronze", logo: null, bg: "#ffffff" }),
    itemFields: [
      { key: "name", label: "Company name", type: "text" },
      { key: "tier", label: "Tier", type: "select", optionsFrom: "tiers" },
      { key: "logo", label: "Logo", type: "image" },
      { key: "bg", label: "Logo background", type: "color" },
      { key: "round", label: "Round logo", type: "checkbox" },
    ],
  },
  {
    id: "journeyList", title: "Years", kind: "list", file: "journey",
    hint: "The timeline under About. Add a year whenever there is news, for example 2027.",
    itemName: "year",
    newItem: () => ({ year: "", title: { en: "", ar: "", ku: "" }, text: { en: "", ar: "", ku: "" } }),
    itemFields: [
      { key: "year", label: "Year", type: "text" },
      { key: "title", label: "Title", type: "i18n" },
      { key: "text", label: "Text", type: "i18n" },
      { key: "now", label: "Highlight this one (the coming edition)", type: "checkbox" },
    ],
  },
  {
    id: "projectList", title: "italk projects", kind: "list", file: "projects",
    hint: "The small blocks under the italk card, such as italkMedX. Add one for every project you want to show.",
    itemName: "project",
    newItem: () => ({ title: { en: "", ar: "", ku: "" }, text: { en: "", ar: "", ku: "" } }),
    itemFields: [
      { key: "title", label: "Project name", type: "i18n" },
      { key: "text", label: "Text under the name", type: "i18n" },
    ],
  },

  // ---------- page text ----------
  {
    id: "menu", title: "Menu & buttons",
    hint: "The links in the top bar and the two buttons under the big title.",
    fields: many([
      ["nav_about", "Menu: About"], ["nav_program", "Menu: Program"], ["nav_workshops", "Menu: Workshops"],
      ["nav_speakers", "Menu: Speakers"], ["nav_sponsors", "Menu: Sponsors"], ["nav_companies", "Menu: Companies"],
      ["nav_venue", "Menu: Venue"], ["nav_register", "Register button"],
      ["cta_register", "Big button 1"], ["cta_program", "Big button 2"],
      ["a_theme", "Light/dark button (for screen readers)"], ["a_menu", "Menu button (for screen readers)"], ["a_lang", "Language buttons (for screen readers)"],
      ["a_days", "Program days (for screen readers)"], ["a_copy", "Copy address (for screen readers)"],
      ["page_title", "Browser tab title"],
    ]),
  },
  {
    id: "hero", title: "Top of the page",
    hint: "The big title visitors see first.",
    fields: many([["hero_title", "Main title"], ["hero_sub", "Subtitle"]]),
  },
  {
    id: "ticket", title: "Ticket card",
    hint: "The card beside the title that looks like an event pass.",
    fields: many([
      ["t_pass", "Small label above the name"], ["t_edition", "Badge (edition)"],
      ["t_when", "Row 1 label"], ["t_when_v", "Row 1 value (date)"],
      ["t_len", "Row 2 label"], ["t_len_v", "Row 2 value (length)"],
      ["t_where", "Row 3 label"], ["t_venue", "Venue name"],
      ["org_by", "Organised by (label)"], ["org_italk", "Organiser name"],
      ["org_with", "In collaboration with (label)"], ["org_kda", "Partner name"],
    ]),
  },
  {
    id: "stats", title: "Proven reach",
    hint: "The dark panel with the three numbers.",
    fields: [
      f("stat_title", "Panel title"), f("stat_sub", "Panel subtitle"),
      { key: "stat1_n", label: "Number 1", short: true }, f("stat1", "Number 1 — text under it"),
      { key: "stat2_n", label: "Number 2", short: true }, f("stat2", "Number 2 — text under it"),
      { key: "stat3_n", label: "Number 3", short: true }, f("stat3", "Number 3 — text under it"),
    ],
  },
  {
    id: "about", title: "About iSmile",
    hint: "The story, the three years, and the italk / Vision / Mission cards.",
    fields: many([
      ["ab_title", "Section title"], ["ab_lead", "Section text"],
      ["org_visit", "Link to italk.krd"], ["org_p1", "italk paragraph 1"], ["org_p2", "italk paragraph 2"],
      ["org_f1", "Fact 1 label"], ["org_f2b", "Fact 2 value"], ["org_f2", "Fact 2 label"],
      ["org_f3b", "Fact 3 value"], ["org_f3", "Fact 3 label"], ["org_medx", "italkMedX note"],
      ["vis_t", "Vision title"], ["vis_p", "Vision text"],
      ["mis_t", "Mission title"], ["mis_p", "Mission text"],
    ]),
  },
  {
    id: "experience", title: "What happens",
    hint: "The eight boxes about the conference, exhibition, workshops and the rest.",
    fields: [
      f("exp_title", "Section title"), f("exp_sub", "Section text"),
      f("bx_tag", "Big box — small label"), f("bx_live", "“Live” badge"),
      ...numbered(8, (n) => [f(`bx${n}`, `Box ${n} title`), f(`bx${n}p`, `Box ${n} text`)]),
      ...numbered(4, (n) => [f(`chip${n}`, `Big box tag ${n}`)]),
    ],
  },
  {
    id: "areas", title: "Scientific areas",
    hint: "The eight numbered subject cards.",
    fields: [
      f("area_title", "Section title"), f("area_sub", "Section text"),
      ...numbered(8, (n) => [f(`ar${n}`, `Area ${n} title`), f(`ar${n}p`, `Area ${n} text`)]),
    ],
  },
  {
    id: "programText", title: "Program — words",
    hint: "The headings and column names of the program table. The days and sessions are in “Program”.",
    fields: many([
      ["prog_title", "Section title"], ["prog_sub", "Section text"],
      ["pg_sessions", "“sessions” (after the count)"], ["pg_schedule", "“schedule” (after the times)"],
      ["pg_locations", "“locations” (after the count)"],
      ["pg_time", "Column: time"], ["pg_session", "Column: session"],
      ["pg_topic_speaker", "Column: topic and speaker"], ["pg_location", "Column: location"],
      ["pg_to", "Word between start and end time"], ["am", "Morning (AM)"], ["pm", "Afternoon (PM)"], ["pg_topic", "Row: topic"], ["pg_speaker", "Row: speaker"],
      ["pg_tba", "Shown when the topic is not known yet"],
    ]),
  },
  {
    id: "workshopsText", title: "Workshops — words",
    hint: "The heading and labels on the workshop cards. The workshops themselves are in “Workshop list”.",
    fields: many([
      ["ws_title", "Section title"], ["ws_sub", "Section text"],
      ["w_by", "Label: run by"], ["w_company", "Shown when no company is set"],
      ["w_speaker", "Label: speaker"], ["w_dr", "Shown when no speaker is set"],
      ["w_open", "Badge: open"], ["w_full", "Badge: full"], ["w_left", "After the seat count"],
      ["w_notify", "Notify button"], ["w_notified", "Notify button after pressing"],
    ]),
  },
  {
    id: "speakersText", title: "Speakers — words",
    hint: "The heading and the placeholder text for speakers you have not announced yet.",
    fields: many([
      ["sp_title", "Section title"], ["sp_sub", "Section text"],
      ["sp_name", "Placeholder name"], ["sp_role", "Placeholder specialty"], ["sp_soon", "Badge on empty photos"],
    ]),
  },
  {
    id: "sponsorsText", title: "Sponsors — words",
    hint: "The headings above the sponsor tiers and the trusted partners.",
    fields: many([
      ["spon_title", "Section title"], ["spon_sub", "Section text"], ["spon_cta", "Become a sponsor button"],
      ["spon_slot", "Empty logo place"], ["spon_spots", "After the number of places"],
      ["tp_title", "Partners title"], ["tp_sub", "Partners text"],
      ["tp_company", "“company” (one)"], ["tp_companies", "“companies” (many)"], ["tp_cta", "Line above the sponsor link"],
    ]),
  },
  {
    id: "companies", title: "For companies",
    hint: "The three cards for sponsors, exhibition booths and team registration.",
    fields: many([
      ["co_title", "Section title"], ["co_sub", "Section text"],
      ["co1", "Card 1 title"], ["co1p", "Card 1 text"], ["co1b", "Card 1 button"],
      ["co2", "Card 2 title"], ["co2p", "Card 2 text"], ["co2b", "Card 2 button"],
      ["co3", "Card 3 title and button"], ["co3p", "Card 3 text"],
    ]),
  },
  {
    id: "registration", title: "Registration form",
    hint: "Everything inside the three-step form, including the error messages.",
    fields: many([
      ["reg_kicker", "Small label"], ["reg_title", "Section title"], ["reg_sub", "Section text"],
      ["reg_tickets", "Ticket options title"],
      ["tk_prof", "Ticket 1 name"], ["tk_prof_d", "Ticket 1 text"],
      ["tk_student", "Ticket 2 name"], ["tk_student_d", "Ticket 2 text"],
      ["tk_team", "Ticket 3 name"], ["tk_team_d", "Ticket 3 text"], ["tk_soon", "Price badge"],
      ["how_title", "How it works title"],
      ["how1", "Step 1"], ["how1_d", "Step 1 text"], ["how2", "Step 2"], ["how2_d", "Step 2 text"],
      ["how3", "Step 3"], ["how3_d", "Step 3 text"],
      ["help_title", "Help box title"], ["reg_b4", "Refund note"],
      ["st1", "Stepper 1"], ["st2", "Stepper 2"], ["st3", "Stepper 3"], ["step_of", "Step counter (keep {n} and {total})"],
      ["s1_title", "Step 1 title"], ["s1_sub", "Step 1 text"],
      ["mode_person", "Choice 1 title"], ["mode_person_d", "Choice 1 text"],
      ["mode_company", "Choice 2 title"], ["mode_company_d", "Choice 2 text"],
      ["s2_title_p", "Step 2 title (person)"], ["s2_title_c", "Step 2 title (company)"], ["s2_sub", "Step 2 text"],
      ["f_name", "Field: full name"], ["ph_name", "Field: full name — grey hint"],
      ["f_phone", "Field: WhatsApp"], ["f_email", "Field: email"], ["f_spec", "Field: specialty"],
      ["o_choose", "Specialty: first option"], ["o_gp", "Specialty: general"], ["o_spec", "Specialty: specialist"],
      ["o_omfs", "Specialty: surgeon"], ["o_lab", "Specialty: technician"], ["o_acad", "Specialty: academic"],
      ["o_student", "Specialty: student"],
      ["f_ticket", "Field: ticket type"], ["ticket_prof", "Ticket toggle 1"], ["ticket_student", "Ticket toggle 2"],
      ["f_uni", "Field: university"], ["ph_uni", "Field: university — grey hint"],
      ["f_cname", "Field: company name"], ["f_contact", "Field: contact person"],
      ["f_dentists", "Field: dentists attending"], ["add_dentist", "Add dentist button"],
      ["d_name", "Dentist row: name"], ["d_phone", "Dentist row: WhatsApp"],
      ["dentist", "“dentist” (one)"], ["dentists", "“dentists” (many)"], ["remove", "Remove dentist"],
      ["s3_title", "Step 3 title"], ["s3_sub", "Step 3 text"], ["pay_legend", "Payment method label"],
      ["pay_fib_t", "Payment 1 name"], ["pay_fib_d", "Payment 1 text"],
      ["pay_fastpay_t", "Payment 2 name"], ["pay_fastpay_d", "Payment 2 text"],
      ["review_title", "Review box title"], ["rv_type", "Review row: registration"], ["edit", "Edit button"],
      ["terms", "Agreement sentence"], ["back", "Back button"], ["continue", "Continue button"], ["submit", "Finish button"],
      ["success_title", "Done title"], ["success_text", "Done text (keep {method} {phone} {email})"],
      ["success_ref", "Reference number label"], ["register_again", "Register someone else"],
      ["err_required", "Error: empty field"], ["err_name", "Error: short name"], ["err_email", "Error: email"],
      ["err_phone", "Error: phone"], ["err_terms", "Error: agreement"],
    ]),
  },
  {
    id: "venue", title: "Venue",
    hint: "The hotel card, the address and the map buttons.",
    fields: many([
      ["venue_title", "Small label"], ["venue_heading", "Section title"], ["venue_sub", "Section text"],
      ["venue_type", "Under the hotel name"],
      ["venue_addr_label", "Address label"], ["venue_addr", "Address"],
      ["copy", "Copy button"], ["copied", "Copy button after pressing"],
      ["venue_when_label", "Date label"], ["venue_when", "Date"],
      ["venue_air_label", "Airport label"], ["venue_air", "Airport"],
      ["venue_spaces", "Spaces label"],
      ["vs1", "Space 1"], ["vs2", "Space 2"], ["vs3", "Space 3"], ["vs4", "Space 4"],
      ["directions", "Directions button"], ["maps", "Google Maps button"],
      ["dir_hint", "Note under the buttons"], ["dir_locating", "While finding the visitor"],
      ["dir_found", "When the location was found"], ["dir_denied", "When location was blocked"],
      ["dir_failed", "When location failed"], ["venue_badge", "Badge on the map"],
    ]),
  },
  {
    id: "footer", title: "Footer",
    hint: "The bottom of the page.",
    fields: many([
      ["foot_org", "Organisers line"], ["foot_links", "Links column title"],
      ["foot_contact", "Contact column title"], ["legal", "Copyright line"],
    ]),
  },
];

// ---------------------------------------------------------------------------
// The admin follows the website from top to bottom. Each step is one screen and
// may hold several blocks (the wording of a section plus its list of items).
// ---------------------------------------------------------------------------
const byId = Object.fromEntries(PARTS.map((part) => [part.id, part]));

const text = (id, title) => ({ ...byId[id], type: "text", title: title ?? null });
const list = (id, title) => ({ ...byId[id], type: "list", title: title ?? null });
const special = (id, kind, title) => ({ ...byId[id], type: kind, title: title ?? null });

export const GROUPS = [
  {
    id: "hero", title: "Top of the page", where: "The first screen visitors see",
    blocks: [text("hero", "Title and subtitle"), text("ticket", "Ticket card")],
  },
  {
    id: "stats", title: "Proven reach", where: "The dark panel with the three numbers",
    blocks: [text("stats")],
  },
  {
    id: "about", title: "About iSmile", where: "The story, the years, italk and the partner",
    blocks: [list("journeyList", "Years on the timeline"), list("projectList", "italk projects"), text("about", "Wording of the section")],
  },
  {
    id: "experience", title: "What happens", where: "The eight boxes about the two days",
    blocks: [text("experience")],
  },
  {
    id: "areas", title: "Scientific areas", where: "The eight numbered subject cards",
    blocks: [text("areas")],
  },
  {
    id: "program", title: "Program", where: "The day tabs and the schedule table",
    blocks: [special("program", "program", "Days and sessions"), special("program", "types", "Session types"), text("programText", "Wording around the table")],
  },
  {
    id: "workshops", title: "Workshops", where: "The workshop cards with seats",
    blocks: [list("workshopList", "The workshops"), text("workshopsText", "Wording on the cards")],
  },
  {
    id: "speakers", title: "Speakers", where: "The speaker photos grid",
    blocks: [special("speakers", "speakers", "The speakers"), text("speakersText", "Wording and placeholders")],
  },
  {
    id: "sponsors", title: "Sponsors & partners", where: "Sponsor tiers and the trusted partner logos",
    blocks: [list("sponsorTiers", "Sponsor tiers"), list("partnerList", "Trusted partners"), text("sponsorsText", "Wording")],
  },
  {
    id: "companies", title: "For companies", where: "The three cards for companies",
    blocks: [text("companies")],
  },
  {
    id: "registration", title: "Registration", where: "The three-step form and its messages",
    blocks: [text("registration")],
  },
  {
    id: "venue", title: "Venue", where: "The hotel card, address and map",
    blocks: [text("venue")],
  },
  {
    id: "footer", title: "Footer", where: "The bottom of every page",
    blocks: [text("footer")],
  },
  {
    id: "menu", title: "Menu & buttons", where: "The top bar links, used on every screen",
    blocks: [text("menu")],
  },
  {
    id: "security", title: "Password", where: "Who can open this admin page",
    blocks: [{ type: "security", title: "Sign-in for the admin page", fields: [] }],
  },
];

// Files the list editors read and write.
export const DATA_FILES = {
  speakers: "data/speakers.json",
  journey: "data/journey.json",
  projects: "data/projects.json",
  workshops: "data/workshops.json",
  sponsors: "data/sponsors.json",
  partners: "data/partners.json",
  program: "data/program.json",
};
