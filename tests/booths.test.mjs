import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { boothAvailability, normalizeMapText } from "../server/booths.mjs";

describe("map references in existing persistent translations", () => {
  it("updates the count and width-only booth references without replacing edited text", () => {
    const result = normalizeMapText({
      bm_intro: "My 44 numbered booths",
      bm_size_note: "الأجنحة 1 و6 و24",
      legal: "Keep this edited text",
    });
    expect(result).toEqual({
      bm_intro: "My 39 numbered booths",
      bm_size_note: "الأجنحة 1 و5 و21",
      legal: "Keep this edited text",
    });
    expect(normalizeMapText(result)).toEqual(result);
  });
  it("preserves Arabic digits when updating Kurdish map references", () => {
    const result = normalizeMapText({ bm_intro: "٤٤ بووث", bm_size_note: "١، ٦ و ٢٤" });
    expect(result).toEqual({ bm_intro: "٣٩ بووث", bm_size_note: "١، ٥ و ٢١" });
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
  it.each([["8"], [0], [45], ["private company"], Array(45).fill(8)])(
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
