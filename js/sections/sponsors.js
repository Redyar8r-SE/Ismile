// Sponsor tiers with open "Your logo here" slots, from data/sponsors.json.
import { ICONS } from "../config/icons.js";
import { t, tr, onLangChange } from "../i18n.js";

export function initSponsors(tiers) {
  const container = document.getElementById("sponsorTiers");

  function render() {
    container.innerHTML = tiers
      .map((tier) => {
        const slot = `<a class="logo-slot" href="#companies"><span class="slot-plus">${ICONS.plus}</span><span>${t("spon_slot")}</span></a>`;
        return `
          <div class="tierc ${tier.className}">
            <div class="tc-top">
              <span class="medal">${ICONS.gem}</span>
              <div><h3>${tr(tier.name)}</h3><p>${tr(tier.subtitle)}</p></div>
              <span class="tc-count">${tier.spots} ${t("spon_spots")}</span>
            </div>
            <div class="tc-slots">${slot.repeat(tier.spots)}</div>
          </div>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
