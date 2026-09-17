// Translations.
// English text written in index.html (elements with data-i18n) is collected
// automatically; extra strings used only by JavaScript live in data/i18n/en.json.
// To add a language later: addLanguage("ku", dictionary) then setLang("ku").

const dictionaries = { en: {} };
const listeners = [];
let currentLang = "en";

export function t(key) {
  return dictionaries[currentLang]?.[key] || dictionaries.en[key] || key;
}

export function initI18n(extraEnglish) {
  document.querySelectorAll("[data-i18n]").forEach((el) => {
    const key = el.dataset.i18n;
    if (!(key in dictionaries.en)) dictionaries.en[key] = el.textContent;
  });
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
  currentLang = code;
  const html = document.documentElement;
  html.lang = code === "ku" ? "ckb" : code;
  html.dir = code === "en" ? "ltr" : "rtl";

  document.querySelectorAll("[data-i18n]").forEach((el) => {
    el.textContent = t(el.dataset.i18n);
  });
  document.querySelectorAll(".lang button").forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.lang === code));
  });
  listeners.forEach((callback) => callback());
}
