// The Program / Map switch in the homepage Program section. The map is the
// exhibition floor plan as a plain picture: booths coloured by tier, with no
// live availability and nothing to select or book.
import { t, onLangChange } from "../i18n.js?v=95";
import { buildBooths } from "../config/booth-layout.js?v=95";
import { floorPlanSVG } from "./floor-plan.js?v=1";

export function initProgramMap({ rows, colors }) {
  const plan = document.getElementById("progMapPlan");
  if (!plan) return;
  const booths = buildBooths(rows);
  const label = (booth) => `${t("bm_booth")} ${booth.number} · ${t(`bm_${booth.tier}`)} · ${booth.size}`;
  const render = () => {
    plan.innerHTML = floorPlanSVG({ booths, colors, label, interactive: false });
  };

  const panels = { map: document.getElementById("progMap"), list: document.getElementById("progList") };
  const buttons = { map: document.getElementById("progMapBtn"), list: document.getElementById("progListBtn") };
  const show = (view) => {
    for (const key of Object.keys(panels)) {
      panels[key].hidden = key !== view;
      buttons[key].setAttribute("aria-pressed", String(key === view));
    }
  };
  buttons.map.addEventListener("click", () => show("map"));
  buttons.list.addEventListener("click", () => show("list"));

  render();
  onLangChange(render);
}
