// Translations.
// English text written in index.html (elements with data-i18n) is collected
// automatically; extra strings used only by JavaScript live in data/i18n/en.json.
// Other languages are plain JSON files with the same keys: data/i18n/ar.json.
// To add a language: addLanguage("ku", dictionary) then setLang("ku").

const dictionaries = { en: {} };
const listeners = [];
const STORAGE_KEY = "ismile-lang";
const RTL = ["ar", "ku"];
let currentLang = "en";

export function t(key) {
  return dictionaries[currentLang]?.[key] || dictionaries.en[key] || key;
}

export function getLang() {
  return currentLang;
}

// Text that lives in the data files. A value is either a plain string (same in
// every language) or an object per language: { "en": "Day 1", "ar": "اليوم 1" }.
export function tr(value) {
  if (value && typeof value === "object") return value[currentLang] ?? value.en ?? "";
  return value;
}

// Text in index.html is the English dictionary: data-i18n for text,
// data-i18n-ph for an input placeholder, data-i18n-label for an aria-label.
export function initI18n(extraEnglish) {
  document.querySelectorAll("[data-i18n]").forEach((el) => {
    const key = el.dataset.i18n;
    if (!(key in dictionaries.en)) dictionaries.en[key] = el.textContent;
  });
  document.querySelectorAll("[data-i18n-ph]").forEach((el) => {
    const key = el.dataset.i18nPh;
    if (!(key in dictionaries.en)) dictionaries.en[key] = el.placeholder;
  });
  document.querySelectorAll("[data-i18n-label]").forEach((el) => {
    const key = el.dataset.i18nLabel;
    if (!(key in dictionaries.en)) dictionaries.en[key] = el.getAttribute("aria-label");
  });
  // Each page names its own title key (data-title-key on <html>), so a second
  // page does not inherit the home page's title when the language changes.
  const titleKey = document.documentElement.dataset.titleKey || "page_title";
  if (!(titleKey in dictionaries.en)) dictionaries.en[titleKey] = document.title;
  Object.assign(dictionaries.en, extraEnglish);
}

export function addLanguage(code, dictionary) {
  dictionaries[code] = dictionary;
}

// Sections that render text from JavaScript register here to re-render on language change.
export function onLangChange(callback) {
  listeners.push(callback);
}

export function setLang(code) {
  currentLang = dictionaries[code] ? code : "en";
  const html = document.documentElement;
  html.lang = currentLang === "ku" ? "ckb" : currentLang;
  html.dir = RTL.includes(currentLang) ? "rtl" : "ltr";
  document.title = t(document.documentElement.dataset.titleKey || "page_title");

  document.querySelectorAll("[data-i18n]").forEach((el) => {
    el.textContent = t(el.dataset.i18n);
  });
  document.querySelectorAll("[data-i18n-ph]").forEach((el) => {
    el.placeholder = t(el.dataset.i18nPh);
  });
  document.querySelectorAll("[data-i18n-label]").forEach((el) => {
    el.setAttribute("aria-label", t(el.dataset.i18nLabel));
  });
  document.querySelectorAll(".lang button").forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.lang === currentLang));
  });

  try { localStorage.setItem(STORAGE_KEY, currentLang); } catch { /* private browsing */ }
  listeners.forEach((callback) => callback());
}

// The language the visitor chose last time, or Arabic for Arabic browsers.
export function preferredLang() {
  let saved = null;
  try { saved = localStorage.getItem(STORAGE_KEY); } catch { /* private browsing */ }
  if (saved && dictionaries[saved]) return saved;
  const browser = (navigator.language || "en").slice(0, 2);
  return dictionaries[browser] ? browser : "en";
}

// Header buttons: EN / العربية
export function initLangSwitch() {
  document.querySelectorAll(".lang button").forEach((button) => {
    button.addEventListener("click", () => setLang(button.dataset.lang));
  });
}
