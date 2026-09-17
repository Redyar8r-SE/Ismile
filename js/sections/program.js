// Program: day tabs + schedule, from data/program.json.
import { ICONS } from "../config/icons.js";

const TBA = "To be announced";

export function initProgram({ types, days }) {
  const tabs = document.getElementById("dayTabs");
  const meta = document.getElementById("dayMeta");
  const schedule = document.getElementById("sched");

  tabs.innerHTML = days
    .map((day, i) => `<button role="tab" aria-selected="${i === 0}" data-day="${day.id}"><b>${day.label}</b><small>${day.subtitle}</small></button>`)
    .join("");

  tabs.addEventListener("click", (event) => {
    const tab = event.target.closest("button");
    if (!tab) return;
    tabs.querySelectorAll("button").forEach((b) => b.setAttribute("aria-selected", String(b === tab)));
    renderDay(tab.dataset.day);
  });

  function renderDay(dayId) {
    const { sessions } = days.find((day) => day.id === dayId);
    const talks = sessions.filter((s) => s.type !== "break");
    const locations = new Set(talks.map((s) => s.location));
    const first = sessions[0];
    const last = sessions[sessions.length - 1];

    meta.innerHTML = `
      <span><b>${talks.length}</b> sessions</span>
      <span><b>${first.start}–${last.end}</b> schedule</span>
      <span><b>${locations.size}</b> locations</span>`;

    schedule.innerHTML =
      `<div class="sched-head"><span>Time</span><span></span><span>Session</span><span>Topic and speaker</span><span>Location</span></div>` +
      sessions.map(renderSession).join("");
  }

  function renderSession(s) {
    if (s.type === "break") {
      return `<div class="slot brk"><div class="tm"><b>${s.start}</b></div><div class="rail"><i></i></div><h3>${ICONS.cup}${s.title}</h3></div>`;
    }
    return `
      <div class="slot t-${s.type}">
        <div class="tm"><b>${s.start}</b><small>to ${s.end}</small></div>
        <div class="rail"><i></i></div>
        <div><span class="badge">${types[s.type]}</span><h3>${s.title}</h3></div>
        <dl class="who">
          <div><dt>Topic</dt><dd>${s.topic || TBA}</dd></div>
          <div><dt>Speaker</dt><dd>${s.speaker || TBA}</dd></div>
        </dl>
        <div class="loc">${ICONS.pin}${s.location}</div>
      </div>`;
  }

  renderDay(days[0].id);
}
