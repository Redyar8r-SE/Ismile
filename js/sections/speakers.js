// Speakers grid, from data/speakers.json.
// Empty fields (null) show "Coming soon" placeholders.
import { t, tr, onLangChange } from "../i18n.js";
import { ICONS } from "../config/icons.js";

export function initSpeakers(speakers) {
  const grid = document.getElementById("spGrid");

  function render() {
    grid.innerHTML = speakers
      .map((s) => {
        const photo = s.photo
          ? `<img src="${s.photo}" alt="${tr(s.name) || ""}" loading="lazy">`
          : `<em>${t("sp_soon")}</em>${ICONS.person}`;
        return `
          <div class="sp">
            <div class="ph">${photo}</div>
            <h3>${tr(s.name) || t("sp_name")}</h3>
            <p>${tr(s.role) || t("sp_role")}</p>
          </div>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
