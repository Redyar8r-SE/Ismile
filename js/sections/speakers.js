// Speakers grid, from data/speakers.json.
// Empty fields (null) show "Coming soon" placeholders.
import { t, tr, onLangChange } from "../i18n.js?v=24";
import { ICONS } from "../config/icons.js?v=24";

export function initSpeakers(speakers) {
  const grid = document.getElementById("spGrid");

  function render() {
    grid.innerHTML = speakers
      .map((s) => {
        const name = tr(s.name);
        const role = tr(s.role);
        const photo = s.photo
          ? `<img src="${s.photo}" alt="${name || ""}" loading="lazy">`
          : `<em>${t("sp_soon")}</em>${ICONS.person}`;
        // A speaker who is not announced yet shows shaped placeholders instead
        // of pretend text, with the wording kept for screen readers.
        const body = name
          ? `<h3>${name}</h3><p>${role || t("sp_role")}</p>`
          : `<h3 class="sk-line"><span class="sr-only">${t("sp_name")}</span></h3>
             <p class="sk-line sk-short"><span class="sr-only">${t("sp_role")}</span></p>`;
        return `
          <article class="sp${name ? "" : " is-soon"}">
            <div class="ph">${photo}</div>
            <div class="sp-body">${body}</div>
          </article>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
