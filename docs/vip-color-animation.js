/* This is an isolated preview. The registration page and its behavior are unchanged. */
(() => {
  const original = document.getElementById("baseLayer");
  const originalNodes = [original, ...original.querySelectorAll("*")];
  originalNodes.forEach((node, index) => {
    node.dataset.paintKey = String(index);
  });

  const vip = original.cloneNode(true);
  vip.id = "vipLayer";
  vip.classList.add("is-vip");
  vip.setAttribute("aria-hidden", "true");
  const idAttributes = ["for", "aria-labelledby", "aria-describedby"];
  vip.querySelectorAll("*").forEach((node) => {
    if (node.id) node.id = `vip-${node.id}`;
    for (const attribute of idAttributes) {
      if (node.hasAttribute(attribute)) {
        node.setAttribute(
          attribute,
          node.getAttribute(attribute).split(/\s+/).map((id) => `vip-${id}`).join(" "),
        );
      }
    }
  });
  document.getElementById("previewPage").append(vip);

  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  let animation = null;
  let mode = "original";

  function chooseTicket(next, animate = true) {
    const useVip = next === "vip";
    if (animation) animation.cancel();
    animation = null;
    mode = useVip ? "vip" : "original";
    vip.style.clipPath = useVip ? "inset(0 0 0 0)" : "inset(0 0 100% 0)";
    document.body.dataset.previewMode = mode;
    document.querySelectorAll("[data-ticket]").forEach((button) => {
      button.setAttribute("aria-pressed", String(button.dataset.ticket === mode));
    });
    if (useVip && animate && !reducedMotion.matches) {
      animation = vip.animate(
        [
          { clipPath: "inset(0 0 100% 0)" },
          { clipPath: "inset(0 0 0 0)" },
        ],
        { duration: 1100, easing: "cubic-bezier(.25,.7,.25,1)" },
      );
    }
    return animation;
  }

  document.querySelectorAll("[data-ticket]").forEach((button) => {
    button.addEventListener("click", () => chooseTicket(button.dataset.ticket));
  });
  document.getElementById("replayVip").addEventListener("click", () => chooseTicket("vip"));
  reducedMotion.addEventListener("change", () => chooseTicket(mode, false));

  window.vipColorPreview = {
    select: chooseTicket,
    get animation() { return animation; },
    verifyLayout() {
      const originalElements = original.querySelectorAll("[data-paint-key]");
      const vipElements = vip.querySelectorAll("[data-paint-key]");
      const issues = [];
      originalElements.forEach((node, index) => {
        const counterpart = vipElements[index];
        if (!counterpart || node.textContent !== counterpart.textContent) {
          issues.push(`Content differs at ${index}`);
          return;
        }
        const a = node.getBoundingClientRect();
        const b = counterpart.getBoundingClientRect();
        for (const key of ["x", "y", "width", "height"]) {
          if (Math.abs(a[key] - b[key]) > 0.1) {
            issues.push(`Geometry differs at ${index}: ${key}`);
          }
        }
      });
      return { elements: originalElements.length, issues };
    },
  };

  document.fonts.ready.then(() => {
    document.body.dataset.previewReady = "true";
  });
})();
