// The small blocks under the italk card ("italkMedX" and any others),
// from data/projects.json.
import { tr, onLangChange } from "../i18n.js?v=22";

export function initProjects(items) {
  const holder = document.getElementById("orgProjects");

  function render() {
    holder.innerHTML = items
      .map((item) => `
        <div class="org-sub">
          <h4>${tr(item.title)}</h4>
          <p>${tr(item.text)}</p>
        </div>`)
      .join("");
  }

  render();
  onLangChange(render);
}
