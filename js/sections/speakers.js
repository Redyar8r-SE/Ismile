// A single sliding row, from data/speakers.json.
// Empty fields (null) show "Coming soon" placeholders.
import { t, tr, onLangChange } from "../i18n.js?v=148";
import { ICONS } from "../config/icons.js?v=148";

export function initSpeakers(speakers) {
  const grid = document.getElementById("spGrid");
  if (!grid) return;
  const escape = value => String(value ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[c]);
  const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)");
  const carousel = document.createElement("div");
  carousel.className = "sp-carousel";
  carousel.setAttribute("role", "region");
  carousel.setAttribute("aria-roledescription", "carousel");
  grid.before(carousel);
  const toolbar = document.createElement("div");
  toolbar.className = "sp-controls";
  carousel.append(grid, toolbar);
  grid.tabIndex = 0;
  let index = 0;
  let frame;
  const visible = () => Math.max(1, Number(getComputedStyle(grid).getPropertyValue("--sp-visible")) || 1);
  const step = () => (grid.querySelector(".sp")?.getBoundingClientRect().width || 0) + (parseFloat(getComputedStyle(grid).columnGap) || 0);
  const last = () => Math.max(0, speakers.length - visible());
  const rtl = () => document.documentElement.dir === "rtl";
  const align = (target, animate = true) => {
    index = Math.max(0, Math.min(last(), target));
    grid.scrollTo({left: (rtl() ? -1 : 1) * index * step(), behavior: animate && !reducedMotion.matches ? "smooth" : "instant"});
    updateControls();
  };
  function updateControls() {
    const previous = toolbar.querySelector("[data-sp-previous]");
    const next = toolbar.querySelector("[data-sp-next]");
    if (!previous || !next) return;
    previous.disabled = index === 0;
    next.disabled = index >= last();
    toolbar.querySelector(".sp-position").textContent = t("sp_range")
      .replace("{start}", speakers.length ? index + 1 : 0)
      .replace("{end}", Math.min(index + visible(), speakers.length))
      .replace("{total}", speakers.length);
    toolbar.hidden = speakers.length <= visible();
  }

  function render() {
    grid.innerHTML = speakers
      .map((s) => {
        const name = escape(tr(s.name));
        const role = escape(tr(s.role));
        const topic = escape(tr(s.topic));
        const photo = s.photo
          ? `<img src="${escape(s.photo)}" alt="${name || ""}" loading="lazy" decoding="async">`
          : `<em>${t("sp_soon")}</em>${ICONS.person}`;
        // A speaker who is not announced yet shows shaped placeholders instead
        // of pretend text, with the wording kept for screen readers.
        const body = name
          ? `<h3>${name}</h3>${role ? `<p class="sp-role">${role}</p>` : ""}${
              topic
                ? `<div class="sp-topic"><span class="sp-topic-label">${ICONS.mic}${t("sp_topic")}</span><p>${topic}</p></div>`
                : ""
            }`
          : `<h3 class="sk-line"><span class="sr-only">${t("sp_name")}</span></h3>
             <p class="sk-line sk-short"><span class="sr-only">${t("sp_role")}</span></p>`;
        return `
          <article class="sp${name ? "" : " is-soon"}">
            <div class="ph">${photo}</div>
            <div class="sp-body">${body}</div>
          </article>`;
      })
      .join("");
    carousel.setAttribute("aria-label", t("sp_carousel_label"));
    grid.setAttribute("aria-label", t("sp_browse_hint"));
    const arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg>';
    toolbar.innerHTML = `<p class="sp-browse-hint">${escape(t("sp_browse_hint"))}</p><div class="sp-navigation"><button type="button" class="sp-arrow" data-sp-previous aria-controls="spGrid" aria-label="${escape(t("sp_previous"))}">${arrow}</button><span class="sp-position" role="status" aria-live="polite" aria-atomic="true"></span><button type="button" class="sp-arrow sp-arrow-next" data-sp-next aria-controls="spGrid" aria-label="${escape(t("sp_next"))}">${arrow}</button></div>`;
    toolbar.querySelector("[data-sp-previous]").addEventListener("click", () => align(index - visible()));
    toolbar.querySelector("[data-sp-next]").addEventListener("click", () => align(index + visible()));
    requestAnimationFrame(() => align(index, false));
  }

  grid.addEventListener("scroll", () => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
      index = Math.max(0, Math.min(last(), Math.round(Math.abs(grid.scrollLeft) / (step() || 1))));
      updateControls();
    });
  }, {passive: true});
  grid.addEventListener("keydown", event => {
    if (event.target !== grid) return;
    const direction = event.key === "ArrowRight" ? (rtl() ? -1 : 1) : event.key === "ArrowLeft" ? (rtl() ? 1 : -1) : 0;
    if (direction || event.key === "Home" || event.key === "End") {
      event.preventDefault();
      align(event.key === "Home" ? 0 : event.key === "End" ? last() : index + direction);
    }
  });
  new ResizeObserver(() => align(index, false)).observe(grid);
  render();
  onLangChange(render);
}
