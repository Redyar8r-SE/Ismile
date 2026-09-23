// Prices are whole dinars. Digits stay Latin in every language so a total
// reads the same on the payment link and on the page.
import { t } from "../i18n.js?v=24";

export function formatPrice(amount) {
  const value = Number(amount);
  if (!value) return t("tk_soon");
  return `${value.toLocaleString("en-US")} ${t("cur_iqd")}`;
}
