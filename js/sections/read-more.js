// "Read more" on the italkMedX card: the rest of the text starts folded (a few
// lines fading out) and opens smoothly. Both button words sit in the page, and
// CSS shows the one that fits, so a language switch never mixes them up.

export function initReadMore() {
  document.querySelectorAll("[data-read-more]").forEach((button) => {
    const panel = document.getElementById(button.getAttribute("aria-controls"));
    if (!panel) return;

    button.addEventListener("click", () => {
      const opening = button.getAttribute("aria-expanded") !== "true";
      button.setAttribute("aria-expanded", String(opening));
      // Animate between the folded height and the real one, then let the text
      // size itself (so resizing or a language switch never cuts it off).
      const from = panel.getBoundingClientRect().height;
      panel.classList.toggle("is-open", opening);
      const to = opening ? panel.scrollHeight : panel.getBoundingClientRect().height;
      panel.style.maxHeight = `${from}px`;
      panel.getBoundingClientRect();
      panel.style.maxHeight = `${to}px`;
      setTimeout(() => { panel.style.maxHeight = ""; }, 600);
      if (!opening && panel.getBoundingClientRect().top < 80) {
        panel.closest("article")?.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });
}
