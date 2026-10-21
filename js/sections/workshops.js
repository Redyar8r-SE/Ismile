// Workshops: the cards on workshops.html (#wsGrid), from data/workshops.json.
// An announced workshop shows the trainer's photo, who organizes it
// ("Organized by iSmile" or "Organized by <company>"), its title, trainer,
// duration and level, and opens its details page (workshop.html?id=…).
// iSmile's workshops are booked there with the summit email; a company's
// workshops by calling the company's phone.
// A workshop without a title is not announced yet: shaped placeholders only,
// never pretend text.
import { t, tr, onLangChange } from "../i18n.js?v=136";
import { formatPrice } from "../utils/money.js?v=136";

// A tooth and a dental mirror: hands-on dental training.
export const WORKSHOP_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3.5c-2.8 0-4.6 1.5-4.6 4.2 0 1.8.6 3.1.9 5.1.3 2.8.5 6.7 1.8 6.7 1.1 0 1-3.6 1.9-3.6s.8 3.6 1.9 3.6c1.3 0 1.5-3.9 1.8-6.7.3-2 .9-3.3.9-5.1 0-2.7-1.8-4.2-4.6-4.2z"/><circle cx="17.6" cy="5.4" r="2.6"/><path d="M17.9 8l.5 3"/><path d="M18.4 11l1.4 9.2"/></svg>';
const ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>';
const USER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.9-3.6 3.9-5.5 7.5-5.5s6.6 1.9 7.5 5.5"/></svg>';
const CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>';
const LEVEL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 19v-4M12 19V10M19 19V5"/></svg>';

/** Text in the visitor's language, falling back to English when it is empty. */
export function pick(value) {
  if (value && typeof value === "object") return tr(value) || value.en || "";
  return value || "";
}

/** Plain text made safe to put inside HTML. */
export function esc(value) {
  return String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}

/** The seat price in the workshop's currency: "$100" or "75,000 IQD". */
export function workshopPrice(w) {
  const value = Number(w.price);
  if (!value) return t("tk_soon");
  return w.currency === "USD" ? `$${value.toLocaleString("en-US")}` : formatPrice(value);
}

/** Only pictures that belong to this website (assets/…) are shown. */
export function photoSrc(w) {
  return typeof w.photo === "string" && /^assets\/[\w./-]+\.(jpe?g|png|webp)$/i.test(w.photo) && !w.photo.includes("..") ? w.photo : "";
}

export const detailsHref = (w) => `workshop.html?id=${encodeURIComponent(w.id)}`;

/** A company runs this workshop: people call the company to register. */
export const byCompany = (w) => w?.organizer === "company";

/** "Organized by iSmile" or "Organized by <company name>". */
export function organizerText(w) {
  const company = byCompany(w) ? pick(w.company) : "";
  return company ? t("ws_org_by").replace("{name}", company) : t("ws_org");
}

export function initWorkshops(workshops) {
  const grid = document.getElementById("wsGrid");

  function render() {
    grid.innerHTML = workshops.map(renderCard).join("");
  }

  function badge(w) {
    return `<span class="ws-badge${byCompany(w) ? " is-company" : ""}" dir="auto">${esc(organizerText(w))}</span>`;
  }

  function renderCard(w) {
    const title = pick(w.title);
    if (!title) {
      return `
      <article class="ws is-soon">
        <div class="ws-ph">${badge(w)}${WORKSHOP_ICON}</div>
        <div class="ws-sk">
          <span class="ws-line"><span class="sr-only">${esc(t("w_tba_title"))}</span></span>
          <span class="ws-line ws-short"></span>
        </div>
      </article>`;
    }
    const photo = photoSrc(w);
    const speaker = pick(w.speaker);
    const chips = [[CLOCK, pick(w.duration)], [LEVEL, pick(w.level)]]
      .filter(([, text]) => text)
      .map(([icon, text]) => `<span class="ws-chip">${icon}<span dir="auto">${esc(text)}</span></span>`)
      .join("");
    return `
      <article class="ws ws-card${w.seatsLeft === 0 ? " full" : ""}">
        <div class="ws-media${photo ? "" : " no-photo"}">
          ${photo ? `<img src="${esc(photo)}" alt="" loading="lazy" decoding="async">` : WORKSHOP_ICON}
          ${badge(w)}
          <span class="ws-go" aria-hidden="true">${ARROW}</span>
        </div>
        <div class="ws-body">
          <h3><a class="ws-link" href="${detailsHref(w)}" aria-label="${esc(`${t("ws_details")}: ${title}`)}" dir="auto">${esc(title)}</a></h3>
          ${speaker ? `<p class="ws-trainer">${USER}<span dir="auto">${esc(speaker)}</span></p>` : ""}
          ${chips ? `<div class="ws-chips">${chips}</div>` : ""}
        </div>
      </article>`;
  }

  render();
  onLangChange(render);
}
