// Header behavior: frosted style on scroll, progress line,
// active link for the section on screen, the back-to-top button,
// and the mobile menu.
export function initNav() {
  const header = document.getElementById("top");
  const nav = document.getElementById("nav");
  const button = document.getElementById("menuBtn");
  const progress = document.getElementById("scrollProgress");
  if (!header) return;

  // Back-to-top button: shows once the first screen has scrolled away.
  const toTop = document.getElementById("toTop");
  if (toTop) {
    toTop.addEventListener("click", () => {
      const smooth = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      window.scrollTo({ top: 0, behavior: smooth ? "smooth" : "auto" });
    });
  }

  // Scrolled look + reading progress
  function onScroll() {
    header.classList.toggle("scrolled", window.scrollY > 8);
    toTop?.classList.toggle("show", window.scrollY > window.innerHeight * 0.6);
    if (!progress) return;
    const max = document.documentElement.scrollHeight - window.innerHeight;
    progress.style.setProperty("--progress", max > 0 ? Math.min(window.scrollY / max, 1) : 0);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  // Mobile menu - only on a page that has one.
  if (!nav || !button) return;

  function setMenu(open) {
    nav.classList.toggle("open", open);
    button.setAttribute("aria-expanded", String(open));
  }
  button.addEventListener("click", () => setMenu(!nav.classList.contains("open")));
  nav.addEventListener("click", (event) => {
    if (event.target.closest("a")) setMenu(false);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") setMenu(false);
  });
  document.addEventListener("click", (event) => {
    if (!header.contains(event.target)) setMenu(false);
  });
  window.matchMedia("(min-width: 1061px)").addEventListener("change", (event) => {
    if (event.matches) setMenu(false);
  });

  // Highlight the link of the section in the middle of the screen
  const links = [...nav.querySelectorAll('a:not(.btn)[href^="#"]')];
  const sections = links.map((link) => document.querySelector(link.getAttribute("href"))).filter(Boolean);
  const visible = new Set();
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) visible.add(entry.target.id);
        else visible.delete(entry.target.id);
      });
      const current = sections.find((section) => visible.has(section.id));
      links.forEach((link) => {
        const active = current && link.getAttribute("href") === `#${current.id}`;
        if (active) link.setAttribute("aria-current", "true");
        else link.removeAttribute("aria-current");
      });
    },
    { rootMargin: "-45% 0px -50% 0px" }
  );
  sections.forEach((section) => observer.observe(section));
}
