// Registration: individual details, optional workshops, payment review, and success screen.
// Demo only: nothing is sent to a server yet. Connect submitRegistration() to your backend.
// The finished registration is remembered in this browser (js/utils/attendee.js)
// so the workshop cards can tell a registered visitor from a new one.
import { t, tr, onLangChange } from "../i18n.js?v=24";
import { WORKSHOP_ICONS } from "../config/icons.js?v=28";
import { formatPrice } from "../utils/money.js?v=1";
import { getAttendee, saveAttendee, addWorkshops, clearAttendee, makeReference } from "../utils/attendee.js?v=1";

const TOTAL_STEPS = 3;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

// Iraqi mobiles (0750 123 4567 / +964 750 123 4567) or any international number starting with +
export function isValidPhone(value) {
  const digits = value.replace(/[\s\-().]/g, "");
  if (/^(\+964|00964|964)7\d{9}$/.test(digits) || /^07\d{9}$/.test(digits)) return true;
  return /^\+\d{8,15}$/.test(digits) && !digits.startsWith("+964");
}

export function initRegistration({ workshops = [], tickets = {} } = {}) {
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
  const picker = $("wsPicker");

  let step = 1;

  // ---------- Mode ----------
  // "full": a new registration. "addon": this browser already holds a
  // registration and the visitor only wants to add workshops to it, so the
  // details step is skipped and only the workshops are paid for.
  const params = new URLSearchParams(location.search);
  const wantedWorkshop = params.get("workshop");
  let attendee = getAttendee();
  const mode = attendee && (params.get("mode") === "workshops" || wantedWorkshop) ? "addon" : "full";
  const selected = new Set();
  let lastSuccess = null;

  // ---------- Helpers ----------
  const checkedValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value;
  const optionText = (select) => select.options[select.selectedIndex]?.textContent.trim() || "";
  const isHidden = (el) => Boolean(el.closest("[hidden]:not(.reg-step)"));
  const workshopById = (id) => workshops.find((w) => w.id === id);
  const isReserved = (id) => Boolean(attendee?.workshops.includes(id));
  const isBookable = (w) => Boolean(w) && w.seatsLeft !== 0 && !isReserved(w.id);
  const ticketPrice = () => Number(tickets[checkedValue("ticket") === "student" ? "student" : "professional"]) || 0;
  const workshopsTotal = () => [...selected].reduce((sum, id) => sum + (Number(workshopById(id)?.price) || 0), 0);
  const orderTotal = () => (mode === "addon" ? 0 : ticketPrice()) + workshopsTotal();

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
      if (mode === "addon") return true;
      const inputs = [...form.querySelectorAll('.reg-step[data-step="1"] [data-rule]')].filter((input) => !isHidden(input));
      const invalid = inputs.filter((input) => !checkField(input));
      invalid[0]?.focus();
      return invalid.length === 0;
    }
    if (number === 2) {
      // Workshops are optional on a new registration, but an add-on order
      // with nothing in it makes no sense.
      const error = $("wsError");
      const missing = mode === "addon" && selected.size === 0;
      error.hidden = !missing;
      error.textContent = t("ws_err_pick");
      if (missing) picker.querySelector("input:not(:disabled)")?.focus();
      return !missing;
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

  // ---------- Registered-on-this-device banner ----------
  function renderAttendee() {
    const banner = $("regAttendee");
    if (!attendee) {
      banner.hidden = true;
      return;
    }
    banner.hidden = false;
    banner.classList.toggle("is-addon", mode === "addon");
    $("regAttendeeName").textContent = attendee.name || attendee.email || "";
    $("regAttendeeRef").textContent = attendee.ref;
    $("regAttendeeNote").textContent = t(mode === "addon" ? "reg_addon_sub" : "reg_addon_hint");
    $("regAttendeeAdd").hidden = mode === "addon";
  }

  $("regAttendeeReset").addEventListener("click", () => {
    clearAttendee();
    location.href = "register.html";
  });

  // A workshop card on the home page can send the visitor here with
  // ?workshop=id: tell them at step 1 that it will be added at step 2.
  const wantedNote = document.createElement("p");
  wantedNote.className = "reg-ws-wanted";
  wantedNote.hidden = true;
  form.querySelector(".reg-person-intro").insertAdjacentElement("afterend", wantedNote);
  function renderWantedNote() {
    const workshop = workshopById(wantedWorkshop);
    const show = mode === "full" && isBookable(workshop);
    wantedNote.hidden = !show;
    if (show) wantedNote.textContent = t("reg_ws_wanted").replace("{title}", tr(workshop.title));
  }

  // ---------- Step 2: workshops ----------
  function renderWorkshops() {
    picker.querySelectorAll(".ws-pick").forEach((el) => el.remove());
    workshops.forEach((w) => {
      const full = w.seatsLeft === 0;
      const reserved = isReserved(w.id);
      const disabled = full || reserved;
      const checked = selected.has(w.id);
      const taken = w.totalSeats ? Math.round((1 - w.seatsLeft / w.totalSeats) * 100) : 0;
      let status = `<span class="ws-pick-seats">${w.seatsLeft} ${t("w_left")}</span>`;
      if (reserved) status = `<span class="ws-pick-reserved">${t("ws_reserved")}</span>`;
      else if (full) status = `<span class="ws-pick-full">${t("w_full")}</span>`;
      const who = [tr(w.speaker), tr(w.company)].filter(Boolean).join(" · ") || t("w_dr");

      const label = document.createElement("label");
      label.className = `ws-pick${disabled ? " is-disabled" : ""}${full ? " is-full" : ""}${checked ? " is-checked" : ""}`;
      label.innerHTML = `
        <input type="checkbox" name="ws" value="${w.id}"${checked ? " checked" : ""}${disabled ? " disabled" : ""}>
        <span class="ws-pick-ic" aria-hidden="true">${WORKSHOP_ICONS[w.icon] || WORKSHOP_ICONS.tools}</span>
        <span class="ws-pick-text">
          <b>${tr(w.title)}</b>
          <small>${who}</small>
        </span>
        <span class="ws-pick-seatbar" aria-hidden="true"><i style="width:${taken}%"></i></span>
        <span class="ws-pick-meta">${status}</span>
        <span class="ws-pick-foot">
          <span class="ws-pick-price"><b>${formatPrice(w.price)}</b><small>${t("w_price_seat")}</small></span>
          <span class="ws-pick-btn">${t(checked ? "ws_added" : "ws_add")}</span>
        </span>`;
      picker.append(label);
    });
    updateWorkshopSummary();
  }

  function updateWorkshopSummary() {
    const count = selected.size;
    $("wsSummaryCount").textContent = count ? t("ws_pick_count").replace("{n}", count) : t("ws_pick_none");
    $("wsSummaryHint").textContent = t(count ? "ws_summary_hint" : "ws_summary_empty");
    $("wsSummaryBadge").textContent = count;
    $("wsSummaryBadge").hidden = count === 0;
    $("wsSummaryTotal").textContent = count ? formatPrice(workshopsTotal()) : "";
    $("wsSummary").classList.toggle("has-items", count > 0);
    updateNextLabel();
  }

  // With nothing ticked the button says "Skip workshops" so it is clear the
  // step is optional.
  function updateNextLabel() {
    const skipping = step === 2 && mode === "full" && selected.size === 0;
    nextBtn.querySelector("span").textContent = t(skipping ? "ws_skip" : "continue");
  }

  picker.addEventListener("change", (event) => {
    const input = event.target;
    if (input.name !== "ws") return;
    if (input.checked) selected.add(input.value);
    else selected.delete(input.value);
    const label = input.closest("label");
    label.classList.toggle("is-checked", input.checked);
    label.querySelector(".ws-pick-btn").textContent = t(input.checked ? "ws_added" : "ws_add");
    $("wsError").hidden = true;
    updateWorkshopSummary();
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
    backBtn.classList.toggle("is-hidden", step === 1 || (mode === "addon" && step === 2));
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

  function updateCount() {
    $("regCount").textContent = t("step_of").replace("{n}", step).replace("{total}", TOTAL_STEPS);
  }

  nextBtn.addEventListener("click", () => {
    if (!validateStep(step)) return;
    // Leaving the details step is the moment to check the name: it is printed
    // on the certificate, so ask once before moving on.
    if (step === 1 && mode === "full") askBeforeLeavingDetails();
    else goTo(step + 1);
  });
  backBtn.addEventListener("click", () => goTo(step - 1));
  $("editDetails").addEventListener("click", () => goTo(mode === "addon" ? 2 : 1));
  $("editWorkshops").addEventListener("click", () => goTo(2));

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

  // ---------- "Is everything correct?" ----------
  const confirmDialog = $("regConfirm");

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
      dd.textContent = value;
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
    confirmDialog.showModal();
  }

  confirmDialog.addEventListener("close", () => {
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
      dd.textContent = value;
      row.append(dt, dd);
      return row;
    }));
  }

  function renderReview() {
    const rows = [[t("rv_type"), t("mode_person")]];
    const workshopNames = [...selected].map((id) => tr(workshopById(id)?.title)).filter(Boolean);
    if (mode === "addon") {
      if (attendee.name) rows.push([t("f_first"), attendee.name]);
      rows.push([t("f_email"), attendee.email]);
      rows.push([t("rv_ref"), attendee.ref]);
    } else {
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
    }
    rows.push([t("rv_workshops"), workshopNames.length ? workshopNames.join(", ") : t("rv_none")]);
    rows.push([t("pay_legend"), t(checkedValue("pay") === "fastpay" ? "pay_fastpay_t" : "pay_fib_t")]);
    fillList($("reviewList"), rows);
    renderOrder();
  }

  // One order: the ticket (on a new registration) plus every chosen workshop.
  function orderLines() {
    const lines = [];
    if (mode === "full") {
      const student = checkedValue("ticket") === "student";
      lines.push({ id: `ticket-${student ? "student" : "professional"}`, label: t(student ? "order_ticket_student" : "order_ticket_prof"), price: ticketPrice() });
    }
    selected.forEach((id) => {
      const w = workshopById(id);
      if (w) lines.push({ id: `workshop-${id}`, label: tr(w.title), price: Number(w.price) || 0 });
    });
    return lines;
  }

  function renderOrder() {
    fillList($("orderList"), orderLines().map((line) => [line.label, formatPrice(line.price)]));
    $("orderTotal").textContent = formatPrice(orderTotal());
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
    if (!validateStep(2)) {
      goTo(2);
      return;
    }
    if (!validateStep(3)) return;
    submitRegistration();
  });

  function submitRegistration() {
    const ids = [...selected];
    const payment = checkedValue("pay") === "fastpay" ? "fastpay" : "fib";
    const order = { lines: orderLines(), total: orderTotal(), payment };

    if (mode === "addon") {
      attendee = addWorkshops(ids) || attendee;
    } else {
      attendee = {
        ref: makeReference(),
        createdAt: new Date().toISOString(),
        name: [$("p_first"), $("p_second"), $("p_third")].map((input) => input.value.trim()).join(" "),
        email: $("p_email").value.trim(),
        phone: $("p_phone").value.trim(),
        ticket: checkedValue("ticket") === "student" ? "student" : "professional",
        workshops: ids,
      };
      saveAttendee(attendee);
    }
    // TODO: send { attendee, order } to your server here.

    lastSuccess = { payment, total: order.total, email: attendee.email, workshops: ids };
    renderSuccess();
    form.hidden = true;
    progress.hidden = true;
    $("regAttendee").hidden = true;
    success.hidden = false;
    card.scrollIntoView({ behavior: "smooth", block: "start" });
    success.focus({ preventScroll: true });
  }

  function renderSuccess() {
    if (!lastSuccess) return;
    const { payment, total, email, workshops: ids } = lastSuccess;
    const method = t(payment === "fastpay" ? "pay_fastpay_t" : "pay_fib_t");
    const addon = mode === "addon";
    $("successTitle").textContent = t(addon ? "success_addon_title" : "success_title");
    $("successText").textContent = t(addon ? "success_addon_text" : "success_text")
      .replace("{method}", method)
      .replace("{total}", formatPrice(total))
      .replaceAll("{email}", email);
    $("successRef").textContent = attendee.ref;

    const list = $("successWorkshops");
    list.hidden = ids.length === 0;
    list.replaceChildren(...ids.map((id) => {
      const w = workshopById(id);
      const item = document.createElement("li");
      const name = document.createElement("span");
      const price = document.createElement("b");
      name.textContent = tr(w?.title) || id;
      price.textContent = formatPrice(w?.price);
      item.append(name, price);
      return item;
    }));
    $("regAgain").hidden = addon;
    $("regBackWs").hidden = !addon;
  }

  $("regAgain").addEventListener("click", () => {
    form.reset();
    updateAgeButtons();
    form.querySelectorAll('[aria-invalid="true"]').forEach((input) => showError(input, ""));
    $("termsError").hidden = true;
    $("wsError").hidden = true;
    ["pay", "ticket"].forEach(syncChecked);
    toggleUniversity();
    selected.clear();
    renderWorkshops();
    renderAttendee();
    lastSuccess = null;
    success.hidden = true;
    form.hidden = false;
    progress.hidden = false;
    goTo(1);
  });

  // ---------- Ticket prices in the side panel ----------
  function renderTicketPrices() {
    $("tkPriceProf").textContent = formatPrice(tickets.professional);
    $("tkPriceStudent").textContent = formatPrice(tickets.student);
  }

  // ---------- Language changes ----------
  onLangChange(() => {
    updateCount();
    form.querySelectorAll(".f-error[data-key]").forEach((message) => { message.textContent = t(message.dataset.key); });
    renderTicketPrices();
    renderAttendee();
    renderWantedNote();
    renderWorkshops();
    if (!$("wsError").hidden) $("wsError").textContent = t("ws_err_pick");
    if (step === 3) renderReview();
    renderSuccess();
  });

  // ---------- Start ----------
  if (isBookable(workshopById(wantedWorkshop))) selected.add(wantedWorkshop);
  updateAgeButtons();
  renderTicketPrices();
  renderAttendee();
  renderWantedNote();
  renderWorkshops();
  goTo(mode === "addon" ? 2 : 1, { scroll: false });
}
