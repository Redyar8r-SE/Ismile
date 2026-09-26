// Workshops: cards with remaining seats, from data/workshops.json, on
// workshops.html (#wsGrid and #wsCallbar).
// Seats are not booked on the website: every open workshop has a
// "Call to book" button that dials the office (the "ws_phone" text).
// A workshop without a title is not announced yet: like a speaker who is not
// announced, it shows only "Coming soon" over shaped placeholders, never
// pretend text, and no price or button (the office number sits above the cards).
import { t, tr, onLangChange } from "../i18n.js?v=24";
import { formatPrice } from "../utils/money.js?v=1";
import { callButton, phoneHref, PHONE_ICON } from "../utils/phone.js?v=1";
import { tCount } from "../utils/count.js?v=1";

// A tooth and a dental mirror: hands-on dental training.
const WORKSHOP_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3.5c-2.8 0-4.6 1.5-4.6 4.2 0 1.8.6 3.1.9 5.1.3 2.8.5 6.7 1.8 6.7 1.1 0 1-3.6 1.9-3.6s.8 3.6 1.9 3.6c1.3 0 1.5-3.9 1.8-6.7.3-2 .9-3.3.9-5.1 0-2.7-1.8-4.2-4.6-4.2z"/><circle cx="17.6" cy="5.4" r="2.6"/><path d="M17.9 8l.5 3"/><path d="M18.4 11l1.4 9.2"/></svg>';

export function initWorkshops(workshops) {
  const grid = document.getElementById("wsGrid");
  const callbar = document.getElementById("wsCallbar");

  function render() {
    grid.innerHTML = workshops.map(renderCard).join("");
    renderCallbar();
  }

  // One clear line above the cards: how to book, and the number to call.
  function renderCallbar() {
    const number = t("ws_phone");
    const href = phoneHref(number);
    // A real number reads left to right and dials; until then the words
    // ("Will be announced soon") read in the page language.
    const shown = href ? `<b dir="ltr">${number}</b>` : `<b>${number}</b>`;
    callbar.innerHTML = `
      <span class="ws-callbar-ic">${PHONE_ICON}</span>
      <span class="ws-callbar-text"><small>${t("w_call_how")}</small>${href ? `<a href="${href}">${shown}</a>` : shown}</span>`;
  }

  function renderCard(w) {
    if (!tr(w.title)) {
      return `
      <article class="ws is-soon">
        <div class="ws-ph"><em>${t("w_soon")}</em>${WORKSHOP_ICON}</div>
        <div class="ws-sk">
          <span class="ws-line"><span class="sr-only">${t("w_tba_title")}</span></span>
          <span class="ws-line ws-short"></span>
        </div>
      </article>`;
    }

    const isFull = w.seatsLeft === 0;
    const takenPercent = w.totalSeats ? Math.round((1 - w.seatsLeft / w.totalSeats) * 100) : 0;
    const status = isFull
      ? `<span class="full">${t("w_full")}</span>`
      : `<span class="open">${t("w_open")}</span>
         <span class="seats-left">${tCount("w_left", w.seatsLeft)}</span>`;

    return `
      <article class="ws${isFull ? " full" : ""}">
        <h3>${tr(w.title)}</h3>
        <dl>
          <div><dt>${t("w_by")}</dt><dd>${tr(w.company) || t("w_company")}</dd></div>
          <div><dt>${t("w_speaker")}</dt><dd>${tr(w.speaker) || t("w_dr")}</dd></div>
        </dl>
        <div class="seats" aria-hidden="true"><i style="width:${takenPercent}%"></i></div>
        <div class="status">${status}</div>
        <div class="ws-actions">
          <span class="ws-price">${formatPrice(w.price)}<small>${t("w_price_seat")}</small></span>
          ${isFull ? "" : callButton(t("w_call"), t("ws_phone"))}
        </div>
      </article>`;
  }

  render();
  onLangChange(render);
}
