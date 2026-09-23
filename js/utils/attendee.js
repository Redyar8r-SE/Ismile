// Who is registered on this device.
//
// Front-end only for now: the registration is remembered in the browser so
// the workshop pages can tell "registered" from "not registered". When the
// server arrives, submitRegistration() posts the same shape there and this
// store becomes a cache of the server's answer.
const KEY = "ismile-attendee";

export const REF_PATTERN = /^ISM26-[A-Z2-9]{6}$/;

export function getAttendee() {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data || !REF_PATTERN.test(data.ref || "")) return null;
    if (!Array.isArray(data.workshops)) data.workshops = [];
    return data;
  } catch {
    return null;
  }
}

export function saveAttendee(attendee) {
  try {
    localStorage.setItem(KEY, JSON.stringify(attendee));
  } catch {
    // Private browsing: the visitor can still register, we just cannot remember it.
  }
}

// Reserved workshops are appended, never replaced, so two orders add up.
export function addWorkshops(ids) {
  const attendee = getAttendee();
  if (!attendee) return null;
  const known = new Set(attendee.workshops);
  ids.forEach((id) => known.add(id));
  attendee.workshops = [...known];
  saveAttendee(attendee);
  return attendee;
}

export function clearAttendee() {
  try { localStorage.removeItem(KEY); } catch { /* nothing to clear */ }
}

export function makeReference() {
  const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  const bytes = crypto.getRandomValues(new Uint8Array(6));
  return `ISM26-${[...bytes].map((byte) => alphabet[byte % alphabet.length]).join("")}`;
}
