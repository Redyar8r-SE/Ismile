// The footer links and contact lines, from data/footer.json.
import { tr, onLangChange } from "../i18n.js?v=18";

export function initFooter({ links = [], contact = [] }) {
  const linkBox = document.getElementById("footLinks");
  const contactBox = document.getElementById("footContact");

  function render() {
    linkBox.innerHTML = links
      .map((item) => {
        const label = tr(item.label) || "";
        return item.href ? `<p><a href="${item.href}">${label}</a></p>` : `<p>${label}</p>`;
      })
      .join("");

    // Emails, phone numbers and addresses always read left to right.
    contactBox.innerHTML = contact
      .map((item) => {
        const text = item.text || "";
        const inner = item.href ? `<a href="${item.href}">${text}</a>` : text;
        return `<p class="ltr" dir="ltr">${inner}</p>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
