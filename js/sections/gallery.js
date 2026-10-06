// Photos under the timeline in "About iSmile", from data/gallery.json.
// Pictures only: they do not open larger when tapped.
import { tr, onLangChange } from "../i18n.js?v=81";

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

  function render() {
    grid.dataset.count = list.length;
    grid.innerHTML = list
      .map((item, i) => `
        <li${spanFor(i)}>
          <figure class="gal-item">
            <img src="${item.photo}" alt="${tr(item.caption)}" loading="lazy" width="1080" height="720">
            <figcaption class="gal-cap">${tr(item.caption)}</figcaption>
          </figure>
        </li>`)
      .join("");
  }

  render();
  onLangChange(render);
  initVideo();
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
