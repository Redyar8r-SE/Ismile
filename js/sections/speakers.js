// Speakers grid, from data/speakers.json.
// Empty fields (null) show "Coming soon" placeholders.
import { t, tr, onLangChange } from "../i18n.js";
import { ICONS } from "../config/icons.js";

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
        // A speaker who is not announced yet is drawn in a quieter style.
        return `
          <article class="sp${name ? "" : " is-soon"}">
            <div class="ph">${photo}</div>
            <div class="sp-body">
              <h3>${name || t("sp_name")}</h3>
              <p>${role || t("sp_role")}</p>
            </div>
          </article>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
