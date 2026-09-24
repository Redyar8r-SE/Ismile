// Program: day tabs + schedule, from data/program.json.
import { ICONS } from "../config/icons.js?v=24";
import { t, tr, onLangChange } from "../i18n.js?v=24";
import { show } from "../utils/time.js?v=24";

export function initProgram({ types, days }) {
  const tabs = document.getElementById("dayTabs");
  const meta = document.getElementById("dayMeta");
  const schedule = document.getElementById("sched");
  let openDay = days[0].id;

  function renderTabs() {
    tabs.innerHTML = days
      .map((day) => `<button role="tab" aria-selected="${day.id === openDay}" data-day="${day.id}"><b>${tr(day.label)}</b><small>${tr(day.subtitle)}</small></button>`)
      .join("");
  }

  tabs.addEventListener("click", (event) => {
    const tab = event.target.closest("button");
    if (!tab) return;
    tabs.querySelectorAll("button").forEach((b) => b.setAttribute("aria-selected", String(b === tab)));
    openDay = tab.dataset.day;
    renderDay();
  });

  function renderDay() {
    // By start time ("09:30" sorts before "13:00"), so a session added later in
    // the admin still lands in its right place.
    const sessions = [...days.find((day) => day.id === openDay).sessions]
      .sort((a, b) => String(a.start).localeCompare(String(b.start)));
    const talks = sessions.filter((s) => s.type !== "break");
    const locations = new Set(talks.map((s) => tr(s.location)));
    const first = sessions[0];
    const last = sessions[sessions.length - 1];

    meta.innerHTML = `
      <span><b>${talks.length}</b> ${t("pg_sessions")}</span>
      <span><b><bdi dir="ltr">${show(first.start, t)}</bdi> ${t("pg_to")} <bdi dir="ltr">${show(last.end, t)}</bdi></b> ${t("pg_schedule")}</span>
      <span><b>${locations.size}</b> ${t("pg_locations")}</span>`;

    schedule.innerHTML =
      `<div class="sched-head"><span>${t("pg_time")}</span><span></span><span>${t("pg_session")}</span><span>${t("pg_topic_speaker")}</span><span>${t("pg_location")}</span></div>` +
      sessions.map(renderSession).join("");
  }

  function renderSession(s) {
    if (s.type === "break") {
      return `<div class="slot brk"><div class="tm"><b><bdi dir="ltr">${show(s.start, t)}</bdi></b></div><div class="rail"><i></i></div><h3>${ICONS.cup}${tr(s.title)}</h3></div>`;
    }
    return `
      <div class="slot t-${s.type}">
        <div class="tm"><b><bdi dir="ltr">${show(s.start, t)}</bdi></b><small>${t("pg_to")} <bdi dir="ltr">${show(s.end, t)}</bdi></small></div>
        <div class="rail"><i></i></div>
        <div><span class="badge">${tr(types[s.type])}</span><h3>${tr(s.title)}</h3></div>
        <dl class="who">
          <div><dt>${t("pg_topic")}</dt><dd>${tr(s.topic) || t("pg_tba")}</dd></div>
          <div><dt>${t("pg_speaker")}</dt><dd>${tr(s.speaker) || t("pg_tba")}</dd></div>
        </dl>
        <div class="loc">${ICONS.pin}${tr(s.location)}</div>
      </div>`;
  }

  renderTabs();
  renderDay();
  onLangChange(() => { renderTabs(); renderDay(); });
}
