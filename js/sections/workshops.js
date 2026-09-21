// Workshops: cards with remaining seats, from data/workshops.json.
import { t, tr, onLangChange } from "../i18n.js?v=22";

export function initWorkshops(workshops) {
  const grid = document.getElementById("wsGrid");
  const notified = new Set();

  function render() {
    grid.innerHTML = workshops.map(renderCard).join("");
  }

  function renderCard(w, index) {
    const isFull = w.seatsLeft === 0;
    const takenPercent = Math.round((1 - w.seatsLeft / w.totalSeats) * 100);
    const isNotified = notified.has(index);

    const status = isFull
      ? `<span class="full">${t("w_full")}</span>
         <button type="button" class="notify" aria-pressed="${isNotified}" data-ws="${index}">${t(isNotified ? "w_notified" : "w_notify")}</button>`
      : `<span class="open">${t("w_open")}</span>
         <span class="seats-left">${w.seatsLeft} ${t("w_left")}</span>`;

    return `
      <article class="ws${isFull ? " full" : ""}">
        <h3>${tr(w.title)}</h3>
        <dl>
          <div><dt>${t("w_by")}</dt><dd>${tr(w.company) || t("w_company")}</dd></div>
          <div><dt>${t("w_speaker")}</dt><dd>${tr(w.speaker) || t("w_dr")}</dd></div>
        </dl>
        <div class="seats" aria-hidden="true"><i style="width:${takenPercent}%"></i></div>
        <div class="status">${status}</div>
      </article>`;
  }

  grid.addEventListener("click", (event) => {
    const button = event.target.closest(".notify");
    if (!button) return;
    const index = Number(button.dataset.ws);
    if (notified.has(index)) notified.delete(index);
    else notified.add(index);
    const on = notified.has(index);
    button.setAttribute("aria-pressed", String(on));
    button.textContent = t(on ? "w_notified" : "w_notify");
  });

  render();
  onLangChange(render);
}
