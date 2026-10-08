// Registration page: shared navigation, translations, footer, and form.
import { loadJSON } from "./utils/load-json.js?v=93";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=93";
import { initNav } from "./components/nav.js?v=93";
import { initTheme } from "./components/theme.js?v=93";
import { initFooter } from "./sections/footer.js?v=93";
import { initRegistration } from "./sections/registration.js?v=94";

async function start() {
  initNav();
  initTheme();
  try {
    const [english, footer, tickets, registrationEnglish] = await Promise.all([
      loadJSON("data/i18n/en.json"),
      loadJSON("data/footer.json"),
      loadJSON("data/tickets.json").catch(() => ({})),
      loadJSON("data/i18n/registration-en.json?v=94"),
    ]);
    initI18n({...english, ...registrationEnglish});
    const [arabic, kurdish, registrationArabic, registrationKurdish] = await Promise.all([
      loadJSON("data/i18n/ar.json").catch(() => null),
      loadJSON("data/i18n/ku.json").catch(() => null),
      loadJSON("data/i18n/registration-ar.json?v=94"),
      loadJSON("data/i18n/registration-ku.json?v=94"),
    ]);
    if (arabic) addLanguage("ar", {...arabic, ...registrationArabic});
    if (kurdish) addLanguage("ku", {...kurdish, ...registrationKurdish});
    initFooter(footer);
    initRegistration({ tickets });
    initLangSwitch();
    setLang(preferredLang());
  } catch (error) {
    console.error(error);
    const notice = document.createElement("div");
    notice.className = "load-error";
    notice.textContent = "Registration could not load. Please refresh the page, or contact ismile@italk.krd for help.";
    document.body.prepend(notice);
  } finally {
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
