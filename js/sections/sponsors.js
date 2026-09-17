// Sponsor tiers with open "Your logo here" slots, from data/sponsors.json.
import { ICONS } from "../config/icons.js";

export function initSponsors(tiers) {
  const container = document.getElementById("sponsorTiers");

  container.innerHTML = tiers
    .map((tier) => {
      const slot = `<a class="logo-slot" href="#companies"><span class="slot-plus">${ICONS.plus}</span><span>Your logo here</span></a>`;
      return `
        <div class="tierc ${tier.className}">
          <div class="tc-top">
            <span class="medal">${ICONS.gem}</span>
            <div><h3>${tier.name}</h3><p>${tier.subtitle}</p></div>
            <span class="tc-count">${tier.spots} spots available</span>
          </div>
          <div class="tc-slots">${slot.repeat(tier.spots)}</div>
        </div>`;
    })
    .join("");
}
