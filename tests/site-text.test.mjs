import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

// The browser's registry is outside the server TypeScript project.
const registryURL = new URL("../js/admin/fields.js", import.meta.url);
const { groupsForText } = await import(registryURL.href);

const pages = ["index.html", "workshops.html", "register.html", "sponsor.html", "payment.html"];
/** @param {string} name */
const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), "utf8");
/** @type {Record<string, Record<string, string>>} */
const dictionaries = Object.fromEntries(
  ["en", "ar", "ku"].map((code) => [code, JSON.parse(read(`data/i18n/site-${code}.json`))]),
);
const keys = new Set(Object.keys(dictionaries.en));
for (const page of pages) {
  const html = read(page);
  for (const match of html.matchAll(
    /data-i18n(?:-(?:ph|label|alt|title|content|edition))?="([^"]+)"/g,
  )) {
    keys.add(match[1]);
  }
  keys.add(/data-title-key="([^"]+)"/.exec(html)?.[1] || "page_title");
}

describe("public translations and complete content editing", () => {
  it.each(["ar", "ku"])("has a %s translation for every public text key", (code) => {
    for (const key of keys) {
      expect(dictionaries[code][key], `${code}: ${key}`).toBeTypeOf("string");
      expect(dictionaries[code][key].trim(), `${code}: ${key}`).not.toBe("");
    }
  });

  it.each(["ar", "ku"])("retains all English placeholders in %s messages", (code) => {
    /** @param {string} text */
    const placeholders = (text) => (text.match(/\{[^}]+\}/g) || []).sort();
    for (const [key, text] of Object.entries(dictionaries.en)) {
      expect(placeholders(dictionaries[code][key]), `${code}: ${key}`).toEqual(placeholders(text));
    }
  });

  it("exposes every public key and future keys in the admin", () => {
    const english = Object.fromEntries([...keys].map((key) => [key, dictionaries.en[key] || key]));
    english.future_public_text = "Future website text";
    /** @type {{id: string, blocks: {fields?: {key: string}[]}[]}[]} */
    const groups = groupsForText(english);
    const editable = new Set(
      groups.flatMap((group) =>
        group.blocks.flatMap((block) => (block.fields || []).map((field) => field.key)),
      ),
    );
    expect([...keys].filter((key) => !editable.has(key))).toEqual([]);
    expect(editable.has("future_public_text")).toBe(true);
    expect(groups.find((group) => group.id === "payment")).toBeDefined();
  });

  it("uses the same editable language files on every page", () => {
    for (const entry of ["main", "workshops-page", "register", "sponsor", "payment"]) {
      const source = read(`js/${entry}.js`);
      for (const code of ["en", "ar", "ku"]) {
        expect(source).toContain(`data/i18n/site-${code}.json`);
      }
      expect(source).not.toContain("data/i18n/registration-");
    }
    expect(read("js/admin/main.js")).toMatch(/data\/i18n\/site-\$\{lang\}\.json/);
    expect(read("js/admin/main.js")).toContain('"payment.html"');
  });
});
