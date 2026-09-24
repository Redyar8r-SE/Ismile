// Takes the files "Download files" just saved and puts them on the website.
//
//   node tools/publish.mjs          copy, check, commit and push
//   node tools/publish.mjs --dry    show what would happen, change nothing
//
// Why this exists: the admin page cannot write to GitHub by itself, because a
// website that only hands out files has no way to receive them. This does that
// last step from the computer the repository is already on.

import { readdirSync, statSync, readFileSync, writeFileSync, renameSync, existsSync } from "node:fs";
import { join, resolve } from "node:path";
import { execFileSync } from "node:child_process";
import { homedir } from "node:os";

const dry = process.argv.includes("--dry");
const repo = resolve(new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"));

// Where each downloaded file belongs.
const HOME = { en: "data/i18n", ar: "data/i18n", ku: "data/i18n" };
const DATA = ["speakers", "map", "footer", "journey", "projects", "gallery", "workshops",
              "sponsors", "partners", "program", "admin-accounts"];
DATA.forEach((name) => { HOME[name] = "data"; });

const downloads = process.env.DOWNLOADS || join(homedir(), "Downloads");
if (!existsSync(downloads)) {
  console.error(`No Downloads folder at ${downloads}`);
  process.exit(1);
}

// Chrome names a second copy "speakers (1).json"; the newest one wins.
const found = new Map();
for (const entry of readdirSync(downloads)) {
  const match = /^([a-z-]+)(?: \(\d+\))?\.json$/i.exec(entry);
  if (!match) continue;
  const name = match[1].toLowerCase();
  if (!(name in HOME)) continue;
  const path = join(downloads, entry);
  const when = statSync(path).mtimeMs;
  const best = found.get(name);
  if (!best || when > best.when) found.set(name, { path, when, entry });
}

if (!found.size) {
  console.log("Nothing to publish: no admin files in", downloads);
  console.log("Press “Download files” in the admin first.");
  process.exit(0);
}

const changed = [];
const same = [];
const bad = [];
const kept = [];

for (const [name, file] of [...found].sort()) {
  const target = join(repo, HOME[name], `${name}.json`);
  let text;
  try {
    text = readFileSync(file.path, "utf8");
    const incoming = JSON.parse(text);      // never publish a file that is not valid

    // Language files are merged, never replaced, exactly as the admin's own
    // save does. A download carries only the words the admin knows about, so
    // replacing would quietly delete keys that only the code uses — am, pm,
    // a_theme — and break the page.
    if (HOME[name] === "data/i18n" && existsSync(target)) {
      const held = JSON.parse(readFileSync(target, "utf8"));
      const lost = Object.keys(held).filter((key) => !(key in incoming));
      if (lost.length) kept.push(`  kept ${lost.length} key(s) the download left out: ${lost.join(", ")}`);
      text = JSON.stringify({ ...held, ...incoming }, null, 2);
    }
  } catch (error) {
    bad.push(`${file.entry}: ${error.message}`);
    continue;
  }
  if (!text.endsWith("\n")) text += "\n";
  const before = existsSync(target) ? readFileSync(target, "utf8") : "";
  if (before === text) { same.push(name); continue; }
  const age = Math.round((Date.now() - file.when) / 60000);
  changed.push({ name, target, text, from: file, age });
}

if (bad.length) {
  console.error("These files are not valid JSON and were left alone:");
  bad.forEach((line) => console.error("  " + line));
}
if (same.length) console.log(`Already up to date: ${same.join(", ")}`);
kept.forEach((line) => console.log(line));

if (!changed.length) {
  console.log("Nothing to publish: the website already has these changes.");
  process.exit(0);
}

console.log("\nWill publish:");
changed.forEach((c) => console.log(`  ${HOME[c.name]}/${c.name}.json   (downloaded ${c.age} min ago)`));

if (dry) { console.log("\n--dry: nothing was changed."); process.exit(0); }

for (const c of changed) writeFileSync(c.target, c.text);

const git = (...args) => execFileSync("git", args, { cwd: repo, encoding: "utf8" });
git("add", ...changed.map((c) => `${HOME[c.name]}/${c.name}.json`));
const what = changed.map((c) => c.name).join(", ");
git("commit", "-m", `Admin: update ${what}`);
git("push", "origin", "HEAD");

// Used files are renamed so a stale download cannot undo later work.
for (const c of changed) {
  try { renameSync(c.from.path, c.from.path.replace(/\.json$/i, ".published.json")); }
  catch { /* the file may be open; not worth stopping for */ }
}

console.log(`\nPublished ${changed.length} file(s). The website updates in a minute or two.`);
