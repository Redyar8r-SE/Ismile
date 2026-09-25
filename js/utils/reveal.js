// Scroll reveal: parts of a section fade and slide up as they come into view,
// one after another, once. Each group is a list of elements that share a
// stagger (0 ms, +step, +2×step…).
//
// Positions are checked on scroll and resize (not with IntersectionObserver,
// which some embedded and headless browsers never fire): an element that is
// on screen is always shown. Nothing is hidden when the visitor asks for less
// motion, so text can never go missing.

export function initReveal(groups, { step = 110 } = {}) {
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  let pending = [];
  groups.forEach((group) => {
    [...group].forEach((el, i) => {
      el.classList.add("rv");
      el.style.setProperty("--rv-delay", `${i * step}ms`);
      pending.push(el);
    });
  });
  if (!pending.length) return;
  document.documentElement.classList.add("reveal-on");

  // Checked straight on each scroll (a dozen elements is cheap), not on the
  // next animation frame: some in-app browsers hold frames back.
  function check() {
    const line = window.innerHeight * 0.9;
    pending = pending.filter((el) => {
      if (el.getBoundingClientRect().top < line) { el.classList.add("is-in"); return false; }
      return true;
    });
    if (!pending.length) {
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
    }
  }
  const onScroll = () => check();
  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", onScroll);
  check();
}
