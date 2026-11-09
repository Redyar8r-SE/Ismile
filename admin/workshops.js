document.addEventListener("DOMContentLoaded", () => {
  const root = document.querySelector("[data-workshop-workspace]");
  if (!root) return;
  root.querySelectorAll('[data-ws-workshop-picker]').forEach(form => {
    form.querySelector('[data-ws-picker-submit]').hidden = true;
    form.elements.id.addEventListener('change', () => form.requestSubmit());
  });
  const lookup = root.querySelector('[data-ws-attendee-lookup]');
  if (lookup) {
    const form = lookup.querySelector('[data-ws-attendee-search]');
    const input = form.elements.q;
    const results = lookup.querySelector('[data-ws-attendee-results]');
    const status = lookup.querySelector('[role=status]');
    const more = lookup.querySelector('[data-ws-more-attendees]');
    const arrow = form.querySelector('button svg')?.cloneNode(true);
    let timer, controller, revision = 0, composing = false, stopped = false;
    let query = input.value.trim();
    const initialTotal = Number(more.dataset.total);
    const idle = () => { status.textContent = 'Start typing to see matching attendees.'; };
    const row = person => {
      const link = document.createElement('a');
      link.className = 'ws-attendee-result';
      const url = new URL(form.action, location.href);
      url.search = new URLSearchParams(new FormData(form));
      url.searchParams.set('rid', person.id);
      url.hash = 'book';
      link.href = url.href;
      const avatar = document.createElement('span');
      avatar.className = 'ws-attendee-avatar'; avatar.setAttribute('aria-hidden', 'true');
      avatar.textContent = Array.from(person.first_name || '')[0]?.toLocaleUpperCase() || '';
      const identity = document.createElement('span'); identity.className = 'ws-attendee-identity';
      const name = document.createElement('strong');
      name.textContent = [person.first_name, person.father_name, person.grandfather_name].filter(Boolean).join(' ');
      const contact = document.createElement('small');
      contact.textContent = [person.phone, person.email].filter(Boolean).join(' · ');
      identity.append(name, contact); link.append(avatar, identity);
      if (arrow) link.append(arrow.cloneNode(true));
      return link;
    };
    const search = async (append = false) => {
      if (composing || stopped) return;
      clearTimeout(timer); controller?.abort();
      const current = ++revision;
      query = input.value.trim();
      if (!query) { results.replaceChildren(); more.hidden = true; idle(); return; }
      if (!append) results.replaceChildren();
      more.hidden = true; status.textContent = 'Searching…';
      results.setAttribute('aria-busy', 'true');
      const url = new URL(form.action, location.href);
      url.hash = ''; url.search = new URLSearchParams({ attendee_search: '1', mode: form.elements.mode.value, q: query, offset: String(append ? results.children.length : 0) });
      const active = new AbortController(); controller = active;
      const timeout = setTimeout(() => active.abort(), 10000);
      try {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: active.signal, headers: { Accept: 'application/json' } });
        if (!response.ok || response.redirected) throw new Error('Search unavailable');
        const data = await response.json();
        if (current !== revision || stopped) return;
        if (!data.ok || !Array.isArray(data.people) || !Number.isInteger(data.total)) throw new Error('Invalid search response');
        results.append(...data.people.map(row));
        const shown = results.children.length;
        more.hidden = shown >= data.total;
        status.textContent = data.total ? (shown < data.total ? `Showing ${shown} of ${data.total} attendees` : `${data.total} ${data.total === 1 ? 'attendee' : 'attendees'} found`) : 'No attendee found. Try another name, phone number or email.';
      } catch {
        if (current === revision && !stopped) status.textContent = 'Search unavailable. Press the search arrow to try again.';
      } finally {
        clearTimeout(timeout);
        if (current === revision) results.removeAttribute('aria-busy');
      }
    };
    const changed = () => {
      revision++; controller?.abort(); clearTimeout(timer);
      results.replaceChildren(); results.removeAttribute('aria-busy'); more.hidden = true;
      if (!input.value.trim()) { idle(); return; }
      status.textContent = 'Searching…';
      if (!composing) timer = setTimeout(() => search(), 180);
    };
    input.addEventListener('input', event => { if (!event.isComposing) changed(); });
    input.addEventListener('compositionstart', () => { composing = true; revision++; controller?.abort(); clearTimeout(timer); results.replaceChildren(); more.hidden = true; });
    input.addEventListener('compositionend', () => { composing = false; changed(); });
    form.addEventListener('submit', event => { event.preventDefault(); search(); });
    more.addEventListener('click', () => search(true));
    lookup.addEventListener('keydown', event => {
      const links = [...results.querySelectorAll('a')];
      if (event.key === 'ArrowDown') { const next = event.target === input ? links[0] : links[links.indexOf(event.target) + 1]; if (next) { event.preventDefault(); next.focus(); } }
      if (event.key === 'ArrowUp' && links.includes(event.target)) { event.preventDefault(); (links[links.indexOf(event.target) - 1] || input).focus(); }
    });
    if (!query) idle();
    else if (initialTotal > results.children.length) more.hidden = false;
    window.addEventListener('pagehide', () => { stopped = true; revision++; clearTimeout(timer); controller?.abort(); });
    window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; search(); } });
  }
  root.querySelectorAll('[data-workshop-cash]').forEach(form => {
    const currency = form.elements.cash_currency;
    const amount = form.elements.cash_amount;
    const iqd = Number(form.dataset.iqd);
    const usdMinor = Number(form.dataset.usdMinor);
    const refresh = () => {
      const dollars = currency.value === 'USD';
      const received = Number(amount.value);
      const converted = dollars && usdMinor > 0 ? Math.round(received * 100 * iqd / usdMinor) : Math.round(received);
      const matches = Number.isFinite(received) && received > 0 && (dollars ? Math.round(received * 100) === usdMinor : received === iqd);
      form.querySelector('[data-cash-converted]').textContent = Number.isFinite(converted) ? converted.toLocaleString('en-US') + ' IQD' : '—';
      form.querySelector('[data-cash-match]').textContent = matches ? 'Matches the PSOOLA ticket price.' : 'Amount must match the full workshop price.';
    };
    currency.addEventListener('change', () => {
      const dollars = currency.value === 'USD';
      amount.step = dollars ? '0.01' : '1';
      amount.min = dollars ? '0.01' : '1';
      amount.value = String(dollars ? usdMinor / 100 : iqd);
      refresh();
    });
    amount.addEventListener('input', refresh);
    refresh();
  });
  const directory = root.querySelector(".ws-directory-list");
  const attendeeTable = root.querySelector('.ws-attendees');
  if (attendeeTable) {
    const menus = [...attendeeTable.querySelectorAll('details.ws-menu')];
    menus.forEach(menu => menu.addEventListener('toggle', () => {
      const panel = menu.querySelector('.ws-menu-content');
      if (!panel) return;
      if (!menu.open) { panel.classList.remove('is-floating'); panel.style.removeProperty('top'); panel.style.removeProperty('left'); return; }
      const anchor = menu.querySelector('summary').getBoundingClientRect();
      panel.classList.add('is-floating');
      const box = panel.getBoundingClientRect();
      panel.style.left = Math.max(12, Math.min(anchor.right - box.width, innerWidth - box.width - 12)) + 'px';
      panel.style.top = Math.max(12, anchor.bottom + box.height + 8 > innerHeight ? anchor.top - box.height - 8 : anchor.bottom + 8) + 'px';
    }));
    const closeMenus = () => menus.forEach(menu => { menu.open = false; });
    attendeeTable.addEventListener('scroll', closeMenus);
    window.addEventListener('resize', closeMenus);
  }
  const selectedCard = directory?.querySelector('.ws-workshop-card[aria-current="true"]');
  if (selectedCard) {
    const listBox = directory.getBoundingClientRect();
    const cardBox = selectedCard.getBoundingClientRect();
    if (cardBox.bottom > listBox.bottom) directory.scrollTop += cardBox.bottom - listBox.bottom + 4;
    else if (cardBox.top < listBox.top) directory.scrollTop -= listBox.top - cardBox.top + 4;
  }
  const settings = root.querySelector("[data-workshop-settings]");
  const settingsForms = settings ? [...settings.querySelectorAll("[data-settings-form]")] : [];
  const photoStates = new WeakMap();
  const setTab = (form, section) => {
    form.querySelectorAll("[data-settings-panel]").forEach(panel => { panel.hidden = panel.dataset.settingsPanel !== section; });
    form.querySelectorAll("[data-settings-tab]").forEach(tab => {
      const active = tab.dataset.settingsTab === section;
      tab.setAttribute("aria-selected", String(active)); tab.tabIndex = active ? 0 : -1;
    });
    form.querySelector('[name="settings_section"]').value = section;
  };
  const selectEditor = id => {
    if (!settings) return;
    const previous = settings.dataset.selected;
    const editors = [...settings.querySelectorAll("[data-settings-editor]")];
    if (!editors.some(editor => editor.dataset.settingsEditor === id)) return;
    editors.forEach(editor => { editor.hidden = editor.dataset.settingsEditor !== id; });
    settings.querySelectorAll("[data-settings-select]").forEach(choice => {
      const active = choice.dataset.settingsSelect === id;
      choice.classList.toggle("is-active", active);
      if (active) choice.setAttribute("aria-current", "true"); else choice.removeAttribute("aria-current");
    });
    settings.querySelector("[data-settings-picker]").value = id;
    settings.dataset.selected = id;
    if (id === "__new" && previous !== "__new") setTab(editors.find(editor => editor.dataset.settingsEditor === id).querySelector("form"), "details");
  };
  const syncOrganizer = form => {
    const company = form.querySelector('[name="organizer"]:checked')?.value === "company";
    form.querySelector("[data-settings-company]").hidden = !company;
  };
  const syncPhoto = form => {
    const state = photoStates.get(form), image = form.querySelector("[data-settings-photo-preview]");
    const removed = form.querySelector("[data-settings-remove-photo]").checked;
    const src = removed ? "" : state.blob || state.original;
    if (src) image.src = src; else image.removeAttribute("src");
    image.hidden = !src;
    form.querySelector("[data-settings-photo-empty]").hidden = !!src;
    const remove = form.querySelector("[data-settings-photo-remove]");
    remove.hidden = !src && !removed; remove.setAttribute("aria-label", removed ? "Undo photo removal" : "Remove trainer photo");
    remove.classList.toggle("is-removed", removed);
  };
  if (settings) {
    settings.classList.add("is-enhanced");
    settingsForms.forEach(form => {
      setTab(form, settings.dataset.section || "details"); syncOrganizer(form);
      photoStates.set(form, { original: form.querySelector("[data-settings-photo-preview]").getAttribute("src") || "", blob: null });
      form.addEventListener("input", () => { form.querySelector("[data-settings-save-note]").textContent = "Unsaved changes"; });
      form.addEventListener("change", event => {
        form.querySelector("[data-settings-save-note]").textContent = "Unsaved changes";
        if (event.target.name === "organizer") syncOrganizer(form);
        if (event.target.matches("[data-settings-photo]")) {
          const state = photoStates.get(form);
          if (state.blob) URL.revokeObjectURL(state.blob);
          state.blob = event.target.files[0] ? URL.createObjectURL(event.target.files[0]) : null;
          form.querySelector("[data-settings-remove-photo]").checked = false; syncPhoto(form);
        }
      });
      form.addEventListener("invalid", event => {
        const panel = event.target.closest("[data-settings-panel]");
        if (panel) setTab(form, panel.dataset.settingsPanel);
        for (let parent = event.target.parentElement; parent && parent !== form; parent = parent.parentElement) if (parent.tagName === "DETAILS") parent.open = true;
      }, true);
    });
    selectEditor(settings.dataset.selected);
    settings.querySelector("[data-settings-picker]").addEventListener("change", event => selectEditor(event.target.value));
    settings.addEventListener("keydown", event => {
      const tab = event.target.closest("[data-settings-tab]");
      if (!tab || !["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault(); const tabs = [...tab.parentElement.querySelectorAll("[data-settings-tab]")];
      const direction = getComputedStyle(tab.parentElement).direction === "rtl" ? -1 : 1;
      const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : (tabs.indexOf(tab) + (event.key === "ArrowRight" ? direction : -direction) + tabs.length) % tabs.length;
      setTab(tab.closest("form"), tabs[next].dataset.settingsTab); tabs[next].focus();
    });
  }
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
        selectEditor(opener.dataset.wsEdit);
      }
      return;
    }
    const close = event.target.closest("[data-ws-close]");
    if (close) close.closest("dialog")?.close();
    const choice = event.target.closest("[data-settings-select]");
    if (choice) { event.preventDefault(); selectEditor(choice.dataset.settingsSelect); }
    const tab = event.target.closest("[data-settings-tab]");
    if (tab) setTab(tab.closest("form"), tab.dataset.settingsTab);
    const capacity = event.target.closest("[data-settings-capacity]");
    if (capacity) {
      const input = capacity.parentElement.querySelector("input");
      input.value = String(Math.max(1, Math.min(2000, (Number(input.value) || 1) + Number(capacity.dataset.settingsCapacity))));
      input.dispatchEvent(new Event("input", { bubbles: true }));
    }
    const removePhoto = event.target.closest("[data-settings-photo-remove]");
    if (removePhoto) {
      const form = removePhoto.closest("form"), state = photoStates.get(form), flag = form.querySelector("[data-settings-remove-photo]");
      flag.checked = !flag.checked;
      if (flag.checked) { form.querySelector("[data-settings-photo]").value = ""; if (state.blob) URL.revokeObjectURL(state.blob); state.blob = null; }
      syncPhoto(form); flag.dispatchEvent(new Event("change", { bubbles: true }));
    }
    const cancelSettings = event.target.closest("[data-settings-cancel]");
    if (cancelSettings) {
      const form = cancelSettings.closest("form"), state = photoStates.get(form);
      form.reset(); if (state.blob) URL.revokeObjectURL(state.blob); state.blob = null;
      syncOrganizer(form); syncPhoto(form); form.querySelector("[data-settings-save-note]").textContent = "Changes appear on the website after saving.";
      form.closest("dialog")?.close();
    }
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
  root.querySelectorAll("form[data-ws-issue-form], form[data-settings-form]").forEach(form => {
    form.addEventListener("submit", event => {
      if (form.dataset.pending === "true") { event.preventDefault(); return; }
      const consent = form.querySelector('[name="delivery_confirmation"]');
      if (consent) consent.value = "email-whatsapp-v1";
      form.dataset.pending = "true";
      requestAnimationFrame(() => form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
        if (button.form !== form || button.disabled) return;
        button.disabled = true; button.setAttribute("aria-busy", "true");
      }));
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
