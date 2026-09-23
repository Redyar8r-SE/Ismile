// Workshops: cards with remaining seats, from data/workshops.json.
// A seat belongs to a registration. "Reserve seat" sends a registered visitor
// straight to the workshop step of the registration form; a new visitor is
// asked to register first, with the workshop added in the same form.
import { t, tr, onLangChange } from "../i18n.js?v=24";
import { formatPrice } from "../utils/money.js?v=1";
import { getAttendee, saveAttendee, REF_PATTERN } from "../utils/attendee.js?v=1";

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const registerLink = (id) => `register.html?workshop=${encodeURIComponent(id)}`;

export function initWorkshops(workshops) {
  const grid = document.getElementById("wsGrid");
  const notified = new Set();
  const gate = buildGate();

  function render() {
    const attendee = getAttendee();
    grid.innerHTML = workshops.map((w, index) => renderCard(w, index, attendee)).join("");
  }

  function renderCard(w, index, attendee) {
    const isFull = w.seatsLeft === 0;
    const reserved = Boolean(attendee?.workshops.includes(w.id));
    const takenPercent = Math.round((1 - w.seatsLeft / w.totalSeats) * 100);
    const isNotified = notified.has(index);

    const status = isFull
      ? `<span class="full">${t("w_full")}</span>
         <button type="button" class="notify" aria-pressed="${isNotified}" data-ws="${index}">${t(isNotified ? "w_notified" : "w_notify")}</button>`
      : `<span class="open">${t("w_open")}</span>
         <span class="seats-left">${w.seatsLeft} ${t("w_left")}</span>`;

    let action = "";
    if (reserved) {
      action = `<span class="reserved"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>${t("w_reserved")}</span>`;
    } else if (!isFull) {
      action = `<button type="button" class="btn btn-primary reserve" data-ws-id="${w.id}">${t("w_reserve")}</button>`;
    }

    return `
      <article class="ws${isFull ? " full" : ""}${reserved ? " reserved" : ""}">
        <h3>${tr(w.title)}</h3>
        <dl>
          <div><dt>${t("w_by")}</dt><dd>${tr(w.company) || t("w_company")}</dd></div>
          <div><dt>${t("w_speaker")}</dt><dd>${tr(w.speaker) || t("w_dr")}</dd></div>
        </dl>
        <div class="seats" aria-hidden="true"><i style="width:${takenPercent}%"></i></div>
        <div class="status">${status}</div>
        <div class="ws-actions">
          <span class="ws-price">${formatPrice(w.price)}<small>${t("w_price_seat")}</small></span>
          ${action}
        </div>
      </article>`;
  }

  grid.addEventListener("click", (event) => {
    const notify = event.target.closest(".notify");
    if (notify) {
      const index = Number(notify.dataset.ws);
      if (notified.has(index)) notified.delete(index);
      else notified.add(index);
      const on = notified.has(index);
      notify.setAttribute("aria-pressed", String(on));
      notify.textContent = t(on ? "w_notified" : "w_notify");
      return;
    }
    const reserve = event.target.closest(".reserve");
    if (!reserve) return;
    const id = reserve.dataset.wsId;
    if (getAttendee()) location.href = registerLink(id);
    else gate.open(id);
  });

  render();
  onLangChange(render);
}

// "Register first" dialog for a visitor this browser does not know. It also
// takes a reference number from someone who registered on another device.
function buildGate() {
  const dialog = document.createElement("dialog");
  dialog.className = "ws-gate";
  dialog.innerHTML = `
    <form method="dialog" class="ws-gate-card">
      <button type="submit" class="ws-gate-close" aria-label="Not now" data-i18n-label="w_gate_close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      <span class="ws-gate-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M6.5 15c.5-2 1.3-3 2.5-3s2 1 2.5 3M14 8h3M14 11h3M14 16l1.5 1.5 3-3"/></svg></span>
      <h3 data-i18n="w_gate_title"></h3>
      <p data-i18n="w_gate_text"></p>
      <a class="btn btn-primary ws-gate-go" href="register.html" data-i18n="w_gate_go"></a>
      <button type="submit" class="btn btn-outline" data-i18n="w_gate_close"></button>
      <details class="ws-gate-have">
        <summary data-i18n="w_gate_have"></summary>
        <div class="ws-gate-fields">
          <label><span data-i18n="w_gate_ref"></span><input class="ws-gate-ref" dir="ltr" placeholder="ISM26-ABC234" autocomplete="off" autocapitalize="characters"></label>
          <label><span data-i18n="w_gate_email"></span><input class="ws-gate-email" type="email" dir="ltr" placeholder="name@example.com" autocomplete="email"></label>
          <p class="ws-gate-err" hidden></p>
          <button type="button" class="btn btn-primary ws-gate-continue" data-i18n="w_gate_continue"></button>
        </div>
      </details>
    </form>`;
  document.body.append(dialog);

  const go = dialog.querySelector(".ws-gate-go");
  const refInput = dialog.querySelector(".ws-gate-ref");
  const emailInput = dialog.querySelector(".ws-gate-email");
  const error = dialog.querySelector(".ws-gate-err");
  let current = "";

  // setLang() fills data-i18n text on every change; this covers the first paint.
  function translate() {
    dialog.querySelectorAll("[data-i18n]").forEach((el) => { el.textContent = t(el.dataset.i18n); });
    dialog.querySelectorAll("[data-i18n-label]").forEach((el) => { el.setAttribute("aria-label", t(el.dataset.i18nLabel)); });
  }

  dialog.querySelector(".ws-gate-continue").addEventListener("click", () => {
    const ref = refInput.value.trim().toUpperCase();
    const email = emailInput.value.trim();
    if (!REF_PATTERN.test(ref) || !EMAIL_PATTERN.test(email)) {
      error.textContent = t("w_gate_err");
      error.hidden = false;
      (REF_PATTERN.test(ref) ? emailInput : refInput).focus();
      return;
    }
    // Front-end only: the format is checked here. Once there is a server it
    // confirms the reference really exists before the seat is booked.
    saveAttendee({ ref, email, name: "", workshops: [], createdAt: new Date().toISOString(), unverified: true });
    location.href = registerLink(current);
  });

  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) dialog.close();
  });

  return {
    open(id) {
      current = id;
      go.href = registerLink(id);
      error.hidden = true;
      translate();
      if (typeof dialog.showModal === "function") dialog.showModal();
      else location.href = go.href;
    },
  };
}
