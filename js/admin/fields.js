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
    id: "ticketPrices", title: "Ticket prices", kind: "single", file: "tickets",
    hint: "In Iraqi dinars, whole numbers. Leave 0 to show “Price soon”. Workshop prices are set on each workshop in “Workshop list”.",
    itemFields: [
      { key: "professional", label: "Professional ticket (IQD)", type: "number" },
      { key: "student", label: "Student ticket (IQD)", type: "number" },
    ],
  },
  {
    id: "workshopList", title: "Workshop list", kind: "list", file: "workshops",
    hint: "Each workshop card, with its price and how many seats are left. The link ID is what the “Reserve seat” button sends to the registration page — letters and numbers only, and do not change it once people have reserved.",
    itemName: "workshop",
    newItem: () => ({ id: `ws${Date.now().toString(36).slice(-4)}`, icon: "tools", title: { en: "", ar: "", ku: "" }, company: null, speaker: null, price: 0, totalSeats: 20, seatsLeft: 20 }),
    itemFields: [
      { key: "title", label: "Workshop title", type: "i18n" },
      {
        key: "icon", label: "Icon on the registration card", type: "icons", fallback: "tools",
        options: [
          ["tools", "Hands-on"], ["tooth", "Tooth"], ["implant", "Implant"], ["smile", "Smile"],
          ["root", "Root canal"], ["scan", "Scanner"], ["braces", "Braces"], ["drill", "Handpiece"],
        ],
      },
      { key: "company", label: "Company (optional)", type: "i18n" },
      { key: "speaker", label: "Speaker (optional)", type: "i18n" },
      { key: "id", label: "Link ID (e.g. implant)", type: "text" },
      { key: "price", label: "Price per seat (IQD)", type: "number" },
      { key: "totalSeats", label: "Total seats", type: "number" },
      { key: "seatsLeft", label: "Seats left", type: "number" },
    ],
  },
  {
    id: "sponsorTiers", title: "Sponsor tiers", kind: "list", file: "sponsors", listKey: "tiers",
    hint: "The Diamond / Platinum / Gold / Silver / Exhibitor cards and how many places are still open in each.",
    itemName: "tier",
    refreshes: ["sponsorList"],
    rowInfo: (tier, data) => {
      const used = (data.sponsors.sponsors || []).filter((s) => s.tier === tier.id).length;
      return {
        label: used ? `${used} sponsor${used === 1 ? "" : "s"}` : "no sponsors yet",
        lock: used > 0,
        lockReason: "Sponsors use this tier — move them first",
      };
    },
    newItem: () => ({ id: `tier${Date.now().toString(36).slice(-4)}`, name: { en: "", ar: "", ku: "" }, className: "tc-silver", subtitle: { en: "", ar: "", ku: "" }, spots: 3 }),
    itemFields: [
      { key: "name", label: "Tier name", type: "i18n" },
      { key: "subtitle", label: "Tier subtitle", type: "i18n" },
      { key: "spots", label: "Open places", type: "number" },
      { key: "className", label: "Colour", type: "select", options: [
        ["tc-dia", "Diamond (light blue)"], ["tc-gold", "Gold"], ["tc-silver", "Silver"], ["tc-exhibitor", "Exhibitor"], ["tc-bronze", "Bronze"], ["tc-plat", "Platinum"],
      ] },
    ],
  },
  {
    id: "sponsorList", title: "Sponsors", kind: "list", file: "sponsors", listKey: "sponsors",
    hint: "Companies that already sponsor iSmile 2026. Each one shows its logo in its tier, in place of an open slot.",
    itemName: "sponsor",
    refreshes: ["sponsorTiers"],
    newItem: () => ({ name: "", tier: "gold", logo: null, bg: "#ffffff" }),
    itemFields: [
      { key: "name", label: "Company name", type: "text" },
      { key: "tier", label: "Tier", type: "select", optionsFrom: "sponsorTiers" },
      { key: "logo", label: "Logo", type: "image" },
      { key: "bg", label: "Logo background", type: "color" },
      { key: "round", label: "Round logo", type: "checkbox" },
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

  {
    id: "footerLinks", title: "Footer links", kind: "list", file: "footer", listKey: "links",
    hint: "The links under “Summit”. Use #program for a place on this page, or a full address like https://italk.krd/.",
    itemName: "link",
    newItem: () => ({ label: { en: "", ar: "", ku: "" }, href: "#program" }),
    itemFields: [
      { key: "label", label: "Wording", type: "i18n" },
      { key: "href", label: "Link", type: "text" },
    ],
  },
  {
    id: "footerContact", title: "Contact lines", kind: "list", file: "footer", listKey: "contact",
    hint: "The lines under “Contact”: email, phone, website. Leave the link empty for plain text, or write mailto:you@site.krd for an email and tel:+9647… for a phone.",
    itemName: "line",
    newItem: () => ({ text: "", href: "" }),
    itemFields: [
      { key: "text", label: "What is shown", type: "text" },
      { key: "href", label: "Link (optional)", type: "text" },
    ],
  },

  {
    id: "sponsorEnquiry", title: "Where sponsor requests go", kind: "single", file: "sponsors", objectKey: "enquiry",
    hint: "When a company finishes the form on the sponsor page, these are offered as the way to send it. Write the WhatsApp number with its country code and no spaces, like 9647701234567. Leave it empty to offer email only.",
    itemFields: [
      { key: "whatsapp", label: "WhatsApp number (country code, no +)", type: "text" },
      { key: "email", label: "Email address", type: "text" },
    ],
  },

  {
    id: "mapSettings", title: "Map", kind: "single", file: "map",
    hint: "Easiest way: open Google Maps, find the place, press Share → Copy link, and paste it below. A short maps.app.goo.gl link does not work. Open it first, then copy the long address from the browser bar.",
    itemFields: [
      { key: "url", label: "Google Maps link (most exact)", type: "text" },
      { key: "place", label: "Place name", type: "text" },
      { key: "coordinates", label: "Coordinates (optional)", type: "text" },
      { key: "zoom", label: "Zoom (1 far — 20 close)", type: "number" },
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
      ["t_when", "Row 1 label"], ["t_date_full", "Full event date"],
      ["t_len", "Row 2 label"], ["t_len_v", "Row 2 value (length)"],
      ["t_where", "Row 3 label"], ["t_venue", "Venue name"],
      ["org_by", "Organised by (label)"], ["org_italk", "Organiser name"],
      ["org_with", "In collaboration with (label)"], ["ticket_org_kda", "Ticket partner name"], ["org_kda", "Partner name"],
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
      ["w_price_seat", "Under the price"], ["w_reserve", "Reserve seat button"], ["w_reserved", "Badge after a seat is reserved"],
      ["w_gate_title", "Register-first popup: title"], ["w_gate_text", "Register-first popup: text"],
      ["w_gate_go", "Popup: register button"], ["w_gate_close", "Popup: not now button"],
      ["w_gate_have", "Popup: I have a reference"], ["w_gate_ref", "Popup: reference label"],
      ["w_gate_email", "Popup: email label"], ["w_gate_continue", "Popup: continue button"],
      ["w_gate_err", "Popup: error when the reference or email is wrong"],
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
      ["tp_company", "“company” (one)"], ["tp_companies_brands", "“companies & brands” (many)"], ["tp_cta", "Line above the sponsor link"],
    ]),
  },
  {
    id: "companies", title: "For companies",
    hint: "The two cards for sponsors and exhibition booths.",
    fields: many([
      ["co_title", "Section title"], ["co_sub", "Section text"],
      ["co1", "Card 1 title"], ["co1p", "Card 1 text"], ["co1b", "Card 1 button"],
      ["co2", "Card 2 title"], ["co2p", "Card 2 text"], ["co2b", "Card 2 button"],
    ]),
  },
  {
    id: "registrationInvite", title: "Homepage registration card",
    hint: "The ‘Join us at iSmile 2026’ card on the homepage.",
    fields: many([
      ["reg_invite_kicker", "Small label"], ["reg_invite_title", "Card title"],
      ["reg_invite_text", "Card text"], ["reg_invite_button", "Button text"],
    ]),
  },
  {
    id: "registrationTop", title: "Top of the registration page",
    hint: "The browser title, back button, main heading and introduction.",
    fields: many([
      ["reg_page_title", "Browser tab title"], ["reg_back_site", "Back to the summit button"],
      ["reg_kicker", "Small label"], ["reg_title", "Main title"], ["reg_sub", "Introduction"],
    ]),
  },
  {
    id: "registrationTickets", title: "Tickets and instructions",
    hint: "The ticket choices and side panels beside the registration form.",
    fields: many([
      ["reg_tickets", "Ticket options title"], ["tk_prof", "Professional ticket"],
      ["tk_prof_d", "Professional description"], ["tk_student", "Student ticket"],
      ["tk_student_d", "Student description"], ["tk_soon", "Price badge"],
      ["how_title", "How it works title"], ["how1", "Instruction 1"], ["how1_d", "Instruction 1 text"],
      ["how2", "Instruction 2"], ["how2_d", "Instruction 2 text"], ["how3", "Instruction 3"],
      ["how3_d", "Instruction 3 text"], ["help_title", "Help box title"], ["reg_b4", "Refund note"],
    ]),
  },
  {
    id: "registrationDetails", title: "Personal details form",
    hint: "The first step: names, contact details, gender, age, specialty and ticket type.",
    fields: many([
      ["st2", "Stepper: details"], ["mode_person", "Form introduction title"],
      ["mode_person_d", "Form introduction text"], ["s2_title_p", "Step title"], ["s2_sub", "Step text"],
      ["f_first", "First name label"], ["ph_first", "First name hint"],
      ["f_second", "Second name label"], ["ph_second", "Second name hint"],
      ["f_third", "Third name label"], ["ph_third", "Third name hint"],
      ["f_phone_number", "Phone number label"], ["f_email", "Email label"],
      ["f_city", "City label"], ["ph_city", "City hint"],
      ["f_gender", "Gender label"], ["o_gender", "Gender: choose"], ["o_female", "Gender: female"],
      ["o_male", "Gender: male"], ["o_other", "Gender: other"], ["o_prefer_not", "Gender: prefer not to say"],
      ["f_age", "Age label"], ["ph_age", "Age hint"],
      ["age_decrease", "Decrease age button"], ["age_increase", "Increase age button"], ["f_spec", "Specialty label"],
      ["o_choose", "Specialty: choose"], ["o_gp", "Specialty: general dentist"], ["o_spec", "Specialty: specialist"],
      ["o_omfs", "Specialty: oral surgeon"], ["o_lab", "Specialty: dental technician"],
      ["o_acad", "Specialty: academic"], ["o_student", "Specialty: student"],
      ["f_ticket", "Ticket type label"], ["ticket_prof", "Professional option"], ["ticket_student", "Student option"],
    ]),
  },
  {
    id: "registrationStudent", title: "Student verification",
    hint: "These fields appear only after the visitor chooses the student ticket.",
    fields: many([
      ["f_uni", "University and department label"], ["ph_uni", "University hint"],
      ["f_ambassador", "Ambassador code label"], ["ph_ambassador", "Ambassador code hint"],
      ["f_student_id", "Student ID photo label"], ["student_id_upload_title", "Upload box title"],
      ["student_id_upload_copy", "Upload box explanation"], ["student_id_upload_action", "Choose photo button"],
      ["student_id_hint", "File type and size note"], ["student_id_selected", "Selected photo label"],
    ]),
  },
  {
    id: "registrationWorkshops", title: "Workshop step",
    hint: "The optional second step where a visitor adds paid workshops, and the banner shown to someone this browser already knows.",
    fields: many([
      ["st_ws", "Stepper: workshops"], ["how_ws", "How it works: choose workshops"], ["how_ws_d", "How it works: workshops detail"],
      ["s_ws_title", "Step title"], ["s_ws_sub", "Step text"], ["ws_optional", "Optional badge"],
      ["ws_pick_none", "Summary: nothing selected"], ["ws_pick_count", "Summary: selected (keep {n})"],
      ["ws_skip", "Continue button when nothing is selected"], ["ws_reserved", "Badge: already reserved"],
      ["ws_add", "Card button: add"], ["ws_added", "Card button: added"],
      ["ws_summary_hint", "Summary bar: with workshops"], ["ws_summary_empty", "Summary bar: nothing chosen"],
      ["ws_err_pick", "Error: add-on order with no workshop"],
      ["reg_addon_as", "Banner: registered as"], ["reg_addon_hint", "Banner: already registered hint"],
      ["reg_addon_link", "Banner: add workshops button"], ["reg_addon_sub", "Banner: adding workshops text"],
      ["reg_addon_not_you", "Banner: not you link"], ["reg_ws_wanted", "Note at step 1 (keep {title})"],
    ]),
  },
  {
    id: "registrationPayment", title: "Payment and review",
    hint: "The last step, review box, order summary, agreement and navigation buttons.",
    fields: many([
      ["st3", "Stepper: payment and review"], ["step_of", "Step counter (keep {n} and {total})"],
      ["s3_title", "Step title"], ["s3_sub", "Step text"], ["pay_legend", "Payment method label"],
      ["pay_fib_t", "FIB title"], ["pay_fib_d", "FIB description"],
      ["pay_fastpay_t", "FastPay title"], ["pay_fastpay_d", "FastPay description"],
      ["review_title", "Review box title"], ["rv_type", "Review row: registration"], ["rv_workshops", "Review row: workshops"],
      ["rv_none", "Review: no workshops"], ["rv_ref", "Review row: reference"], ["edit", "Edit button"],
      ["order_title", "Order summary title"], ["order_ticket_prof", "Order line: professional ticket"],
      ["order_ticket_student", "Order line: student ticket"], ["order_total", "Order total label"], ["cur_iqd", "Currency after amounts"],
      ["terms", "Agreement sentence"], ["back", "Back button"], ["continue", "Continue button"],
      ["submit", "Complete registration button"],
    ]),
  },
  {
    id: "registrationMessages", title: "Success and error messages",
    hint: "What visitors see after submitting and when a form field needs correction.",
    fields: many([
      ["success_title", "Success title"], ["success_text", "Success text (keep {method}, {phone} and {email}; ticket delivery is email only)"],
      ["success_ref", "Reference number label"], ["register_again", "Register someone else button"],
      ["success_addon_title", "Success title after adding workshops"], ["success_addon_text", "Success text after adding workshops (keep {method}, {total} and {email})"],
      ["success_back_ws", "Back to the workshops button"],
      ["err_required", "Error: required field"], ["err_name", "Error: short name"], ["err_age", "Error: age"],
      ["err_email", "Error: email"], ["err_phone", "Error: phone"], ["err_student_id", "Error: missing student ID"],
      ["err_student_id_type", "Error: student ID file type"], ["err_student_id_size", "Error: student ID file size"],
      ["err_terms", "Error: agreement"],
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
      ["venue_when_label", "Date label"], ["venue_date_full", "Full event date"], ["venue_duration", "Event duration"],
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
    id: "spTop", title: "Top of the sponsor page",
    hint: "The heading a company reads first, and the way back to the main site.",
    fields: many([
      ["spf_page_title", "Browser tab title"],
      ["spf_back_site", "Back to the site button"],
      ["spf_kicker", "Small label above the title"],
      ["spf_title", "Page title"],
      ["spf_sub", "Text under the title"],
    ]),
  },
  {
    id: "spWhy", title: "Why sponsor (side panel)",
    hint: "The three reasons beside the form, and the two notes under them.",
    fields: many([
      ["spf_why", "Panel title"],
      ["spf_p1", "Reason 1"], ["spf_p1_d", "Reason 1 text"],
      ["spf_p2", "Reason 2"], ["spf_p2_d", "Reason 2 text"],
      ["spf_p3", "Reason 3"], ["spf_p3_d", "Reason 3 text"],
      ["spf_talk", "Help box title"],
      ["spf_privacy", "Note about their details"],
    ]),
  },
  {
    id: "spStep1", title: "Step 1: what they want",
    hint: "The choice between a sponsorship package and an exhibition booth, and the packages underneath. The package names themselves are in “Sponsor tiers” on the Sponsors screen.",
    fields: many([
      ["spf_st1", "Stepper: step 1"], ["spf_st2", "Stepper: step 2"],
      ["step_of", "Step counter (keep {n} and {total})"],
      ["spf_s1_title", "Step 1 title"], ["spf_s1_sub", "Step 1 text"],
      ["spf_kind_spon", "Choice 1: sponsorship"], ["spf_kind_spon_d", "Choice 1 text"],
      ["spf_kind_booth", "Choice 2: booth"], ["spf_kind_booth_d", "Choice 2 text"],
      ["spf_pack_title", "Packages heading"], ["spf_pack_sub", "Packages text"],
      ["spf_pack_unsure", "Last option: not sure yet"], ["spf_pack_unsure_d", "Last option: its text"],
    ]),
  },
  {
    id: "spStep2", title: "Step 2: the company",
    hint: "Every company field label and the review box at the bottom.",
    fields: many([
      ["spf_s2_title", "Step 2 title"], ["spf_s2_sub", "Step 2 text"],
      ["spf_f_company", "Field: company name"], ["spf_ph_company", "Field: company name — grey hint"],
      ["spf_f_contact", "Field: contact person"],
      ["spf_f_role", "Field: position"], ["spf_ph_role", "Field: position — grey hint"],
      ["spf_f_website", "Field: website"], ["spf_ph_website", "Field: website — grey hint"],
      ["spf_f_city", "Field: city"], ["spf_ph_city", "Field: city — grey hint"],
      ["spf_f_note", "Field: their question"],
      ["spf_review", "Review box title"],
      ["spf_rv_kind", "Review row: request"], ["spf_rv_pack", "Review row: package"],
      ["spf_submit", "Send button"],
    ]),
  },
  {
    id: "spDone", title: "After they send",
    hint: "The thank-you screen, and the message that goes to WhatsApp or email.",
    fields: many([
      ["spf_ok_title", "Thank-you title"],
      ["spf_ok_text", "Thank-you text (keep {email})"],
      ["spf_ok_ref", "Reference label"],
      ["spf_send_note", "Note above the send buttons"],
      ["spf_send_whats", "WhatsApp button"], ["spf_send_mail", "Email button"],
      ["spf_msg_head", "First line of the message that is sent"],
    ]),
  },
  {
    id: "footer", title: "Footer",
    hint: "The bottom of the page.",
    fields: many([
      ["foot_org", "Organisers line"], ["foot_links", "Links column title"],
      ["foot_contact", "Contact column title"], ["legal", "Copyright line"],
      ["credit_by", "Credit line (before the developer name)"],
      ["credit_aria", "Developer link (for screen readers)"],
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
const single = (id, title) => ({ ...byId[id], type: "single", title: title ?? null });

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
    blocks: [
      list("sponsorTiers", "Sponsor tiers"), list("sponsorList", "Sponsors with a logo"),
      list("partnerList", "Trusted partners"), text("sponsorsText", "Wording"),
    ],
  },
  {
    id: "becomeSponsor", title: "Become a sponsor", where: "The separate page companies reach from the sponsor and booth buttons",
    blocks: [
      single("sponsorEnquiry", "Where a request goes"),
      text("spTop", "Top of the page"),
      text("spWhy", "Why sponsor"),
      text("spStep1", "Step 1: what they want"),
      text("spStep2", "Step 2: the company"),
      text("spDone", "After they send"),
    ],
  },
  {
    id: "companies", title: "For companies", where: "The two sponsor and exhibition cards",
    blocks: [text("companies")],
  },
  {
    id: "registrationCard", title: "Homepage registration card", where: "The ‘Join us at iSmile 2026’ card",
    blocks: [text("registrationInvite", "Card wording")],
  },
  {
    id: "registration", title: "Registration page", where: "The separate three-step registration page",
    blocks: [
      single("ticketPrices", "Ticket prices"),
      text("registrationTop", "Top of the page"),
      text("registrationTickets", "Tickets and instructions"),
      text("registrationDetails", "Step 1: personal details"),
      text("registrationStudent", "Student verification"),
      text("registrationWorkshops", "Step 2: workshops"),
      text("registrationPayment", "Step 3: payment and review"),
      text("registrationMessages", "Success and errors"),
    ],
  },
  {
    id: "venue", title: "Venue", where: "The hotel card, address and map",
    blocks: [text("venue", "Wording"), single("mapSettings", "Map location")],
  },
  {
    id: "footer", title: "Footer", where: "The bottom of every page",
    blocks: [text("footer", "Wording"), list("footerLinks", "Links"), list("footerContact", "Contact lines")],
  },
  {
    id: "menu", title: "Menu & buttons", where: "The top bar links, used on every screen",
    blocks: [text("menu")],
  },
  {
    id: "security", title: "Password", where: "The email and password that open this admin",
    blocks: [{ type: "security", title: "Sign-in for the admin page", fields: [] }],
  },
];

// Files the list editors read and write.
export const DATA_FILES = {
  speakers: "data/speakers.json",
  map: "data/map.json",
  footer: "data/footer.json",
  journey: "data/journey.json",
  projects: "data/projects.json",
  workshops: "data/workshops.json",
  tickets: "data/tickets.json",
  sponsors: "data/sponsors.json",
  partners: "data/partners.json",
  program: "data/program.json",
};
