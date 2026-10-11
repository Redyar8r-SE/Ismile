// The sponsorship packages are managed in the database on the PHP backend,
// which writes them into ITS copy of data/sponsors.json. This website serves
// its own copy, so the "tiers" are taken from the backend's file; the logos and
// contact details stay as edited here. Without a backend, the local file is used.
import { readFileSync } from "node:fs";
import { backendConnection } from "./website-state.mjs";

const CACHE_MS = 15000;
/** @type {{ at: number, tiers: unknown[] | null }} */
let cache = { at: 0, tiers: null };

/** @param {unknown} tier */
function validTier(tier) {
  if (!tier || typeof tier !== "object") return false;
  const { id, name, className, spots } = /** @type {Record<string, unknown>} */ (tier);
  return (
    typeof id === "string" &&
    /^[a-z0-9-]{1,40}$/.test(id) &&
    !!name &&
    typeof name === "object" &&
    typeof className === "string" &&
    /^tc-[a-z]{1,20}$/.test(className) &&
    Number.isInteger(spots)
  );
}

async function backendTiers() {
  if (Date.now() - cache.at < CACHE_MS) return cache.tiers;
  let tiers = null;
  try {
    const { endpoint, upstreamHeaders } = backendConnection(
      "/data/sponsors.json",
      new URLSearchParams(),
    );
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(4000),
    });
    if (response.ok) {
      const data = await response.json();
      if (Array.isArray(data?.tiers) && data.tiers.every(validTier)) tiers = data.tiers;
    }
  } catch {
    // No backend, or it is down: keep the website's own tiers.
  }
  // A failed fetch keeps the last good answer rather than flipping back.
  cache = { at: Date.now(), tiers: tiers ?? cache.tiers };
  return cache.tiers;
}

/** @param {string} localFile the website's own data/sponsors.json */
export async function sponsorsFile(localFile) {
  const data = JSON.parse(readFileSync(localFile, "utf8"));
  const tiers = await backendTiers();
  if (tiers) data.tiers = tiers;
  return {
    statusCode: 200,
    headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" },
    body: `${JSON.stringify(data, null, 2)}\n`,
  };
}
