// Workshops: the cards on workshops.html (#wsGrid), from data/workshops.json.
// An announced workshop shows the trainer's photo, its title, trainer,
// duration and level, the seats, and a short button on the right: "Register
// online" (iSmile) or "Call to register" (company); both open the details
// page. No price on the cards.
// The whole card opens its details page (workshop.html?id=…).
// iSmile's workshops are booked there with the summit email; a company's
// workshops by calling the company's phone.
// A workshop without a title is not announced yet: shaped placeholders only,
// never pretend text.
import { t, tr, onLangChange, getLang } from "../i18n.js?v=148";
import { formatPrice } from "../utils/money.js?v=148";
import { PHONE_ICON } from "../utils/phone.js?v=148";

// A tooth and a dental mirror: hands-on dental training.
export const WORKSHOP_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3.5c-2.8 0-4.6 1.5-4.6 4.2 0 1.8.6 3.1.9 5.1.3 2.8.5 6.7 1.8 6.7 1.1 0 1-3.6 1.9-3.6s.8 3.6 1.9 3.6c1.3 0 1.5-3.9 1.8-6.7.3-2 .9-3.3.9-5.1 0-2.7-1.8-4.2-4.6-4.2z"/><circle cx="17.6" cy="5.4" r="2.6"/><path d="M17.9 8l.5 3"/><path d="M18.4 11l1.4 9.2"/></svg>';
const CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>';
const MAIL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7.5l8 5.5 8-5.5"/></svg>';
const USERS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>';
const RIGHT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
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

export function workshopSchedule(w) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(w.scheduledDate || "") || !/^\d{2}:\d{2}$/.test(w.startTime || "") || !/^\d{2}:\d{2}$/.test(w.endTime || "")) return null;
  const start = new Date(`${w.scheduledDate}T${w.startTime}:00+03:00`), end = new Date(`${w.scheduledDate}T${w.endTime}:00+03:00`);
  if (!Number.isFinite(start.getTime()) || end <= start) return null;
  const locale = { en: "en-GB", ar: "ar-IQ", ku: "ckb-IQ" }[getLang()] || "en-GB";
  const options = { timeZone: "Asia/Baghdad" };
  const date = new Intl.DateTimeFormat(locale, { ...options, day: "numeric", month: "long", year: "numeric" }).format(start);
  const clock = new Intl.DateTimeFormat(locale, { ...options, hour: "numeric", minute: "2-digit", hour12: true });
  const hours = (end - start) / 3600000;
  return { date, time: `${clock.format(start)}–${clock.format(end)}`, duration: t("wd_hours").replace("{n}", new Intl.NumberFormat(locale).format(hours)) };
}

/** A company runs this workshop: people call the company to register. */
export const byCompany = (w) => w?.organizer === "company";

// Payment holds expire independently of this page. Refresh visible pages so a
// released seat is shown without requiring visitors to reload manually.
export function watchWorkshopAvailability(update) {
  let busy = false;
  async function refresh() {
    if (document.hidden || busy) return;
    busy = true;
    try {
      const response = await fetch("api/workshops.php", { cache: "no-store" });
      const rows = await response.json();
      if (response.ok && Array.isArray(rows) && rows.every((w) => typeof w.id === "string" && Number.isInteger(w.seatsLeft))) update(rows);
    } catch {
      // Keep the last verified display; purchases still check current stock.
    } finally { busy = false; }
  }
  setInterval(refresh, 30000);
  document.addEventListener("visibilitychange", refresh);
  window.addEventListener("pageshow", (event) => { if (event.persisted) refresh(); });
}

export function initWorkshops(workshops) {
  const grid = document.getElementById("wsGrid");

  function render() {
    grid.innerHTML = workshops.map(renderCard).join("");
  }

  function renderCard(w) {
    const title = pick(w.title);
    if (!title) {
      return `
      <article class="ws is-soon">
        <div class="ws-ph">${WORKSHOP_ICON}</div>
        <div class="ws-sk">
          <span class="ws-line"><span class="sr-only">${esc(t("w_tba_title"))}</span></span>
          <span class="ws-line ws-short"></span>
        </div>
      </article>`;
    }
    const photo = photoSrc(w);
    const speaker = pick(w.speaker);
    const company = byCompany(w);
    const total = Number(w.totalSeats) || 0;
    const left = Math.max(0, Number(w.seatsLeft) || 0);
    const chips = [[CLOCK, pick(w.duration)], [LEVEL, pick(w.level)]]
      .filter(([, text]) => text)
      .map(([icon, text]) => `<span class="ws-chip">${icon}<span dir="auto">${esc(text)}</span></span>`)
      .join("");
    // Seats: iSmile counts the seats left; a company takes its own bookings.
    const [seats, seatsSub] = w.availabilityVerified === false
      ? [t("wd_availability_unavailable"), ""]
      : left === 0
      ? [t(Number(w.pendingPayments) > 0 ? "wd_seats_in_checkout" : "wd_full"), ""]
      : company
        ? [t("wd_seats_total").replace("{total}", total), t("wdc_limited")]
        : [t("wdi_left").replace("{n}", left), t("wdi_seats").replace("{total}", total)];
    // The button opens the details page: there iSmile's workshop has the email
    // form and a company's workshop has the call button with the phone number.
    const cta = company
      ? `<a class="wsb-cta" href="${detailsHref(w)}">${PHONE_ICON}<span>${esc(t("wsb_company_call"))}</span>${RIGHT}</a>`
      : `<a class="wsb-cta" href="${detailsHref(w)}">${MAIL}<span>${esc(t("wsb_online"))}</span>${RIGHT}</a>`;
    return `
      <article data-workshop-id="${esc(w.id)}" class="ws ws-card${w.photoStyle === "cutout" ? " is-cutout" : ""}${company ? " is-company" : ""}${left === 0 && w.availabilityVerified !== false ? " full" : ""}">
        <div class="ws-media${photo ? "" : " no-photo"}">
          ${photo ? `<img src="${esc(photo)}" alt="" loading="lazy" decoding="async">` : WORKSHOP_ICON}
        </div>
        <div class="ws-body">
          <h3><a class="ws-link" href="${detailsHref(w)}" aria-label="${esc(`${t("ws_details")}: ${title}`)}" dir="auto">${esc(title)}</a></h3>
          ${speaker ? `<p class="ws-trainer" dir="auto">${esc(speaker)}</p>` : ""}
          ${workshopSchedule(w) ? `<p class="ws-schedule">${esc(workshopSchedule(w).date)}<br>${esc(workshopSchedule(w).time)}</p>` : ""}
          ${chips ? `<div class="ws-chips">${chips}</div>` : ""}
          <div class="wsb-foot">
            <div class="wsb-seats"><span class="wsb-seats-ic">${USERS}</span><div><b>${esc(seats)}</b>${seatsSub ? `<small>${esc(seatsSub)}</small>` : ""}</div></div>
            ${cta}
          </div>
        </div>
      </article>`;
  }

  render();
  onLangChange(render);
  watchWorkshopAvailability((rows) => {
    if (JSON.stringify(rows) === JSON.stringify(workshops)) return;
    workshops = rows;
    render();
  });
}
