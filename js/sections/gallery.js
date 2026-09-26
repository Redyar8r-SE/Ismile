// Photos under the timeline in "About iSmile", from data/gallery.json.
// Pictures only: they do not open larger when tapped.
import { tr, onLangChange } from "../i18n.js?v=69";

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

// The highlights video: the big play button starts it with sound, then gets
// out of the way; it comes back when the video ends.
function initVideo() {
  const box = document.getElementById("galVideo");
  if (!box) return;
  const video = box.querySelector("video");
  const cover = box.querySelector(".gv-cover");
  cover.addEventListener("click", () => {
    box.classList.add("is-playing");
    video.controls = true;
    video.play().catch(() => {});
    video.focus();
  });
  video.addEventListener("play", () => { box.classList.add("is-playing"); video.controls = true; });
  video.addEventListener("ended", () => {
    box.classList.remove("is-playing");
    video.controls = false;
    video.load(); // back to the poster
  });
}
