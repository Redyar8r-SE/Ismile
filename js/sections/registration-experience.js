import { t, onLangChange } from "../i18n.js?v=95";

// Presentation and VIP lunch choices; validation and submission stay in registration.js.
export function initRegistrationExperience({ tickets, onLunchChange, getStep }) {
  const stage = document.getElementById("experienceStage");
  if (!stage) return null;
  const form = document.getElementById("regForm");
  const vipPanel = document.getElementById("vipExperience");
  const motion = matchMedia("(prefers-reduced-motion: reduce)");
  const $ = (id) => document.getElementById(id);
  const ticket = () => form.querySelector('[name="ticket"]:checked')?.value || "professional";
  let includedDay = "day1";
  let extraLunch = false;
  let availability = { day1: true, day2: true };
  let openingNodes = [];
  let openingTimer;
  let celebrationTimer;
  let observer;
  let opened = false;
  let previousTier = ticket();
  let standardLunch = [...form.querySelectorAll('[name="lunch"]')].filter((input) => input.checked).map((input) => input.value);

  form.querySelector(".ticket-toggle").closest(".f").hidden = true;
  function finishOpening() {
    clearTimeout(openingTimer);
    openingNodes.forEach((node) => {
      node.classList.remove("opening-reveal");
      node.style.removeProperty("--opening-delay");
    });
    openingNodes = [];
  }
  function stopCelebration() {
    clearTimeout(celebrationTimer);
    stage.classList.remove("celebrate");
    vipPanel.classList.remove("celebrate");
  }
  function celebrate() {
    stopCelebration();
    if (ticket() !== "vip" || motion.matches) return;
    void vipPanel.offsetWidth;
    stage.classList.add("celebrate");
    vipPanel.classList.add("celebrate");
    celebrationTimer = setTimeout(stopCelebration, 2650);
  }
  function reveal() {
    opened = true;
    observer?.disconnect();
    finishOpening();
    if (motion.matches || form.hidden) return;
    const cues = [];
    const cue = (node, delay) => {
      if (node && !node.hidden && node.getClientRects().length) cues.push([node, delay]);
    };
    if (ticket() === "vip") celebrate();
    else {
      [".xp-eyebrow", "h2", ".standard-intro", "h3", ".how-steps li", ".standard-support"].forEach((selector, group) => {
        $("standardExperience").querySelectorAll(selector).forEach((node, index) => cue(node, group * 110 + index * 90));
      });
    }
    cue($("regProgress"), 70);
    const step = form.querySelector(`.reg-step[data-step="${getStep()}"]`);
    cue(step.querySelector(".reg-person-intro"), 140);
    cue([...step.querySelectorAll(".step-head")].find((node) => node.getClientRects().length), 230);
    cue(step.querySelector(".cert-note"), 340);
    const fields = [...step.querySelectorAll(".fields > .f")].filter((node) => !node.hidden && node.getClientRects().length);
    const rows = [...new Set(fields.map((node) => Math.round(node.offsetTop)))].sort((a, b) => a - b);
    fields.forEach((node) => cue(node, 430 + rows.indexOf(Math.round(node.offsetTop)) * 85));
    cue(form.querySelector(".reg-nav"), 650);
    cues.forEach(([node, delay]) => {
      node.style.setProperty("--opening-delay", `${delay}ms`);
      node.classList.add("opening-reveal");
    });
    openingNodes = cues.map(([node]) => node);
    openingTimer = setTimeout(finishOpening, Math.max(0, ...cues.map(([, delay]) => delay)) + 1150);
  }
  function updateExtras() {
    const other = includedDay === "day1" ? "day2" : "day1";
    const price = `$${Number(tickets[other === "day1" ? "lunchDay1" : "lunchDay2"] ?? 42)}`;
    $("extraLunchTitle").textContent = t(`xp_add_${other}`);
    $("extraLunchDescription").textContent = t(`xp_${other}_full`);
    $("extraLunchPrice").textContent = price;
    $("extraLunch").setAttribute("aria-label", t("xp_add_lunch_label").replace("{title}", t(`xp_add_${other}`)).replace("{price}", `\u2068${price}\u2069`));
    $("extraLunch").disabled = !availability[other];
    $("extraLunchStatus").textContent = t(!availability[other] ? "xp_unavailable" : extraLunch ? "xp_added" : "xp_add_package");
  }
  function syncLunch() {
    if (ticket() === "vip") {
      ["day1", "day2"].forEach((day) => {
        form.querySelector(`[name="lunch"][value="${day}"]`).checked = day === includedDay || extraLunch;
      });
      onLunchChange();
    }
    updateExtras();
  }
  function renderTier(animate = false) {
    const tier = ticket(), vip = tier === "vip", student = tier === "student";
    if (previousTier !== tier) {
      if (vip) standardLunch = [...form.querySelectorAll('[name="lunch"]')].filter((input) => input.checked).map((input) => input.value);
      else if (previousTier === "vip") {
        form.querySelectorAll('[name="lunch"]').forEach((input) => { input.checked = !input.disabled && standardLunch.includes(input.value); });
        onLunchChange();
      }
      previousTier = tier;
    }
    stage.classList.toggle("is-vip", vip);
    stage.dataset.tier = tier;
    document.querySelector(`[name="experience"][value="${tier}"]`).checked = true;
    vipPanel.hidden = !vip;
    $("standardExperience").hidden = vip;
    $("standardTitle").textContent = t(student ? "xp_student_heading" : "xp_professional_heading");
    $("standardCopy").textContent = t(student ? "xp_student_copy" : "xp_professional_copy");
    $("standardLunch").hidden = vip;
    $("vipExtras").hidden = !vip;
    $("extrasStepLabel").textContent = t(vip ? "xp_vip_extras_step" : "st_lunch");
    const intro = form.querySelector(".reg-person-intro");
    intro.querySelector("b").textContent = t(vip ? "xp_vip_intro" : student ? "xp_student_intro" : "mode_person");
    intro.querySelector("small").textContent = t(vip ? "xp_vip_intro_copy" : student ? "xp_student_intro_copy" : "mode_person_d");
    intro.querySelector(".tc-ic").innerHTML = document.querySelector(`[data-choice="${tier}"] .choice-icon`).innerHTML;
    const heading = form.querySelector('.reg-step[data-step="1"] .step-head');
    heading.querySelector("h3").textContent = t(vip ? "xp_vip_details_title" : "s2_title_p");
    heading.querySelector("p").textContent = t(vip ? "xp_vip_details_copy" : "s2_sub");
    $("reviewTier").textContent = t(vip ? "xp_review_vip" : student ? "xp_student" : "xp_professional");
    $("reviewPrice").textContent = vip ? `$${tickets.vipUSD ?? tickets.vip ?? 100}` : `${Number(tickets[tier] || 0).toLocaleString("en-US")} ${t("cur_iqd")}`;
    syncLunch();
    stopCelebration();
    if (animate) reveal();
  }
  document.querySelectorAll('[name="experience"]').forEach((input) => input.addEventListener("change", () => {
    const selected = input.value === "professional" && $("p_spec").value === "student" ? "student" : input.value;
    const radio = form.querySelector(`[name="ticket"][value="${selected}"]`);
    radio.checked = true;
    radio.dispatchEvent(new Event("change", { bubbles: true }));
    renderTier(true);
  }));
  form.querySelectorAll('[name="includedLunch"]').forEach((input) => input.addEventListener("change", () => {
    includedDay = input.value;
    syncLunch();
  }));
  $("extraLunch").addEventListener("change", (event) => { extraLunch = event.target.checked; syncLunch(); });
  $("toggleBenefits").addEventListener("click", () => {
    const open = vipPanel.classList.toggle("benefits-open");
    $("toggleBenefits").setAttribute("aria-expanded", String(open));
    $("toggleBenefits").querySelector("[data-i18n]").textContent = t(open ? "xp_hide_benefits" : "xp_show_benefits");
    $("toggleBenefits").lastElementChild.textContent = open ? "−" : "＋";
  });
  motion.addEventListener("change", () => { if (motion.matches) { finishOpening(); stopCelebration(); } });
  onLangChange(() => {
    renderTier();
    $("toggleBenefits").querySelector("[data-i18n]").textContent = t(vipPanel.classList.contains("benefits-open") ? "xp_hide_benefits" : "xp_show_benefits");
  });
  renderTier();
  return {
    syncTier: renderTier,
    beforeStep: finishOpening,
    includedDay: () => includedDay,
    availability(data) {
      availability = { day1: data?.lunch?.day1 !== false, day2: data?.lunch?.day2 !== false };
      if (!availability[includedDay]) includedDay = availability.day1 ? "day1" : "day2";
      const other = includedDay === "day1" ? "day2" : "day1";
      if (!availability[other]) extraLunch = false;
      $("extraLunch").checked = extraLunch;
      form.querySelectorAll('[name="includedLunch"]').forEach((input) => {
        input.checked = input.value === includedDay;
        input.disabled = !availability[input.value];
        input.closest("label").classList.toggle("is-unavailable", input.disabled);
      });
      const vipInput = document.querySelector('[name="experience"][value="vip"]');
      vipInput.disabled = data?.open !== true || Number(data?.prices?.vip || 0) <= 0 || (!availability.day1 && !availability.day2);
      document.querySelectorAll('[name="experience"]').forEach((input) => { if (input.value !== "vip") input.disabled = data?.open !== true; });
      syncLunch();
      if (!opened && data?.open && "IntersectionObserver" in window && !motion.matches) {
        document.fonts.ready.then(() => {
          if (opened) return;
          observer = new IntersectionObserver((entries) => { if (entries.some((entry) => entry.isIntersecting)) reveal(); }, { threshold: 0.04, rootMargin: "0px 0px -24px 0px" });
          observer.observe(document.querySelector(".experience-grid"));
        });
      }
    },
  };
}
