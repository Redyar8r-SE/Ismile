// Entry point for workshops.html: the workshop cards, the office number and
// how booking works. The same header, footer and languages as the main page.
import { loadJSON } from "./utils/load-json.js?v=24";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=24";
import { initNav } from "./components/nav.js?v=25";
import { initTheme } from "./components/theme.js?v=24";
import { initFooter } from "./sections/footer.js?v=24";
import { initWorkshops } from "./sections/workshops.js?v=35";

async function start() {
  initNav();
  initTheme();

  try {
    const [strings, workshops, footer] = await Promise.all([
      loadJSON("data/i18n/en.json"),
      loadJSON("data/workshops.json"),
      loadJSON("data/footer.json"),
    ]);

    initI18n(strings);
    // A missing or broken translation file must never take the page down.
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/ar.json").catch(() => null),
      loadJSON("data/i18n/ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);

    initFooter(footer);
    initWorkshops(workshops);

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
