// The sponsorship and exhibition-booth request form (sponsor.html).
//
// Two steps: what the company wants, then who they are. The packages come from
// data/sponsors.json, so a tier renamed in the admin is renamed here too.
//
// Where the request goes: to the server (api/sponsor.php), which saves it for
// the admin and emails the company and the team. Where there is no server (the
// GitHub Pages copy), the last screen hands the filled-in request to WhatsApp
// or email instead, already written out, so a request is never lost.
import { t, tr, getLang, onLangChange } from "../i18n.js?v=74";
import { isValidPhone } from "./registration.js?v=74";

const TOTAL_STEPS = 2;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export function initSponsorForm({ tiers = [], enquiry = {} } = {}) {
  const $ = (id) => document.getElementById(id);
  const card = $("spfCard");
  const form = $("spfForm");
  const progress = $("spfProgress");
  const stepperItems = [...$("spfStepper").children];
  const steps = [...form.querySelectorAll(".reg-step")];
  const packBox = $("spfPacks");
  const packWrap = $("spfPackWrap");
  const backBtn = $("spfBack");
  const nextBtn = $("spfNext");
  const submitBtn = $("spfSubmit");
  const success = $("spfSuccess");

  let step = 1;
  let kind = "booth" === startKind() ? "booth" : "sponsor";
  let pack = "";

  // "Ask about booths" links to sponsor.html#booth, so the visitor lands on the
  // choice they already made rather than making it twice.
  function startKind() {
    return location.hash.replace("#", "").toLowerCase();
  }

  // ---------- The package cards ----------
  // The tiers sit in an even grid. "Not sure yet" is not a package, so it gets
  // a row of its own underneath and a quieter shape.
  function renderPacks() {
    const options = [
      ...tiers.map((tier) => ({ id: tier.id, name: tr(tier.name), note: tr(tier.subtitle), className: tier.className })),
      { id: "unsure", name: t("spf_pack_unsure"), note: t("spf_pack_unsure_d"), className: "spf-pack-any" },
    ];
    if (!pack) pack = options[0].id;

    packBox.innerHTML = options
      .map(
        (option) => `
        <label class="spf-pack ${option.className}${option.id === pack ? " is-checked" : ""}">
          <input type="radio" name="spfPack" value="${option.id}"${option.id === pack ? " checked" : ""}>
          <span class="spf-pack-text">
            <span class="spf-pack-name">${option.name}</span>
            <span class="spf-pack-note">${option.note}</span>
          </span>
          <span class="tc-check" aria-hidden="true"></span>
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
    const fields = [...form.querySelectorAll('[data-step="2"] [data-rule]')];
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
    step = Math.min(Math.max(target, 1), TOTAL_STEPS);
    steps.forEach((panel) => {
      panel.hidden = Number(panel.dataset.step) !== step;
    });
    stepperItems.forEach((item, index) => {
      item.classList.toggle("is-active", index === step - 1);
      item.classList.toggle("is-done", index < step - 1);
    });
    $("spfBar").style.width = `${((step - 1) / (TOTAL_STEPS - 1)) * 100}%`;
    backBtn.classList.toggle("is-hidden", step === 1);
    nextBtn.hidden = step === TOTAL_STEPS;
    submitBtn.hidden = step !== TOTAL_STEPS;
    $("spfCount").textContent = t("step_of").replace("{n}", step).replace("{total}", TOTAL_STEPS);
    if (step === TOTAL_STEPS) renderReview();
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
      hp: $("spfHp")?.value || "",
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
      goTo(2);
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
    if (step < TOTAL_STEPS) {
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
      const email = enquiry.email || "info@ismile.krd";
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
      packWrap.hidden = kind === "booth";
    }
    if (input.name === "spfPack") {
      pack = input.value;
      packBox.querySelectorAll("label").forEach((label) => {
        label.classList.toggle("is-checked", label.querySelector("input").checked);
      });
    }
  });

  form.addEventListener("blur", (event) => {
    if (event.target.dataset?.rule) checkField(event.target);
  }, true);

  form.addEventListener("input", (event) => {
    const input = event.target;
    if (input.dataset?.rule && input.getAttribute("aria-invalid")) checkField(input);
  });

  nextBtn.addEventListener("click", () => goTo(step + 1));
  backBtn.addEventListener("click", () => goTo(step - 1));
  $("spfEdit").addEventListener("click", () => {
    goTo(2);
    $("s_company").focus();
  });
  form.addEventListener("submit", send);

  // The direct contact line in the side column follows the same settings.
  const direct = $("spfDirect");
  if (direct && enquiry.email) {
    direct.textContent = enquiry.email;
    direct.href = `mailto:${enquiry.email}`;
  }

  // ---------- Start ----------
  if (kind === "booth") {
    const radio = form.querySelector('input[name="spfKind"][value="booth"]');
    if (radio) {
      radio.checked = true;
      form.querySelectorAll('input[name="spfKind"]').forEach((item) => {
        item.closest("label").classList.toggle("is-checked", item.checked);
      });
      packWrap.hidden = true;
    }
  }
  renderPacks();
  goTo(1, true);

  onLangChange(() => {
    renderPacks();
    $("spfCount").textContent = t("step_of").replace("{n}", step).replace("{total}", TOTAL_STEPS);
    if (step === TOTAL_STEPS) renderReview();
    // Error messages already on screen follow the language too.
    form.querySelectorAll(".f-error[data-key]").forEach((message) => {
      message.textContent = t(message.dataset.key);
    });
  });
}
