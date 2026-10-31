// The Node website reads numbered availability from the separate PHP backend.
// Any backend HTTP credentials remain on the server. Forward numbers only.
import { readFileSync } from "node:fs";

// These are immutable reservation IDs, including ID 44 (visible booth 39).
/** @type {{ rows: { numbers: number[], labels: number[], size: string }[] }} */
const plan = JSON.parse(readFileSync(new URL("../data/booth-tiers.json", import.meta.url), "utf8"));
const positionIds = new Set(plan.rows.flatMap((row) => row.numbers));

/** Upgrade map references in older persistent translations, keeping edited text.
 * @param {Record<string, unknown>} dictionary */
export function normalizeMapText(dictionary) {
  const result = { ...dictionary };
  for (const key of ["bm_intro", "pg_map_text", "pm_text"]) {
    if (typeof result[key] === "string") {
      result[key] = result[key].replace(/44|٤٤/g, (match) =>
        match === "٤٤"
          ? String(positionIds.size).replace(/\d/g, (digit) => "٠١٢٣٤٥٦٧٨٩"[Number(digit)])
          : String(positionIds.size),
      );
    }
  }
  if (typeof result.bm_size_note === "string") {
    const labels = new Map(
      plan.rows
        .filter((row) => row.size.startsWith("W "))
        .flatMap((row) => row.numbers.map((id, i) => [id, row.labels[i]])),
    );
    result.bm_size_note = result.bm_size_note.replace(
      /(?<![0-9٠-٩])(?:24|6|٢٤|٦)(?![0-9٠-٩])/g,
      (match) => {
        const arabic = /[٠-٩]/.test(match);
        const id = Number(match.replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit))));
        const label = String(labels.get(id) ?? id);
        return arabic ? label.replace(/\d/g, (digit) => "٠١٢٣٤٥٦٧٨٩"[Number(digit)]) : label;
      },
    );
  }
  return result;
}

/** @param {string} [method] */
export async function boothAvailability(method = "GET") {
  const headers = {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff",
  };
  /** @param {number} statusCode @param {unknown} body */
  const result = (statusCode, body) => ({ statusCode, headers, body: JSON.stringify(body) });
  if (method !== "GET") return result(405, { ok: false, error: "method" });
  const unavailable = () => result(503, { ok: false, error: "availability_unavailable" });
  if (!process.env.BOOTH_API_URL) return unavailable();
  try {
    const endpoint = new URL(process.env.BOOTH_API_URL);
    const local = ["localhost", "127.0.0.1", "[::1]"].includes(endpoint.hostname);
    if (endpoint.protocol !== "https:" && !(local && endpoint.protocol === "http:")) {
      return unavailable();
    }
    if (endpoint.username || endpoint.password) return unavailable();
    /** @type {Record<string, string>} */
    const upstreamHeaders = { Accept: "application/json" };
    const username = process.env.BOOTH_API_USERNAME;
    const password = process.env.BOOTH_API_PASSWORD;
    if (username || password) {
      if (!username || !password) return unavailable();
      upstreamHeaders.Authorization = `Basic ${Buffer.from(`${username}:${password}`).toString("base64")}`;
    }
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    if (!response.ok) return unavailable();
    /** @type {{ ok?: unknown, booked?: unknown }} */
    const state = await response.json();
    if (
      state?.ok !== true ||
      !Array.isArray(state.booked) ||
      state.booked.length > positionIds.size ||
      !state.booked.every((number) => Number.isInteger(number) && positionIds.has(number))
    ) {
      return unavailable();
    }
    return result(200, { ok: true, booked: [...new Set(state.booked)].sort((a, b) => a - b) });
  } catch {
    return unavailable();
  }
}
