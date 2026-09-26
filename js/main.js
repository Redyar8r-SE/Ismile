// Entry point: loads the data files, then starts each page section.
import { loadJSON } from "./utils/load-json.js?v=24";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang } from "./i18n.js?v=24";
import { initNav } from "./components/nav.js?v=25";
import { initTheme } from "./components/theme.js?v=24";
import { initProgram } from "./sections/program.js?v=27";
import { initSpeakers } from "./sections/speakers.js?v=24";
import { initSponsors } from "./sections/sponsors.js?v=26";
import { initPartners } from "./sections/partners.js?v=30";
import { initJourney } from "./sections/journey.js?v=24";
import { initProjects } from "./sections/projects.js?v=24";
import { initGallery } from "./sections/gallery.js?v=3";
import { initFooter } from "./sections/footer.js?v=24";
import { initVenue } from "./sections/venue.js?v=24";
import { initReveal } from "./utils/reveal.js?v=4";
import { initReadMore } from "./sections/read-more.js?v=1";

async function start() {
  initNav();
  initTheme();

  try {
    const [strings, program, speakers, sponsors, partners, journey, projects, gallery, footer, map] = await Promise.all([
      loadJSON("data/i18n/en.json"),
      loadJSON("data/program.json"),
      loadJSON("data/speakers.json"),
      loadJSON("data/sponsors.json"),
      loadJSON("data/partners.json"),
      loadJSON("data/journey.json"),
      loadJSON("data/projects.json"),
      loadJSON("data/gallery.json"),
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
    initSpeakers(speakers);
    initSponsors(sponsors);
    initPartners(partners);
    initJourney(journey);
    initProjects(projects);
    initGallery(gallery);
    initReadMore();
    initFooter(footer);
    initVenue(map);

    initLangSwitch();
    setLang(preferredLang());

    // Scroll reveal, once per block: section titles, then the cards of each
    // section one after another. Plain paragraphs are never animated on
    // their own. Started after the language is set, because that redraws
    // the lists.
    const all = (s) => document.querySelectorAll(s);
    initReveal([
      all(".ab-head, .gal-head, .exp-head, .areas-head, .prog-head, #speakers .wrap > h2, #speakers .wrap > .lead, .spon-head, .tp-head, #companies .wrap > h2, #companies .wrap > .lead, .venue-head"),
      all("#journey > li"), all("#galleryGrid > li"),
      all(".org-card"), all(".org-top, .org-card > p, .medx-rest, .medx-toggle"), all(".medx-card"), all(".org-facts li"), all(".vm2 > .vm-card"),
      all(".bento > .bx"), all(".areas > .area"),
      all("#dayTabs, #dayMeta, #sched"),
      all(".reg-invite-card"),
      all("#spGrid > *"), all("#sponsorTiers > *"), all("#ptiers > *, .p-cta"),
      all(".co-grid > .co"), all(".venue-card"),
    ]);
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
