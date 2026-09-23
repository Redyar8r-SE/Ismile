// Entry point: loads the data files, then starts each page section.
import { loadJSON } from "./utils/load-json.js?v=24";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=24";
import { initNav } from "./components/nav.js?v=24";
import { initTheme } from "./components/theme.js?v=24";
import { initProgram } from "./sections/program.js?v=24";
import { initWorkshops } from "./sections/workshops.js?v=29";
import { initSpeakers } from "./sections/speakers.js?v=24";
import { initSponsors } from "./sections/sponsors.js?v=25";
import { initPartners } from "./sections/partners.js?v=29";
import { initJourney } from "./sections/journey.js?v=24";
import { initProjects } from "./sections/projects.js?v=24";
import { initFooter } from "./sections/footer.js?v=24";
import { initVenue } from "./sections/venue.js?v=24";

async function start() {
  initNav();
  initTheme();

  try {
    const [strings, program, workshops, speakers, sponsors, partners, journey, projects, footer, map] = await Promise.all([
      loadJSON("data/i18n/en.json"),
      loadJSON("data/program.json"),
      loadJSON("data/workshops.json"),
      loadJSON("data/speakers.json"),
      loadJSON("data/sponsors.json"),
      loadJSON("data/partners.json"),
      loadJSON("data/journey.json"),
      loadJSON("data/projects.json"),
      loadJSON("data/footer.json"),
      loadJSON("data/map.json"),
    ]);

    initI18n(strings);
    // A missing or broken translation file must never take the page down.
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/ar.json").catch(() => null),
      loadJSON("data/i18n/ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);

    initProgram(program);
    initWorkshops(workshops);
    initSpeakers(speakers);
    initSponsors(sponsors);
    initPartners(partners);
    initJourney(journey);
    initProjects(projects);
    initFooter(footer);
    initVenue(map);

    initLangSwitch();
    setLang(preferredLang());
  } catch (error) {
    console.error(error);
    const notice = document.createElement("div");
    notice.className = "load-error";
    notice.textContent = "Could not load the site data. Open the site through a local server (see README.md).";
    document.body.prepend(notice);
  } finally {
    // The page is as ready as it is going to get, so take the cover off. Also
    // on failure: a visitor should see the message, not a ring turning forever.
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
