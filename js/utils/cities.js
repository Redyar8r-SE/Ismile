import { loadJSON } from "./load-json.js?v=148";
import { t, tr, onLangChange } from "../i18n.js?v=148";

export async function initCitySelect(select) {
  if (!select) return;
  const cities = await loadJSON("data/cities.json");
  function render() {
    const selected = select.value;
    select.replaceChildren(new Option(t("o_city"), ""));
    for (const city of cities) select.add(new Option(tr(city.name), city.value));
    if (selected && !cities.some(city => city.value === selected)) select.add(new Option(selected, selected));
    select.value = selected;
  }
  render();
  onLangChange(render);
}
