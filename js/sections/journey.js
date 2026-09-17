// The years under "About iSmile", from data/journey.json.
import { tr, onLangChange } from "../i18n.js";

export function initJourney(items) {
  const list = document.getElementById("journey");

  function render() {
    list.style.setProperty("--count", items.length);
    list.innerHTML = items
      .map((item) => `
        <li${item.now ? ' class="now"' : ""}>
          <span class="yr"><bdi dir="ltr">${item.year}</bdi></span>
          <div><b>${tr(item.title)}</b><p>${tr(item.text)}</p></div>
        </li>`)
      .join("");
  }

  render();
  onLangChange(render);
}
