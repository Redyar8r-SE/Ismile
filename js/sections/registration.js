// Registration: individual details, optional lunch, payment review, and success screen.
// Workshops are not sold here: after registering, the success screen
// suggests them with a link to the workshops page.
//
// The form is sent to the server (api/register.php), which saves it, makes the
// reference and works out the price. Professionals then go straight to the
// payment page; students wait for their ID to be checked. Where there is no
// server (GitHub Pages) or registration is closed, the form is not shown at
// all, so nobody can believe they registered when nothing was saved.
// Nothing about the visitor is kept in the browser.
import { t, getLang, onLangChange } from "../i18n.js?v=78";
import { formatPrice } from "../utils/money.js?v=78";

const TOTAL_STEPS = 3;

// The four payment methods Psoola will take. The key is what the server
// receives; the value is the translation key of the name shown to the visitor.
const PAY_METHODS = { visa: "pay_visa_t", mastercard: "pay_mastercard_t", fib: "pay_fib_t", fastpay: "pay_fastpay_t" };
const payMethod = (value) => (value in PAY_METHODS ? value : "visa");

// Which step each field the server may complain about sits on.
const FIELD_STEP = { terms: 3 };

// Earlier versions kept the visitor's name, email and phone in the browser.
// Nothing uses them any more, so they are removed from any device that has them.
try { localStorage.removeItem("ismile-attendee"); } catch { /* storage blocked: nothing kept */ }
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

// Iraqi mobiles (0750 123 4567 / +964 750 123 4567) or any international number starting with +
export function isValidPhone(value) {
  const digits = value.replace(/[\s\-().]/g, "");
  if (/^(\+964|00964|964)7\d{9}$/.test(digits) || /^07\d{9}$/.test(digits)) return true;
  return /^\+\d{8,15}$/.test(digits) && !digits.startsWith("+964");
}

export function initRegistration({ tickets = {} } = {}) {
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

  let lastSuccess = null;
  let sending = false;
  // What the server says: open or not, prices, lunch days left. null = no server.
  let server = null;
  let serverChecked = false;

  // ---------- Helpers ----------
  const checkedValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value;
  const optionText = (select) => select.options[select.selectedIndex]?.textContent.trim() || "";
  const isHidden = (el) => Boolean(el.closest("[hidden]:not(.reg-step)"));
  const ticketPrice = () => Number(tickets[checkedValue("ticket") === "student" ? "student" : "professional"]) || 0;
  // Lunch is optional and per day: none, one or both.
  const LUNCH = [
    { id: "day1", price: () => Number(tickets.lunchDay1) || 0, label: "order_lunch_d1", short: "lunch_d1" },
    { id: "day2", price: () => Number(tickets.lunchDay2) || 0, label: "order_lunch_d2", short: "lunch_d2" },
  ];
  const chosenLunch = () => LUNCH.filter((day) => form.querySelector(`input[name="lunch"][value="${day.id}"]`).checked);
  const orderTotal = () => ticketPrice() + chosenLunch().reduce((sum, day) => sum + day.price(), 0);

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
    updateNextLabel();
    if (step === 3) renderReview();

    if (scroll) {
      const top = card.getBoundingClientRect().top;
      if (top < 80 || top > window.innerHeight * 0.6) card.scrollIntoView({ behavior: "smooth", block: "start" });
      steps[step - 1].querySelector(".step-head h3").focus({ preventScroll: true });
    }
  }

  // Lunch is optional: with no day ticked the button says so.
  function updateNextLabel() {
    const skipping = step === 2 && chosenLunch().length === 0;
    nextBtn.querySelector("span").textContent = t(skipping ? "lunch_skip" : "continue");
  }

  function updateCount() {
    $("regCount").textContent = t("step_of").replace("{n}", step).replace("{total}", TOTAL_STEPS);
  }

  nextBtn.addEventListener("click", () => {
    if (!validateStep(step)) return;
    // Leaving the details step is the moment to check the name: it is printed
    // on the certificate, so ask once before moving on.
    if (step === 1) askBeforeLeavingDetails();
    else goTo(step + 1);
  });
  backBtn.addEventListener("click", () => goTo(step - 1));
  $("editDetails").addEventListener("click", () => goTo(1));
  $("editLunch").addEventListener("click", () => goTo(2));

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
    if (input.name === "lunch") {
      syncChecked("lunch");
      updateNextLabel();
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

  // ---------- "Is everything correct?" ----------
  const confirmDialog = $("regConfirm");
  let awaitingConfirm = false;

  // What the visitor typed keeps its own direction inside Arabic and Kurdish
  // text: "0750 123 4567" must not come out as "4567 123 0750".
  const isolate = (value) => `⁨${value}⁩`;

  function askBeforeLeavingDetails() {
    const fullName = [$("p_first"), $("p_second"), $("p_third")].map((input) => input.value.trim()).join(" ");
    const rows = [
      [t("f_name_full"), fullName, "name"],
      [t("f_phone_number"), $("p_phone").value.trim(), ""],
      [t("f_email"), $("p_email").value.trim(), ""],
    ];

    $("regConfirmList").replaceChildren(...rows.map(([label, value, kind]) => {
      const row = document.createElement("div");
      if (kind) row.className = `is-${kind}`;
      const dt = document.createElement("dt");
      const dd = document.createElement("dd");
      dt.textContent = label;
      dd.textContent = isolate(value);
      row.append(dt, dd);
      if (kind === "name") {
        const hint = document.createElement("small");
        hint.textContent = t("confirm_name_hint");
        row.append(hint);
      }
      return row;
    }));

    // A browser without <dialog> keeps the old behaviour rather than trapping
    // the visitor on the details step.
    if (typeof confirmDialog.showModal !== "function") {
      goTo(2);
      return;
    }
    awaitingConfirm = true;
    confirmDialog.showModal();
  }

  // One answer per opening: a second or late "close" can never move a form
  // that has since been sent or started again.
  confirmDialog.addEventListener("close", () => {
    if (!awaitingConfirm) return;
    awaitingConfirm = false;
    if (confirmDialog.returnValue === "ok") goTo(2);
    else $("p_first").focus();
  });

  // ---------- Review ----------
  function fillList(list, rows) {
    list.replaceChildren(...rows.map(([label, value]) => {
      const row = document.createElement("div");
      const dt = document.createElement("dt");
      const dd = document.createElement("dd");
      dt.textContent = label;
      dd.textContent = isolate(value);
      row.append(dt, dd);
      return row;
    }));
  }

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
    const lunch = chosenLunch().map((day) => t(day.short));
    rows.push([t("rv_lunch"), lunch.length ? lunch.join(", ") : t("rv_lunch_none")]);
    rows.push([t("pay_legend"), t(PAY_METHODS[payMethod(checkedValue("pay"))])]);
    fillList($("reviewList"), rows);
    renderOrder();
  }

  // The order is the ticket plus any lunch days. Workshops are paid for
  // through the office.
  function orderLines() {
    const student = checkedValue("ticket") === "student";
    return [
      { id: `ticket-${student ? "student" : "professional"}`, label: t(student ? "order_ticket_student" : "order_ticket_prof"), price: ticketPrice() },
      ...chosenLunch().map((day) => ({ id: `lunch-${day.id}`, label: t(day.label), price: day.price() })),
    ];
  }

  function renderOrder() {
    fillList($("orderList"), orderLines().map((line) => [line.label, formatPrice(line.price)]));
    $("orderTotal").textContent = formatPrice(orderTotal());
  }

  // ---------- Submit ----------
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    // Pressing Enter before the last step moves forward instead of submitting
    if (step < TOTAL_STEPS) {
      nextBtn.click();
      return;
    }
    if (!validateStep(1)) {
      goTo(1);
      validateStep(1);
      return;
    }
    if (!validateStep(3)) return;
    submitRegistration();
  });

  // Everything the server needs, read straight from the fields. The server
  // checks it all again and sets the price itself.
  function formData() {
    const data = new FormData();
    const student = checkedValue("ticket") === "student";
    data.append("lang", getLang());
    data.append("first_name", $("p_first").value.trim());
    data.append("father_name", $("p_second").value.trim());
    data.append("grandfather_name", $("p_third").value.trim());
    data.append("phone", $("p_phone").value.trim());
    data.append("email", $("p_email").value.trim());
    data.append("city", $("p_city").value.trim());
    data.append("gender", $("p_gender").value);
    data.append("age", $("p_age").value.trim());
    data.append("specialty", specialty.value);
    data.append("ticket", student ? "student" : "professional");
    chosenLunch().forEach((day) => data.append(`lunch_${day.id}`, "1"));
    data.append("pay", payMethod(checkedValue("pay")));
    data.append("terms", $("terms").checked ? "1" : "0");
    data.append("website", $("regTrap")?.value || "");   // spam trap: people never fill it
    if (student) {
      data.append("university", $("p_uni").value.trim());
      data.append("ambassador", $("p_ambassador").value.trim());
      const photo = $("p_student_id").files?.[0];
      if (photo) data.append("student_id", photo);
    }
    return data;
  }

  function setSending(on) {
    sending = on;
    submitBtn.disabled = on;
    backBtn.disabled = on;
    submitBtn.querySelector("span").textContent = t(on ? "reg_sending" : "submit");
  }

  // A problem the server found: shown next to the field it is about, or under
  // the terms when it is about the registration as a whole.
  function showServerError(key, field) {
    const input = field ? $(field) : null;
    if (input && input.id !== "terms") {
      goTo(FIELD_STEP[input.id] || 1);
      showError(input, key);
      input.focus();
      return;
    }
    const error = $("termsError");
    error.textContent = t(key);
    error.hidden = false;
  }

  async function submitRegistration() {
    if (sending) return;
    setSending(true);
    $("termsError").hidden = true;
    let result = null;
    try {
      const response = await fetch("api/register.php", { method: "POST", body: formData(), headers: { Accept: "application/json" } });
      result = await response.json();
    } catch {
      result = { ok: false, error: "err_server" };
    }

    if (!result.ok) {
      setSending(false);
      if (result.error === "reg_closed" || result.error === "reg_full") {
        await checkServer();
        return;
      }
      showServerError(result.error || "err_server", result.field);
      return;
    }

    // Everyone (students too) goes straight to the secure payment page: a
    // person is registered only once the payment is confirmed. The form stays
    // disabled so a second press cannot send it twice.
    submitBtn.querySelector("span").textContent = t("reg_to_payment");
    if (result.redirect) {
      window.location.assign(result.redirect);
      return;
    }
    // The payment could not start right now (full, closed, or the payment
    // company did not answer): their own payment page says why and offers
    // "Try again". Never the form a second time.
    window.location.assign(`${result.statusUrl}&e=${encodeURIComponent(result.payError || "pay_start_failed")}`);   // lunch_full, reg_full, reg_closed or pay_start_failed
  }

  function renderSuccess() {
    if (!lastSuccess) return;
    const { kind, email, ref } = lastSuccess;
    $("successTitle").textContent = t(kind === "student" ? "reg_ok_student_title" : "reg_ok_later_title");
    $("successText").textContent = t(kind === "student" ? "reg_ok_student_text" : "reg_ok_later_text").replaceAll("{email}", isolate(email));
    $("successRef").textContent = ref;
  }

  // ---------- Is registration open? ----------
  // Asks the server before showing the form. No answer (no server, e.g. the
  // GitHub Pages copy) counts as closed.
  async function checkServer() {
    try {
      const response = await fetch(`api/config.php?lang=${getLang()}`, { cache: "no-store", headers: { Accept: "application/json" } });
      const data = await response.json();
      server = data && data.ok === true ? data : null;
    } catch {
      server = null;
    }
    serverChecked = true;
    renderAvailability();
  }

  function renderAvailability() {
    const closedBox = $("regClosed");
    card.classList.toggle("is-checking", !serverChecked);
    if (!serverChecked) return;
    const open = server?.open === true;
    closedBox.hidden = open || lastSuccess !== null;
    if (lastSuccess === null) {
      form.hidden = !open;
      progress.hidden = !open;
    }
    if (!open) {
      const reason = server?.reason;
      const message = reason === "closed" ? server.messages?.[getLang()] || server.message : "";
      $("regClosedTitle").textContent = t(reason === "full" ? "reg_full_title" : "reg_closed_title");
      $("regClosedText").textContent = message || t(reason === "full" ? "reg_full_text" : "reg_closed_text");
      return;
    }
    // Lunch days that are full (or have no price yet) cannot be chosen.
    LUNCH.forEach((day) => {
      const input = form.querySelector(`input[name="lunch"][value="${day.id}"]`);
      const available = server.lunch?.[day.id] !== false;
      input.disabled = !available;
      if (!available) input.checked = false;
      input.closest("label")?.classList.toggle("is-unavailable", !available);
    });
    syncChecked("lunch");
    updateNextLabel();
  }

  $("regAgain").addEventListener("click", () => {
    form.reset();
    updateAgeButtons();
    form.querySelectorAll('[aria-invalid="true"]').forEach((input) => showError(input, ""));
    $("termsError").hidden = true;
    ["pay", "ticket", "lunch"].forEach(syncChecked);
    toggleUniversity();
    lastSuccess = null;
    success.hidden = true;
    form.hidden = false;
    progress.hidden = false;
    goTo(1);
    renderAvailability();
  });

  // ---------- Prices in the side panel and on the lunch cards ----------
  function renderTicketPrices() {
    $("tkPriceProf").textContent = formatPrice(tickets.professional);
    $("tkPriceStudent").textContent = formatPrice(tickets.student);
    const [day1, day2] = LUNCH.map((day) => day.price());
    $("lunchPrice1").textContent = formatPrice(day1);
    $("lunchPrice2").textContent = formatPrice(day2);
    // One price when both days cost the same, otherwise the lower one as "from".
    const prices = [day1, day2].filter(Boolean);
    $("tkPriceLunch").textContent = new Set(prices).size > 1 || prices.length === 1
      ? t("lunch_from").replace("{price}", formatPrice(Math.min(...prices)))
      : formatPrice(day1);
  }

  // ---------- Language changes ----------
  onLangChange(() => {
    updateCount();
    form.querySelectorAll(".f-error[data-key]").forEach((message) => { message.textContent = t(message.dataset.key); });
    renderTicketPrices();
    updateNextLabel();
    if (step === 3) renderReview();
    renderSuccess();
    renderAvailability();
    if (sending) submitBtn.querySelector("span").textContent = t("reg_sending");
  });

  // ---------- Start ----------
  updateAgeButtons();
  renderTicketPrices();
  goTo(1, { scroll: false });
  renderAvailability();
  checkServer();
}
