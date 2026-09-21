// Registration: 3-step form with validation, review and success screen.
// Demo only: nothing is sent to a server yet. Connect submitRegistration() to your backend.
import { t, onLangChange } from "../i18n.js?v=22";

const TOTAL_STEPS = 3;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

// Iraqi mobiles (0750 123 4567 / +964 750 123 4567) or any international number starting with +
export function isValidPhone(value) {
  const digits = value.replace(/[\s\-().]/g, "");
  if (/^(\+964|00964|964)7\d{9}$/.test(digits) || /^07\d{9}$/.test(digits)) return true;
  return /^\+\d{8,15}$/.test(digits) && !digits.startsWith("+964");
}

export function initRegistration() {
  const $ = (id) => document.getElementById(id);
  const card = $("regCard");
  const form = $("regForm");
  const progress = $("regProgress");
  const stepperItems = [...$("regStepper").children];
  const steps = [...form.querySelectorAll(".reg-step")];
  const backBtn = $("regBack");
  const nextBtn = $("regNext");
  const submitBtn = $("regSubmit");
  const specialty = $("p_spec");
  const dentistList = $("dentists");
  const success = $("regSuccess");

  let step = 1;
  let type = "person";
  let dentistId = 0;

  // ---------- Helpers ----------
  const checkedValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value;
  const optionText = (select) => select.options[select.selectedIndex]?.textContent.trim() || "";
  const isHidden = (el) => Boolean(el.closest("[hidden]"));

  // Keep the "selected" style of radio cards in sync
  function syncChecked(name) {
    form.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
      input.closest("label").classList.toggle("is-checked", input.checked);
    });
  }

  // ---------- Field validation ----------
  function showError(input, key) {
    const wrap = input.closest(".f");
    let message = wrap.querySelector(":scope > .f-error");
    if (!key) {
      input.removeAttribute("aria-invalid");
      input.removeAttribute("aria-describedby");
      message?.remove();
      return;
    }
    if (!message) {
      message = document.createElement("p");
      message.className = "f-error";
      message.id = `${input.id}-error`;
      input.insertAdjacentElement("afterend", message);
    }
    message.dataset.key = key;
    message.textContent = t(key);
    input.setAttribute("aria-invalid", "true");
    input.setAttribute("aria-describedby", message.id);
  }

  function checkField(input) {
    const value = input.value.trim();
    const rule = input.dataset.rule;
    let error = "";
    if (!value) {
      if (!("optional" in input.dataset)) error = "err_required";
    } else if (rule === "email" && !EMAIL_PATTERN.test(value)) {
      error = "err_email";
    } else if (rule === "phone" && !isValidPhone(value)) {
      error = "err_phone";
    } else if (rule === "name" && value.length < 3) {
      error = "err_name";
    }
    showError(input, error);
    return !error;
  }

  function validateStep(number) {
    if (number === 2) {
      const panel = form.querySelector(`[data-panel="${type}"]`);
      const inputs = [...panel.querySelectorAll("[data-rule]")].filter((input) => !isHidden(input));
      const invalid = inputs.filter((input) => !checkField(input));
      invalid[0]?.focus();
      return invalid.length === 0;
    }
    if (number === 3) {
      const terms = $("terms");
      const error = $("termsError");
      error.hidden = terms.checked;
      error.textContent = t("err_terms");
      if (!terms.checked) terms.focus();
      return terms.checked;
    }
    return true;
  }

  // Re-check a field as the visitor fixes it
  form.addEventListener("input", (event) => {
    const input = event.target;
    if (input.matches("[data-rule]") && input.getAttribute("aria-invalid") === "true") checkField(input);
    if (input.id === "terms" && input.checked) $("termsError").hidden = true;
  });
  form.addEventListener("focusout", (event) => {
    const input = event.target;
    if (input.matches("[data-rule]") && input.value.trim()) checkField(input);
  });

  // ---------- Steps ----------
  function goTo(number, { scroll = true } = {}) {
    step = number;
    steps.forEach((section) => { section.hidden = Number(section.dataset.step) !== step; });
    stepperItems.forEach((item, index) => {
      item.classList.toggle("is-active", index + 1 === step);
      item.classList.toggle("is-done", index + 1 < step);
    });
    $("regBar").style.width = `${(step / TOTAL_STEPS) * 100}%`;
    backBtn.classList.toggle("is-hidden", step === 1);
    nextBtn.hidden = step === TOTAL_STEPS;
    submitBtn.hidden = step !== TOTAL_STEPS;
    updateCount();
    if (step === 3) renderReview();

    if (scroll) {
      const top = card.getBoundingClientRect().top;
      if (top < 80 || top > window.innerHeight * 0.6) card.scrollIntoView({ behavior: "smooth", block: "start" });
      steps[step - 1].querySelector(".step-head h3").focus({ preventScroll: true });
    }
  }

  function updateCount() {
    $("regCount").textContent = t("step_of").replace("{n}", step).replace("{total}", TOTAL_STEPS);
  }

  nextBtn.addEventListener("click", () => {
    if (validateStep(step)) goTo(step + 1);
  });
  backBtn.addEventListener("click", () => goTo(step - 1));
  $("editDetails").addEventListener("click", () => goTo(2));

  // ---------- Registration type ----------
  function setType(newType) {
    type = newType;
    form.querySelector(`input[name="regType"][value="${type}"]`).checked = true;
    syncChecked("regType");
    form.querySelectorAll("[data-panel]").forEach((panel) => { panel.hidden = panel.dataset.panel !== type; });
    form.querySelectorAll("[data-type-show]").forEach((el) => { el.hidden = el.dataset.typeShow !== type; });
  }

  form.addEventListener("change", (event) => {
    const input = event.target;
    if (input.name === "regType") setType(input.value);
    if (input.name === "pay") {
      syncChecked("pay");
      renderReview();
    }
    if (input.name === "ticket") {
      syncChecked("ticket");
      toggleUniversity();
    }
    if (input === specialty && specialty.value === "student") {
      form.querySelector('input[name="ticket"][value="student"]').checked = true;
      syncChecked("ticket");
      toggleUniversity();
    }
  });

  // "Register your team" button in the Companies section
  document.querySelector("[data-go-company]")?.addEventListener("click", () => {
    setType("company");
    goTo(2, { scroll: false });
  });

  // Students add their university
  function toggleUniversity() {
    const isStudent = checkedValue("ticket") === "student";
    $("uniWrap").hidden = !isStudent;
    if (!isStudent) showError($("p_uni"), "");
  }

  // ---------- Dentists (company) ----------
  function addDentist() {
    dentistId++;
    const id = dentistId;
    const row = document.createElement("div");
    row.className = "dentist";
    // The specialty options are copied from the person form so the list lives in one place.
    row.innerHTML = `
      <span class="dent-num" aria-hidden="true"></span>
      <div class="f"><label for="dn${id}" data-i18n="d_name">${t("d_name")}</label><input id="dn${id}" autocomplete="off" data-rule="name"></div>
      <div class="f"><label for="dp${id}" data-i18n="d_phone">${t("d_phone")}</label><input id="dp${id}" type="tel" inputmode="tel" dir="ltr" placeholder="07xx xxx xxxx" data-rule="phone" data-optional></div>
      <div class="f"><label for="ds${id}" data-i18n="f_spec">${t("f_spec")}</label><select id="ds${id}">${specialty.innerHTML}</select></div>
      <button type="button" class="rm" aria-label="${t("remove")}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
      </button>`;
    row.querySelector(".rm").addEventListener("click", () => {
      if (dentistList.children.length > 1) {
        row.remove();
        refreshDentists();
      }
    });
    dentistList.appendChild(row);
    refreshDentists();
    return row;
  }

  function refreshDentists() {
    const rows = [...dentistList.children];
    rows.forEach((row, index) => {
      row.querySelector(".dent-num").textContent = index + 1;
      const remove = row.querySelector(".rm");
      remove.disabled = rows.length === 1;
      remove.setAttribute("aria-label", t("remove"));
    });
    $("dentCount").textContent = `${rows.length} ${t(rows.length === 1 ? "dentist" : "dentists")}`;
  }

  $("addDentist").addEventListener("click", () => addDentist().querySelector("input").focus());

  // ---------- Review ----------
  function renderReview() {
    const rows = [[t("rv_type"), t(type === "person" ? "mode_person" : "mode_company")]];
    if (type === "person") {
      rows.push([t("f_name"), $("p_name").value.trim()]);
      rows.push([t("f_spec"), optionText(specialty)]);
      rows.push([t("f_ticket"), t(checkedValue("ticket") === "student" ? "ticket_student" : "ticket_prof")]);
      if (checkedValue("ticket") === "student") rows.push([t("f_uni"), $("p_uni").value.trim()]);
      rows.push([t("f_phone"), $("p_phone").value.trim()]);
      rows.push([t("f_email"), $("p_email").value.trim()]);
    } else {
      const names = [...dentistList.querySelectorAll('input[id^="dn"]')].map((input) => input.value.trim()).filter(Boolean);
      rows.push([t("f_cname"), $("c_name").value.trim()]);
      rows.push([t("f_contact"), $("c_contact").value.trim()]);
      rows.push([t("f_phone"), $("c_phone").value.trim()]);
      rows.push([t("f_email"), $("c_email").value.trim()]);
      rows.push([t("f_dentists"), `${names.length} · ${names.join(", ")}`]);
    }
    rows.push([t("pay_legend"), t(checkedValue("pay") === "fastpay" ? "pay_fastpay_t" : "pay_fib_t")]);

    const list = $("reviewList");
    list.replaceChildren(...rows.map(([label, value]) => {
      const row = document.createElement("div");
      const dt = document.createElement("dt");
      const dd = document.createElement("dd");
      dt.textContent = label;
      dd.textContent = value;
      row.append(dt, dd);
      return row;
    }));
  }

  // ---------- Submit ----------
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    // Pressing Enter on steps 1–2 moves forward instead of submitting
    if (step < TOTAL_STEPS) {
      nextBtn.click();
      return;
    }
    if (!validateStep(2)) {
      goTo(2);
      validateStep(2);
      return;
    }
    if (!validateStep(3)) return;
    submitRegistration();
  });

  function submitRegistration() {
    // TODO: send the registration to your server here.
    const phone = $(type === "person" ? "p_phone" : "c_phone").value.trim();
    const email = $(type === "person" ? "p_email" : "c_email").value.trim();
    const method = t(checkedValue("pay") === "fastpay" ? "pay_fastpay_t" : "pay_fib_t");

    $("successText").textContent = t("success_text")
      .replace("{method}", method)
      .replace("{phone}", phone)
      .replace("{email}", email);
    $("successRef").textContent = makeReference();

    form.hidden = true;
    progress.hidden = true;
    success.hidden = false;
    card.scrollIntoView({ behavior: "smooth", block: "start" });
    success.focus({ preventScroll: true });
  }

  function makeReference() {
    const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    const bytes = crypto.getRandomValues(new Uint8Array(6));
    return `ISM26-${[...bytes].map((byte) => alphabet[byte % alphabet.length]).join("")}`;
  }

  $("regAgain").addEventListener("click", () => {
    form.reset();
    form.querySelectorAll('[aria-invalid="true"]').forEach((input) => showError(input, ""));
    $("termsError").hidden = true;
    dentistList.replaceChildren();
    addDentist();
    setType("person");
    ["pay", "ticket"].forEach(syncChecked);
    toggleUniversity();
    success.hidden = true;
    form.hidden = false;
    progress.hidden = false;
    goTo(1);
  });

  // ---------- Language changes ----------
  onLangChange(() => {
    updateCount();
    refreshDentists();
    form.querySelectorAll(".f-error[data-key]").forEach((message) => { message.textContent = t(message.dataset.key); });
    if (step === 3) renderReview();
  });

  // ---------- Start ----------
  addDentist();
  setType("person");
  goTo(1, { scroll: false });
}
