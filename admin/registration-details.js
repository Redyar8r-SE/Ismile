document.addEventListener("DOMContentLoaded", () => {
  const profile = document.querySelector("[data-registration-details]");
  if (!profile) return;
  const providerDialog = profile.querySelector("[data-rd-psoola-dialog]");
  const providerBody = providerDialog?.querySelector("[data-rd-psoola-body]");
  let providerTrigger;
  const label = value => typeof value === "string" ? value : value?.en || value?.ckb || value?.ar || "";
  const clearProvider = () => { providerBody?.replaceChildren(); };
  providerDialog?.addEventListener("close", () => { clearProvider(); providerTrigger?.focus(); });
  window.addEventListener("pagehide", () => { providerDialog?.close(); clearProvider(); });
  const showProvider = (data, trigger) => {
    if (!providerDialog || !providerBody) throw Error("Reload this page to view the original PSOOLA ticket.");
    clearProvider(); providerTrigger = trigger;
    const add = (parent, tag, text, className) => {
      const element = document.createElement(tag); element.textContent = text;
      if (className) element.className = className; parent.append(element); return element;
    };
    const summary = add(providerBody, "div", "", "rd-provider-summary");
    add(summary, "span", data.isComplimentary ? "Complimentary" : "Issued", "rd-badge green");
    add(summary, "h3", label(data.event) || "PSOOLA event");
    const rawDate = data.showDateTime;
    const date = rawDate == null ? null : new Date(typeof rawDate === "number" && rawDate < 1e12 ? rawDate * 1000 : rawDate);
    if (date && !Number.isNaN(date.getTime())) add(summary, "p", date.toLocaleString("en-GB", { timeZone: "Asia/Baghdad", dateStyle: "medium", timeStyle: "short" }) + " · Iraq time");
    add(summary, "strong", "Total: " + Number(data.totalAmount).toLocaleString("en-GB") + " " + data.currency);
    for (const item of data.tickets) {
      const card = add(providerBody, "section", "", "rd-provider-ticket");
      add(card, "h3", item.holderName || data.buyer?.name || "Ticket holder");
      add(card, "p", label(item.ticketTypeName), "rd-hint");
      if (item.qrImage?.startsWith("data:image/svg+xml;base64,")) {
        const image = document.createElement("img"); image.src = item.qrImage; image.alt = "Original PSOOLA ticket QR code"; image.width = 240; image.height = 240; card.append(image);
        add(card, "code", item.code, "rd-provider-code");
      } else add(card, "p", "This ticket is " + item.status + ". Its QR is unavailable.", "rd-hint");
      add(card, "p", "Status: " + item.status + (item.checkedIn ? " · Checked in" : "") + (item.inside ? " · Inside" : ""));
      for (const addon of Array.isArray(item.addOns) ? item.addOns : []) {
        const name = label(typeof addon === "string" ? addon : addon.name || addon.title);
        if (name) add(card, "p", name, "rd-hint");
      }
    }
    const delivery = add(providerBody, "div", "", "rd-provider-delivery");
    if (data.buyer?.email) add(delivery, "p", data.buyer.email);
    if (data.buyer?.phone) add(delivery, "p", data.buyer.phone);
    if (data.emailStatus) add(delivery, "p", "Email: " + data.emailStatus);
    if (data.whatsappStatus) add(delivery, "p", "WhatsApp: " + data.whatsappStatus);
    add(providerBody, "p", "Private ticket access. Share only with the ticket holder.", "rd-hint");
    if (data.ticketsUrl) {
      const link = add(providerBody, "a", "Open PSOOLA ticket page", "btn ghost");
      link.href = data.ticketsUrl; link.referrerPolicy = "no-referrer"; link.rel = "noreferrer";
    }
    providerDialog.showModal(); providerDialog.querySelector("[data-rd-close]").focus();
  };
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
        if (!response.ok || !data.ok) throw Error(data.error === "view_only" ? "Your account does not have permission to view this ticket." : data.error || "PSOOLA could not return the ticket page.");
        if (Array.isArray(data.tickets) && data.tickets.length) {
          showProvider(data, ticket); showResult("Original PSOOLA ticket loaded.", "success"); return;
        }
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
