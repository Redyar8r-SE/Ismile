// Venue: "Copy address" button.
import { t } from "../i18n.js";

export function initVenue() {
  const button = document.getElementById("copyAddress");
  const address = document.getElementById("venueAddress");
  const label = button.querySelector("span");
  let timer;

  button.addEventListener("click", async () => {
    const text = address.textContent.trim();
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      // Older browsers: copy through a hidden text field
      const field = document.createElement("textarea");
      field.value = text;
      field.style.position = "fixed";
      field.style.opacity = "0";
      document.body.appendChild(field);
      field.select();
      document.execCommand("copy");
      field.remove();
    }
    button.classList.add("copied");
    label.textContent = t("copied");
    clearTimeout(timer);
    timer = setTimeout(() => {
      button.classList.remove("copied");
      label.textContent = t("copy");
    }, 2000);
  });
}
