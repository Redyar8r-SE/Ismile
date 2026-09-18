// Light / dark switch.
// No choice saved yet -> the site follows the visitor's device.
// Once they choose, the choice is remembered in their browser only.
const KEY = "ismile-theme";

const systemDark = () => window.matchMedia?.("(prefers-color-scheme: dark)").matches;
const saved = () => {
  try { return localStorage.getItem(KEY); } catch { return null; }
};

export function currentTheme() {
  const choice = saved();
  if (choice === "light" || choice === "dark") return choice;
  return systemDark() ? "dark" : "light";
}

function apply(theme, button) {
  document.documentElement.dataset.theme = theme;
  if (button) {
    button.dataset.now = theme;
    button.setAttribute("aria-pressed", String(theme === "dark"));
  }
}

export function initTheme() {
  const button = document.getElementById("themeBtn");
  apply(currentTheme(), button);

  button?.addEventListener("click", () => {
    const next = currentTheme() === "dark" ? "light" : "dark";
    try { localStorage.setItem(KEY, next); } catch { /* private browsing */ }
    apply(next, button);
  });

  // Follow the device while the visitor has not chosen anything themselves.
  window.matchMedia?.("(prefers-color-scheme: dark)").addEventListener?.("change", () => {
    if (!saved()) apply(currentTheme(), button);
  });
}
