// The sponsorship and exhibition-booth request form (sponsor.html).
//
// Interest and floor plan, sponsorship package, then company details. The packages come from
// data/sponsors.json, so a tier renamed in the admin is renamed here too.
//
// Where the request goes: to the server (api/sponsor.php), which saves it for
// the admin and emails the company and the team. Where there is no server (the
// GitHub Pages copy), the last screen hands the filled-in request to WhatsApp
// or email instead, already written out, so a request is never lost.
import { t, tr, getLang, onLangChange } from "../i18n.js?v=95";
import { isValidPhone } from "./registration.js?v=95";

const COMPANY_STEP = 3;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export function initSponsorForm({ tiers = [], enquiry = {} } = {}) {
  const $ = (id) => document.getElementById(id);
  const card = $("spfCard");
  const form = $("spfForm");
  const progress = $("spfProgress");
  const stepperItems = [...$("spfStepper").children];
  const steps = [...form.querySelectorAll(".reg-step")];
  const packBox = $("spfPacks");
  const backBtn = $("spfBack");
  const nextBtn = $("spfNext");
  const submitBtn = $("spfSubmit");
  const success = $("spfSuccess");
  const sponsorMap = $("exhibition");

  let step = 1;
  let kind = "";
  let pack = "";
  const activeSteps = () => kind === "booth" ? [1, COMPANY_STEP] : [1, 2, COMPANY_STEP];

  // ---------- The package cards ----------
  // The tiers sit in an even grid. "Not sure yet" is not a package, so it gets
  // a row of its own underneath and a quieter shape.
  function renderPacks() {
    const options = [
      ...tiers.map((tier) => ({ id: tier.id, name: tr(tier.name), note: tr(tier.subtitle), className: tier.className })),
      { id: "unsure", name: t("spf_pack_unsure"), note: t("spf_pack_unsure_d"), className: "spf-pack-any" },
    ];
    const icons = {
      platinum: '<path d="m6 3-4 6 10 12L22 9l-4-6H6ZM2 9h20M6 3l6 18 6-18M6 3l6 6 6-6"/>',
      gold: '<path d="m3 6 4 4 5-7 5 7 4-4-2 12H5L3 6ZM5 21h14"/>',
      silver: '<circle cx="12" cy="8" r="6"/><path d="m8 13-1 9 5-3 5 3-1-9"/>',
      bronze: '<path d="m8 2 4 7 4-7M5 2l4 8m10-8-4 8"/><circle cx="12" cy="15" r="7"/><path d="m12 11 1.2 2.5 2.8.4-2 2 .5 2.8-2.5-1.3-2.5 1.3.5-2.8-2-2 2.8-.4L12 11Z"/>',
      unsure: '<path d="m12 3 10 5-10 5L2 8l10-5ZM2 12l10 5 10-5M2 16l10 5 10-5"/>',
    };
    packBox.innerHTML = options
      .map(
        (option) => `
        <label class="spf-pack ${esc(option.className || "")}${option.id === pack ? " is-checked" : ""}">
          <input type="radio" name="spfPack" value="${esc(option.id)}"${option.id === pack ? " checked" : ""}>
          <span class="spf-pack-top"><span class="spf-pack-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${icons[option.id] || icons.platinum}</svg></span><span class="tc-check" aria-hidden="true"></span></span>
          <span class="spf-pack-text">
            <span class="spf-pack-name">${esc(option.name)}</span>
            <span class="spf-pack-note">${esc(option.note)}</span>
          </span>
        </label>`,
      )
      .join("");
  }

  function packName() {
    if (pack === "unsure") return t("spf_pack_unsure");
    const tier = tiers.find((item) => item.id === pack);
    return tier ? tr(tier.name) : "";
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
    if (rule === "required" && !value) error = "err_required";
    else if (rule === "name" && value.length < 3) error = "err_name";
    else if (rule === "email" && !EMAIL_PATTERN.test(value)) error = "err_email";
    else if (rule === "phone" && !isValidPhone(value)) error = "err_phone";
    showError(input, error);
    return !error;
  }

  function validateDetails() {
    const fields = [...form.querySelectorAll('[data-step="3"] [data-rule]')];
    let firstBad = null;
    fields.forEach((input) => {
      if (!checkField(input) && !firstBad) firstBad = input;
    });
    if (firstBad) firstBad.focus();
    return !firstBad;
  }

  // ---------- Steps ----------
  // quiet: the first render, which must not scroll the page or steal focus.
  function goTo(target, quiet = false) {
    const sequence = activeSteps();
    step = sequence.includes(target) ? target : sequence[0];
    card.closest(".reg").classList.toggle("spf-show-plan", kind === "sponsor" && step === 1);
    const position = sequence.indexOf(step);
    steps.forEach((panel) => {
      panel.hidden = Number(panel.dataset.step) !== step;
    });
    stepperItems.forEach((item, index) => {
      const number = index + 1;
      item.hidden = !sequence.includes(number);
      item.classList.toggle("is-active", number === step);
      item.classList.toggle("is-done", sequence.indexOf(number) < position && !item.hidden);
      item.querySelector(".st-dot").textContent = String(sequence.indexOf(number) + 1);
      if (number === step) item.setAttribute("aria-current", "step");
      else item.removeAttribute("aria-current");
    });
    $("spfStepper").style.setProperty("--spf-step-count", sequence.length);
    $("spfBar").style.width = `${(position / (sequence.length - 1)) * 100}%`;
    backBtn.classList.toggle("is-hidden", step === 1);
    nextBtn.hidden = step === COMPANY_STEP;
    nextBtn.disabled = step === 1 ? !kind : step === 2 ? !pack : false;
    submitBtn.hidden = step !== COMPANY_STEP;
    $("spfCount").textContent = t("step_of").replace("{n}", position + 1).replace("{total}", sequence.length);
    if (step === COMPANY_STEP) renderReview();
    if (quiet) return;
    steps[step - 1].querySelector("h2")?.focus({ preventScroll: true });
    card.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  // ---------- Review ----------
  function answers() {
    return {
      kind: kind === "booth" ? t("spf_kind_booth") : t("spf_kind_spon"),
      pack: kind === "booth" ? "" : packName(),
      company: $("s_company").value.trim(),
      contact: $("s_contact").value.trim(),
      role: $("s_role").value.trim(),
      phone: $("s_phone").value.trim(),
      email: $("s_email").value.trim(),
      website: $("s_website").value.trim(),
      city: $("s_city").value.trim(),
      note: $("s_note").value.trim(),
    };
  }

  function reviewRows() {
    const a = answers();
    return [
      [t("spf_rv_kind"), a.kind],
      [t("spf_rv_pack"), a.pack],
      [t("spf_f_company"), a.company],
      [t("spf_f_contact"), a.role ? `${a.contact}, ${a.role}` : a.contact],
      [t("f_phone"), a.phone],
      [t("f_email"), a.email],
      [t("spf_f_website"), a.website],
      [t("spf_f_city"), a.city],
      [t("spf_f_note"), a.note],
    ].filter(([, value]) => value);
  }

  function renderReview() {
    $("spfReview").innerHTML = reviewRows()
      .map(([label, value]) => `<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`)
      .join("");
  }

  function esc(text) {
    return text.replace(/[&<>"]/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[character]);
  }

  // ---------- Sending ----------
  function reference() {
    const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    const bytes = crypto.getRandomValues(new Uint8Array(5));
    return `SPN26-${[...bytes].map((byte) => alphabet[byte % alphabet.length]).join("")}`;
  }

  // The whole request as plain text, for WhatsApp and for email.
  function asMessage(ref) {
    const lines = [`${t("spf_msg_head")}: ${ref}`, ""];
    reviewRows().forEach(([label, value]) => lines.push(`${label}: ${value}`));
    return lines.join("\n");
  }

  // What the server receives: ids, not the translated names shown on screen.
  function payload() {
    return {
      lang: getLang(),
      kind,
      package: kind === "booth" ? "" : pack,
      company: $("s_company").value.trim(),
      contact: $("s_contact").value.trim(),
      role: $("s_role").value.trim(),
      phone: $("s_phone").value.trim(),
      email: $("s_email").value.trim(),
      website: $("s_website").value.trim(),
      city: $("s_city").value.trim(),
      note: $("s_note").value.trim(),
      hp: $("spfTrap")?.value || "",
    };
  }

  // Saves on the server. Returns the reference, null when there is no server,
  // or false when the server found a problem it has already shown.
  async function saveOnServer() {
    const body = JSON.stringify(payload());
    let response;
    try {
      response = await fetch("api/sponsor.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body,
      });
    } catch {
      return null;                      // offline or no server: use WhatsApp / email
    }
    let answer;
    try {
      answer = await response.json();
    } catch {
      return null;                      // not our server (e.g. GitHub Pages): use WhatsApp / email
    }
    if (answer.ok && answer.ref) return answer.ref;
    const input = answer.field ? $(answer.field) : null;
    if (input) {
      goTo(Number(input.closest(".reg-step")?.dataset.step) || COMPANY_STEP);
      showError(input, answer.error);
      input.focus();
      return false;
    }
    if (answer.error === "backend_missing") return null;
    $("spfOkText").textContent = "";
    alertLine(answer.error || "err_server");
    return false;
  }

  function alertLine(key) {
    let line = $("spfServerError");
    if (!line) {
      line = document.createElement("p");
      line.className = "f-error";
      line.id = "spfServerError";
      submitBtn.closest(".reg-nav").insertAdjacentElement("beforebegin", line);
    }
    line.dataset.key = key;
    line.textContent = t(key);
    line.hidden = false;
  }

  let sending = false;
  async function send(event) {
    event.preventDefault();
    if (step !== COMPANY_STEP) {
      nextBtn.click();
      return;
    }
    if (!validateDetails() || sending) return;
    sending = true;
    submitBtn.disabled = true;
    $("spfServerError")?.setAttribute("hidden", "");

    const saved = await saveOnServer();
    sending = false;
    submitBtn.disabled = false;
    if (saved === false) return;

    const ref = saved || reference();
    $("spfOkText").textContent = t("spf_ok_text").replace("{email}", answers().email);
    $("spfOkRef").textContent = ref;

    // Saved on the server: the team already has it, so no extra step.
    // No server: one more tap sends it by WhatsApp or email.
    $("spfSend").hidden = Boolean(saved);
    if (!saved) {
      const message = asMessage(ref);
      const phone = String(enquiry.whatsapp || "").replace(/\D/g, "");
      const email = enquiry.email || "ismile@italk.krd";
      const whats = $("spfWhats");
      whats.hidden = !phone;
      if (phone) whats.href = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
      $("spfMail").href = `mailto:${email}?subject=${encodeURIComponent(`${t("spf_msg_head")}: ${ref}`)}&body=${encodeURIComponent(message)}`;
    }

    form.hidden = true;
    progress.hidden = true;
    success.hidden = false;
    card.scrollIntoView({ behavior: "smooth", block: "start" });
    success.focus({ preventScroll: true });
  }

  // ---------- Wiring ----------
  form.addEventListener("change", (event) => {
    const input = event.target;
    if (input.name === "spfKind") {
      kind = input.value;
      form.querySelectorAll('input[name="spfKind"]').forEach((radio) => {
        radio.closest("label").classList.toggle("is-checked", radio.checked);
      });
      sponsorMap.hidden = kind !== "sponsor";
      goTo(1, true);
    }
    if (input.name === "spfPack") {
      pack = input.value;
      packBox.querySelectorAll("label").forEach((label) => {
        label.classList.toggle("is-checked", label.querySelector("input").checked);
      });
      nextBtn.disabled = false;
    }
  });

  form.addEventListener("blur", (event) => {
    if (event.target.dataset?.rule) checkField(event.target);
  }, true);

  form.addEventListener("input", (event) => {
    const input = event.target;
    if (input.dataset?.rule && input.getAttribute("aria-invalid")) checkField(input);
  });

  nextBtn.addEventListener("click", () => {
    if (nextBtn.disabled) return;
    const sequence = activeSteps();
    goTo(sequence[sequence.indexOf(step) + 1]);
  });
  backBtn.addEventListener("click", () => {
    const sequence = activeSteps();
    goTo(sequence[sequence.indexOf(step) - 1]);
  });
  $("spfEdit").addEventListener("click", () => {
    goTo(kind === "sponsor" ? 2 : 1);
  });
  form.addEventListener("submit", send);

  // The direct contact line in the side column follows the same settings.
  const direct = $("spfDirect");
  if (direct && enquiry.email) {
    direct.textContent = enquiry.email;
    direct.href = `mailto:${enquiry.email}`;
  }

  // ---------- Start ----------
  sponsorMap.hidden = kind !== "sponsor";
  form.querySelectorAll('input[name="spfKind"]').forEach((radio) => {
    radio.checked = radio.value === kind;
    radio.closest("label").classList.toggle("is-checked", radio.checked);
  });
  renderPacks();
  goTo(1, true);

  onLangChange(() => {
    renderPacks();
    goTo(step, true);
    // Error messages already on screen follow the language too.
    form.querySelectorAll(".f-error[data-key]").forEach((message) => {
      message.textContent = t(message.dataset.key);
    });
  });
}
