// Localize the existing error notice even when a page's content fails to load.
import { onLangChange, t } from "../i18n.js?v=95";

const fallback = {
  load_error: "Could not load the site data. Open the site through a local server (see README.md).",
  reg_load_error: "Registration could not load. Please refresh the page, or contact ismile@italk.krd for help.",
};

export async function showLoadError(key) {
  const notice = document.createElement("div");
  notice.className = "load-error";
  notice.dataset.i18n = key;
  let code = (navigator.language || "en").slice(0, 2);
  try { code = localStorage.getItem("ismile-lang") || code; } catch { /* private browsing */ }
  if (!["en", "ar", "ku"].includes(code)) code = "en";
  let wording = fallback[key];
  try {
    const response = await fetch(`data/i18n/site-${code}.json`, { cache: "no-cache" });
    if (response.ok) wording = (await response.json())[key] || wording;
  } catch { /* retain the existing notice when the network is unavailable */ }
  const render = () => { notice.textContent = t(key) === key ? wording : t(key); };
  render();
  onLangChange(render);
  document.body.prepend(notice);
}
