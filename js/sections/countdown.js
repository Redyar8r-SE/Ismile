// Countdown in the top section to the first day of the summit. The start
// time is data-start on #countdown in index.html. Hidden once it has started.
export function initCountdown() {
  const box = document.getElementById("countdown");
  if (!box) return;
  const start = new Date(box.dataset.start).getTime();
  if (Number.isNaN(start)) return;
  const parts = {};
  box.querySelectorAll("[data-cd]").forEach((el) => { parts[el.dataset.cd] = el; });
  const pad = (n) => String(n).padStart(2, "0");
  let timer = 0;

  function tick() {
    const left = start - Date.now();
    if (left <= 0) { box.hidden = true; clearInterval(timer); return; }
    const seconds = Math.floor(left / 1000);
    parts.days.textContent = Math.floor(seconds / 86400);
    parts.hours.textContent = pad(Math.floor(seconds / 3600) % 24);
    parts.minutes.textContent = pad(Math.floor(seconds / 60) % 60);
    parts.seconds.textContent = pad(seconds % 60);
    box.hidden = false;
  }

  tick();
  timer = setInterval(tick, 1000);
}
