// A number inside a sentence. Each language puts {n} where its grammar needs
// it ("12 seats left", "المقاعد المتبقية: 12"), so Arabic is correct for every
// number. A text without {n} is shown as it is ("شركة واحدة").
import { t } from "../i18n.js?v=24";

export function tCount(key, n, wrap = (value) => value) {
  const text = t(key);
  return text.includes("{n}") ? text.replace("{n}", wrap(n)) : text;
}
