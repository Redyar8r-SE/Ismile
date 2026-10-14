// Search without navigation, including single letters and pasted scanner text.
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("form[data-instant-search], form[data-guest-suggestions]").forEach(form => {
    const input = form.querySelector('[name="q"]');
    if (!input) return;
    input.autocomplete = "off";
    const suggestions = form.hasAttribute("data-guest-suggestions");
    const keys = (form.dataset.searchRegions || "").split(" ").filter(Boolean);
    const regions = keys.map(key => document.querySelector(`[data-search-region="${key}"], [data-live-region="${key}"]`));
    if (!suggestions && (!regions.length || regions.some(region => !region))) return;
    const status = document.createElement("p");
    status.className = "instant-search-status muted small";
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    status.textContent = "Matches appear as you type.";
    let panel;
    if (suggestions) {
      panel = document.createElement("div");
      panel.className = "guest-search-suggestions";
      panel.hidden = true;
      panel.setAttribute("aria-label", "Matching guests");
      panel.append(status);
      form.append(panel);
      input.setAttribute("aria-expanded", "false");
    } else form.after(status);
    let revision = 0, timer, controller, composing = false, stopped = false;
    const changed = () => {
      revision++;
      clearTimeout(timer);
      controller?.abort();
      if (suggestions) { panel.replaceChildren(status); panel.hidden = !input.value.trim(); input.setAttribute("aria-expanded", String(!panel.hidden)); status.textContent = "Searching…"; }
      if (!suggestions) {
        form.dataset.searchDirty = "true";
        document.dispatchEvent(new Event("admin:search-start"));
        regions.forEach(region => { region.inert = true; region.setAttribute("aria-busy", "true"); });
      }
    };
    const search = async () => {
      if (stopped || composing) return;
      const current = revision;
      const url = new URL(form.getAttribute("action") || location.href, location.href);
      url.searchParams.delete("page");
      const fields = new FormData(form);
      for (const key of new Set([...fields.keys()])) url.searchParams.delete(key);
      for (const [key, value] of fields) if (String(value).trim()) url.searchParams.append(key, String(value).trim());
      if (suggestions && !input.value.trim()) { panel.hidden = true; input.setAttribute("aria-expanded", "false"); return; }
      if (!suggestions) history.replaceState(null, "", url.href);
      else { panel.hidden = false; input.setAttribute("aria-expanded", "true"); panel.replaceChildren(status); }
      status.textContent = "Searching…";
      const activeController = new AbortController();
      controller = activeController;
      const timeout = setTimeout(() => activeController.abort(), 10000);
      try {
        const response = await fetch(url, { credentials: "same-origin", cache: "no-store", signal: activeController.signal, headers: { Accept: "text/html" } });
        if (response.redirected || !response.ok || !response.headers.get("content-type")?.includes("text/html")) throw new Error("unavailable");
        const page = new DOMParser().parseFromString(await response.text(), "text/html");
        if (current !== revision || stopped) return;
        if (suggestions) {
          const directory = page.querySelector('[data-live-region="registration-directory"]');
          if (!directory) throw new Error("unavailable");
          const matches = [...directory.querySelectorAll("[data-registration-result]")];
          status.textContent = matches.length ? "Matching guests" : "No matching guests.";
          for (const row of matches.slice(0, 8)) {
            const source = row.querySelector(".guest-cell a");
            const link = document.createElement("a");
            link.href = new URL(source.getAttribute("href"), url).href;
            const name = document.createElement("b");
            name.textContent = source.textContent;
            const ref = document.createElement("small");
            ref.textContent = row.querySelector("code")?.textContent || "";
            link.append(name, ref);
            panel.append(link);
          }
          if (matches.length) {
            const all = document.createElement("a");
            all.className = "guest-search-all";
            all.href = url.href;
            all.textContent = "View all matches";
            panel.append(all);
          }
        } else {
          const replacements = keys.map(key => page.querySelector(`[data-search-region="${key}"], [data-live-region="${key}"]`));
          if (replacements.some(region => !region)) throw new Error("unavailable");
          regions.forEach((region, index) => { region.innerHTML = replacements[index].innerHTML; region.inert = false; region.removeAttribute("aria-busy"); });
          form.dataset.searchDirty = "false";
          status.textContent = input.value.trim() ? "Matches updated as you type." : "Search cleared.";
          document.dispatchEvent(new Event("admin:search-complete"));
        }
      } catch {
        if (current !== revision || stopped) return;
        status.textContent = "Search unavailable. Check your connection and try again.";
        // Leave the last results usable, and let the page's own updates resume.
        regions.forEach(region => { region.inert = false; region.removeAttribute("aria-busy"); });
        form.dataset.searchDirty = "false";
      } finally { clearTimeout(timeout); }
    };
    input.addEventListener("input", event => {
      changed();
      if (!composing && !event.isComposing) timer = setTimeout(search, 250);
    });
    input.addEventListener("compositionstart", () => { composing = true; changed(); });
    input.addEventListener("compositionend", () => { composing = false; changed(); timer = setTimeout(search, 250); });
    if (!suggestions) {
      form.addEventListener("submit", event => { event.preventDefault(); changed(); search(); });
      form.addEventListener("change", event => { if (event.target !== input) { changed(); timer = setTimeout(search, 250); } });
    } else {
      document.addEventListener("click", event => { if (!form.contains(event.target)) { panel.hidden = true; input.setAttribute("aria-expanded", "false"); } });
      form.addEventListener("keydown", event => {
        if (event.key === "Escape") { panel.hidden = true; input.setAttribute("aria-expanded", "false"); input.focus(); }
        if (event.key === "ArrowDown" && event.target === input) { const first = panel.querySelector("a"); if (first) { event.preventDefault(); first.focus(); } }
      });
    }
    window.addEventListener("pagehide", () => { stopped = true; clearTimeout(timer); controller?.abort(); });
    window.addEventListener("pageshow", event => { if (event.persisted) { stopped = false; if (!suggestions) { changed(); search(); } } });
  });
});

// Every admin page stays current: every 10 seconds the page is fetched again and
// only the parts that changed are swapped in, quietly. Pages with marked regions
// (data-live-region) swap those; other pages swap each block of the main area
// that has nothing to type in. Nothing is swapped while it is being used: a
// focused or changed field, an open menu, selected text, a camera.
document.addEventListener("DOMContentLoaded", () => {
  const main = document.getElementById("main-content");
  if (!main || !document.body.classList.contains("admin-shell") || document.querySelector("[data-live-report], [data-no-live], video")) return;
  const marked = [...document.querySelectorAll("[data-live-region]")];
  const previous = new Map();
  let pending = false, stopped = false, timer, controller;
  const typing = el => el.matches?.("input:not([type=hidden]):not([type=submit]):not([type=button]), textarea, select, [contenteditable]");
  // A part may be swapped only when nothing in it is in use.
  const inUse = region => {
    const focused = document.activeElement;
    if (focused && region.contains(focused) && typing(focused)) return true;
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed && (region.contains(selection.anchorNode) || region.contains(selection.focusNode))) return true;
    if (region.querySelector("details[open]:not([data-live-key]), dialog[open], video, canvas")) return true;
    return [...region.querySelectorAll("input, textarea, select")].some(field =>
      field.type === "checkbox" || field.type === "radio" ? field.checked !== field.defaultChecked
        : field.tagName === "SELECT" ? [...field.options].some(option => option.selected !== option.defaultSelected)
        : field.type !== "hidden" && field.value !== field.defaultValue);
  };
  // Without marked regions: the blocks of the main area with no typing fields.
  const blocks = root => [...root.children].filter(el => !el.matches(".flash, script, .page-heading, .instant-search-status") && !el.querySelector("input:not([type=hidden]):not([type=submit]):not([type=button]), textarea, select, [contenteditable]"));
  const swap = (region, html) => {
    if (previous.get(region) === html || region.innerHTML === html || inUse(region)) return;
    const focusedLink = region.contains(document.activeElement) ? document.activeElement.closest("a[href]")?.getAttribute("href") : null;
    const opened = [...region.querySelectorAll("details[open][data-live-key]")].map(el => el.dataset.liveKey);
    const scrolls = [...region.querySelectorAll(".table-wrap")].map(el => [el.scrollLeft, el.scrollTop]);
    region.innerHTML = html;
    for (const detail of region.querySelectorAll("details[data-live-key]")) detail.open = opened.includes(detail.dataset.liveKey);
    region.querySelectorAll(".table-wrap").forEach((el, i) => { if (scrolls[i]) [el.scrollLeft, el.scrollTop] = scrolls[i]; });
    if (focusedLink) [...region.querySelectorAll("a[href]")].find(el => el.getAttribute("href") === focusedLink)?.focus({ preventScroll: true });
    previous.set(region, html);
  };
  const update = async () => {
    if (pending || stopped || document.hidden || document.querySelector('[data-instant-search][data-search-dirty="true"]')) return;
    const requestUrl = location.href;
    pending = true;
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(location.href, { credentials: "same-origin", cache: "no-store", signal: controller.signal, headers: { Accept: "text/html" } });
      if (response.redirected || response.status === 401 || response.status === 403) { stopped = true; clearInterval(timer); return; }
      if (!response.ok || !response.headers.get("content-type")?.includes("text/html")) return;
      const page = new DOMParser().parseFromString(await response.text(), "text/html");
      if (location.href !== requestUrl || document.querySelector('[data-instant-search][data-search-dirty="true"]')) return;
      const fresh = page.getElementById("main-content");
      if (!fresh || !page.body.classList.contains("admin-shell")) return;
      if (marked.length) {
        marked.forEach(region => {
          const replacement = page.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
          if (replacement) swap(region, replacement.innerHTML);
        });
        return;
      }
      const now = blocks(main), next = blocks(fresh);
      // Only when the page still has the same shape; otherwise wait for a real visit.
      if (now.length !== next.length || now.some((el, i) => el.tagName !== next[i].tagName || el.className !== next[i].className)) return;
      now.forEach((el, i) => swap(el, next[i].innerHTML));
    } catch {
      // Offline or busy: keep what is on the screen and try again next time.
    } finally {
      clearTimeout(timeout);
      pending = false;
    }
  };
  document.addEventListener("admin:search-start", () => { previous.clear(); controller?.abort(); });
  timer = setInterval(update, 10000);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) update(); });
  window.addEventListener("pagehide", () => { stopped = true; clearInterval(timer); controller?.abort(); });
  window.addEventListener("pageshow", event => { if (event.persisted) { stopped = false; clearInterval(timer); timer = setInterval(update, 10000); update(); } });
});

// Live attendance report: refresh committed arrivals without leaving the page.
document.addEventListener("DOMContentLoaded", () => {
  const report = document.querySelector("[data-live-report]");
  if (!report) return;
  const hiddenDays = new Set();
  let pending = false, stopped = false, controller;
  const applySeries = () => {
    for (const day of ["1", "2"]) {
      report.querySelector(`.series-day${day}`)?.classList.toggle("series-hidden", hiddenDays.has(day));
      report.querySelector(`[data-chart-day="${day}"]`)?.setAttribute("aria-pressed", String(!hiddenDays.has(day)));
    }
  };
  report.addEventListener("click", event => {
    const button = event.target.closest("[data-chart-day]");
    if (!button) return;
    const day = button.dataset.chartDay;
    hiddenDays.has(day) ? hiddenDays.delete(day) : hiddenDays.add(day);
    applySeries();
  });
  const update = async () => {
    if (pending || stopped || document.hidden) return;
    pending = true;
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(report.dataset.source, { credentials: "same-origin", cache: "no-store", signal: controller.signal, headers: { Accept: "application/json" } });
      if (response.redirected || !response.ok || !response.headers.get("content-type")?.includes("application/json")) throw new Error("Report unavailable");
      const data = await response.json();
      if (typeof data.html !== "string" || typeof data.updated_at !== "string") throw new Error("Invalid report");
      const openDetails = [...report.querySelectorAll("details[open][data-report-detail]")].map(el => el.dataset.reportDetail);
      const focusedDay = document.activeElement?.dataset.chartDay;
      report.innerHTML = data.html;
      applySeries();
      for (const key of openDetails) report.querySelector(`[data-report-detail="${key}"]`)?.setAttribute("open", "");
      if (focusedDay) report.querySelector(`[data-chart-day="${focusedDay}"]`)?.focus({ preventScroll: true });
    } catch {
      // Offline or busy: keep the last report on screen and try again next time.
    } finally {
      clearTimeout(timeout);
      pending = false;
    }
  };
  const timer = setInterval(update, 10000);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) update(); });
  window.addEventListener("pagehide", () => { stopped = true; clearInterval(timer); controller?.abort(); });
  window.addEventListener("pageshow", event => { if (event.persisted) window.location.reload(); });
});

// Small helpers for the admin pages (no inline scripts, so the page's content
// security policy can forbid them).

// Package options carry their floor-plan numbers from the shared backend data.
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-booth-picker]").forEach((form) => {
    const packageSelect = form.querySelector('[name="package_id"]');
    const boothSelect = form.querySelector('[name="booth_number"]');
    if (!packageSelect || !boothSelect) return;
    const emptyLabel = boothSelect.options[0].textContent;
    const booked = new Set(JSON.parse(boothSelect.dataset.booked || "[]"));
    const render = () => {
      const previous = boothSelect.value;
      const numbers = JSON.parse(packageSelect.selectedOptions[0]?.dataset.booths || "[]");
      boothSelect.replaceChildren(new Option(numbers.length ? emptyLabel : "Choose a package first", ""));
      numbers.forEach((number) => {
        const own = String(number) === boothSelect.dataset.current && boothSelect.dataset.currentActive === "1";
        const taken = booked.has(number) && !own;
        const option = new Option(`Booth ${number}${taken ? " — Booked" : ""}`, String(number));
        option.disabled = taken;
        boothSelect.add(option);
      });
      boothSelect.value = numbers.some((number) => String(number) === previous) ? previous : "";
    };
    packageSelect.addEventListener("change", render);
    render();
  });
});

// The sidebar stays visible on desktop and becomes a keyboard-friendly drawer on phones.
document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.getElementById("admin-sidebar");
  const workspace = document.getElementById("admin-workspace");
  const toggle = document.querySelector(".sidebar-toggle");
  const close = document.querySelector(".sidebar-close");
  const backdrop = document.querySelector(".sidebar-backdrop");
  if (!sidebar || !workspace || !toggle || !close || !backdrop) return;
  const mobile = window.matchMedia("(max-width: 1000px)");
  let opened = false;
  document.body.classList.add("nav-enhanced");

  const setOpen = (requested, restoreFocus = true) => {
    opened = requested && mobile.matches;
    document.body.classList.toggle("sidebar-open", opened);
    toggle.setAttribute("aria-expanded", String(opened));
    toggle.setAttribute("aria-label", opened ? "Close navigation" : "Open navigation");
    backdrop.hidden = !opened;
    sidebar.inert = mobile.matches && !opened;
    workspace.inert = opened;
    if (opened) {
      sidebar.setAttribute("role", "dialog");
      sidebar.setAttribute("aria-modal", "true");
      sidebar.removeAttribute("aria-hidden");
      close.focus();
    } else {
      sidebar.removeAttribute("role");
      sidebar.removeAttribute("aria-modal");
      if (mobile.matches) sidebar.setAttribute("aria-hidden", "true");
      else sidebar.removeAttribute("aria-hidden");
      if (restoreFocus && mobile.matches) toggle.focus();
    }
  };
  const sync = () => {
    toggle.hidden = !mobile.matches;
    close.hidden = !mobile.matches;
    setOpen(false, false);
  };
  toggle.addEventListener("click", () => setOpen(!opened));
  close.addEventListener("click", () => setOpen(false));
  backdrop.addEventListener("click", () => setOpen(false));
  sidebar.addEventListener("click", (event) => {
    if (opened && event.target.closest("a")) setOpen(false);
  });
  document.addEventListener("keydown", (event) => {
    if (!opened) return;
    if (event.key === "Escape") {
      event.preventDefault();
      setOpen(false);
    } else if (event.key === "Tab") {
      const focusable = [...sidebar.querySelectorAll('a[href], button:not([hidden])')];
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  mobile.addEventListener("change", sync);
  sync();
});

// "Are you sure?" before buttons that delete, cancel, remove, hide or disable
// something: one window for the whole database (Page::confirm fills it). The
// colour shows how serious it is; "type the reference" boxes must match first.
(() => {
  const icons = {
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    "ticket-x": '<path d="M3 8a2 2 0 0 0 0 4 2 2 0 0 0 0 4v2h18v-2a2 2 0 0 1 0-4 2 2 0 0 1 0-4V6H3z"/><path d="m10 10 4 4M14 10l-4 4"/>',
    gift: '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v9h14v-9M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
    "eye-off": '<path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c5 0 9 4.5 10 7a13 13 0 0 1-3 4.2M6.6 6.6A13 13 0 0 0 2 12c1 2.5 5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    "user-x": '<circle cx="9" cy="8" r="4"/><path d="M2 21c1-4 3.8-6 7-6s6 2 7 6M17 8l4 4M21 8l-4 4"/>',
    "circle-x": '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
    undo: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
    phone: '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.3-6 8-6s6.8 2 8 6"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.9-3.6 3.5-5.5 6.5-5.5s5.6 1.9 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.9.7 3 2.5 3.5 5.2"/>',
    hash: '<path d="M5 9h14M5 15h14M10 3 8 21M16 3l-2 18"/>',
    ticket: '<path d="M3 8a2 2 0 0 0 0 4 2 2 0 0 0 0 4v2h18v-2a2 2 0 0 1 0-4 2 2 0 0 1 0-4V6H3z"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
    cash: '<rect x="2.5" y="6" width="19" height="12" rx="2.5"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>',
    ban: '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    tag: '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
    building: '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    shield: '<path d="M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    bulb: '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2V17h5v-1.1c0-.8.4-1.5 1-2A6 6 0 0 0 12 3"/>',
    x: '<path d="m7 7 10 10M17 7 7 17"/>',
    check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  };
  const svg = (name) => {
    const holder = document.createElement("span");
    holder.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || icons.info}</svg>`;
    return holder.firstChild;
  };
  const make = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const defaults = {
    red: ["Yes, delete", "No, keep it"],
    gold: ["Yes, continue", "No, go back"],
    violet: ["Yes, continue", "No, go back"],
    blue: ["Yes, continue", "No, go back"],
  };
  let box = null;

  // The Register (cash) form's own facts, for "Give a free ticket?".
  const callerFacts = (form) => {
    // form.elements also has fields outside the form tag (form="caller-form"), like the reason.
    const value = (name) => (form.elements.namedItem(name)?.value || "").trim();
    const ticket = form.elements.namedItem("ticket");
    const name = [value("first_name"), value("father_name"), value("grandfather_name")].filter(Boolean).join(" ");
    const facts = [["user", name || "Name not typed yet"]];
    if (ticket) facts.push(["ticket", ticket.options[ticket.selectedIndex]?.text || ticket.value]);
    facts.push(["info", value("comp_reason") ? `Reason: ${value("comp_reason")}` : "No reason typed yet"]);
    return facts;
  };

  const build = () => {
    box = make("dialog", "confirm-box");
    box.setAttribute("aria-labelledby", "confirm-box-title");
    box.addEventListener("click", (event) => {
      if (event.target !== box) return;
      const edge = box.getBoundingClientRect();
      if (event.clientX < edge.left || event.clientX > edge.right || event.clientY < edge.top || event.clientY > edge.bottom) box.close();
    });
    document.body.append(box);
  };

  const open = (button) => {
    let data;
    try { data = JSON.parse(button.dataset.confirmBox || "null"); } catch (error) { data = null; }
    data = data || { tone: "gold", icon: "info", title: button.dataset.confirm, text: "", facts: [] };
    if (!box) build();
    const tone = defaults[data.tone] ? data.tone : "blue";
    const form = button.form;
    box.className = `confirm-box tone-${tone}`;
    box.replaceChildren();
    const head = make("div", "confirm-head");
    const icon = make("span", "confirm-icon");
    icon.append(svg(data.icon));
    const title = make("h2", "", data.title);
    title.id = "confirm-box-title";
    head.append(icon, title);
    if (data.text) head.append(make("p", "confirm-text", data.text));
    const body = make("div", "confirm-body");
    const facts = data.form === "caller" && form ? callerFacts(form) : (data.facts || []);
    if (facts.length) {
      const list = make("ul", "confirm-facts");
      facts.forEach(([name, text]) => {
        const row = make("li");
        row.append(svg(name), make("span", "", text));
        list.append(row);
      });
      body.append(list);
    }
    if (data.deletes && data.deletes.length) {
      const deletes = make("div", "confirm-deletes");
      deletes.append(make("p", "confirm-label", "What will be deleted"));
      const chips = make("div", "confirm-chips");
      data.deletes.forEach((text) => {
        const chip = make("span", "confirm-chip");
        chip.append(svg("x"), make("span", "", text));
        chips.append(chip);
      });
      deletes.append(chips);
      body.append(deletes);
    }
    if (data.tip) {
      const tip = make("p", "confirm-tip");
      tip.append(svg("bulb"), make("span", "", data.tip));
      body.append(tip);
    }
    const [yesText, noText] = defaults[tone];
    const yes = make("button", `btn confirm-yes`);
    yes.type = "button";
    yes.append(svg(data.icon), make("span", "", data.yes || yesText));
    let typed = null;
    if (data.type) {
      const label = make("label", "confirm-type");
      const words = make("span", "confirm-type-label");
      words.append("Type ", make("b", "", data.type), " to confirm");
      typed = make("input");
      typed.type = "text";
      typed.autocomplete = "off";
      typed.spellcheck = false;
      typed.dir = "ltr";
      typed.placeholder = data.type;
      const match = make("span", "confirm-match");
      match.append(svg("check"), make("span", "", "Matches"));
      const field = make("span", "confirm-type-field");
      field.append(typed, match);
      label.append(words, field);
      body.append(label);
      const same = () => typed.value.trim().toLowerCase() === String(data.type).trim().toLowerCase();
      typed.addEventListener("input", () => {
        label.classList.toggle("is-match", same());
        yes.disabled = !same();
      });
      typed.addEventListener("keydown", (event) => {
        if (event.key === "Enter") { event.preventDefault(); if (same()) yes.click(); }
      });
      yes.disabled = true;
    }
    if (data.note) {
      const note = make("p", "confirm-note");
      note.append(svg(tone === "red" ? "shield" : "info"), make("span", "", data.note));
      body.append(note);
    }
    const actions = make("div", "confirm-actions");
    const no = make("button", "btn ghost", data.no || noText);
    no.type = "button";
    no.addEventListener("click", () => box.close());
    yes.addEventListener("click", () => {
      if (yes.disabled) return;
      if (typed && data.field && form) {
        const target = form.querySelector(`[name="${data.field}"]`);
        if (target) target.value = typed.value.trim();
      }
      yes.disabled = true;
      box.close();
      if (form && typeof form.requestSubmit === "function") {
        form.requestSubmit(button);
      } else {
        button.dataset.confirmed = "1";
        button.click();
      }
    });
    actions.append(no, yes);
    box.append(make("span", "confirm-strip"), head);
    if (body.childNodes.length) box.append(body);
    box.append(actions);
    box.showModal();
    (typed || no).focus();
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-confirm]");
    if (!button) return;
    if (button.dataset.confirmed === "1") {
      delete button.dataset.confirmed;
      return;
    }
    event.preventDefault();
    // Show the form's own "please fill this in" first (e.g. a cancel reason).
    if (button.form && !button.form.reportValidity()) return;
    if (typeof HTMLDialogElement === "undefined") {
      if (window.confirm(button.dataset.confirm)) { button.dataset.confirmed = "1"; button.click(); }
      return;
    }
    open(button);
  });
})();

// Phone registrations use the same student-ticket rule as the public form.
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("caller-form");
  if (!form) return;
  const specialty = form.querySelector('[name="specialty"]');
  const ticket = form.querySelector('[name="ticket"]');
  const fields = document.getElementById("caller-student-fields");
  const university = form.querySelector('[name="university"]');
  // Ticket and specialty follow each other: a Student ticket means a dental
  // student, and a Dentist ticket cannot be a dental student. VIP takes any.
  const studentOption = specialty.querySelector('[value="student"]');
  const sync = (event) => {
    const from = event && event.target;
    if (from === specialty) {
      if (specialty.value === "student" && ticket.value !== "vip") ticket.value = "student";
      else if (specialty.value !== "student" && ticket.value === "student") ticket.value = "professional";
    } else if (ticket.value === "student") {
      specialty.value = "student";
    } else if (ticket.value === "professional" && specialty.value === "student") {
      specialty.value = "";
    }
    const dentistTicket = ticket.value === "professional";
    if (studentOption) {
      studentOption.hidden = dentistTicket;
      studentOption.disabled = dentistTicket;
    }
    const studentTicket = ticket.value === "student";
    fields.hidden = !studentTicket;
    fields.querySelectorAll("input, select").forEach((input) => { input.disabled = !studentTicket; });
    university.required = studentTicket;
    const stage = document.getElementById("caller-stage");
    if (stage) stage.required = studentTicket;
    const photo = document.getElementById("caller-student-id");
    if (photo) photo.required = studentTicket;
    const vipFields = document.getElementById("caller-vip-lunch");
    const vipDay = form.querySelector('[name="vip_lunch_day"]');
    if (vipFields && vipDay) {
      const vip = ticket.value === "vip";
      vipFields.hidden = !vip;
      vipDay.disabled = !vip;
      vipDay.required = vip;
      form.querySelectorAll('[name="lunch_day1"], [name="lunch_day2"]').forEach((input) => {
        const included = vip && input.name === `lunch_day${vipDay.value}`;
        // Keep included lunch in the POST even though it cannot be unticked.
        if (included) input.checked = true;
      });
    }
    showTotal();
  };
  // The cash total as staff choose: PSOOLA's prices (data-prices), the same sum
  // as PsoolaPrices::estimate. PSOOLA's answer at the sale stays the real amount.
  let prices = null;
  try { prices = form.dataset.prices ? JSON.parse(form.dataset.prices) : null; } catch (error) { prices = null; }
  const pay = document.getElementById("caller-pay");
  const lines = document.getElementById("cash-lines");
  const total = document.getElementById("cash-total");
  const money = (amount) => `${amount.toLocaleString("en-US")} ${prices.currency}`;
  const ticketNames = { professional: "Dentist ticket", student: "Student ticket", vip: "VIP ticket" };
  let saleTotal = null;
  function showTotal() {
    const type = ticket.value;
    const vipDay = type === "vip" ? Number(form.querySelector('[name="vip_lunch_day"]')?.value || 0) : 0;
    document.querySelectorAll("[data-pick-ticket]").forEach((card) => {
      const on = card.dataset.pickTicket === type;
      card.classList.toggle("is-on", on);
      card.setAttribute("aria-pressed", on ? "true" : "false");
      card.disabled = Boolean(ticket.querySelector(`[value="${card.dataset.pickTicket}"]`)?.disabled);
    });
    const items = [[ticketNames[type] || "Ticket", prices ? prices[type] : null]];
    [1, 2].forEach((day) => {
      const box = form.querySelector(`[name="lunch_day${day}"]`);
      const included = day === vipDay;
      box.closest(".lunch-card")?.classList.toggle("is-on", box.checked);
      box.closest(".lunch-card")?.classList.toggle("is-included", included);
      const price = form.querySelector(`[data-lunch-price="${day}"]`);
      if (price && prices) price.textContent = included ? "Included with VIP" : `${box.checked ? "+ " : ""}${money(prices["lunch" + day])}`;
      if (box.checked) items.push([`Lunch day ${day}${included ? " (with VIP)" : ""}`, included ? 0 : (prices ? prices["lunch" + day] : null)]);
    });
    if (!lines || !total) return;
    lines.replaceChildren(...items.map(([name, amount]) => {
      const row = document.createElement("li");
      const left = document.createElement("span");
      const right = document.createElement("b");
      left.textContent = name;
      right.textContent = amount === null ? "–" : amount === 0 ? "Included" : money(amount);
      row.append(left, right);
      return row;
    }));
    if (!prices) return;
    const sum = items.reduce((all, [, amount]) => all + (amount || 0), 0);
    saleTotal = sum;
    const unit = document.createElement("small");
    unit.textContent = prices.currency;
    total.replaceChildren(`${sum.toLocaleString("en-US")} `, unit);
    if (pay) pay.textContent = `Paid by cash · ${money(sum)}`;
  }
  document.querySelectorAll("[data-pick-ticket]").forEach((card) => card.addEventListener("click", () => {
    if (card.disabled) return;
    ticket.value = card.dataset.pickTicket;
    ticket.dispatchEvent(new Event("change"));
  }));
  // The student ID box: the chosen photo's name, size and a preview; over 8 MB is refused here already.
  const idPhoto = document.getElementById("caller-student-id");
  const idBox = document.getElementById("caller-id-upload");
  if (idPhoto && idBox) {
    const fileLine = idBox.querySelector(".id-upload-file");
    const preview = idBox.querySelector(".id-upload-preview");
    const image = preview.querySelector("img");
    let previewUrl = null;
    const showPhoto = () => {
      const file = idPhoto.files && idPhoto.files[0];
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = null;
      const megabytes = file ? (file.size < 1048576 ? `${Math.max(1, Math.round(file.size / 1024))} KB` : `${(file.size / 1048576).toFixed(1)} MB`) : "";
      const tooBig = Boolean(file) && file.size > 8 * 1048576;
      idBox.classList.toggle("has-file", Boolean(file) && !tooBig);
      idBox.classList.toggle("too-big", tooBig);
      idPhoto.setCustomValidity(tooBig ? "The photo must be 8 MB at most." : "");
      fileLine.textContent = !file ? fileLine.dataset.empty
        : tooBig ? `${file.name} is ${megabytes}: too large. Choose a photo of 8 MB at most.`
        : `✓ ${file.name} · ${megabytes}. Click to choose another photo.`;
      if (file && !tooBig && file.type.startsWith("image/")) {
        previewUrl = URL.createObjectURL(file);
        image.src = previewUrl;
        preview.hidden = false;
      } else {
        image.removeAttribute("src");
        preview.hidden = true;
      }
    };
    idPhoto.addEventListener("change", showPhoto);
    ["dragenter", "dragover"].forEach((type) => idBox.addEventListener(type, () => idBox.classList.add("dragging")));
    ["dragleave", "drop"].forEach((type) => idBox.addEventListener(type, () => idBox.classList.remove("dragging")));
    showPhoto();
  }
  // "Did you receive the cash?": Paid by cash opens it; the sale is sent only
  // after staff tick that the money was received and press "Yes, sell the ticket".
  const confirmBox = document.getElementById("cash-confirm");
  if (pay && confirmBox && typeof confirmBox.showModal === "function") {
    const received = document.getElementById("cash-confirm-received");
    const yes = document.getElementById("cash-confirm-yes");
    const field = (name) => confirmBox.querySelector(`[data-confirm-field="${name}"]`);
    const value = (name) => (form.querySelector(`[name="${name}"]`)?.value || "").trim();
    let confirmed = false;
    pay.addEventListener("click", (event) => {
      if (confirmed) return;
      event.preventDefault();
      if (!form.reportValidity()) return;
      const vipDay = ticket.value === "vip" ? Number(value("vip_lunch_day") || 0) : 0;
      const days = { 1: "Day 1 · Friday 20 Nov", 2: "Day 2 · Saturday 21 Nov" };
      const lunch = [1, 2].filter((day) => form.querySelector(`[name="lunch_day${day}"]`).checked)
        .map((day) => days[day] + (day === vipDay ? " (with VIP)" : ""));
      field("name").textContent = [value("first_name"), value("father_name"), value("grandfather_name")].filter(Boolean).join(" ");
      field("phone").textContent = value("phone");
      field("email").textContent = value("email");
      field("ticket").textContent = (ticketNames[ticket.value] || "Ticket").replace(" ticket", "");
      field("lunch").textContent = lunch.length ? lunch.join("\n") : "No lunch";
      const amount = field("total");
      if (prices && saleTotal !== null) {
        const unit = document.createElement("small");
        unit.textContent = prices.currency;
        amount.replaceChildren(`${saleTotal.toLocaleString("en-US")} `, unit);
        amount.classList.remove("is-waiting");
        field("received").textContent = `I have received ${money(saleTotal)} in cash from this guest.`;
      } else {
        amount.textContent = "Shown after the sale";
        amount.classList.add("is-waiting");
        field("received").textContent = "I have received the cash from this guest.";
      }
      received.checked = false;
      yes.disabled = true;
      confirmBox.showModal();
    });
    received.addEventListener("change", () => { yes.disabled = !received.checked; });
    confirmBox.querySelector("[data-confirm-back]").addEventListener("click", () => confirmBox.close());
    // A click on the dark area around the window also goes back.
    confirmBox.addEventListener("click", (event) => {
      if (event.target !== confirmBox) return;
      const box = confirmBox.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) confirmBox.close();
    });
    yes.addEventListener("click", () => {
      if (!received.checked) return;
      confirmed = true;
      yes.disabled = true;
      confirmBox.close();
      if (typeof form.requestSubmit === "function") form.requestSubmit(pay);
      else pay.click();
    });
  }
  specialty.addEventListener("change", sync);
  ticket.addEventListener("change", sync);
  form.querySelector('[name="vip_lunch_day"]')?.addEventListener("change", sync);
  form.querySelectorAll('[name="lunch_day1"], [name="lunch_day2"]').forEach((input) => input.addEventListener("change", sync));
  sync();
});

// Refresh an open live scanner at the server's next midnight. The server also
// chooses the current day on every admission, even when a phone has been asleep.
document.addEventListener("DOMContentLoaded", () => {
  const banner = document.querySelector("[data-checkin-rollover-ms]");
  if (!banner) return;
  const remaining = Number(banner.dataset.checkinRolloverMs);
  if (!Number.isFinite(remaining) || remaining <= 0) return;
  // A relative wall-clock deadline keeps counting while a phone sleeps;
  // the device's timezone or clock offset does not affect the countdown.
  const deadline = Date.now() + remaining;
  const refresh = () => {
    if (Date.now() >= deadline) window.location.reload();
  };
  window.setTimeout(refresh, remaining + 50);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") refresh();
  });
});

// Decode the emailed ticket QR using the phone camera.
document.addEventListener("DOMContentLoaded", () => {
  const start = document.getElementById("scanCamera");
  const video = document.getElementById("scanVideo");
  const input = document.getElementById("scanInput");
  const form = document.getElementById("scanForm");
  const status = document.getElementById("scanStatus");
  const stop = document.getElementById("scanStop");
  if (!start || !video) return;
  start.hidden = false;
  let stream = null;
  let running = false;
  const canvas = document.createElement("canvas");
  const context = canvas.getContext("2d", { willReadFrequently: true });
  const end = () => {
    running = false;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    video.srcObject = null;
    video.hidden = true;
    if (stop) stop.hidden = true;
  };
  stop?.addEventListener("click", () => { end(); if (status) status.textContent = "Camera stopped."; });
  window.addEventListener("pagehide", end);

  start.addEventListener("click", async () => {
    if (stream) return;
    if (!navigator.mediaDevices?.getUserMedia) {
      if (status) status.textContent = "Camera access is unavailable here. Open this page over HTTPS in Chrome or Safari, or find the guest by name, phone, reference or ticket number.";
      return;
    }
    let detector = null;
    try {
      if ("BarcodeDetector" in window) {
        try { detector = new window.BarcodeDetector({ formats: ["qr_code"] }); } catch { /* use local decoder */ }
      }
      if (!detector && !window.jsQR) {
        if (status) status.textContent = "The QR scanner could not load. Refresh this page, or find the guest by name, phone, reference or ticket number.";
        return;
      }
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
      video.srcObject = stream;
      video.hidden = false;
      await video.play();
      running = true;
      if (stop) stop.hidden = false;
      if (status) status.textContent = "Point the camera at the ticket QR.";
    } catch {
      end();
      if (status) status.textContent = "Camera could not start. Allow camera access, or use a QR scanner to paste the ticket’s complete QR text above.";
      return;
    }
    const look = async () => {
      if (!running) return;
      try {
        let value = null;
        if (detector) {
          const codes = await detector.detect(video);
          value = codes[0]?.rawValue;
        } else if (video.readyState >= 2 && context) {
          canvas.width = Math.min(640, video.videoWidth);
          canvas.height = Math.round(video.videoHeight * canvas.width / video.videoWidth);
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          value = window.jsQR(context.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height, { inversionAttempts: "dontInvert" })?.data;
        }
        if (value && running) {
          end();
          input.value = value;
          form.requestSubmit();
          return;
        }
      } catch {
        detector = null; // Fall back when native detection fails on this device.
      }
      if (running) window.setTimeout(look, 150);
    };
    look();
  });
});

// Booking a workshop: show "amount received / how" only when "Paid now" is
// chosen, and put the workshop's price in the price box.
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".book-form").forEach((form) => {
    const sync = () => {
      const choice = form.querySelector('input[name="payment_status"]:checked');
      form.classList.toggle("is-paid", !!choice && choice.value === "paid");
    };
    form.addEventListener("change", (event) => {
      if (event.target.matches("[data-price-source]")) {
        const option = event.target.selectedOptions[0];
        const price = form.querySelector("[data-price-target]");
        if (price && option && option.dataset.price && option.dataset.price !== "0") price.value = option.dataset.price;
      }
      sync();
    });
    sync();
  });
});

// "Print" buttons (no inline scripts: the page's security policy forbids them).
document.addEventListener("click", (event) => {
  if (event.target.closest("[data-print]")) window.print();
});

// Lists: the ambassador picker (find by name or code, label, close outside).
document.addEventListener("DOMContentLoaded", () => {
  const picker = document.querySelector(".amb-picker");
  if (!picker) return;
  const find = picker.querySelector("[data-amb-find]");
  const summary = picker.querySelector("[data-amb-summary]");
  const options = [...picker.querySelectorAll(".amb-option")];
  find.addEventListener("input", () => {
    const words = find.value.trim().toLowerCase();
    options.forEach(option => { option.hidden = Boolean(words) && !option.dataset.ambText.includes(words); });
  });
  // Typing in the finder only filters the menu; it is not a list filter.
  find.addEventListener("change", event => event.stopPropagation());
  find.addEventListener("keydown", event => { if (event.key === "Enter") event.preventDefault(); });
  const value = document.querySelector("[data-amb-value]");
  // Runs before the form's own change handler, so the search sends the new value.
  picker.addEventListener("change", () => {
    const picked = options.filter(option => option.querySelector("input").checked);
    value.value = picked.map(option => option.querySelector("input").value).join(",");
    summary.textContent = !picked.length ? "All ambassadors" : picked.length > 1 ? `${picked.length} ambassadors` : picked[0].querySelector("b").textContent;
  });
  document.addEventListener("click", event => { if (picker.open && !picker.contains(event.target)) picker.open = false; });
  picker.addEventListener("keydown", event => { if (event.key === "Escape") { picker.open = false; picker.querySelector("summary").focus(); } });
});

// A form is sent once: a double click or Enter-then-click on "Send Pay now",
// "Free ticket", "Record payment"… never posts it twice. Downloads that do not
// leave the page are released again after a few seconds.
document.addEventListener("submit", event => {
  const form = event.target;
  if (event.defaultPrevented || form.method.toLowerCase() !== "post") return;
  if (form.dataset.sending) { event.preventDefault(); return; }
  form.dataset.sending = "1";
  setTimeout(() => delete form.dataset.sending, 8000);
});
window.addEventListener("pageshow", () => document.querySelectorAll("form[data-sending]").forEach(form => delete form.dataset.sending));

// Viewer accounts: fields in forms that would change data cannot be typed in.
document.addEventListener("DOMContentLoaded", () => {
  if (!document.body.classList.contains("view-only")) return;
  const lock = root => root.querySelectorAll('main form[method="post" i] :is(input, select, textarea)').forEach(field => {
    if (field.type === "hidden") return;
    field.tabIndex = -1;
    if (field.tagName === "SELECT" || field.type === "checkbox" || field.type === "radio") field.disabled = true;
    else field.readOnly = true;
  });
  lock(document);
  new MutationObserver(() => lock(document)).observe(document.querySelector("main") || document.body, { childList: true, subtree: true });
});
