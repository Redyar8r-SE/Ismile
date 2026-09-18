// Works out where the map should point.
// Accepts, in order: a Google Maps link (or the whole embed code), exact
// coordinates, or a plain place name.
export function readMapsLink(input) {
  if (!input) return {};
  const text = String(input).trim();
  // "Share -> Embed a map" gives a whole <iframe ...> block
  const src = text.startsWith("<") ? (text.match(/src="([^"]+)"/i)?.[1] || "") : text;
  if (!src) return {};

  const result = {};
  const zoom = src.match(/[,/](\d{1,2})(?:\.\d+)?z/);
  if (zoom) result.zoom = Number(zoom[1]);

  // .../@35.5608,45.4347,17z/...
  const at = src.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
  if (at) return { ...result, target: `${at[1]},${at[2]}` };

  // ...!3d35.5608!4d45.4347 (inside embed links)
  const pin = src.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);
  if (pin) return { ...result, target: `${pin[1]},${pin[2]}` };

  try {
    const url = new URL(src);
    const query = url.searchParams.get("q") || url.searchParams.get("query") || url.searchParams.get("destination");
    if (query) return { ...result, target: query };
  } catch { /* not a full address */ }

  // .../place/Grand+Millennium+Sulaimani/...
  const place = src.match(/\/place\/([^/@?]+)/);
  if (place) return { ...result, target: decodeURIComponent(place[1]).replace(/\+/g, " ") };

  // A short link (maps.app.goo.gl) cannot be opened from the browser.
  if (/goo\.gl|maps\.app/.test(src)) return { ...result, short: true };
  return result;
}

export function mapTarget(map = {}) {
  const fromLink = readMapsLink(map.url);
  const target = (map.coordinates || "").trim() || fromLink.target || (map.place || "").trim();
  const zoom = Number(map.zoom) || fromLink.zoom || 15;
  return { target, zoom, short: Boolean(fromLink.short && !map.coordinates && !map.place) };
}
