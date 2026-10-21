// The exhibition floor plan drawing, shared by the live booth map on
// sponsor.html and the plain map in the homepage Program section.
import { t } from "../i18n.js?v=145";

const escape = (value) => String(value).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

// interactive: the booths are buttons (sponsor.html). Off, they are only
// coloured shapes with a hover title, and nothing can be picked or booked.
// gatePills: draw Entrance and Exit as coloured pills (sponsor.html).
export function floorPlanSVG({ booths, colors, label, booked = new Set(), selected = null, interactive = true, gatePills = false }) {
  const gate = (x, y, text, kind) => {
    const width = Math.max(78, text.length * 11 + 30);
    return `${gatePills ? `<rect class="plan-gate-pill plan-gate-pill-${kind}" x="${x - width / 2}" y="${y - 13}" width="${width}" height="26" rx="13"/>` : ""}<text class="plan-gate-label plan-gate-label-${kind}" x="${x}" y="${y}">${escape(text)}</text>`;
  };
  return `<svg class="booth-map-svg" viewBox="25 230 1020 590" xmlns="http://www.w3.org/2000/svg" role="group" aria-label="${escape(t("bm_map_label"))}">
      <defs><pattern id="boothPlanGrid" width="24" height="24" patternUnits="userSpaceOnUse"><circle class="plan-dot" cx="1" cy="1" r="1"/></pattern></defs>
      <g aria-hidden="true">
        <rect class="plan-background" x="25" y="230" width="1020" height="590"/>
        <rect x="25" y="230" width="1020" height="590" fill="url(#boothPlanGrid)"/>
        <path class="plan-floor" d="M51 543 230 285 287 310 291 294 392 334 500 352 502 383 585 383 584 358 Q708 334 787 298 L798 322 844 294 1021 557 966 594 989 634 Q861 709 686 758 Q469 817 220 738 L71 630 91 597Z"/>
        <path class="plan-room" d="m230 285 56 26-44 67 18 12 45-66 87 30-7 17-91-28-23 44 Q368 434 490 452 L501 352 461 345 456 383 441 380 443 344 392 334 387 353 327 332 334 313 291 294 284 310Z"/>
        <path class="plan-room" d="M585 358 Q696 337 786 299 L798 322 780 334 791 359 813 346 830 381 814 392 807 377 Q735 416 634 442 L640 454 622 461 610 428 627 421 Q715 398 797 375 L787 355 Q701 397 589 406Z"/>
        <path class="plan-room" d="M51 543 Q190 632 363 685 L411 696 399 754 Q250 718 112 649 L137 600Z"/>
        <path class="plan-room" d="m677 693 11 61 Q867 707 989 634 L966 594 Q830 665 677 693Z"/>
        <path class="plan-room" d="M440 719 641 719 641 755 Q546 780 440 755Z"/>
        <path class="plan-wall" d="m112 649 25-49M363 685l-9 57m48-39-45-12m321 5 39-10m245-76 24 36M440 727h201m-201 9h201m-201 9h201m-201 9h201M502 383h23m39 0h21M461 349l-3 33 36 4 4-32M287 312l28 13 12-25m65 34-8 37 42 8 10-35M640 348l8 29m37-41 9 28m53-50 9 28M277 397 289 385 Q385 430 490 444 M594 460 584 358 M659 448 709 686 Q869 654 1021 557"/>
        <path class="plan-door" d="M524 383v-20q21 0 21 20m19 0v-20q-19 0-19 20M252 365l18 8q-7 17-25 9m84-58-7 18q18 7 25-11m95 13-3 20q19 3 22-15m152 12 3 19q19-3 16-22m71-20 5 19q18-5 13-24m70-24 8 18q16-8 8-26M399 754l20 4v-20q-19 0-20 16m41 1-19 3v-20q19 0 19 17m201 0 21-5-5-20q-19 5-16 25m47-1-22 5-5-20q19-5 27 15"/>
        <path class="plan-route" d="M183 435 128 520 Q365 662 638 637 L612 494M518 413l-6 219"/>
        <text class="plan-brand" x="815" y="469">iSmile</text>
        <text class="plan-label" x="815" y="496">${escape(t("bm_hall"))}</text>
        ${gate(546, 270, t("bm_entrance"), "in")}
        <path class="plan-gate-arrow plan-gate-in" d="M546 289v49m-10-10 10 10 10-10"/>
        ${gate(285, 253, t("bm_exit"), "out")}
        <path class="plan-gate-arrow plan-gate-out" d="m251 313 23-41m-14 5 14-5 4 14"/>
      </g>
      ${booths.map((booth) => {
        const x = booth.points.reduce((sum, point) => sum + point[0], 0) / booth.points.length;
        const y = booth.points.reduce((sum, point) => sum + point[1], 0) / booth.points.length;
        const points = booth.points.map((point) => point.join(",")).join(" ");
        return `<g class="map-booth${booked.has(booth.number) ? " is-booked" : ""}${selected === booth.number ? " is-selected" : ""}" data-booth="${booth.number}"${interactive ? ` tabindex="0" role="button" aria-pressed="${selected === booth.number}" aria-label="${escape(label(booth))}"` : ""} style="--booth-color:${colors[booth.tier]}"><title>${escape(label(booth))}</title><polygon points="${points}"/><polyline class="booth-outline" points="${points} ${booth.points[0].join(",")}"/><text x="${x}" y="${y}" aria-hidden="true">${booth.number}</text></g>`;
      }).join("")}
    </svg>`;
}
