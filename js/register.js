import { showLoadError } from "./components/load-error.js?v=95";
// Registration page: shared navigation, translations, footer, and form.
import { loadJSON } from "./utils/load-json.js?v=95";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=95";
import { initNav } from "./components/nav.js?v=95";
import { initTheme } from "./components/theme.js?v=95";
import { initFooter } from "./sections/footer.js?v=95";
import { initRegistration } from "./sections/registration.js?v=99";

async function start() {
  initNav();
  initTheme();
  try {
    const [english, footer, tickets] = await Promise.all([
      loadJSON("data/i18n/site-en.json"),
      loadJSON("data/footer.json"),
      loadJSON("data/tickets.json").catch(() => ({})),
    ]);
    initI18n(english);
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/site-ar.json").catch(() => null),
      loadJSON("data/i18n/site-ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);
    initFooter(footer);
    const ready = initRegistration({ tickets });
    initLangSwitch();
    setLang(preferredLang());
    await ready;
  } catch (error) {
    console.error(error);
    await showLoadError("reg_load_error");
  } finally {
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
