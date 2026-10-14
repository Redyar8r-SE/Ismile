// The Program / Map switch in the homepage Program section. The map is the
// exhibition floor plan as a plain picture: every booth the same colour (the
// tiers are not shown to visitors), no availability and nothing to book.
import { t, onLangChange } from "../i18n.js?v=131";
import { buildBooths } from "../config/booth-layout.js?v=131";
import { floorPlanSVG } from "./floor-plan.js?v=131";

export function initProgramMap({ rows }) {
  const plan = document.getElementById("progMapPlan");
  if (!plan) return;
  const booths = buildBooths(rows);
  const label = (booth) => `${t("bm_booth")} ${booth.number}`;
  const colors = { platinum: "var(--pm-booth)", gold: "var(--pm-booth)", silver: "var(--pm-booth)", bronze: "var(--pm-booth)" };
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
