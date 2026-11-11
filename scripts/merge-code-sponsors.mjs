// Run by the deploy, on the server, in the new release folder.
// The live sponsor list is the copy edited in Site content (CONTENT_ROOT), which
// a deploy never overwrites. A sponsor added in the code's data/sponsors.json is
// copied into that live list once — at the end of its own tier — so a push is
// enough to publish it. Each code sponsor is applied only once (remembered in
// .code-sponsors-applied.json), so one removed later in Site content stays
// removed. The first run only records the current code sponsors.
import { existsSync, readFileSync, renameSync, statSync, chownSync, writeFileSync, copyFileSync, mkdirSync } from "node:fs";
import path from "node:path";

const release = process.cwd();
const envFile = path.join(release, ".env");
const fromEnv = existsSync(envFile) ? /^CONTENT_ROOT=(.*)$/m.exec(readFileSync(envFile, "utf8"))?.[1]?.trim() : "";
const contentRoot = process.env.CONTENT_ROOT || fromEnv || path.join(release, ".site-content");
const liveFile = path.join(contentRoot, "data", "sponsors.json");
const ledgerFile = path.join(contentRoot, ".code-sponsors-applied.json");
const key = (s) => `${String(s.name).trim().toLowerCase()}|${s.tier}`;

const code = JSON.parse(readFileSync(path.join(release, "data", "sponsors.json"), "utf8"));
if (!Array.isArray(code.sponsors)) throw new Error("The code's data/sponsors.json has no sponsors list.");
if (!existsSync(liveFile)) {
  console.log("Code sponsors: no live sponsor list yet; the website uses the code's list.");
  process.exit(0);
}
const raw = readFileSync(liveFile, "utf8");
const live = JSON.parse(raw);
if (!Array.isArray(live.sponsors)) throw new Error("The live sponsors.json has no sponsors list.");

const firstRun = !existsSync(ledgerFile);
const applied = new Set(firstRun ? [] : JSON.parse(readFileSync(ledgerFile, "utf8")));
const liveKeys = new Set(live.sponsors.map(key));
const added = [];
if (!firstRun) {
  for (const sponsor of code.sponsors) {
    const k = key(sponsor);
    if (applied.has(k) || liveKeys.has(k)) continue;
    // Place it after the last live sponsor of the same tier (or at the end).
    let at = live.sponsors.length;
    for (let i = live.sponsors.length - 1; i >= 0; i--) {
      if (live.sponsors[i].tier === sponsor.tier) { at = i + 1; break; }
    }
    live.sponsors.splice(at, 0, sponsor);
    liveKeys.add(k);
    added.push(sponsor.name);
  }
}
for (const sponsor of code.sponsors) applied.add(key(sponsor));

const writeLike = (file, text, like) => {
  const temporary = `${file}.code-sponsors-writing`;
  writeFileSync(temporary, text, { mode: 0o644 });
  if (like) {
    const { uid, gid } = statSync(like);
    try { chownSync(temporary, uid, gid); } catch { /* not owner: keep ours */ }
  }
  renameSync(temporary, file);
};
if (added.length) {
  const backups = path.join(path.dirname(contentRoot), "content-backups");
  mkdirSync(backups, { recursive: true, mode: 0o700 });
  copyFileSync(liveFile, path.join(backups, `sponsors-before-code-${Date.now()}.json`));
  writeLike(liveFile, JSON.stringify(live, null, 2) + "\n", liveFile);
}
writeLike(ledgerFile, JSON.stringify([...applied].sort(), null, 2) + "\n", liveFile);
console.log(firstRun
  ? `Code sponsors: first run, ${applied.size} recorded; nothing changed.`
  : added.length ? `Code sponsors: added ${added.join(", ")}.` : "Code sponsors: nothing new.");
