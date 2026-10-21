import { showLoadError } from "./components/load-error.js?v=136";
// Entry point for workshop.html?id=…: one workshop's details page (trainer
// photo, title, degrees, format, duration, level, overview, seats and
// price) and how to book a seat:
//  - organized by iSmile: "Register for workshop" with the email the person
//    registered for the summit with (api/workshop-register.php);
//  - organized by a company: the company's phone and "Call to register".
import { loadJSON } from "./utils/load-json.js?v=136";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang, t, onLangChange } from "./i18n.js?v=136";
import { initNav } from "./components/nav.js?v=136";
import { initTheme } from "./components/theme.js?v=136";
import { initFooter } from "./sections/footer.js?v=136";
import { pick, esc, workshopPrice, photoSrc, byCompany, organizerText, WORKSHOP_ICON } from "./sections/workshops.js?v=138";
import { phoneHref, PHONE_ICON } from "./utils/phone.js?v=136";

const ICONS = {
  format: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 13V5.5a1.5 1.5 0 013 0V12"/><path d="M11 11.5V4a1.5 1.5 0 013 0v7.5"/><path d="M14 11.5V6a1.5 1.5 0 013 0v7"/><path d="M17 12.5a1.5 1.5 0 013 0V15a7 7 0 01-7 7h-1.2a6 6 0 01-4.6-2.2L4.5 16a1.6 1.6 0 012.3-2.2L8 15"/></svg>',
  duration: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  level: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 19v-4M12 19V10M19 19V5"/></svg>',
  mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7.5l8 5.5 8-5.5"/></svg>',
  arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>',
};

// The answers the server can give, as website texts.
const ERRORS = {
  wd_err_email: "wd_err_email",
  wd_err_not_registered: "wd_err_not_registered",
  wd_err_already: "wd_err_already",
  wd_err_full: "wd_err_full",
  wd_err_missing: "wd_err_missing",
  wd_err_by_phone: "wd_err_by_phone",
  err_busy: "wd_err_busy",
};

function initDetails(workshop) {
  const root = document.getElementById("wdRoot");
  // What the visitor did, kept when the language changes.
  const state = { email: "", sending: false, done: false, error: "" };

  function render() {
    if (!workshop) {
      root.innerHTML = `<p class="wd-missing">${esc(t("wd_not_found"))}</p>`;
      return;
    }
    const w = workshop;
    const title = pick(w.title);
    const speaker = pick(w.speaker);
    const credentials = pick(w.credentials);
    const overview = pick(w.overview);
    const photo = photoSrc(w);
    const total = Number(w.totalSeats) || 0;
    const left = Math.max(0, Number(w.seatsLeft) || 0);
    const full = left === 0;
    const company = byCompany(w);
    const takenPercent = total ? Math.round(((total - left) / total) * 100) : 0;
    document.title = `${title} · iSmile 2026`;

    const facts = [["format", "wd_format"], ["duration", "wd_duration"], ["level", "wd_level"]]
      .map(([field, label]) => [field, label, pick(w[field])])
      .filter(([, , value]) => value);

    root.innerHTML = `
      <article class="wd">
        <figure class="wd-photo${photo ? "" : " no-photo"}">
          ${photo ? `<img src="${esc(photo)}" alt="${esc(speaker)}" decoding="async">` : WORKSHOP_ICON}
          ${speaker ? `<figcaption><b dir="auto">${esc(speaker)}</b></figcaption>` : ""}
        </figure>
        <div class="wd-info">
          <span class="wd-badge${company ? " is-company" : ""}" dir="auto">${esc(organizerText(w))}</span>
          <h1 dir="auto">${esc(title)}</h1>
          ${speaker ? `<div class="wd-trainer"><b dir="auto">${esc(speaker)}</b>${credentials ? `<span dir="auto">${esc(credentials)}</span>` : ""}</div>` : ""}
          ${facts.length ? `<dl class="wd-facts">${facts.map(([field, label, value]) => `
            <div class="wd-fact"><dt>${ICONS[field]}<span>${esc(t(label))}</span></dt><dd dir="auto">${esc(value)}</dd></div>`).join("")}
          </dl>` : ""}
          ${overview ? `<section class="wd-overview"><h2>${esc(t("wd_overview"))}</h2>${overview.split(/\n{2,}/).map((part) => `<p dir="auto">${esc(part)}</p>`).join("")}</section>` : ""}
          <section class="wd-book${full ? " is-full" : ""}${company ? " is-call" : ""}" aria-label="${esc(t(company ? "wd_call_title" : "wd_register"))}">
            <div class="wd-book-top">
              <span class="wd-state">${esc(t(full ? "wd_full" : "wd_open"))}</span>
              <span class="wd-left">${esc(company ? t("wd_seats_total").replace("{total}", total) : t("wd_seats").replace("{n}", left).replace("{total}", total))}</span>
            </div>
            ${company ? "" : `<div class="wd-bar" aria-hidden="true"><i style="width:${takenPercent}%"></i></div>`}
            <div class="wd-price"><b dir="ltr">${esc(workshopPrice(w))}</b><small>${esc(t("w_price_seat"))}</small></div>
            ${company ? callBox(w) : state.done ? doneBox() : formBox(full)}
          </section>
        </div>
      </article>`;
    bindForm();
  }

  // A company's workshop: people call the company; nothing is sent from here.
  function callBox(w) {
    const name = pick(w.company);
    const number = String(w.companyPhone || "").trim();
    const href = phoneHref(number);
    const button = href
      ? `<a class="btn wd-call-btn" href="${esc(href)}">${PHONE_ICON}<span>${esc(t("wd_call_btn"))}</span><b dir="ltr">${esc(number)}</b></a>`
      : "";
    return `
      <div class="wd-call">
        <span class="wd-call-ic">${PHONE_ICON}</span>
        <div><b>${esc(t("wd_call_title"))}</b><p dir="auto">${esc(t("wd_call_text").replace("{name}", name))}</p></div>
      </div>
      ${button}
      <p class="wd-help">${esc(t("wd_call_help"))}</p>`;
  }

  function doneBox() {
    return `<div class="wd-done" role="status">${ICONS.check}<div><b>${esc(t("wd_ok_title"))}</b><p>${esc(t("wd_ok_text"))}</p></div></div>`;
  }

  function formBox(full) {
    const error = state.error
      ? `<div class="wd-error" role="alert">${ICONS.warn}<div><p>${esc(t(state.error))}</p>${state.error === "wd_err_not_registered" ? `<a class="btn btn-sm btn-primary" href="register.html">${esc(t("wd_register_summit"))}</a>` : ""}</div></div>`
      : "";
    return `
      <form class="wd-form" id="wdForm" novalidate>
        <div class="wd-trap" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
        <label class="wd-email${state.error ? " has-error" : ""}">
          ${ICONS.mail}
          <span class="sr-only">${esc(t("wd_email_label"))}</span>
          <input type="email" name="email" inputmode="email" autocomplete="email" required maxlength="190" dir="ltr"
            placeholder="${esc(t("wd_email_ph"))}" value="${esc(state.email)}"${full ? " disabled" : ""}${state.error ? ' aria-invalid="true"' : ""}>
        </label>
        <button class="btn btn-primary wd-submit" type="submit"${full || state.sending ? " disabled" : ""}>
          <span>${esc(t(state.sending ? "wd_sending" : "wd_register"))}</span>${state.sending ? "" : ICONS.arrow}
        </button>
      </form>
      ${error}
      <p class="wd-help">${esc(t("wd_help"))}</p>`;
  }

  function bindForm() {
    const form = document.getElementById("wdForm");
    if (!form) return;
    const input = form.elements.email;
    input.addEventListener("input", () => {
      state.email = input.value;
    });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (state.sending) return;
      state.email = input.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.email)) {
        state.error = "wd_err_email";
        render();
        document.querySelector(".wd-email input")?.focus();
        return;
      }
      state.sending = true;
      state.error = "";
      render();
      const body = new FormData();
      body.append("workshop", workshop.id);
      body.append("email", state.email);
      body.append("website", form.elements.website.value);
      try {
        const response = await fetch("api/workshop-register.php", { method: "POST", body, headers: { Accept: "application/json" } });
        const result = await response.json().catch(() => ({}));
        if (response.ok && result.ok) {
          state.done = true;
          workshop.seatsLeft = Math.max(0, (Number(workshop.seatsLeft) || 0) - 1);
        } else {
          state.error = ERRORS[result.error] || "wd_err_server";
          if (result.error === "wd_err_full") workshop.seatsLeft = 0;
        }
      } catch {
        state.error = "wd_err_server";
      }
      state.sending = false;
      render();
      if (state.error) document.querySelector(".wd-email input")?.focus();
    });
  }

  render();
  onLangChange(render);
}

async function start() {
  initNav();
  initTheme();
  try {
    const [strings, workshops, footer] = await Promise.all([
      loadJSON("data/i18n/site-en.json"),
      loadJSON("data/workshops.json"),
      loadJSON("data/footer.json"),
    ]);
    initI18n(strings);
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/site-ar.json").catch(() => null),
      loadJSON("data/i18n/site-ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);
    initFooter(footer);
    const id = new URLSearchParams(window.location.search).get("id") || "";
    const workshop = Array.isArray(workshops) ? workshops.find((w) => w.id === id && pick(w.title)) : null;
    initLangSwitch();
    setLang(preferredLang());
    initDetails(workshop || null);
  } catch (error) {
    console.error(error);
    await showLoadError("load_error");
  } finally {
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
