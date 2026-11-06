document.addEventListener("DOMContentLoaded", () => {
  const profile = document.querySelector("[data-registration-details]");
  if (!profile) return;
  profile.addEventListener("click", async event => {
    const opener = event.target.closest("[data-rd-open]");
    if (opener) {
      const dialog = document.getElementById(opener.dataset.rdOpen);
      profile.querySelectorAll(".rd-menu[open]").forEach(menu => { menu.open = false; });
      if (dialog && typeof dialog.showModal === "function") dialog.showModal();
      return;
    }
    const close = event.target.closest("[data-rd-close]");
    if (close) { close.closest("dialog")?.close(); return; }
    const ticket = event.target.closest("[data-rd-ticket]");
    if (!ticket) return;
    event.preventDefault();
    if (ticket.dataset.pending === "true") return;
    const view = ticket.dataset.rdTicket === "view";
    const result = profile.querySelector("[data-rd-link-result]");
    let tab;
    if (view) { tab = window.open("about:blank", "_blank"); if (tab) tab.opener = null; }
    ticket.dataset.pending = "true";
    ticket.setAttribute("aria-busy", "true");
    try {
      let url;
      if (ticket.dataset.orderId) {
        const response = await fetch("registration.php?new=free", {
          method: "POST", credentials: "same-origin", cache: "no-store",
          headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
          body: new URLSearchParams({ do: "link", order_id: ticket.dataset.orderId, csrf: profile.dataset.csrf }),
        });
        const data = await response.json();
        if (!response.ok || !data.ticketsUrl) throw Error(data.error || "The ticket link is unavailable. Retry the link lookup, not issuance.");
        url = data.ticketsUrl;
      } else url = profile.querySelector("a[data-rd-ticket=view]")?.href;
      if (!url) throw Error("No ticket link is available yet.");
      if (view) {
        if (tab) tab.location.replace(url);
        else throw Error("Allow pop-ups for this site, then use View ticket again.");
      } else {
        await navigator.clipboard.writeText(url);
        if (result) result.textContent = "Ticket link copied. Keep this private access link confidential.";
      }
    } catch (error) {
      if (tab) tab.close();
      if (result) { result.textContent = error.message || "The ticket link could not be retrieved."; result.scrollIntoView({ block: "nearest" }); }
    } finally {
      delete ticket.dataset.pending;
      ticket.removeAttribute("aria-busy");
    }
  });
  document.addEventListener("click", event => {
    profile.querySelectorAll(".rd-menu[open]").forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
  });
  document.addEventListener("keydown", event => {
    if (event.key === "Escape") profile.querySelectorAll(".rd-menu[open]").forEach(menu => { menu.open = false; });
  });
  if (location.hash === "#deleteRecord") { const menu = profile.querySelector(".rd-menu"); if (menu) menu.open = true; }
  profile.querySelectorAll("dialog").forEach(dialog => dialog.addEventListener("click", event => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  }));
  profile.querySelectorAll("form[method=post]").forEach(form => form.addEventListener("submit", event => {
    if (form.dataset.pending === "true") { event.preventDefault(); return; }
    const confirmation = form.querySelector('[name="delivery_confirmation"]');
    if (confirmation) confirmation.value = "email-whatsapp-v1";
    form.dataset.pending = "true";
    requestAnimationFrame(() => { form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button => { button.disabled = true; button.setAttribute("aria-busy", "true"); }); });
  }));
  window.addEventListener("pageshow", () => {
    profile.querySelectorAll("form[data-pending]").forEach(form => {
      delete form.dataset.pending;
      form.querySelectorAll('button[aria-busy="true"]').forEach(button => { button.disabled = false; button.removeAttribute("aria-busy"); });
    });
  });
});
