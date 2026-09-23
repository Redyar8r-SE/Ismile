// Inline SVG icons used by JavaScript-rendered sections.

export const ICONS = {
  pin: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s7-6.2 7-12a7 7 0 10-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>',

  cup: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9h13v5a5 5 0 01-5 5H9a5 5 0 01-5-5z"/><path d="M17 11h1.5a2.5 2.5 0 010 5H17M8 3v3M12 3v3"/></svg>',

  gem: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12l4 6-10 12L2 9z" fill="currentColor" opacity=".9"/><path d="M2 9h20M9 3l3 6 3-6M12 9v12" stroke="rgba(0,0,0,.25)" stroke-width="1.2" fill="none"/></svg>',

  crown: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 18h16l-1.5-10-4.5 4-2-7-2 7-4.5-4L4 18Z"/><path d="M5 21h14M7 15h10"/></svg>',

  medal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="9" r="5"/><path d="m8.5 13-1 8 4.5-2.5 4.5 2.5-1-8"/><path d="m10.2 9 1.2 1.2L14 7.7"/></svg>',

  trophy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 4h8v4c0 3.2-1.6 5.5-4 5.5S8 11.2 8 8V4Z"/><path d="M8 6H4v2c0 2.2 1.5 3.8 4 4M16 6h4v2c0 2.2-1.5 3.8-4 4M12 13.5V18M8.5 21h7M10 18h4"/></svg>',

  award: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5 14 5l2.5-.1.7 2.4 2 1.5-.9 2.3.9 2.3-2 1.5-.7 2.4-2.5-.1-2 1.5-2-1.5-2.5.1-.7-2.4-2-1.5.9-2.3-.9-2.3 2-1.5.7-2.4L10 5l2-1.5Z"/><path d="m9.5 18-1 3 3.5-1.6 3.5 1.6-1-3M9.5 11.2l1.6 1.5 3.4-3.4"/></svg>',

  storefront: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 9 1.5-5h13L20 9"/><path d="M3 9h18v1.5a3 3 0 0 1-5 2.2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2.2V9Z"/><path d="M5 13v7h14v-7M9 20v-4h6v4"/></svg>',

  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',

  person: '<svg viewBox="0 0 40 40" fill="currentColor" aria-hidden="true"><circle cx="20" cy="14" r="8"/><path d="M4 40c1-10 8-15 16-15s15 5 16 15z"/></svg>',
};

// Icons a workshop can pick in the admin ("icon" in data/workshops.json).
const ws = (paths) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
export const WORKSHOP_ICONS = {
  tools: ws('<path d="M6 18L18 6M15 4l5 5M4 15l5 5"/><circle cx="18" cy="18" r="2.5"/>'),
  tooth: ws('<path d="M12 3.5c-3 0-5 1.6-5 4.5 0 2 .7 3.3 1 5.5.4 3 .6 7.5 2 7.5 1.2 0 1-4 2-4s.8 4 2 4c1.4 0 1.6-4.5 2-7.5.3-2.2 1-3.5 1-5.5 0-2.9-2-4.5-5-4.5z"/>'),
  implant: ws('<path d="M8.5 3h7l-1 3h-5z"/><path d="M9.5 6h5v3l-1 1v2l1 1v2l-1 1v1.5L12 21l-1.5-3.5V16l-1-1v-2l1-1v-2l-1-1z"/>'),
  smile: ws('<circle cx="12" cy="12" r="8.5"/><path d="M8 13.5c1 1.8 2.4 2.7 4 2.7s3-.9 4-2.7"/><path d="M9.2 9.3h.01M14.8 9.3h.01"/>'),
  root: ws('<path d="M12 3v18"/><path d="M8.5 7.5 12 5l3.5 2.5M8.5 11.5 12 9l3.5 2.5M8.5 15.5 12 13l3.5 2.5"/>'),
  scan: ws('<path d="M4 8V6.5A2.5 2.5 0 0 1 6.5 4H8M16 4h1.5A2.5 2.5 0 0 1 20 6.5V8M20 16v1.5a2.5 2.5 0 0 1-2.5 2.5H16M8 20H6.5A2.5 2.5 0 0 1 4 17.5V16"/><path d="M7 12h10"/><path d="M9 9.5c1.5-1.4 4.5-1.4 6 0M9 14.5c1.5 1.4 4.5 1.4 6 0"/>'),
  braces: ws('<path d="M4 12c2-4 5-5.5 8-5.5S18 8 20 12c-2 4-5 5.5-8 5.5S6 16 4 12z"/><path d="M7.5 12h9M9.5 10.5v3M14.5 10.5v3"/>'),
  drill: ws('<path d="M4 20l5-5"/><path d="M8 14l2 2 7-7a1.4 1.4 0 0 0-2-2l-7 7z"/><path d="M15 4l5 5"/><path d="M18 3l3 3"/>'),
};
