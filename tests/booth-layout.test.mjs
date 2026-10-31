import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

// Browser modules remain outside the server's TypeScript project.
const { buildBooths } = await import(new URL("../js/config/booth-layout.js", import.meta.url).href);
const { floorPlanSVG } = await import(
  new URL("../js/sections/floor-plan.js", import.meta.url).href
);
const plan = JSON.parse(readFileSync(new URL("../data/booth-tiers.json", import.meta.url), "utf8"));
/** @typedef {{number:number,displayNumber:number,tier:string,size:string,points:number[][]}} Booth */
/** @type {Booth[]} */
const booths = buildBooths(plan.rows);
const ids = [
  1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 30,
  32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44,
];

describe("39-booth layout and permanent reservation identities", () => {
  it("uses all 39 approved position IDs and visible numbers 1–39 exactly once", () => {
    expect(booths.map((b) => b.number)).toEqual(ids);
    expect(booths.map((b) => b.displayNumber)).toEqual(Array.from({ length: 39 }, (_, i) => i + 1));
    expect(booths.every((b) => b.points?.length >= 4)).toBe(true);
  });

  it("retains the original physical outlines of every unaffected booked booth", () => {
    const original = {
      1: [
        [596, 462],
        [657, 448],
        [667, 492],
        [642, 498],
      ],
      5: [
        [663, 595],
        [688, 589],
        [698, 634],
        [672, 641],
      ],
      15: [
        [360, 650],
        [385, 661],
        [382, 686],
        [349, 674],
      ],
      16: [
        [329, 636],
        [360, 650],
        [349, 674],
        [318, 660],
      ],
      17: [
        [300, 622],
        [329, 636],
        [318, 660],
        [288, 646],
      ],
      18: [
        [270, 608],
        [300, 622],
        [288, 646],
        [258, 632],
      ],
      19: [
        [240, 594],
        [270, 608],
        [258, 632],
        [228, 618],
      ],
      20: [
        [210, 580],
        [240, 594],
        [228, 618],
        [198, 604],
      ],
      23: [
        [117, 539],
        [150, 552],
        [138, 577],
        [105, 563],
      ],
      43: [
        [219, 466],
        [266, 486],
        [255, 511],
        [209, 491],
      ],
      44: [
        [209, 491],
        [255, 511],
        [245, 534],
        [199, 514],
      ],
    };
    for (const [id, points] of Object.entries(original)) {
      expect(booths.find((b) => b.number === Number(id))?.points).toEqual(points);
    }
    // Reserved position 7 expands leftwards within its existing bottom row.
    expect(booths.find((b) => b.number === 7)?.points).toContainEqual([647, 661]);
    expect(booths.find((b) => b.number === 7)?.points).toContainEqual([647, 686]);
  });

  it("keeps every booth in its original tier and uses the requested new sizes", () => {
    expect(booths.filter((b) => b.tier === "gold").map((b) => b.number)).toEqual([
      1, 6, 24, 30, 36, 43, 44,
    ]);
    expect(booths.filter((b) => b.tier === "platinum").map((b) => b.number)).toEqual([
      37, 38, 39, 40, 41, 42,
    ]);
    expect(booths.filter((b) => b.tier === "bronze").map((b) => b.number)).toEqual([
      15, 16, 17, 18, 19, 20, 21, 22, 23,
    ]);
    for (const b of booths.filter((b) => b.tier === "silver")) {
      expect(b.size).toBe(
        [2, 3, 5].includes(b.number) ? "3.5 × 2 m" : b.number <= 12 ? "3.2 × 2 m" : "3 × 2 m",
      );
    }
  });

  it("renders new labels while selection and booking attributes retain stable IDs", () => {
    const svg = floorPlanSVG({
      booths,
      colors: plan.colors,
      label: (/** @type {Booth} */ b) => `Booth ${b.displayNumber}`,
      booked: new Set([7, 44]),
      translate: (/** @type {string} */ key) => key,
    });
    expect(svg).toMatch(/data-booth="7".*?aria-label="Booth 6".*?>6<\/text>/s);
    expect(svg).toMatch(/data-booth="44".*?aria-label="Booth 39".*?>39<\/text>/s);
    expect((svg.match(/class="map-booth/g) || []).length).toBe(39);
    expect((svg.match(/is-booked/g) || []).length).toBe(2);
  });
});
