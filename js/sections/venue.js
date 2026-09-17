// Venue: "Copy address" and "Get directions" (uses the visitor's location when allowed).
import { t } from "../i18n.js";

const DESTINATION = "Grand Millennium Sulaimani, Sulaymaniyah";

export function initVenue() {
  initCopyAddress();
  initDirections();
}

function initCopyAddress() {
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

function initDirections() {
  const button = document.getElementById("directionsBtn");
  const label = button.querySelector(".dir-label");
  const note = document.getElementById("directionsNote");
  const noteText = document.getElementById("directionsNoteText");

  // Without geolocation (or on plain http), the normal link still opens directions.
  if (!("geolocation" in navigator) || !window.isSecureContext) return;

  let locating = false;

  function setNote(key, tone) {
    noteText.textContent = t(key);
    note.classList.toggle("is-success", tone === "success");
    note.classList.toggle("is-warning", tone === "warning");
  }

  function finish() {
    locating = false;
    button.classList.remove("is-locating");
    label.textContent = t("directions");
  }

  button.addEventListener("click", (event) => {
    event.preventDefault();
    if (locating) return;
    locating = true;
    button.classList.add("is-locating");
    label.textContent = t("dir_locating");
    setNote("dir_hint");

    navigator.geolocation.getCurrentPosition(
      (position) => {
        const { latitude, longitude } = position.coords;
        setNote("dir_found", "success");
        openDirections(`${latitude.toFixed(6)},${longitude.toFixed(6)}`);
        finish();
      },
      (error) => {
        setNote(error.code === error.PERMISSION_DENIED ? "dir_denied" : "dir_failed", "warning");
        openDirections(null);
        finish();
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
    );
  });
}

function openDirections(origin) {
  const params = new URLSearchParams({ api: "1", destination: DESTINATION, travelmode: "driving" });
  if (origin) params.set("origin", origin);
  const url = `https://www.google.com/maps/dir/?${params}`;

  // A new tab can be blocked if the visitor took a while to answer the location prompt;
  // in that case open the route in the same tab instead.
  const win = window.open(url, "_blank");
  if (win) win.opener = null;
  else window.location.href = url;
}
