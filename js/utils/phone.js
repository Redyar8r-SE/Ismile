// The office number for workshop bookings lives in the translations
// (key "ws_phone") so it can be changed from the admin like any other text.

const PHONE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.6 3.5h2.6l1.5 4-2 1.3a11 11 0 005.5 5.5l1.3-2 4 1.5v2.6a2 2 0 01-2.2 2A16.5 16.5 0 014.6 5.7a2 2 0 012-2.2z"/></svg>';

// "+964 750 123 4567" -> "tel:+9647501234567". A number that is not filled
// in yet (such as "+964 7xx xxx xxxx") gives "", so nothing dials a wrong line.
export function phoneHref(text) {
  const digits = String(text || "").replace(/[^\d+]/g, "");
  return /^\+?\d{8,15}$/.test(digits) ? `tel:${digits}` : "";
}

// A "Call to book" button. Without a real number yet it still shows, but as
// plain text with the number beside it rather than a link that goes nowhere.
export function callButton(label, number, className = "btn btn-primary ws-call") {
  const href = phoneHref(number);
  return href
    ? `<a class="${className}" href="${href}">${PHONE_ICON}<span>${label}</span></a>`
    : `<span class="${className} is-pending" title="${number}">${PHONE_ICON}<span>${label}</span></span>`;
}

export { PHONE_ICON };
