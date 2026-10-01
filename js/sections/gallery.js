// Photos under the timeline in "About iSmile", from data/gallery.json.
// Pictures only: they do not open larger when tapped.
import { tr, onLangChange } from "../i18n.js?v=82";

export function initGallery(items) {
  const block = document.getElementById("gallery");
  const grid = document.getElementById("galleryGrid");
  const list = (items || []).filter((item) => item && item.photo);

  // No photos yet: hide the whole block rather than an empty frame.
  if (!list.length) { block.hidden = true; return; }

  // Rows of three from six photos up: a last row of one or two is stretched
  // to fill the width instead of leaving a gap.
  function spanFor(index) {
    const count = list.length;
    if (count < 6) return "";
    const left = count % 3;
    if (left === 1 && index === count - 1) return ' class="span-6"';
    if (left === 2 && index >= count - 2) return ' class="span-3"';
    return "";
  }

  function tile(item, i, clone) {
    return `
        <li${clone ? ' class="gal-clone" aria-hidden="true"' : spanFor(i)}>
          <figure class="gal-item">
            <img src="${item.photo}" alt="${clone ? "" : tr(item.caption)}" loading="lazy" width="1080" height="720">
            <figcaption class="gal-cap">${tr(item.caption)}</figcaption>
          </figure>
        </li>`;
  }

  // A copy of every photo follows the last one. Only phones show the copies
  // (gallery.css): they let the sliding row carry on from the last photo to
  // the first in the same direction, like a circle, instead of rewinding.
  function render() {
    grid.dataset.count = list.length;
    grid.innerHTML = list.map((item, i) => tile(item, i, false)).join("")
      + (list.length > 1 ? list.map((item, i) => tile(item, i, true)).join("") : "");
  }

  render();
  onLangChange(render);
  initVideo();
  initPhoneSlides(grid);
}

// On phones only (the one-row layout in gallery.css, 520px wide or less) the
// photos drift slowly and without stopping, like a belt going round: copies
// of the photos follow the last one, and when the first copy reaches the
// place of the first photo the row moves back by exactly that distance, which
// looks the same, so the circle never ends. Snapping is off while it drifts.
// It waits while the row is off screen, and for a while after the visitor
// swipes it themselves. Tablets and laptops keep the still grid.
const SPEED = 25; // pixels a second
const WAIT_AFTER_TOUCH = 5000;

function initPhoneSlides(grid) {
  const phone = matchMedia("(max-width:520px)");
  // The owner wants these moving on every phone, so the "Reduce Motion"
  // setting does not stop them.
  if (!("IntersectionObserver" in window)) return;
  let visible = false;
  let touchedAt = 0;
  let pos = null; // how far the row has drifted, kept as a fraction
  let lastTime = 0;

  // The length of one full round: from the first photo to its first copy.
  function roundLength() {
    const first = grid.firstElementChild;
    const copy = grid.querySelector(".gal-clone");
    if (!first || !copy) return 0;
    return Math.abs(copy.getBoundingClientRect().left - first.getBoundingClientRect().left);
  }

  function frame(time) {
    requestAnimationFrame(frame);
    const moving = phone.matches && visible && !document.hidden && Date.now() - touchedAt > WAIT_AFTER_TOUCH;
    if (!moving) {
      if (grid.style.scrollSnapType) grid.style.scrollSnapType = "";
      pos = null;
      return;
    }
    const round = roundLength();
    if (!round) return;
    const sign = getComputedStyle(grid).direction === "rtl" ? -1 : 1;
    if (pos === null) {
      // Start (or carry on after a swipe) from wherever the row is now.
      grid.style.scrollSnapType = "none";
      pos = Math.abs(grid.scrollLeft);
      lastTime = time;
    }
    const seconds = Math.min(time - lastTime, 50) / 1000;
    lastTime = time;
    pos += SPEED * seconds;
    if (pos >= round) pos -= round;
    grid.scrollLeft = sign * pos;
  }

  const touched = () => { touchedAt = Date.now(); };
  grid.addEventListener("touchstart", touched, { passive: true });
  grid.addEventListener("pointerdown", touched);
  grid.addEventListener("wheel", touched, { passive: true });

  new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; }, { threshold: 0.3 }).observe(grid);
  requestAnimationFrame(frame);
}

// The highlights video plays by itself, muted and looping, while it is on
// screen (browsers only allow a video to start on its own without sound).
// "Tap for sound" starts it again from the beginning with sound and controls;
// when that ends it goes back to playing muted. If the browser still refuses
// (iPhone Low Power Mode and the like), the big play button is shown and the
// first tap anywhere on the page starts it.
function initVideo() {
  const box = document.getElementById("galVideo");
  if (!box) return;
  const video = box.querySelector("video");
  const cover = box.querySelector(".gv-cover");
  const sound = box.querySelector(".gv-sound");
  let withSound = false;
  let visible = false;

  function playMuted() {
    if (withSound || !visible) return;
    video.muted = true;
    video.loop = true;
    video.controls = false;
    video.play().then(() => {
      box.classList.add("is-auto");
      sound.hidden = false;
    }).catch(() => {});
  }

  function playWithSound() {
    withSound = true;
    box.classList.remove("is-auto");
    box.classList.add("is-playing");
    sound.hidden = true;
    video.muted = false;
    video.loop = false;
    video.controls = true;
    video.currentTime = 0;
    video.play().catch(() => {});
    video.focus();
  }

  cover.addEventListener("click", playWithSound);
  sound.addEventListener("click", playWithSound);
  video.addEventListener("click", () => { if (!withSound) playWithSound(); });
  video.addEventListener("ended", () => {
    withSound = false;
    box.classList.remove("is-playing");
    playMuted();
  });

  // The owner wants these moving on every phone, so the "Reduce Motion"
  // setting does not stop them.
  if (!("IntersectionObserver" in window)) return;

  // Some phones refuse to start any video by itself (iPhone Low Power Mode,
  // Android battery saver, the browsers inside Instagram or WhatsApp). They
  // do allow it straight after a tap, so any tap on the page tries again.
  const retry = () => { if (visible && !withSound && video.paused) playMuted(); };
  document.addEventListener("touchend", retry, { passive: true });
  document.addEventListener("click", retry);

  new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting;
    if (visible) playMuted();
    else if (!withSound) video.pause();
  }, { threshold: 0.35 }).observe(box);
}
