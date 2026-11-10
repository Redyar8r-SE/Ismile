import { getLang, tr, onLangChange } from "../i18n.js?v=148";

const IDS = ["simplified-direct-veneers", "crown-vs-veneer"];
const copy = {
  en: ["Choose one included workshop", "Your VIP package includes a seat in one of these workshops.", "Included with VIP", "View workshop details", "Choose this workshop", "Back to package", "Choose one available workshop before continuing.", "Currently unavailable", "Workshop details"],
  ar: ["اختر ورشة عمل واحدة مشمولة", "تشمل باقة VIP مقعداً في إحدى ورش العمل التالية.", "مشمولة مع VIP", "عرض تفاصيل الورشة", "اختر هذه الورشة", "العودة إلى الباقة", "اختر ورشة متاحة قبل المتابعة.", "غير متاحة حالياً", "تفاصيل ورشة العمل"],
  ku: ["یەک وۆرکشۆپی ناو پاکێجەکە هەڵبژێرە", "پاکێجی VIP کورسییەک لە یەکێک لەم وۆرکشۆپانە دەگرێتەوە.", "لەگەڵ VIP", "وردەکاریی وۆرکشۆپ", "ئەم وۆرکشۆپە هەڵبژێرە", "گەڕانەوە بۆ پاکێج", "پێش بەردەوامبوون وۆرکشۆپێکی بەردەست هەڵبژێرە.", "ئێستا بەردەست نییە", "وردەکاریی وۆرکشۆپ"]
};
const escape = value => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll('"', "&quot;");

export function initVipWorkshops() {
  const root = document.getElementById("vipWorkshopChoices");
  if (!root) return null;
  const form = document.getElementById("regForm");
  const dialog = document.getElementById("vipWorkshopDialog");
  let workshops = [], selected = "", details = null;
  const words = () => copy[getLang()] || copy.en;
  const date = w => new Intl.DateTimeFormat(getLang() === "ku" ? "en" : getLang(), {day:"numeric",month:"long",timeZone:"Asia/Baghdad"}).format(new Date(w.scheduledDate + "T12:00:00+03:00"));
  const title = () => tr(workshops.find(w => w.id === selected)?.title) || "";
  function render() {
    const text = words();
    root.innerHTML = `<legend><b>${text[0]}</b><span>${text[1]}</span></legend><div class="vip-workshop-list">${workshops.map(w => `<div class="vip-workshop-card"><label class="vip-workshop-option"><input type="radio" name="vip_workshop" value="${w.id}" ${selected === w.id ? "checked" : ""} ${w.seatsLeft < 1 || !w.availabilityVerified ? "disabled" : ""}><span class="vip-workshop-copy"><span class="vip-workshop-tag">${w.seatsLeft < 1 || !w.availabilityVerified ? text[7] : text[2]}</span><strong>${escape(tr(w.title))}</strong><span class="vip-workshop-trainer">${escape(tr(w.speaker))}</span><span class="vip-workshop-meta"><span>${escape(date(w))}</span><span>${escape(w.startTime)}–${escape(w.endTime)}</span></span></span><span class="vip-workshop-check" aria-hidden="true">✓</span><span class="vip-workshop-photo"><img src="${escape(w.photo)}" alt="${escape(tr(w.speaker))}"></span></label><button type="button" class="vip-workshop-details" data-vip-workshop="${w.id}"><span class="vip-workshop-details-copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 5v15M12 5C9 3 5 3 3 4v15c3-1 6-1 9 1 3-2 6-2 9-1V4c-2-1-6-1-9 1Z" stroke-linecap="round" stroke-linejoin="round"/></svg><span>${text[3]}</span></span><span class="vip-workshop-details-arrow" aria-hidden="true">→</span></button></div>`).join("")}</div><p class="vip-workshop-error" role="alert" hidden>${text[6]}</p>`;
  }
  function showDetails(id) {
    details = workshops.find(w => w.id === id); if (!details) return;
    const w = details, text = words();
    const labels = ({en:["Date","Time","Duration","Experience","Venue"],ar:["التاريخ","الوقت","المدة","المستوى","المكان"],ku:["بەروار","کات","ماوە","ئاست","شوێن"]})[getLang()] || ["Date","Time","Duration","Experience","Venue"];
    const values = [date(w), `${w.startTime}–${w.endTime}`, tr(w.duration), tr(w.level), "Grand Millennium Hotel, Sulaimani"];
    const facts = labels.map((label, i) => `<div><dt>${label}</dt><dd>${escape(values[i])}</dd></div>`).join("");
    dialog.innerHTML = `<div class="vip-workshop-dialog-top"><span>${text[8]}</span><button type="button" class="vip-workshop-close" aria-label="${text[5]}">×</button></div><h2 id="vipWorkshopDialogTitle">${escape(tr(w.title))}</h2><div class="vip-workshop-presenter"><img src="${escape(w.photo)}" alt="${escape(tr(w.speaker))}"><div><strong>${escape(tr(w.speaker))}</strong><p>${escape(tr(w.credentials))}</p><small>${text[2]}</small></div></div><dl class="vip-workshop-facts">${facts}</dl><div class="vip-workshop-overview">${String(tr(w.overview) || "").split("\n").filter(Boolean).map(p => `<p>${escape(p)}</p>`).join("")}</div><div class="vip-workshop-dialog-actions"><button type="button" class="btn btn-outline" data-vip-back>${text[5]}</button><button type="button" class="btn btn-primary" data-vip-choose ${w.seatsLeft < 1 || !w.availabilityVerified ? "disabled" : ""}>${text[4]}</button></div>`;
    dialog.showModal();
  }
  root.addEventListener("change", event => { if(event.target.name === "vip_workshop") { selected = event.target.value; root.querySelector(".vip-workshop-error").hidden = true; } });
  root.addEventListener("click", event => { const button = event.target.closest("[data-vip-workshop]"); if(button) showDetails(button.dataset.vipWorkshop); });
  dialog.addEventListener("click", event => {
    if(event.target.closest(".vip-workshop-close,[data-vip-back]")) dialog.close();
    if(event.target.closest("[data-vip-choose]")) { const input = root.querySelector(`[value="${details.id}"]`); if(input && !input.disabled) { input.checked = true; input.dispatchEvent(new Event("change", {bubbles:true})); dialog.close(); } }
  });
  onLangChange(() => { if(workshops.length) render(); if(dialog.open) {dialog.close();showDetails(details.id);} });
  fetch("api/workshops.php", {cache:"no-store"}).then(r => {if(!r.ok) throw Error("catalog");return r.json();}).then(rows => { workshops = IDS.map(id => rows.find(w => w.id === id)).filter(Boolean); render(); }).catch(() => { root.innerHTML = `<legend><b>${words()[0]}</b></legend><p role="status">${words()[7]}</p>`; });
  return {
    selected: () => selected,
    title,
    validate() {
      const valid = workshops.some(w => w.id === selected && w.availabilityVerified && w.seatsLeft > 0);
      const error = root.querySelector(".vip-workshop-error"); if(error) error.hidden = valid;
      if(!valid) root.scrollIntoView({behavior:"smooth",block:"center"});
      return valid;
    }
  };
}
