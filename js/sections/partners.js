// Trusted partners grouped by tier, from data/partners.json.
import { ICONS } from "../config/icons.js?v=16";
import { initials } from "../utils/initials.js?v=16";
import { t, tr, onLangChange } from "../i18n.js?v=16";

export function initPartners({ tiers, partners }) {
  const container = document.getElementById("ptiers");
  document.getElementById("partnerCount").textContent = partners.length;

  function render() {
    container.innerHTML = tiers
      .map((tier) => {
        // tier.name stays English: it is the id each partner is matched on.
        const list = partners.filter((p) => p.tier === tier.name);
        if (!list.length) return "";
        return `
          <div class="tierc ${tier.className} ptier">
            <div class="tc-top">
              <span class="medal">${ICONS.gem}</span>
              <div><h3>${tr(tier.label) || tier.name}</h3><p>${tr(tier.subtitle)}</p></div>
              <span class="tc-count">${list.length} ${t(list.length > 1 ? "tp_companies" : "tp_company")}</span>
            </div>
            <div class="p-slots">${list.map(renderLogo).join("")}</div>
          </div>`;
      })
      .join("");

    // If a logo file is missing, show the company initials instead of a broken image.
    container.querySelectorAll("img").forEach((img) => {
      img.addEventListener("error", () => {
        img.outerHTML = `<span class="pl-mono">${img.dataset.initials}</span>`;
      });
    });
  }

  render();
  onLangChange(render);
}

function renderLogo(p) {
  const mark = p.logo
    ? `<img src="${p.logo}" alt="${p.name} logo" loading="lazy" data-initials="${initials(p.name)}"${p.round ? ' class="round"' : ""}>`
    : `<span class="pl-mono">${initials(p.name)}</span>`;
  return `
    <div class="p-logo">
      <div class="pl-frame" style="background:${p.bg || "#fff"}">${mark}</div>
      <span class="pl-name">${p.name}</span>
    </div>`;
}
