// Registration form: person/company switch, dentist list, validation (demo only, nothing is sent).
import { t, onLangChange } from "../i18n.js";

const EMAIL_PATTERN = /^\S+@\S+\.\S+$/;

export function initRegistration() {
  const form = document.getElementById("regForm");
  const message = document.getElementById("msg");
  const modeButtons = document.querySelectorAll(".seg button");
  const panels = form.querySelectorAll("[data-panel]");
  const specialty = document.getElementById("p_spec");
  const dentistList = document.getElementById("dentists");
  let mode = "person";
  let dentistCount = 0;

  function showMessage(key) {
    message.className = key ? `msg ${key === "ok_msg" ? "ok" : "err"}` : "msg";
    message.textContent = key ? t(key) : "";
    message.dataset.key = key || "";
  }

  function setMode(newMode) {
    mode = newMode;
    modeButtons.forEach((b) => b.setAttribute("aria-selected", String(b.dataset.mode === mode)));
    panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== mode; });
    showMessage(null);
  }

  function addDentist() {
    dentistCount++;
    const id = dentistCount;
    const row = document.createElement("div");
    row.className = "dentist";
    // The specialty options are copied from the person form so the list lives in one place.
    row.innerHTML = `
      <div class="f"><label for="dn${id}" data-i18n="d_name">${t("d_name")}</label><input id="dn${id}"></div>
      <div class="f"><label for="dp${id}" data-i18n="f_phone">${t("f_phone")}</label><input id="dp${id}" type="tel" dir="ltr"></div>
      <div class="f"><label for="ds${id}" data-i18n="f_spec">${t("f_spec")}</label><select id="ds${id}">${specialty.innerHTML}</select></div>
      <button type="button" class="rm" data-i18n="remove">${t("remove")}</button>`;
    row.querySelector(".rm").addEventListener("click", () => {
      if (dentistList.children.length > 1) row.remove();
    });
    dentistList.appendChild(row);
  }

  function validate() {
    const panel = form.querySelector(`[data-panel="${mode}"]`);
    let valid = true;
    panel.querySelectorAll("[data-req]").forEach((input) => {
      const value = input.value.trim();
      const ok = value !== "" && (input.type !== "email" || EMAIL_PATTERN.test(value));
      input.setAttribute("aria-invalid", String(!ok));
      if (!ok) valid = false;
    });
    return valid;
  }

  modeButtons.forEach((b) => b.addEventListener("click", () => setMode(b.dataset.mode)));
  document.querySelector("[data-go-company]").addEventListener("click", () => setMode("company"));

  // Students get the university field and the student ticket.
  specialty.addEventListener("change", () => {
    const isStudent = specialty.value === "student";
    document.getElementById("uniWrap").hidden = !isStudent;
    document.getElementById("p_ticket").selectedIndex = isStudent ? 1 : 0;
  });

  document.getElementById("addDentist").addEventListener("click", addDentist);

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    showMessage(validate() ? "ok_msg" : "err_msg");
  });

  onLangChange(() => {
    if (message.dataset.key) message.textContent = t(message.dataset.key);
  });

  addDentist();
  addDentist();
}
