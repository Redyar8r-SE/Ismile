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
        regions.forEach(region => region.removeAttribute("aria-busy"));
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

// Live directories: replace only read-only results, preserving search forms.
document.addEventListener("DOMContentLoaded", () => {
  const toolbar = document.querySelector("[data-live-directory]");
  if (!toolbar) return;
  const regions = [...document.querySelectorAll("[data-live-region]")];
  const status = toolbar.querySelector("[data-live-directory-status]");
  const refresh = toolbar.querySelector("[data-live-directory-refresh]");
  const badge = toolbar.querySelector(".live-report-badge");
  const previous = new Map();
  let pending = false, stopped = false, timer, controller;
  const update = async () => {
    if (pending || stopped || document.hidden || document.querySelector('[data-instant-search][data-search-dirty="true"]')) return;
    const requestUrl = location.href;
    pending = true;
    refresh.disabled = true;
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(location.href, { credentials: "same-origin", cache: "no-store", signal: controller.signal, headers: { Accept: "text/html" } });
      if (response.redirected || response.status === 401 || response.status === 403) {
        stopped = true;
        clearInterval(timer);
        throw new Error("session");
      }
      if (!response.ok || !response.headers.get("content-type")?.includes("text/html")) throw new Error("unavailable");
      const page = new DOMParser().parseFromString(await response.text(), "text/html");
      if (location.href !== requestUrl || document.querySelector('[data-instant-search][data-search-dirty="true"]')) return;
      if (!page.querySelector("[data-live-directory]")) throw new Error("unavailable");
      const replacements = regions.map(region => page.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`));
      if (replacements.some(region => !region)) throw new Error("unavailable");
      regions.forEach((region, index) => {
        const html = replacements[index].innerHTML;
        // Keep keyboard focus and text selection while the user interacts.
        const selection = window.getSelection();
        const focused = region.contains(document.activeElement) ? document.activeElement : null;
        if (focused?.matches("input, textarea, select, [contenteditable]") || (selection && !selection.isCollapsed && (region.contains(selection.anchorNode) || region.contains(selection.focusNode)))) return;
        if (previous.get(region) === html) return;
        const focusedDetail = focused?.closest("details[data-live-key]")?.dataset.liveKey;
        const focusedLink = focused?.closest("a[href]")?.getAttribute("href");
        const opened = [...region.querySelectorAll("details[open][data-live-key]")].map(el => el.dataset.liveKey);
        const scrolls = [...region.querySelectorAll(".table-wrap")].map(el => [el.scrollLeft, el.scrollTop]);
        region.innerHTML = html;
        for (const detail of region.querySelectorAll("details[data-live-key]")) detail.open = opened.includes(detail.dataset.liveKey);
        region.querySelectorAll(".table-wrap").forEach((el, i) => { if (scrolls[i]) [el.scrollLeft, el.scrollTop] = scrolls[i]; });
        if (focusedDetail) [...region.querySelectorAll("details[data-live-key]")].find(el => el.dataset.liveKey === focusedDetail)?.querySelector("summary")?.focus({ preventScroll: true });
        else if (focusedLink) [...region.querySelectorAll("a[href]")].find(el => el.getAttribute("href") === focusedLink)?.focus({ preventScroll: true });
        previous.set(region, html);
      });
      status.textContent = "Updated " + new Intl.DateTimeFormat("en-GB", { timeZone: "Asia/Baghdad", hour: "2-digit", minute: "2-digit", second: "2-digit" }).format(new Date()) + " · Iraq time";
      badge.classList.remove("offline");
    } catch (error) {
      if (location.href !== requestUrl || document.querySelector('[data-instant-search][data-search-dirty="true"]')) return;
      status.textContent = error.message === "session" ? "Session ended. Sign in again to see live updates." : "Connection interrupted. Keeping your current list and retrying automatically.";
      badge.classList.add("offline");
    } finally {
      clearTimeout(timeout);
      pending = false;
      refresh.disabled = stopped;
    }
  };
  refresh.addEventListener("click", update);
  document.addEventListener("admin:search-start", () => { previous.clear(); controller?.abort(); });
  timer = setInterval(update, 5000);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) update(); });
  window.addEventListener("pagehide", () => { stopped = true; clearInterval(timer); controller?.abort(); });
  window.addEventListener("pageshow", event => { if (event.persisted) { stopped = false; timer = setInterval(update, 5000); update(); } });
});

// Live attendance report: refresh committed arrivals without leaving the page.
document.addEventListener("DOMContentLoaded", () => {
  const report = document.querySelector("[data-live-report]");
  if (!report) return;
  const status = document.querySelector("[data-live-report-status]");
  const refresh = document.querySelector("[data-live-report-refresh]");
  const badge = document.querySelector(".live-report-badge");
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
    refresh.disabled = true;
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
      status.textContent = `Updated ${data.updated_at.slice(11)} · Iraq time`;
      badge.classList.remove("offline");
    } catch {
      if (!stopped) {
        status.textContent = "Live update unavailable. Showing the last successful report; retrying automatically.";
        badge.classList.add("offline");
      }
    } finally {
      clearTimeout(timeout);
      pending = false;
      refresh.disabled = false;
    }
  };
  refresh.addEventListener("click", update);
  const timer = setInterval(update, 15000);
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

// "Are you sure?" before buttons that cancel, remove or disable something.
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-confirm]");
  if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
});

// Phone registrations use the same student-ticket rule as the public form.
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("caller-form");
  if (!form) return;
  const specialty = form.querySelector('[name="specialty"]');
  const ticket = form.querySelector('[name="ticket"]');
  const fields = document.getElementById("caller-student-fields");
  const university = form.querySelector('[name="university"]');
  const sync = () => {
    const dentalStudent = specialty.value === "student";
    if (dentalStudent) ticket.value = "student";
    // Keep the select enabled so its value is included in the submitted form.
    ticket.querySelector('[value="professional"]').disabled = dentalStudent;
    const studentTicket = ticket.value === "student";
    fields.hidden = !studentTicket;
    fields.querySelectorAll("input").forEach((input) => { input.disabled = !studentTicket; });
    university.required = studentTicket;
  };
  specialty.addEventListener("change", sync);
  ticket.addEventListener("change", sync);
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
  if (!start || !video || !navigator.mediaDevices?.getUserMedia) return;
  if (!("BarcodeDetector" in window) && !window.jsQR) return;
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
    let detector = null;
    try {
      if ("BarcodeDetector" in window) {
        try { detector = new window.BarcodeDetector({ formats: ["qr_code"] }); } catch { /* use local decoder */ }
      }
      if (!detector && !window.jsQR) throw new Error("QR scanning is unavailable.");
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
