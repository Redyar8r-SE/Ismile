import { t, onLangChange } from "../i18n.js?v=126";
import { buildBooths } from "../config/booth-layout.js?v=126";
import { floorPlanSVG } from "./floor-plan.js?v=126";

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
  const availableBar = document.getElementById("boothAvailableBar");
  const bookedBar = document.getElementById("boothBookedBar");
  const zoomIn = document.getElementById("boothZoomIn");
  const zoomOut = document.getElementById("boothZoomOut");
  const zoomReset = document.getElementById("boothZoomReset");
  const section = document.getElementById("exhibition");
  const mapView = document.getElementById("boothMapView");
  const tableView = document.getElementById("boothTableView");
  const narrowLayout = window.matchMedia(section.closest("#spfForm") ? "(max-width: 700px)" : "(max-width: 900px)");
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

  // One tinted group per tier: its numbers row by row, each with its size.
  function renderTable() {
    const tiers = [...new Set(BOOTH_ROWS.map((row) => row.tier))];
    table.innerHTML = tiers.map((tier) => {
      const rows = BOOTH_ROWS.filter((row) => row.tier === tier);
      const count = rows.reduce((sum, row) => sum + row.numbers.length, 0);
      const taken = rows.reduce((sum, row) => sum + row.numbers.filter((number) => booked.has(number)).length, 0);
      const state = !connected ? `<span class="booth-group-state">${escape(t("bm_unknown_short"))}</span>` : taken ? `<span class="booth-group-state is-booked">${taken} ${escape(t("bm_booked"))}</span>` : "";
      return `<section class="booth-group tier-${tier}" aria-label="${escape(t(`bm_${tier}`))}">
        <div class="booth-group-head"><i aria-hidden="true"></i><strong>${escape(t(`bm_${tier}`))}</strong><small>${count} ${escape(t("bm_booths"))}</small>${state}</div>
        ${rows.map((row) => `<div class="booth-row"><div class="booth-numbers">${row.numbers.map(button).join("")}</div><span class="booth-size"><span class="sr-only">${escape(t("bm_size"))}: </span><span dir="ltr">${escape(row.size)}</span></span></div>`).join("")}
      </section>`;
    }).join("");
  }

  function renderMap() {
    map.innerHTML = floorPlanSVG({ booths: BOOTHS, colors: TIER_COLORS, label, booked, selected, gatePills: true });
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
    live.textContent = connected ? t("bm_live") : attempted ? t("bm_unavailable") : t("bm_connecting");
    live.classList.toggle("is-live", connected);
    live.classList.toggle("is-unavailable", attempted && !connected);
    availableCount.textContent = connected ? String(BOOTHS.length - booked.size) : "—";
    bookedCount.textContent = connected ? String(booked.size) : "—";
    availableBar.style.width = connected ? `${(BOOTHS.length - booked.size) / BOOTHS.length * 100}%` : "0%";
    bookedBar.style.width = connected ? `${booked.size / BOOTHS.length * 100}%` : "0%";
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
