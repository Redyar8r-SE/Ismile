// Scroll reveal: blocks fade and slide up once, as they come into view.
// Each group is a list of elements. Cards of one group that come into view
// together appear one after another (0 ms, +step, +2×step…, capped), and a
// card that arrives later starts at once, so nobody waits for a long queue.
//
// Positions are checked on scroll and resize (not with IntersectionObserver,
// which some embedded and headless browsers never fire): an element that is
// on screen is always shown. Nothing is hidden when the visitor asks for less
// motion, so text can never go missing.

const DURATION = 700;

export function initReveal(groups, { step = 90, maxSteps = 5 } = {}) {
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  let pending = [];
  groups.forEach((group, id) => {
    [...group].forEach((el) => {
      if (el.classList.contains("rv")) return;
      el.classList.add("rv");
      pending.push({ el, id });
    });
  });
  if (!pending.length) return;
  document.documentElement.classList.add("reveal-on");

  function show(el, delay) {
    el.style.setProperty("--rv-delay", `${delay}ms`);
    el.classList.add("is-in");
    // Once in place, hand the element back to its own styles (hover lifts,
    // transitions), as if it had never been animated.
    setTimeout(() => {
      el.classList.remove("rv", "is-in");
      el.style.removeProperty("--rv-delay");
    }, DURATION + delay + 60);
  }

  // Checked straight on each scroll (a few dozen elements is cheap), not on
  // the next animation frame: some in-app browsers hold frames back.
  function check() {
    const line = window.innerHeight * 0.9;
    const seen = new Map();
    pending = pending.filter(({ el, id }) => {
      if (!el.isConnected) return false;
      if (el.getBoundingClientRect().top >= line) return true;
      const n = seen.get(id) || 0;
      seen.set(id, n + 1);
      show(el, Math.min(n, maxSteps) * step);
      return false;
    });
    if (!pending.length) {
      window.removeEventListener("scroll", check);
      window.removeEventListener("resize", check);
    }
  }
  window.addEventListener("scroll", check, { passive: true });
  window.addEventListener("resize", check);
  check();
}
