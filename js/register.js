// Registration page: shared navigation, translations, footer, and form.
import { loadJSON } from "./utils/load-json.js?v=24";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=24";
import { initNav } from "./components/nav.js?v=25";
import { initTheme } from "./components/theme.js?v=24";
import { initFooter } from "./sections/footer.js?v=24";
import { initRegistration } from "./sections/registration.js?v=38";

async function start() {
  initNav();
  initTheme();
  try {
    const [english, footer, workshops, tickets] = await Promise.all([
      loadJSON("data/i18n/en.json"),
      loadJSON("data/footer.json"),
      loadJSON("data/workshops.json"),
      loadJSON("data/tickets.json").catch(() => ({})),
    ]);
    initI18n(english);
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/ar.json").catch(() => null),
      loadJSON("data/i18n/ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);
    initFooter(footer);
    initRegistration({ workshops, tickets });
    initLangSwitch();
    setLang(preferredLang());
  } catch (error) {
    console.error(error);
    const notice = document.createElement("div");
    notice.className = "load-error";
    notice.textContent = "Could not load the site data. Open the site through a local server (see README.md).";
    document.body.prepend(notice);
  } finally {
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
