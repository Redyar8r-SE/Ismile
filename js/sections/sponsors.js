// Sponsor tiers: the sponsors already signed, then the open places left,
// from data/sponsors.json.
import { ICONS } from "../config/icons.js";
import { initials } from "../utils/initials.js";
import { t, tr, onLangChange } from "../i18n.js";

export function initSponsors({ tiers, sponsors = [] }) {
  const container = document.getElementById("sponsorTiers");

  function logo(sponsor) {
    const mark = sponsor.logo
      ? `<img src="${sponsor.logo}" alt="${sponsor.name} logo" loading="lazy"${sponsor.round ? ' class="round"' : ""}>`
      : `<span class="pl-mono">${initials(sponsor.name || "")}</span>`;
    return `
      <div class="p-logo">
        <div class="pl-frame" style="background:${sponsor.bg || "#fff"}">${mark}</div>
        <span class="pl-name">${sponsor.name}</span>
      </div>`;
  }

  function render() {
    container.innerHTML = tiers
      .map((tier) => {
        const mine = sponsors.filter((s) => s.tier === tier.id);
        const free = Math.max(0, Number(tier.spots) || 0);
        const slot = `<a class="logo-slot" href="#companies"><span class="slot-plus">${ICONS.plus}</span><span>${t("spon_slot")}</span></a>`;
        return `
          <div class="tierc ${tier.className}">
            <div class="tc-top">
              <span class="medal">${ICONS.gem}</span>
              <div><h3>${tr(tier.name)}</h3><p>${tr(tier.subtitle)}</p></div>
              ${free ? `<span class="tc-count">${free} ${t("spon_spots")}</span>` : ""}
            </div>
            <div class="tc-slots">${mine.map(logo).join("")}${slot.repeat(free)}</div>
          </div>`;
      })
      .join("");

    // A logo file that cannot be found falls back to the company initials.
    container.querySelectorAll("img").forEach((img) => {
      img.addEventListener("error", () => {
        img.outerHTML = `<span class="pl-mono">${initials(img.alt.replace(/ logo$/, ""))}</span>`;
      });
    });
  }

  render();
  onLangChange(render);
}
