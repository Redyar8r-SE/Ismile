document.addEventListener("DOMContentLoaded", () => {
  const root = document.querySelector("[data-workshop-workspace]");
  if (!root) return;
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
