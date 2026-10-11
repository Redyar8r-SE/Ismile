import { showLoadError } from "./components/load-error.js?v=110";
// Entry point for sponsor.html: the sponsorship and booth request form.
// The same header, footer and languages as the main page, and nothing else.
import { loadJSON } from "./utils/load-json.js?v=110";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=110";
import { initNav } from "./components/nav.js?v=110";
import { initTheme } from "./components/theme.js?v=110";
import { initFooter } from "./sections/footer.js?v=110";
import { initSponsorForm } from "./sections/sponsor-form.js?v=110";
import { initBoothMap } from "./sections/booth-map.js?v=110";

async function start() {
  initNav();
  initTheme();

  try {
    const [strings, sponsors, footer, boothPlan] = await Promise.all([
      loadJSON("data/i18n/site-en.json"),
      loadJSON("data/sponsors.json"),
      loadJSON("data/footer.json"),
      loadJSON("data/booth-tiers.json"),
    ]);

    initI18n(strings);
    // A missing or broken translation file must never take the page down.
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/site-ar.json").catch(() => null),
      loadJSON("data/i18n/site-ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);

    initFooter(footer);
    initSponsorForm(sponsors);
    initBoothMap({ ...boothPlan, colors: { ...boothPlan.colors, platinum: "#D9F4FF", gold: "#F3D98B", silver: "#DAE4E8", bronze: "#E8B48A" } });

    initLangSwitch();
    setLang(preferredLang());
  } catch (error) {
    console.error(error);
    await showLoadError("load_error");
  } finally {
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
