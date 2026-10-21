// Localize the existing error notice even when a page's content fails to load.
import { addLanguage, initI18n, initLangSwitch, onLangChange, preferredLang, setLang, t } from "../i18n.js?v=147";

const fallback = {
  en: {
    load_error: "Could not load the website. Please refresh the page, or contact ismile@italk.krd for help.",
    reg_load_error: "Registration could not load. Please refresh the page, or contact ismile@italk.krd for help.",
  },
  ar: {
    load_error: "تعذّر تحميل الموقع. يُرجى تحديث الصفحة أو التواصل مع ismile@italk.krd للمساعدة.",
    reg_load_error: "تعذّر تحميل التسجيل. يُرجى تحديث الصفحة أو التواصل مع ismile@italk.krd للمساعدة.",
  },
  ku: {
    load_error: "ماڵپەڕەکە بار نەکرا. تکایە پەڕەکە نوێ بکەرەوە، یان بۆ یارمەتی پەیوەندی بە ismile@italk.krd بکە.",
    reg_load_error: "تۆمارکردن بار نەکرا. تکایە پەڕەکە نوێ بکەرەوە یان بۆ یارمەتی پەیوەندی بە ismile@italk.krd بکە.",
  },
};

export async function showLoadError(key) {
  const notice = document.createElement("div");
  notice.className = "load-error";
  notice.dataset.i18n = key;
  const dictionaries = await Promise.all(["en", "ar", "ku"].map(async (code) => {
    try {
      const response = await fetch(`data/i18n/site-${code}.json`, { cache: "no-cache", signal: AbortSignal.timeout(10000) });
      if (response.ok) return { ...fallback[code], ...await response.json() };
    } catch { /* The notice remains translated even when the network is unavailable. */ }
    return fallback[code];
  }));
  initI18n(dictionaries[0]);
  addLanguage("ar", dictionaries[1]);
  addLanguage("ku", dictionaries[2]);
  initLangSwitch();
  setLang(preferredLang());
  const render = () => { notice.textContent = t(key); };
  render();
  onLangChange(render);
  document.body.prepend(notice);
}
