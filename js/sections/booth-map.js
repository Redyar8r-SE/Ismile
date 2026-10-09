import { t, onLangChange } from "../i18n.js?v=95";
import { buildBooths } from "../config/booth-layout.js?v=95";

export function initBoothMap({ rows: BOOTH_ROWS, colors: TIER_COLORS }) {
  const BOOTHS = buildBooths(BOOTH_ROWS);
  const map = document.getElementById("boothMap");
  if (!map) return;
  const table = document.getElementById("boothTableBody");
  const live = document.getElementById("boothLive");
  const detail = document.getElementById("boothSelected");
  const viewport = document.getElementById("boothMapScroll");
  const availableCount = document.getElementById("boothAvailableCount");
  const bookedCount = document.getElementById("boothBookedCount");
  const zoomIn = document.getElementById("boothZoomIn");
  const zoomOut = document.getElementById("boothZoomOut");
  const zoomReset = document.getElementById("boothZoomReset");
  const section = document.getElementById("exhibition");
  const mapView = document.getElementById("boothMapView");
  const tableView = document.getElementById("boothTableView");
  const narrowLayout = window.matchMedia("(max-width: 900px)");
  let zoom = 1;
  let booked = new Set();
  let connected = false;
  let attempted = false;
  let selected = null;
  let inFlight = false;
  let timer;
  const escape = (value) => String(value).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const status = (number) => !connected ? t("bm_unknown") : booked.has(number) ? t("bm_booked") : t("bm_available");
  const label = (booth) => `${t("bm_booth")} ${booth.number} · ${t(`bm_${booth.tier}`)} · ${booth.size} · ${status(booth.number)}`;

  function updateDetails() {
    const booth = BOOTHS.find((item) => item.number === selected);
    detail.hidden = !booth;
    if (!booth) {
      detail.replaceChildren();
      return;
    }
    const taken = booked.has(booth.number);
    detail.innerHTML = `<span class="sr-only">${escape(label(booth))}</span><span class="booth-detail-number${taken ? " is-booked" : ""}" style="--booth-color:${TIER_COLORS[booth.tier]}" aria-hidden="true">${booth.number}</span><div class="booth-detail-copy" aria-hidden="true"><strong>${escape(t("bm_booth"))} ${booth.number} · ${escape(t(`bm_${booth.tier}`))}</strong><small dir="ltr">${escape(booth.size)}</small></div><span class="booth-detail-status${connected ? taken ? " is-booked" : " is-available" : ""}" aria-hidden="true">${escape(status(booth.number))}</span>`;
  }

  function button(number) {
    const booth = BOOTHS.find((item) => item.number === number);
    return `<button type="button" class="booth-number${booked.has(number) ? " is-booked" : ""}${selected === number ? " is-selected" : ""}" data-booth="${number}" aria-label="${escape(label(booth))}" aria-pressed="${selected === number}">${number}</button>`;
  }

  function renderTable() {
    table.innerHTML = BOOTH_ROWS.map((row, index) => {
      const sameTier = BOOTH_ROWS.filter((item) => item.tier === row.tier);
      const first = index === BOOTH_ROWS.findIndex((item) => item.tier === row.tier);
      const count = sameTier.reduce((sum, item) => sum + item.numbers.length, 0);
      const taken = row.numbers.filter((number) => booked.has(number));
      return `<tr class="tier-${row.tier}${first ? " tier-start" : ""}">${first ? `<th scope="rowgroup" rowspan="${sameTier.length}" class="tier-${row.tier}">${escape(t(`bm_${row.tier}`))}<small>${count} ${escape(t("bm_booths"))}</small></th>` : ""}
        <td><div class="booth-numbers">${row.numbers.map(button).join("")}</div></td>
        <td class="booth-size"><span class="booth-mobile-label">${escape(t("bm_size"))}</span><span dir="ltr">${escape(row.size)}</span></td>
        <td class="booth-booked-cell${taken.length ? " has-bookings" : ""}"><span class="booth-mobile-label">${escape(t("bm_booked"))}</span>${!connected ? escape(t("bm_unknown_short")) : taken.length ? `<div class="booth-numbers">${taken.map(button).join("")}</div>` : `<span class="booth-none">${escape(t("bm_none"))}</span>`}</td></tr>`;
    }).join("");
  }

  function renderMap() {
    map.innerHTML = `<svg class="booth-map-svg" viewBox="25 230 1020 590" xmlns="http://www.w3.org/2000/svg" role="group" aria-label="${escape(t("bm_map_label"))}">
      <defs><pattern id="boothPlanGrid" width="24" height="24" patternUnits="userSpaceOnUse"><circle class="plan-dot" cx="1" cy="1" r="1"/></pattern></defs>
      <g aria-hidden="true">
        <rect class="plan-background" x="25" y="230" width="1020" height="590"/>
        <rect x="25" y="230" width="1020" height="590" fill="url(#boothPlanGrid)"/>
        <path class="plan-floor" d="M51 543 230 285 287 310 291 294 392 334 500 352 502 383 585 383 584 358 Q708 334 787 298 L798 322 844 294 1021 557 966 594 989 634 Q861 709 686 758 Q469 817 220 738 L71 630 91 597Z"/>
        <path class="plan-room" d="m230 285 56 26-44 67 18 12 45-66 87 30-7 17-91-28-23 44 Q368 434 490 452 L501 352 461 345 456 383 441 380 443 344 392 334 387 353 327 332 334 313 291 294 284 310Z"/>
        <path class="plan-room" d="M585 358 Q696 337 786 299 L798 322 780 334 791 359 813 346 830 381 814 392 807 377 Q735 416 634 442 L640 454 622 461 610 428 627 421 Q715 398 797 375 L787 355 Q701 397 589 406Z"/>
        <path class="plan-room" d="M51 543 Q190 632 363 685 L411 696 399 754 Q250 718 112 649 L137 600Z"/>
        <path class="plan-room" d="m677 693 11 61 Q867 707 989 634 L966 594 Q830 665 677 693Z"/>
        <path class="plan-room" d="M440 719 641 719 641 755 Q546 780 440 755Z"/>
        <path class="plan-wall" d="m112 649 25-49M363 685l-9 57m48-39-45-12m321 5 39-10m245-76 24 36M440 727h201m-201 9h201m-201 9h201m-201 9h201M502 383h23m39 0h21M461 349l-3 33 36 4 4-32M287 312l28 13 12-25m65 34-8 37 42 8 10-35M640 348l8 29m37-41 9 28m53-50 9 28M277 397 289 385 Q385 430 490 444 M594 460 584 358 M659 448 709 686 Q869 654 1021 557"/>
        <path class="plan-door" d="M524 383v-20q21 0 21 20m19 0v-20q-19 0-19 20M252 365l18 8q-7 17-25 9m84-58-7 18q18 7 25-11m95 13-3 20q19 3 22-15m152 12 3 19q19-3 16-22m71-20 5 19q18-5 13-24m70-24 8 18q16-8 8-26M399 754l20 4v-20q-19 0-20 16m41 1-19 3v-20q19 0 19 17m201 0 21-5-5-20q-19 5-16 25m47-1-22 5-5-20q19-5 27 15"/>
        <path class="plan-route" d="M183 435 128 520 Q365 662 638 637 L612 494M518 413l-6 219"/>
        <text class="plan-brand" x="815" y="469">iSmile</text>
        <text class="plan-label" x="815" y="496">${escape(t("bm_hall"))}</text>
        <text class="plan-gate-label" x="546" y="270">${escape(t("bm_entrance"))}</text>
        <path class="plan-gate-arrow" d="M546 289v49m-10-10 10 10 10-10"/>
        <text class="plan-gate-label" x="285" y="253">${escape(t("bm_exit"))}</text>
        <path class="plan-gate-arrow" d="m251 313 23-41m-14 5 14-5 4 14"/>
      </g>
      ${BOOTHS.map((booth) => {
        const x = booth.points.reduce((sum, point) => sum + point[0], 0) / booth.points.length;
        const y = booth.points.reduce((sum, point) => sum + point[1], 0) / booth.points.length;
        const points = booth.points.map((point) => point.join(",")).join(" ");
        return `<g class="map-booth${booked.has(booth.number) ? " is-booked" : ""}${selected === booth.number ? " is-selected" : ""}" data-booth="${booth.number}" tabindex="0" role="button" aria-pressed="${selected === booth.number}" aria-label="${escape(label(booth))}" style="--booth-color:${TIER_COLORS[booth.tier]}"><title>${escape(label(booth))}</title><polygon points="${points}"/><polyline class="booth-outline" points="${points} ${booth.points[0].join(",")}"/><text x="${x}" y="${y}" aria-hidden="true">${booth.number}</text></g>`;
      }).join("")}
    </svg>`;
  }

  function updateAvailability() {
    map.querySelectorAll(".map-booth").forEach((element) => {
      const booth = BOOTHS.find((item) => item.number === Number(element.dataset.booth));
      element.classList.toggle("is-booked", booked.has(booth.number));
      element.setAttribute("aria-label", label(booth));
      element.querySelector("title").textContent = label(booth);
    });
    // Keep keyboard focus when a live update rebuilds the table.
    const focus = table.contains(document.activeElement) ? document.activeElement.dataset.booth : null;
    renderTable();
    if (focus) table.querySelector(`[data-booth="${focus}"]`)?.focus({ preventScroll: true });
    live.removeAttribute("data-i18n");
    live.textContent = connected ? t("bm_live_short") : attempted ? t("bm_unavailable") : t("bm_connecting");
    live.classList.toggle("is-live", connected);
    live.classList.toggle("is-unavailable", attempted && !connected);
    availableCount.textContent = connected ? String(BOOTHS.length - booked.size) : "—";
    bookedCount.textContent = connected ? String(booked.size) : "—";
    updateDetails();
  }

  function select(event) {
    const element = event.target.closest("[data-booth]");
    if (!element) return;
    if (event.type === "keydown") {
      if (event.key !== "Enter" && event.key !== " ") return;
      event.preventDefault();
    }
    selected = Number(element.dataset.booth);
    const fromTable = table.contains(element);
    if (fromTable && narrowLayout.matches) setView("map");
    const mapBooth = map.querySelector(`[data-booth="${selected}"]`);
    if (fromTable && mapBooth) {
      const target = mapBooth.getBoundingClientRect();
      const frame = viewport.getBoundingClientRect();
      viewport.scrollTo({ left: viewport.scrollLeft + target.left - frame.left - frame.width / 2 + target.width / 2, top: viewport.scrollTop + target.top - frame.top - frame.height / 2 + target.height / 2 });
      if (narrowLayout.matches) mapBooth.focus({ preventScroll: true });
    }
    document.querySelectorAll(".exhibition [data-booth]").forEach((item) => {
      const active = Number(item.dataset.booth) === selected;
      item.classList.toggle("is-selected", active);
      item.setAttribute("aria-pressed", String(active));
    });
    updateDetails();
  }

  function setView(view) {
    section.dataset.view = view;
    mapView.setAttribute("aria-pressed", String(view === "map"));
    tableView.setAttribute("aria-pressed", String(view === "table"));
  }

  function setZoom(next) {
    const horizontal = (viewport.scrollLeft + viewport.clientWidth / 2) / viewport.scrollWidth;
    const vertical = (viewport.scrollTop + viewport.clientHeight / 2) / viewport.scrollHeight;
    zoom = Math.max(1, Math.min(2, next));
    viewport.style.setProperty("--map-scale", String(zoom));
    document.getElementById("boothZoomValue").textContent = `${Math.round(zoom * 100)}%`;
    zoomOut.disabled = zoom === 1;
    zoomIn.disabled = zoom === 2;
    viewport.scrollLeft = horizontal * viewport.scrollWidth - viewport.clientWidth / 2;
    viewport.scrollTop = vertical * viewport.scrollHeight - viewport.clientHeight / 2;
  }

  async function refresh() {
    clearTimeout(timer);
    if (document.hidden || inFlight) return;
    inFlight = true;
    try {
      const response = await fetch("api/booths.php", { cache: "no-store", headers: { Accept: "application/json" }, signal: AbortSignal.timeout(7000) });
      if (!response.ok) throw new Error("availability unavailable");
      const state = await response.json();
      if (state.ok !== true || !Array.isArray(state.booked) || !state.booked.every((number) => Number.isInteger(number) && number >= 1 && number <= 44)) throw new Error("invalid availability");
      const next = new Set(state.booked);
      const changed = !connected || next.size !== booked.size || [...next].some((number) => !booked.has(number));
      booked = next;
      connected = true;
      attempted = true;
      if (changed) updateAvailability();
    } catch {
      // Retain last-known red spaces, but never claim that stale data is live.
      connected = false;
      attempted = true;
      updateAvailability();
    } finally {
      inFlight = false;
      if (!document.hidden) timer = setTimeout(refresh, connected ? 3000 : 10000);
    }
  }

  map.addEventListener("click", select);
  map.addEventListener("keydown", select);
  table.addEventListener("click", select);
  mapView.addEventListener("click", () => setView("map"));
  tableView.addEventListener("click", () => setView("table"));
  zoomIn.addEventListener("click", () => setZoom(zoom + .25));
  zoomOut.addEventListener("click", () => setZoom(zoom - .25));
  zoomReset.addEventListener("click", () => setZoom(1));
  document.addEventListener("visibilitychange", () => { if (document.hidden) clearTimeout(timer); else refresh(); });
  window.addEventListener("online", refresh);
  window.addEventListener("pageshow", refresh);
  window.addEventListener("pagehide", () => clearTimeout(timer));
  onLangChange(() => { renderMap(); updateAvailability(); });
  renderMap();
  updateAvailability();
  refresh();
}
