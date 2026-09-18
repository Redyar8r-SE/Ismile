// Times are stored as "HH:MM" on a 24-hour clock and shown as 9:00 AM.
// The AM / PM words come from the language files, so they can be translated.
export function show(hhmm, t) {
  const [h, m] = String(hhmm || "").split(":");
  const hour = Number(h);
  if (!Number.isFinite(hour)) return hhmm || "";
  const half = hour < 12 ? t("am") : t("pm");
  const shown = hour % 12 === 0 ? 12 : hour % 12;
  return `${shown}:${(m || "00").padStart(2, "0")} ${half}`;
}
