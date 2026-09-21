// The footer links and contact lines, from data/footer.json.
import { tr, onLangChange } from "../i18n.js?v=22";

export function initFooter({ links = [], contact = [] }) {
  const linkBox = document.getElementById("footLinks");
  const contactBox = document.getElementById("footContact");

  // The footer sits on more than one page, and its links point at places on the
  // home page (#program). Away from home those would lead nowhere, so they are
  // sent to the home page first.
  const atHome = Boolean(document.getElementById("home"));
  const href = (value) => (value.startsWith("#") && !atHome ? `index.html${value}` : value);

  function render() {
    linkBox.innerHTML = links
      .map((item) => {
        const label = tr(item.label) || "";
        return item.href ? `<p><a href="${href(item.href)}">${label}</a></p>` : `<p>${label}</p>`;
      })
      .join("");

    // Emails, phone numbers and addresses always read left to right.
    contactBox.innerHTML = contact
      .map((item) => {
        const text = item.text || "";
        const inner = item.href ? `<a href="${href(item.href)}">${text}</a>` : text;
        return `<p class="ltr" dir="ltr">${inner}</p>`;
      })
      .join("");
  }

  render();
  onLangChange(render);
}
