// Photos under the timeline in "About iSmile", from data/gallery.json.
// Pictures only: they do not open larger when tapped.
import { tr, onLangChange } from "../i18n.js?v=78";

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
// photos move to the next one every few seconds, and after the last one the
// first comes next from the same side. When the row settles on a copy of a
// photo it jumps, without animation, to the real one in the same place, so
// the circle never ends. It waits while the row is off screen, and for a
// while after the visitor swipes it themselves. Tablets and laptops keep the
// still grid.
const SLIDE_EVERY = 6000;
const WAIT_AFTER_TOUCH = 8000;

function initPhoneSlides(grid) {
  const phone = matchMedia("(max-width:520px)");
  if (matchMedia("(prefers-reduced-motion: reduce)").matches || !("IntersectionObserver" in window)) return;
  let visible = false;
  let touchedAt = 0;

  // The photo whose start edge is closest to the row's start edge.
  function currentIndex(items, rtl) {
    const edge = grid.getBoundingClientRect()[rtl ? "right" : "left"];
    let best = 0;
    let bestGap = Infinity;
    items.forEach((li, i) => {
      const gap = Math.abs(li.getBoundingClientRect()[rtl ? "right" : "left"] - edge);
      if (gap < bestGap) { best = i; bestGap = gap; }
    });
    return best;
  }

  function next() {
    if (!phone.matches || !visible || document.hidden) return;
    if (Date.now() - touchedAt < WAIT_AFTER_TOUCH) return;
    const items = [...grid.children];
    if (items.length < 2) return;
    const rtl = getComputedStyle(grid).direction === "rtl";
    const index = currentIndex(items, rtl);
    if (index >= items.length - 1) return;
    const target = items[index + 1].getBoundingClientRect();
    const box = grid.getBoundingClientRect();
    const delta = rtl ? target.right - box.right : target.left - box.left;
    grid.scrollBy({ left: delta, behavior: "smooth" });
  }

  // Resting on a copy: move to the real photo, which looks exactly the same.
  function wrap() {
    if (!phone.matches) return;
    const items = [...grid.children];
    const real = items.filter((li) => !li.classList.contains("gal-clone"));
    const copies = items.length - real.length;
    if (!copies) return;
    const index = currentIndex(items, getComputedStyle(grid).direction === "rtl");
    if (index < real.length) return;
    const shift = items[index - real.length].getBoundingClientRect().left - items[index].getBoundingClientRect().left;
    grid.scrollBy({ left: shift, behavior: "instant" });
  }

  let settle = 0;
  grid.addEventListener("scroll", () => {
    clearTimeout(settle);
    settle = setTimeout(wrap, 200);
  }, { passive: true });

  const touched = () => { touchedAt = Date.now(); };
  grid.addEventListener("touchstart", touched, { passive: true });
  grid.addEventListener("pointerdown", touched);
  grid.addEventListener("wheel", touched, { passive: true });

  new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; }, { threshold: 0.5 }).observe(grid);
  setInterval(next, SLIDE_EVERY);
}

// The highlights video plays by itself, muted and looping, while it is on
// screen (browsers only allow a video to start on its own without sound).
// "Tap for sound" starts it again from the beginning with sound and controls;
// when that ends it goes back to playing muted. With reduced motion, or if the
// browser still refuses, the big play button is shown instead.
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

  if (matchMedia("(prefers-reduced-motion: reduce)").matches || !("IntersectionObserver" in window)) return;
  new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting;
    if (visible) playMuted();
    else if (!withSound) video.pause();
  }, { threshold: 0.35 }).observe(box);
}
