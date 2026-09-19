// Photos: shrink what the visitor pastes, then put it in assets/uploads/ on GitHub.
import { writeBinary } from "./store.js?v=10";

export const UPLOAD_DIR = "assets/uploads";
const MAX_SIDE = 1600;      // big enough for a full-width photo, small enough to load fast
const QUALITY = 0.85;

// A safe, unique file name: "Dr Ali photo.PNG" -> "dr-ali-photo-8f3k2a.webp"
function fileName(original) {
  const base = (original || "photo")
    .replace(/\.[^.]+$/, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40) || "photo";
  const stamp = Math.random().toString(36).slice(2, 8);
  return `${base}-${stamp}.webp`;
}

// Draw the picture on a canvas at a sane size and hand back webp bytes.
export async function prepare(file) {
  if (!file.type.startsWith("image/")) throw new Error("That file is not a picture.");
  if (file.type === "image/svg+xml") throw new Error("SVG pictures are not supported here. Use PNG or JPG.");

  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
  const width = Math.round(bitmap.width * scale);
  const height = Math.round(bitmap.height * scale);

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  canvas.getContext("2d").drawImage(bitmap, 0, 0, width, height);
  bitmap.close?.();

  const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/webp", QUALITY));
  if (!blob) throw new Error("This browser could not read the picture.");
  if (blob.size > 5 * 1024 * 1024) throw new Error("The picture is too big even after shrinking.");

  return { blob, width, height, name: fileName(file.name) };
}

export async function upload(file) {
  const { blob, width, height, name } = await prepare(file);
  const path = `${UPLOAD_DIR}/${name}`;
  const bytes = new Uint8Array(await blob.arrayBuffer());
  await writeBinary(path, bytes, `Admin: add photo ${name}`);
  return { path, width, height, size: blob.size };
}

// Used by the paste handler: the first image on the clipboard, if there is one.
export function imageFromClipboard(event) {
  const items = [...(event.clipboardData?.items || [])];
  const item = items.find((i) => i.kind === "file" && i.type.startsWith("image/"));
  return item ? item.getAsFile() : null;
}

