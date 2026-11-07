document.addEventListener("DOMContentLoaded", () => {
  const root = document.querySelector("[data-workshop-workspace]");
  if (!root) return;
  let trigger;
  const open = (dialog, opener) => {
    if (!dialog || typeof dialog.showModal !== "function") return;
    trigger = opener;
    if (dialog.open) dialog.close();
    dialog.showModal();
    const field = dialog.querySelector(".ws-reason textarea, .ws-book-search input[type=search], [data-ws-close]");
    field?.focus({ preventScroll: true });
  };
  document.addEventListener("click", event => {
    const opener = event.target.closest("[data-ws-open]");
    if (opener) {
      const dialog = document.getElementById(opener.dataset.wsOpen);
      if (!dialog) return;
      event.preventDefault(); open(dialog, opener);
      if (opener.dataset.wsEdit) {
        const row = [...dialog.querySelectorAll('[name="ws"]')].find(input => input.value === opener.dataset.wsEdit)?.closest("tr");
        const edit = row?.querySelector("details.edit");
        if (edit) { edit.open = true; requestAnimationFrame(() => edit.scrollIntoView({ block: "start" })); }
      }
      return;
    }
    const close = event.target.closest("[data-ws-close]");
    if (close) close.closest("dialog")?.close();
    root.querySelectorAll(".ws-menu[open]").forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
  });
  root.querySelectorAll(".ws-dialog").forEach(dialog => {
    dialog.addEventListener("close", () => { trigger?.focus({ preventScroll: true }); });
    dialog.addEventListener("click", event => {
      if (event.target !== dialog) return;
      const box = dialog.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
  });
  root.querySelectorAll("form[data-ws-issue-form]").forEach(form => {
    form.addEventListener("submit", event => {
      if (form.dataset.pending === "true") { event.preventDefault(); return; }
      const consent = form.querySelector('[name="delivery_confirmation"]');
      if (consent) consent.value = "email-whatsapp-v1";
      form.dataset.pending = "true";
      requestAnimationFrame(() => form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => { button.disabled = true; button.setAttribute("aria-busy", "true"); }));
    });
  });
  const booking = document.getElementById("ws-book");
  if (booking?.open || location.hash === "#book") open(booking);
  if (location.hash === "#manage") open(document.getElementById("ws-settings"));
  window.addEventListener("pageshow", () => root.querySelectorAll("form[data-pending]").forEach(form => {
    delete form.dataset.pending;
    form.querySelectorAll('button[aria-busy="true"]').forEach(button => { button.disabled = false; button.removeAttribute("aria-busy"); });
  }));
});
