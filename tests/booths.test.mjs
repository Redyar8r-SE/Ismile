import { readFileSync } from "node:fs";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { buildBooths } from "../js/config/booth-layout.js";
import { boothAvailability, normalizeMapText } from "../server/booths.mjs";

describe("map references in existing persistent translations", () => {
  it("updates the previous 39-position count while preserving other edited numbers", () => {
    expect(normalizeMapText({ pm_text: "39 booths in hall 139", bm_intro: "٣٩ بووث" })).toEqual({
      pm_text: "40 booths in hall 139",
      bm_intro: "٤٠ بووث",
    });
  });
  it("updates the count and width-only booth references without replacing edited text", () => {
    const result = normalizeMapText({
      bm_intro: "My 44 numbered booths",
      bm_size_note: "الأجنحة 1 و6 و24",
      legal: "Keep this edited text",
    });
    expect(result).toEqual({
      bm_intro: "My 40 numbered booths",
      bm_size_note: "الأجنحة 1 و5 و21",
      legal: "Keep this edited text",
    });
    expect(normalizeMapText(result)).toEqual(result);
  });
  it("preserves Arabic digits when updating Kurdish map references", () => {
    const result = normalizeMapText({ bm_intro: "٤٤ بووث", bm_size_note: "١، ٦ و ٢٤" });
    expect(result).toEqual({ bm_intro: "٤٠ بووث", bm_size_note: "١، ٥ و ٢١" });
    expect(normalizeMapText(result)).toEqual(result);
  });
});

describe("live booth availability on the Node website", () => {
  beforeEach(() => {
    vi.stubEnv("BOOTH_API_URL", "https://backend.example/api/booths.php");
    vi.stubEnv("BOOTH_API_USERNAME", "");
    vi.stubEnv("BOOTH_API_PASSWORD", "");
  });
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });
  /** @param {unknown} body @param {number} [status] */
  const upstream = (body, status = 200) => {
    const mock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
    vi.stubGlobal("fetch", mock);
    return mock;
  };

  it("forwards only numbered availability and prevents caching", async () => {
    upstream({ ok: true, booked: [9, 8, 8], company: "Private company", contact: "private" });
    const response = await boothAvailability();
    expect(response.statusCode).toBe(200);
    expect(JSON.parse(response.body)).toEqual({ ok: true, booked: [8, 9] });
    expect(response.headers["Cache-Control"]).toBe("no-store");
  });
  it("keeps backend HTTP credentials on the server", async () => {
    vi.stubEnv("BOOTH_API_USERNAME", "staff-server");
    vi.stubEnv("BOOTH_API_PASSWORD", "test-server-password");
    const fetchMock = upstream({ ok: true, booked: [8] });
    const response = await boothAvailability();
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe(
      `Basic ${Buffer.from("staff-server:test-server-password").toString("base64")}`,
    );
    expect(fetchMock.mock.calls[0][1].redirect).toBe("error");
    expect(response.body).not.toContain("staff-server");
    expect(response.body).not.toContain("password");
  });
  it("preserves stable ID 44 for the reservation displayed as booth 39", async () => {
    upstream({ ok: true, booked: [1, 5, 7, 15, 16, 17, 18, 19, 20, 23, 43, 44] });
    const response = await boothAvailability();
    expect(response.statusCode).toBe(200);
    expect(JSON.parse(response.body).booked).toEqual([1, 5, 7, 15, 16, 17, 18, 19, 20, 23, 43, 44]);
  });
  it("accepts the new reservation ID 45 without confusing it with visible booth 40", async () => {
    upstream({ ok: true, booked: [40, 45] });
    expect(JSON.parse((await boothAvailability()).body)).toEqual({ ok: true, booked: [40, 45] });
  });
  it.each([4, 13, 14, 29, 31])("refuses removed position ID %i", async (number) => {
    upstream({ ok: true, booked: [number] });
    expect((await boothAvailability()).statusCode).toBe(503);
  });
  it("never lets visitors write reservations through the Node route", async () => {
    const fetchMock = upstream({ ok: true, booked: [] });
    expect((await boothAvailability("POST")).statusCode).toBe(405);
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it("reports missing configuration as unavailable", async () => {
    vi.stubEnv("BOOTH_API_URL", "");
    expect((await boothAvailability()).statusCode).toBe(503);
  });
  it("reports backend failure without exposing its response", async () => {
    upstream({ error: "private server detail" }, 500);
    const response = await boothAvailability();
    expect(response.statusCode).toBe(503);
    expect(response.body).not.toContain("private server");
  });
  it("reports a failed connection without claiming booths are free", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("private connection details")));
    const response = await boothAvailability();
    expect(response.statusCode).toBe(503);
    expect(JSON.parse(response.body)).toEqual({ ok: false, error: "availability_unavailable" });
  });
  it.each([["8"], [0], [46], ["private company"], Array(45).fill(8)])(
    "rejects malformed or out-of-range booked numbers: %j",
    async (...values) => {
      upstream({ ok: true, booked: values });
      expect((await boothAvailability()).statusCode).toBe(503);
    },
  );
  it("refuses to send backend credentials over a remote HTTP connection", async () => {
    vi.stubEnv("BOOTH_API_URL", "http://backend.example/api/booths.php");
    const fetchMock = upstream({ ok: true, booked: [] });
    expect((await boothAvailability()).statusCode).toBe(503);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("the approved 40-position exhibition plan", () => {
  const plan = JSON.parse(
    readFileSync(new URL("../data/booth-tiers.json", import.meta.url), "utf8"),
  );
  const booths = buildBooths(plan.rows);
  it("keeps all previous labels stable and adds a new stable ID for visible booth 40", () => {
    expect(booths.map((booth) => booth.displayNumber)).toEqual(
      Array.from({ length: 40 }, (_, i) => i + 1),
    );
    expect(booths.find((booth) => booth.displayNumber === 40)?.number).toBe(45);
    expect(booths.find((booth) => booth.number === 40)?.displayNumber).toBe(35);
    expect(booths.filter((booth) => booth.tier === "bronze")).toHaveLength(13);
    expect(booths.filter((booth) => booth.tier === "silver")).toHaveLength(14);
  });
  it("fits four smaller Bronze booths and the new Silver booth into the existing row", () => {
    const row = [22, 23, 24, 25, 40].map((label) => {
      const booth = booths.find((booth) => booth.displayNumber === label);
      if (!booth) throw new Error("Missing approved map position");
      return booth;
    });
    for (const booth of row.slice(0, 4)) {
      expect(booth.tier).toBe("bronze");
      expect(booth.size).toBe("2 × 2.25 m");
    }
    expect(row[4].size).toBe("2 × 3 m");
    /** @param {number[][]} points */
    const area = (points) =>
      Math.abs(
        points.reduce((sum, p, i) => {
          const next = points[(i + 1) % points.length];
          return sum + p[0] * next[1] - next[0] * p[1];
        }, 0) / 2,
      );
    const outer = [
      [85, 496],
      [104, 470],
      [123, 444],
      [142, 418],
      [161, 392],
      [179, 363],
      [199, 377],
      [180, 405],
      [163, 433],
      [144, 459],
      [125, 485],
      [106, 511],
    ];
    expect(
      Math.abs(row.reduce((sum, booth) => sum + area(booth.points), 0) - area(outer)),
    ).toBeLessThan(0.02);
    for (let i = 0; i < row.length - 1; i++)
      expect(
        row[i].points.filter((p) => row[i + 1].points.some((q) => p[0] === q[0] && p[1] === q[1])),
      ).toHaveLength(2);
  });
});
