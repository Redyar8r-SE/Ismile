// Summit partners (official, scientific, accreditation), from data/summit-partners.json.
// The section stays hidden while the list is empty.
import { t, tr, onLangChange } from "../i18n.js?v=132";
import { ICONS } from "../config/icons.js?v=132";

// Each kind has its own colour stripe and icon; anything else is a plain partner.
const KINDS = {
  official: ICONS.award,
  scientific: ICONS.cap,
  accreditation: ICONS.badge,
  partner: ICONS.handshake,
};

const escape = (value) =>
  String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

export function initSummitPartners(data) {
  const section = document.getElementById("partners");
  const list = document.getElementById("spnList");
  const partners = (data?.partners || []).filter((p) => tr(p.name) || p.logo);
  if (!partners.length) return;
  section.hidden = false;

  function render() {
    list.innerHTML = partners
      .map((p) => {
        const kind = p.kind in KINDS ? p.kind : "partner";
        const name = tr(p.name);
        const note = tr(p.note);
        const alt = escape(t("logo_alt").replace("{name}", name));
        const logos = [p.logo, p.logo2].filter(Boolean)
          .map((src) => `<img src="${escape(src)}" alt="${alt}" loading="lazy">`)
          .join('<span class="spn-sep" aria-hidden="true"></span>');
        return `
          <li class="spn-row is-${kind}">
            <div class="spn-logos${p.logo2 ? " is-pair" : ""}">${logos}</div>
            <div class="spn-text">
              <p class="spn-role">${KINDS[kind]}<span>${escape(tr(p.role))}</span></p>
              <h3>${escape(name)}</h3>
              ${note ? `<p class="spn-note" dir="auto">${escape(note)}</p>` : ""}
            </div>
          </li>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
