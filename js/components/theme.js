// Light / dark switch.
// The site opens in dark. A visitor who picks light (or dark again) with the
// switch keeps that choice, remembered in their browser only.
const KEY = "ismile-theme";

const saved = () => {
  try { return localStorage.getItem(KEY); } catch { return null; }
};

export function currentTheme() {
  return saved() === "light" ? "light" : "dark";
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
}
