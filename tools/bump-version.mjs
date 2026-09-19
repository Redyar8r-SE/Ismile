// Stamps a version on every stylesheet and script the pages load.
//
//   node tools/bump-version.mjs          raise it by one
//   node tools/bump-version.mjs 20       set it to 20
//
// Why: browsers hold on to CSS and JavaScript, and GitHub Pages tells them to
// (Cache-Control: max-age=600, and often longer in practice). A page that has
// new markup but an old stylesheet looks broken rather than merely out of
// date, and a page whose script imports a file that has since been deleted
// shows nothing at all. A new number means a new address, which a browser
// cannot answer from what it already has.
//
// Run this after changing anything in css/ or js/.

import { readFileSync, writeFileSync, readdirSync, statSync } from "node:fs";
import { join, resolve } from "node:path";

const repo = resolve(new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"));
const pages = ["index.html", "admin.html"];

// Every .js under js/, at any depth.
function scripts(folder) {
  const found = [];
  for (const entry of readdirSync(folder)) {
    const path = join(folder, entry);
    if (statSync(path).isDirectory()) found.push(...scripts(path));
    else if (entry.endsWith(".js")) found.push(path);
  }
  return found;
}

// What the pages carry today.
const current = Math.max(
  0,
  ...pages.flatMap((page) =>
    [...readFileSync(join(repo, page), "utf8").matchAll(/\?v=(\d+)/g)].map((m) => Number(m[1])),
  ),
);

const asked = process.argv[2];
const version = asked ? Number(asked) : current + 1;
if (!Number.isInteger(version) || version < 1) {
  console.error(`Not a version number: ${asked}`);
  process.exit(1);
}

let touched = 0;

// The pages: stylesheets and the module they start from.
for (const page of pages) {
  const path = join(repo, page);
  const before = readFileSync(path, "utf8");
  const after = before
    .replace(/(href=")((?:css|assets)\/[^"?]+\.css)(\?v=\d+)?(")/g, `$1$2?v=${version}$4`)
    .replace(/(src=")(js\/[^"?]+\.js)(\?v=\d+)?(")/g, `$1$2?v=${version}$4`);
  if (after !== before) { writeFileSync(path, after); touched++; }
}

// The modules: every local import inside them.
for (const path of scripts(join(repo, "js"))) {
  const before = readFileSync(path, "utf8");
  const after = before.replace(
    /(from\s+")(\.\.?\/[^"?]+\.js)(\?v=\d+)?(")/g,
    `$1$2?v=${version}$4`,
  );
  if (after !== before) { writeFileSync(path, after); touched++; }
}

console.log(`Version ${current || "(none)"} -> ${version}, ${touched} file(s) changed.`);
console.log("Commit these together, or a page will ask for files at an address that is not there yet.");
