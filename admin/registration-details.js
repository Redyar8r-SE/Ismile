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
    const provider = ticket.dataset.rdTicket === "psoola";
    const view = ticket.dataset.rdTicket === "view" || provider;
    const lookupView = view && (provider || !!ticket.dataset.orderId);
    const card = ticket.closest(".rd-admission,.rd-booking");
    let result = card ? card.querySelector("[data-rd-link-result]") : profile.querySelector("[data-rd-link-result]");
    if (!result) {
      result = document.createElement("p"); result.className = "rd-link-result"; result.dataset.rdLinkResult = "";
      ticket.closest(".rd-ticket-actions")?.after(result);
    }
    const showResult = (message, state) => {
      result.textContent = message; result.dataset.state = state; result.setAttribute("role", state === "error" ? "alert" : "status");
      if (state === "error") { result.tabIndex = -1; result.focus({ preventScroll: true }); result.scrollIntoView({ block: "nearest" }); }
    };
    let tab;
    if (view && !lookupView) {
      tab = window.open("about:blank", "_blank");
      if (tab) {
        tab.opener = null;
        const policy = tab.document.createElement("meta"); policy.name = "referrer"; policy.content = "no-referrer"; tab.document.head.append(policy);
      }
    }
    ticket.dataset.pending = "true";
    ticket.setAttribute("aria-busy", "true");
    if (lookupView) showResult("Getting the PSOOLA ticket…", "pending");
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      let url;
      if (ticket.dataset.rdTicket === "psoola") {
        const response = await fetch("psoola-ticket.php", {
          method: "POST", credentials: "same-origin", cache: "no-store",
          signal: controller.signal,
          headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
          body: new URLSearchParams({ registration_id: ticket.dataset.registrationId, csrf: profile.dataset.csrf }),
        });
        if (!(response.headers.get("content-type") || "").includes("application/json")) throw Error("Reload this page and sign in again to view the PSOOLA ticket.");
        const data = await response.json();
        if (!response.ok || !data.ticketsUrl) throw Error(data.error === "view_only" ? "Your account does not have permission to view this ticket." : data.error || "PSOOLA could not return the ticket page.");
        url = data.ticketsUrl;
      } else if (ticket.dataset.orderId) {
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
        if (lookupView) { showResult("Opening the PSOOLA ticket…", "pending"); window.location.assign(url); }
        else if (tab) tab.location.replace(url);
        else throw Error("Allow pop-ups for this site, then use View ticket again.");
      } else {
        await navigator.clipboard.writeText(url);
        showResult("Ticket link copied. Keep this private access link confidential.", "success");
      }
    } catch (error) {
      if (tab) tab.close();
      showResult(error.name === "AbortError" ? "The ticket lookup timed out. Please try again." : error.message || "The ticket link could not be retrieved.", "error");
    } finally {
      clearTimeout(timeout);
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
    profile.querySelectorAll("[data-rd-ticket][data-pending]").forEach(button => { delete button.dataset.pending; button.removeAttribute("aria-busy"); });
    profile.querySelectorAll('[data-rd-link-result][data-state="pending"]').forEach(result => { result.textContent = ""; delete result.dataset.state; });
    profile.querySelectorAll("form[data-pending]").forEach(form => {
      delete form.dataset.pending;
      form.querySelectorAll('button[aria-busy="true"]').forEach(button => { button.disabled = false; button.removeAttribute("aria-busy"); });
    });
  });
});
