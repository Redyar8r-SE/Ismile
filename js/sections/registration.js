// Registration: individual details, payment review, and success screen.
// Demo only: nothing is sent to a server yet. Connect submitRegistration() to your backend.
import { t, onLangChange } from "../i18n.js?v=24";

const TOTAL_STEPS = 2;
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
  const age = $("p_age");
  const success = $("regSuccess");

  let step = 1;

  // ---------- Helpers ----------
  const checkedValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value;
  const optionText = (select) => select.options[select.selectedIndex]?.textContent.trim() || "";
  const isHidden = (el) => Boolean(el.closest("[hidden]:not(.reg-step)"));

  // Keep the "selected" style of radio cards in sync
  function syncChecked(name) {
    form.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
      input.closest("label").classList.toggle("is-checked", input.checked);
    });
  }

  // ---------- Field validation ----------
  function showError(input, key) {
    const wrap = input.closest(".f");
    let message = wrap.querySelector(".f-error");
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
      (input.closest(".age-control") || input).insertAdjacentElement("afterend", message);
    }
    message.dataset.key = key;
    message.textContent = t(key);
    input.setAttribute("aria-invalid", "true");
    input.setAttribute("aria-describedby", message.id);
  }

  function checkField(input) {
    if (input.dataset.rule === "student-id") return checkStudentId(input);
    const value = input.value.trim();
    const rule = input.dataset.rule;
    let error = "";
    if (!value) {
      if (!("optional" in input.dataset)) error = "err_required";
    } else if (rule === "email" && !EMAIL_PATTERN.test(value)) {
      error = "err_email";
    } else if (rule === "phone" && !isValidPhone(value)) {
      error = "err_phone";
    } else if (rule === "name-part" && value.length < 2) {
      error = "err_name";
    } else if (rule === "age" && (!Number.isInteger(Number(value)) || Number(value) < 16 || Number(value) > 120)) {
      error = "err_age";
    }
    showError(input, error);
    return !error;
  }

  function checkStudentId(input) {
    const file = input.files?.[0];
    let error = "";
    if (!file) error = "err_student_id";
    else if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) error = "err_student_id_type";
    else if (file.size > 8 * 1024 * 1024) error = "err_student_id_size";
    showError(input, error);
    return !error;
  }

  let studentIdPreviewUrl = "";
  function renderStudentIdPreview() {
    const input = $("p_student_id");
    const preview = $("studentIdPreview");
    const image = $("studentIdImage");
    const name = $("studentIdFileName");
    const file = input.files?.[0];
    if (studentIdPreviewUrl) URL.revokeObjectURL(studentIdPreviewUrl);
    studentIdPreviewUrl = "";
    if (!file) {
      preview.hidden = true;
      image.removeAttribute("src");
      name.textContent = "";
      return;
    }
    studentIdPreviewUrl = URL.createObjectURL(file);
    image.src = studentIdPreviewUrl;
    name.textContent = file.name;
    preview.hidden = false;
  }

  // The upload card also accepts a dropped image, while the visible button
  // keeps the same file picker available on touch devices.
  const studentIdDropzone = $("studentIdDropzone");
  ["dragenter", "dragover"].forEach((eventName) => {
    studentIdDropzone.addEventListener(eventName, (event) => {
      if ($("p_student_id").disabled) return;
      event.preventDefault();
      studentIdDropzone.classList.add("is-dragging");
    });
  });
  ["dragleave", "drop"].forEach((eventName) => {
    studentIdDropzone.addEventListener(eventName, (event) => {
      event.preventDefault();
      studentIdDropzone.classList.remove("is-dragging");
    });
  });
  studentIdDropzone.addEventListener("drop", (event) => {
    const input = $("p_student_id");
    if (input.disabled) return;
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    try {
      const transfer = new DataTransfer();
      transfer.items.add(file);
      input.files = transfer.files;
      input.dispatchEvent(new Event("change", { bubbles: true }));
    } catch {
      // Browsers without writable FileList still have the picker button.
    }
  });

  function validateStep(number) {
    if (number === 1) {
      const inputs = [...form.querySelectorAll('.reg-step[data-step="1"] [data-rule]')].filter((input) => !isHidden(input));
      const invalid = inputs.filter((input) => !checkField(input));
      invalid[0]?.focus();
      return invalid.length === 0;
    }
    if (number === 2) {
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

  // Replace the browser's bright native number arrows with controls that
  // match the registration form and keep the value inside the allowed range.
  const ageButtons = [...form.querySelectorAll("[data-age-step]")];
  function updateAgeButtons() {
    const value = age.value === "" ? null : Number(age.value);
    ageButtons[0].disabled = value !== null && value <= Number(age.min);
    ageButtons[1].disabled = value !== null && value >= Number(age.max);
  }
  ageButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const direction = Number(button.dataset.ageStep);
      const min = Number(age.min);
      const max = Number(age.max);
      const current = age.value === "" ? min : Number(age.value);
      age.value = String(Math.min(max, Math.max(min, age.value === "" ? min : current + direction)));
      age.dispatchEvent(new Event("input", { bubbles: true }));
      age.focus();
      updateAgeButtons();
    });
  });
  age.addEventListener("input", updateAgeButtons);

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
    if (step === 2) renderReview();

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
  $("editDetails").addEventListener("click", () => goTo(1));

  form.addEventListener("change", (event) => {
    const input = event.target;
    if (input.id === "p_student_id") {
      renderStudentIdPreview();
      if (input.getAttribute("aria-invalid") === "true") checkField(input);
    }
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

  // Students add their university and optional ambassador code.
  function toggleUniversity() {
    const isStudent = checkedValue("ticket") === "student";
    $("uniWrap").hidden = !isStudent;
    $("ambassadorWrap").hidden = !isStudent;
    $("studentIdWrap").hidden = !isStudent;
    $("p_ambassador").disabled = !isStudent;
    $("p_student_id").disabled = !isStudent;
    if (!isStudent) {
      showError($("p_uni"), "");
      showError($("p_ambassador"), "");
      showError($("p_student_id"), "");
      $("p_student_id").value = "";
      renderStudentIdPreview();
    }
  }

  // ---------- Review ----------
  function renderReview() {
    const rows = [[t("rv_type"), t("mode_person")]];
    rows.push([t("f_first"), $("p_first").value.trim()]);
    rows.push([t("f_second"), $("p_second").value.trim()]);
    rows.push([t("f_third"), $("p_third").value.trim()]);
    rows.push([t("f_phone_number"), $("p_phone").value.trim()]);
    rows.push([t("f_email"), $("p_email").value.trim()]);
    rows.push([t("f_city"), $("p_city").value.trim()]);
    rows.push([t("f_gender"), optionText($("p_gender"))]);
    rows.push([t("f_age"), $("p_age").value.trim()]);
    rows.push([t("f_spec"), optionText(specialty)]);
    rows.push([t("f_ticket"), t(checkedValue("ticket") === "student" ? "ticket_student" : "ticket_prof")]);
    if (checkedValue("ticket") === "student") {
      rows.push([t("f_uni"), $("p_uni").value.trim()]);
      if ($("p_ambassador").value.trim()) rows.push([t("f_ambassador"), $("p_ambassador").value.trim()]);
      if ($("p_student_id").files?.[0]) rows.push([t("f_student_id"), $("p_student_id").files[0].name]);
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
    if (!validateStep(1)) {
      goTo(1);
      validateStep(1);
      return;
    }
    if (!validateStep(2)) return;
    submitRegistration();
  });

  function submitRegistration() {
    // TODO: send the registration to your server here.
    const phone = $("p_phone").value.trim();
    const email = $("p_email").value.trim();
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
    updateAgeButtons();
    form.querySelectorAll('[aria-invalid="true"]').forEach((input) => showError(input, ""));
    $("termsError").hidden = true;
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
    form.querySelectorAll(".f-error[data-key]").forEach((message) => { message.textContent = t(message.dataset.key); });
    if (step === 2) renderReview();
  });

  // ---------- Start ----------
  updateAgeButtons();
  goTo(1, { scroll: false });
}
