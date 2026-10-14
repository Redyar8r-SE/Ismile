// Sponsor tiers: the sponsors already signed, then the open places left,
// from data/sponsors.json.
import { ICONS } from "../config/icons.js?v=125";
import { initials } from "../utils/initials.js?v=125";
import { t, tr, onLangChange } from "../i18n.js?v=125";

export function initSponsors({ tiers, sponsors = [] }) {
  const container = document.getElementById("sponsorTiers");

  function logo(sponsor) {
    const mark = sponsor.logo
      ? `<img src="${sponsor.logo}" alt="${t("logo_alt").replace("{name}", sponsor.name).replaceAll('"', '&quot;')}" data-name="${sponsor.name.replaceAll('"', '&quot;')}" loading="lazy"${sponsor.round ? ' class="round"' : ""}>`
      : `<span class="pl-mono">${initials(sponsor.name || "")}</span>`;
    // A "tile" logo is a finished picture with its own background: it fills the whole box.
    return `
      <div class="p-logo${sponsor.tile ? " is-tile" : ""}" title="${sponsor.name.replaceAll('"', '&quot;')}">
        <div class="pl-frame" style="background:${sponsor.bg || "#fff"}">${mark}</div>
        <span class="pl-name">${sponsor.name}</span>
      </div>`;
  }

  function render() {
    container.innerHTML = tiers
      .map((tier) => {
        const mine = sponsors.filter((s) => s.tier === tier.id);
        // "spots" is every place in the tier, so the open ones are what the signed
        // sponsors leave. Diamond shows no open places once it has a sponsor.
        const free =
          tier.className === "tc-dia" && mine.length
            ? 0
            : Math.max(0, (Number(tier.spots) || 0) - mine.length);
        const slot = `<a class="logo-slot" href="#companies" aria-label="${t("spon_slot")}"><span class="slot-plus">${ICONS.plus}</span><span class="slot-text">${t("spon_slot")}</span></a>`;
        const sub = tr(tier.subtitle);
        return `
          <div class="swall ${tier.className}">
            <div class="sw-label">
              <span class="sw-line"></span>
              <h3 class="sw-pill">${tierIcon(tier)}<span>${tr(tier.name)}</span></h3>
              <span class="sw-line"></span>
            </div>
            ${sub ? `<p class="sw-sub">${sub}</p>` : ""}
            <div class="sw-logos">${mine.map(logo).join("")}${slot.repeat(free)}</div>
          </div>`;
      })
      .join("");

    // A logo file that cannot be found falls back to the company initials.
    container.querySelectorAll("img").forEach((img) => {
      img.addEventListener("error", () => {
        img.outerHTML = `<span class="pl-mono">${initials(img.dataset.name)}</span>`;
      });
    });
  }

  render();
  onLangChange(render);
}

function tierIcon(tier) {
  if (tier.className === "tc-plat") return ICONS.crown;
  if (tier.className === "tc-gold") return ICONS.trophy;
  if (tier.className === "tc-silver") return ICONS.medal;
  if (tier.className === "tc-bronze") return ICONS.award;
  if (tier.className === "tc-exhibitor") return ICONS.storefront;
  return ICONS.gem;
}
