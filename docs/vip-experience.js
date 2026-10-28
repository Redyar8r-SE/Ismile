import {t, getLang, onLangChange} from '../js/i18n.js?v=93';

(() => {
  const config = JSON.parse(document.getElementById("experienceConfig").textContent);
  const stage = document.getElementById("experienceStage");
  const form = document.getElementById("regForm");
  const vipPanel = document.getElementById("vipExperience");
  const intro = form.querySelector(".reg-person-intro");
  const originalIcon = intro.querySelector(".tc-ic").innerHTML;
  const originalTitle = intro.querySelector("b").textContent;
  const originalCopy = intro.querySelector("small").textContent;
  const detailsHeading = form.querySelector('[data-step="1"] .step-head h3');
  const detailsDescription = form.querySelector('[data-step="1"] .step-head p');
  const defaultDescription = detailsDescription.textContent;
  const error = document.getElementById("experienceError");
  const next = document.getElementById("experienceNext");
  const back = document.getElementById("experienceBack");
  const payment = document.getElementById("experiencePayment");
  const motion = matchMedia("(prefers-reduced-motion: reduce)");
  let celebrateTimer;
  let openingTimer;
  let openingObserver;
  let openingStarted = false;
  let openingNodes = [];
  const dayLabel = (day) => t(`xp_${day}_full`);
  const isolate = (value) => `\u2068${value}\u2069`;
  const state = { tier: "professional", step: 1, includedDay: "day1", extraLunch: false };
  const originalRequired = ["p_first", "p_second", "p_third", "p_phone", "p_email", "p_city", "p_gender", "p_age", "p_spec"];
  form.querySelector(".ticket-toggle").closest(".f").hidden = true;
  form.querySelectorAll(".lunch-price").forEach((node) => { node.textContent = `$${config.lunchPrice}`; });
  form.addEventListener("submit", (event) => event.preventDefault());

  function extraDay() { return state.includedDay === "day1" ? "day2" : "day1"; }
  function extraPrice() { return config.lunchPrice; }
  function clearError() {
    error.hidden = true;
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute("aria-invalid"));
  }
  function finishOpening() {
    clearTimeout(openingTimer);
    openingNodes.forEach((node) => {
      node.classList.remove("opening-reveal");
      node.style.removeProperty("--opening-delay");
    });
    openingNodes = [];
  }
  function revealRegistration() {
    openingStarted = true;
    openingObserver?.disconnect();
    finishOpening();
    if (motion.matches) return;
    const cues = [];
    const cue = (node, delay) => {
      if (node && !node.hidden && node.getClientRects().length) cues.push([node, delay]);
    };
    const panel = document.getElementById("standardExperience");
    if (!panel.hidden) {
      [".xp-eyebrow", "h2", ".standard-intro", "h3", ".how-steps li", ".standard-support"].forEach((selector, group) => {
        panel.querySelectorAll(selector).forEach((node, index) => cue(node, group * 110 + index * 90));
      });
    } else celebrate();
    cue(document.querySelector(".reg-progress"), 70);
    const activeStep = form.querySelector(`.reg-step[data-step="${state.step}"]`);
    cue(activeStep.querySelector(".reg-person-intro"), 140);
    cue(activeStep.querySelector(".step-head"), 230);
    cue(activeStep.querySelector(".cert-note"), 340);
    const fields = [...activeStep.querySelectorAll(".fields > .f")].filter((node) => !node.hidden && node.getClientRects().length);
    const rows = [...new Set(fields.map((node) => Math.round(node.offsetTop)))].sort((a, b) => a - b);
    fields.forEach((node) => cue(node, 430 + rows.indexOf(Math.round(node.offsetTop)) * 85));
    if (state.step !== 1) {
      activeStep.querySelectorAll(".included-lunch, .extra-lunch-card, .badge-delivery, .vip-extras-note, .lunch-options, .experience-review, .pay-options").forEach((node, index) => cue(node, 340 + index * 100));
    }
    cue(form.querySelector(".reg-nav"), 650);
    cues.forEach(([node, delay]) => {
      node.style.setProperty("--opening-delay", `${delay}ms`);
      node.classList.add("opening-reveal");
    });
    openingNodes = cues.map(([node]) => node);
    openingTimer = setTimeout(finishOpening, Math.max(0, ...cues.map(([, delay]) => delay)) + 1150);
  }
  motion.addEventListener("change", () => {
    if (!motion.matches) return;
    finishOpening();
    clearTimeout(celebrateTimer);
    stage.classList.remove("celebrate");
    vipPanel.classList.remove("celebrate");
  });
  function celebrate() {
    clearTimeout(celebrateTimer);
    stage.classList.remove("celebrate");
    vipPanel.classList.remove("celebrate");
    if (state.tier !== "vip" || motion.matches) return;
    void vipPanel.offsetWidth;
    stage.classList.add("celebrate");
    vipPanel.classList.add("celebrate");
    celebrateTimer = setTimeout(() => {
      stage.classList.remove("celebrate");
      vipPanel.classList.remove("celebrate");
    }, 2650);
  }
  function replayLogo() {
    if (motion.matches) return;
    const layers = document.querySelectorAll(".hero-mark, .hero-mark img, .wordmark span");
    layers.forEach((node) => { node.style.animation = "none"; });
    void document.querySelector(".hero-brand").offsetWidth;
    layers.forEach((node) => { node.style.animation = ""; });
  }
  function updateExtras() {
    const other = extraDay();
    const title = t(`xp_add_${other}`);
    document.getElementById("extraLunchTitle").textContent = title;
    document.getElementById("extraLunchDescription").textContent = dayLabel(other);
    document.getElementById("extraLunchPrice").textContent = `$${extraPrice()}`;
    document.getElementById("extraLunch").setAttribute("aria-label", t('xp_add_lunch_label').replace('{title}', title).replace('{price}', isolate(`$${extraPrice()}`)));
    document.getElementById("extraLunchStatus").textContent = t(state.extraLunch ? 'xp_added' : 'xp_add_package');
  }
  function selectTier(tier, animate = true) {
    if (!["student", "professional", "vip"].includes(tier)) return;
    state.tier = tier;
    const vip = tier === "vip";
    const student = tier === "student";
    stage.classList.toggle("is-vip", vip);
    stage.dataset.tier = tier;
    document.querySelector(`input[name="experience"][value="${tier}"]`).checked = true;
    vipPanel.hidden = !vip;
    document.getElementById("standardExperience").hidden = vip;
    document.getElementById("standardTitle").textContent = t(student ? 'xp_student_heading' : 'xp_professional_heading');
    document.getElementById("standardCopy").textContent = t(student ? 'xp_student_copy' : 'xp_professional_copy');
    document.getElementById("standardLunch").hidden = vip;
    document.getElementById("vipExtras").hidden = !vip;
    document.getElementById("extrasStepLabel").textContent = t(vip ? 'xp_vip_extras_step' : 'st_lunch');
    document.getElementById("uniWrap").hidden = !student;
    document.getElementById("ambassadorWrap").hidden = !student;
    document.getElementById("p_ambassador").disabled = !student;
    document.getElementById("studentIdWrap").hidden = !student;
    document.getElementById("p_student_id").disabled = !student;
    intro.querySelector("b").textContent = t(vip ? 'xp_vip_intro' : student ? 'xp_student_intro' : 'mode_person');
    intro.querySelector("small").textContent = t(vip ? 'xp_vip_intro_copy' : student ? 'xp_student_intro_copy' : 'mode_person_d');
    intro.querySelector(".tc-ic").innerHTML = vip ? document.querySelector('[data-choice="vip"] .choice-icon').innerHTML : originalIcon;
    detailsHeading.textContent = t(vip ? 'xp_vip_details_title' : 's2_title_p');
    detailsDescription.textContent = t(vip ? 'xp_vip_details_copy' : 's2_sub');
    clearError();
    showStep(state.step, false);
    if (animate) revealRegistration();
    if (!vip || !animate) { stage.classList.remove("celebrate"); vipPanel.classList.remove("celebrate"); }
  }
  function validate() {
    clearError();
    let missing = null;
    if (state.step === 1) {
      const required = [...originalRequired];
      if (state.tier === "student") required.push("p_uni");
      for (const id of required) {
        const field = document.getElementById(id);
        const phoneDigits = field.value.replace(/\D/g, "");
        const valid = field.value.trim() && (id !== "p_email" || field.checkValidity()) && (id !== "p_age" || (Number(field.value) >= 16 && Number(field.value) <= 120)) && (id !== "p_phone" || (phoneDigits.length >= 7 && phoneDigits.length <= 15));
        if (!valid) { missing = field; break; }
      }
    }
    if (missing) {
      missing.setAttribute("aria-invalid", "true");
      error.textContent = t('xp_complete_field');
      error.hidden = false;
      missing.focus();
      return false;
    }
    return true;
  }
  function addReviewRow(label, value) {
    const row = document.createElement("div");
    const term = document.createElement("dt");
    const description = document.createElement("dd");
    term.textContent = label;
    description.textContent = value;
    description.dir = 'auto';
    row.append(term, description);
    document.getElementById("experienceReview").append(row);
  }
  function buildReview() {
    const vip = state.tier === "vip";
    const selectedLunches = [...form.querySelectorAll('input[name="lunch"]:checked')];
    const lunchTotal = selectedLunches.length * config.lunchPrice;
    const label = t(vip ? 'xp_review_vip' : state.tier === 'student' ? 'xp_student' : 'xp_professional');
    const price = vip ? `$${config.vipPrice}` : `${(state.tier === "student" ? config.studentPrice : config.professionalPrice).toLocaleString("en-US")} IQD`;
    document.getElementById("reviewTier").textContent = label;
    document.getElementById("reviewPrice").textContent = price;
    document.getElementById("experienceReview").replaceChildren();
    addReviewRow(t('xp_review_name'), ["p_first", "p_second", "p_third"].map((id) => document.getElementById(id).value).join(" "));
    addReviewRow(t('f_email'), document.getElementById("p_email").value);
    addReviewRow(t('f_phone_number'), document.getElementById("p_phone").value);
    if (vip) {
      addReviewRow(t('xp_review_included'), `${dayLabel(state.includedDay)} · ${t('xp_included')}`);
      if (state.extraLunch) addReviewRow(t('xp_review_extra'), `${dayLabel(extraDay())} · ${isolate(`$${extraPrice()}`)}`);
      addReviewRow(t('xp_review_followup'), t('xp_review_call').replace('{phone}', isolate(document.getElementById("p_phone").value)));
      addReviewRow(t('xp_review_workshops'), t('xp_review_workshops_copy'));
    } else {
      const ambassadorCode = document.getElementById("p_ambassador").value.trim();
      if (state.tier === "student" && ambassadorCode) addReviewRow(t('f_ambassador'), ambassadorCode);
      addReviewRow(t('rv_lunch'), selectedLunches.length ? selectedLunches.map((input) => dayLabel(input.value)).join(" + ") : t('rv_lunch_none'));
      if (selectedLunches.length) addReviewRow(t('xp_lunch_total'), `$${lunchTotal}`);
    }
    document.getElementById("reviewTotal").textContent = vip ? `$${config.vipPrice + (state.extraLunch ? extraPrice() : 0)}` : lunchTotal ? `${price} + $${lunchTotal}` : price;
    payment.disabled = false;
  }
  function showStep(step, focus = true) {
    finishOpening();
    state.step = step;
    form.querySelectorAll(".reg-step").forEach((node) => { node.hidden = Number(node.dataset.step) !== step; });
    document.querySelectorAll("#experienceStepper li").forEach((node, index) => {
      node.classList.toggle("is-active", index + 1 === step);
      node.classList.toggle("is-done", index + 1 < step);
      if (index + 1 === step) node.setAttribute("aria-current", "step"); else node.removeAttribute("aria-current");
    });
    document.getElementById("experienceBar").style.width = `${step / 3 * 100}%`;
    document.getElementById("experienceCount").textContent = t('step_of').replace('{n}', step).replace('{total}', 3);
    back.classList.toggle("is-hidden", step === 1);
    next.hidden = step === 3;
    payment.hidden = step !== 3;
    if (step === 3) buildReview();
    if (focus) {
      const heading = step === 2 && state.tier === "vip" ? document.querySelector("#vipExtras .step-head h3") : form.querySelector(`.reg-step[data-step="${step}"] h3`);
      heading.focus({ preventScroll: true });
    }
  }
  document.querySelectorAll('input[name="experience"]').forEach((input) => input.addEventListener("change", () => selectTier(input.value)));
  document.querySelectorAll('input[name="includedLunch"]').forEach((input) => input.addEventListener("change", () => { state.includedDay = input.value; updateExtras(); }));
  document.getElementById("extraLunch").addEventListener("change", (event) => { state.extraLunch = event.target.checked; updateExtras(); });
  next.addEventListener("click", () => { if (validate()) { showStep(state.step + 1); document.getElementById("regCard").scrollIntoView({ behavior: motion.matches ? "instant" : "smooth", block: "start" }); } });
  back.addEventListener("click", () => { clearError(); showStep(Math.max(1, state.step - 1)); });
  document.getElementById("editExperience").addEventListener("click", () => showStep(1));
  document.getElementById("replayExperience").addEventListener("click", () => selectTier("vip"));
  document.getElementById("replayLogo").addEventListener("click", replayLogo);
  document.getElementById("toggleBenefits").addEventListener("click", (event) => {
    const open = vipPanel.classList.toggle("benefits-open");
    event.currentTarget.setAttribute("aria-expanded", String(open));
    event.currentTarget.querySelector('[data-i18n]').textContent = t(open ? 'xp_hide_benefits' : 'xp_show_benefits');
    event.currentTarget.lastElementChild.textContent = open ? "−" : "＋";
  });
  document.querySelectorAll('[data-age-step]').forEach((button) => button.addEventListener("click", () => {
    const age = document.getElementById("p_age");
    age.value = Math.min(120, Math.max(16, (Number(age.value) || 28) + Number(button.dataset.ageStep)));
  }));
  form.querySelectorAll('input[name="pay"]').forEach((input) => input.addEventListener("change", () => {
    form.querySelectorAll(".pay-options .pay-card").forEach((label) => label.classList.toggle("is-checked", label.querySelector("input").checked));
  }));
  form.querySelectorAll('input[name="lunch"]').forEach((input) => input.addEventListener("change", () => input.closest("label").classList.toggle("is-checked", input.checked)));
  function fillDemo() {
    const values = { p_first: "Rana", p_second: "Kamal", p_third: "Ahmed", p_phone: "07701234567", p_email: "rana@example.com", p_city: "Sulaimani", p_gender: "female", p_age: "28", p_spec: "gp", p_uni: "Sample College of Dentistry" };
    for (const [id, value] of Object.entries(values)) document.getElementById(id).value = value;
    clearError();
  }
  document.getElementById("fillDemo").addEventListener("click", fillDemo);
  payment.addEventListener("click", () => { if (!payment.disabled) document.getElementById("previewComplete").showModal(); });
  document.getElementById("closePreview").addEventListener("click", () => document.getElementById("previewComplete").close());
  const toTop = document.getElementById("toTop");
  function updateToTop() { toTop.classList.toggle("show", window.scrollY > window.innerHeight * 0.6); }
  window.addEventListener("scroll", updateToTop, { passive: true });
  toTop.addEventListener("click", () => { window.scrollTo({ top: 0, behavior: motion.matches ? "instant" : "smooth" }); });
  updateToTop();
  onLangChange(() => {
    selectTier(state.tier, false);
    updateExtras();
    const open = vipPanel.classList.contains('benefits-open');
    document.getElementById('toggleBenefits').querySelector('[data-i18n]').textContent = t(open ? 'xp_hide_benefits' : 'xp_show_benefits');
    if (!error.hidden) error.textContent = t('xp_complete_field');
  });
  window.vipExperiencePreview = { selectTier, fillDemo, showStep, celebrate, replayLogo, replayOpening: revealRegistration, getState: () => ({ ...state, extraDay: extraDay(), extraPrice: extraPrice() }) };
  const previewParams = new URLSearchParams(window.location.search);
  if (["light", "dark"].includes(previewParams.get("theme"))) document.documentElement.dataset.theme = previewParams.get("theme");
  updateExtras();
  selectTier(["student", "professional", "vip"].includes(previewParams.get("ticket")) ? previewParams.get("ticket") : "professional", false);
  document.fonts.ready.then(() => {
    document.body.dataset.experienceReady = "true";
    if (openingStarted || motion.matches || !('IntersectionObserver' in window)) return;
    openingObserver = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) revealRegistration();
    }, { threshold: 0.04, rootMargin: "0px 0px -24px 0px" });
    openingObserver.observe(document.querySelector(".experience-grid"));
  });
})();
