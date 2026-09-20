// Venue: "Copy address" and "Get directions" (uses the visitor's location when allowed).
import { t } from "../i18n.js?v=21";
import { mapTarget } from "../utils/maps.js?v=21";

// Where the map points. Set in the admin (data/map.json): a place name, or
// exact coordinates when the name is not precise enough.
let destination = "Grand Millennium Sulaimani, Sulaymaniyah";

export function initVenue(map = {}) {
  const { target } = mapTarget(map);
  if (target) destination = target;
  showMap(map);
  initCopyAddress();
  initDirections();
}

// The map picture, the "Get directions" link and the "Open in Google Maps" link.
function showMap(map) {
  const frame = document.querySelector(".map-frame");
  const directions = document.getElementById("directionsBtn");
  const openMaps = document.querySelector(".venue-actions .btn-outline");
  const query = encodeURIComponent(destination);
  const { zoom } = mapTarget(map);

  if (frame) {
    frame.src = `https://www.google.com/maps?q=${query}&z=${zoom}&output=embed`;
    frame.title = map.place || destination;
  }
  if (directions) directions.href = `https://www.google.com/maps/dir/?api=1&destination=${query}`;
  if (openMaps) openMaps.href = `https://www.google.com/maps/search/?api=1&query=${query}`;
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
  const params = new URLSearchParams({ api: "1", destination, travelmode: "driving" });
  if (origin) params.set("origin", origin);
  const url = `https://www.google.com/maps/dir/?${params}`;

  // A new tab can be blocked if the visitor took a while to answer the location prompt;
  // in that case open the route in the same tab instead.
  const win = window.open(url, "_blank");
  if (win) win.opener = null;
  else window.location.href = url;
}
