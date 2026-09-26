// Times are stored as "HH:MM" on a 24-hour clock and shown on a 12-hour
// clock with a word for the part of the day, from the language files:
//   before 12:00        "time_morning"    AM · صباحاً · بەیانی
//   12:00 to 3:59 PM    "time_afternoon"  PM · بعد الظهر · دوای نیوەڕۆ
//   from 4:00 PM        "time_evening"    PM · مساءً · ئێوارە
// Arabic and Kurdish name the part of the day instead of AM / PM.
export function show(hhmm, t) {
  const [h, m] = String(hhmm || "").split(":");
  const hour = Number(h);
  if (!Number.isFinite(hour)) return hhmm || "";
  const part = hour < 12 ? t("time_morning") : hour < 16 ? t("time_afternoon") : t("time_evening");
  const shown = hour % 12 === 0 ? 12 : hour % 12;
  return `${shown}:${(m || "00").padStart(2, "0")} ${part}`;
}
