// Photos under the timeline in "About iSmile", from data/gallery.json.
// Click a photo to see it large; Escape, the × or a click outside closes it.
import { t, tr, onLangChange } from "../i18n.js?v=24";

export function initGallery(items) {
  const block = document.getElementById("gallery");
  const grid = document.getElementById("galleryGrid");
  const box = document.getElementById("galleryBox");
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
          <button class="gal-item" type="button" data-index="${i}">
            <img src="${item.photo}" alt="${tr(item.caption)}" loading="lazy" width="1080" height="720">
            <span class="gal-cap">${tr(item.caption)}</span>
          </button>
        </li>`)
      .join("");
    box.querySelector(".gal-close").setAttribute("aria-label", t("gal_close"));
  }

  function open(index) {
    const item = list[index];
    box.querySelector("img").src = item.photo;
    box.querySelector("img").alt = tr(item.caption);
    box.querySelector("figcaption").textContent = tr(item.caption);
    box.showModal();
  }

  grid.addEventListener("click", (event) => {
    const button = event.target.closest(".gal-item");
    if (button) open(Number(button.dataset.index));
  });
  box.querySelector(".gal-close").addEventListener("click", () => box.close());
  // The dialog itself only receives a click when it lands on the backdrop.
  box.addEventListener("click", (event) => {
    if (event.target === box) box.close();
  });

  render();
  onLangChange(render);
}
