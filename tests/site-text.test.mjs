import { readFileSync } from "node:fs";
import { afterEach, describe, expect, it, vi } from "vitest";

// The browser's registry is outside the server TypeScript project.
const registryURL = new URL("../js/admin/fields.js", import.meta.url);
const { groupsForText } = await import(registryURL.href);

const pages = [
  "index.html",
  "workshops.html",
  "workshop.html",
  "register.html",
  "sponsor.html",
  "payment.html",
];
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
  it.each(["ar", "ku"])("translates every speaker profile into %s", (code) => {
    const speakers = JSON.parse(read("data/speakers.json"));
    for (const speaker of speakers) {
      for (const field of ["name", "role", "topic"]) {
        expect(speaker[field][code], `${speaker.name.en}: ${field}`).toMatch(/[\u0600-\u06ff]/);
        expect(speaker[field][code]).not.toBe(speaker[field].en);
      }
    }
  });

  it.each(["ar", "ku"])("keeps nested public content translated in %s", (code) => {
    const files = [
      "footer",
      "gallery",
      "journey",
      "program",
      "summit-partners",
      "sponsors",
      "partners",
      "projects",
      "workshops",
    ];
    /** @param {unknown} value @param {string} location */
    function check(value, location) {
      if (!value || typeof value !== "object") return;
      const entry = /** @type {Record<string, unknown>} */ (value);
      if (typeof entry.en === "string" && entry.en.trim()) {
        expect(entry[code], `${location}.${code}`).toBeTypeOf("string");
        expect(String(entry[code]).trim(), `${location}.${code}`).not.toBe("");
        // Acronyms and proper names may remain Latin; descriptive text must not.
        if (/[a-z]{4}/i.test(entry.en) && !/\.(?:name|company)$/.test(location)) {
          expect(entry[code], `${location}.${code}`).toMatch(/[\u0600-\u06ff]/);
        }
        return;
      }
      for (const [key, nested] of Object.entries(entry)) check(nested, `${location}.${key}`);
    }
    for (const file of files) check(JSON.parse(read(`data/${file}.json`)), file);
  });

  it.each(["ar", "ku"])("does not copy English prose into the %s dictionary", (code) => {
    const unchanged = new Set([
      "org_italk",
      "medx_title",
      "pay_visa_t",
      "pay_mastercard_t",
      "pay_fib_t",
      "pay_fastpay_t",
      "spf_ph_website",
      "xp_vip",
      "ph_email",
    ]);
    for (const [key, value] of Object.entries(dictionaries[code])) {
      if (/[a-z]/i.test(value) && !unchanged.has(key)) {
        expect(value, `${code}: ${key}`).toMatch(/[\u0600-\u06ff]/);
      }
      expect(value, `${code}: ${key}`).not.toMatch(/\uFFFD|[\u202a-\u202e]|\?{2,}/);
    }
  });

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
    for (const entry of [
      "main",
      "workshops-page",
      "workshop-page",
      "register",
      "sponsor",
      "payment",
    ]) {
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

describe("preferred website language", () => {
  afterEach(() => vi.unstubAllGlobals());

  it.each(["ckb", "ckb-IQ", "ku", "ku-IQ", "ar-IQ", "en-US"])(
    "recognizes %s browsers",
    async (locale) => {
      const i18nURL = new URL("../js/i18n.js", import.meta.url);
      const { addLanguage, preferredLang } = await import(i18nURL.href);
      addLanguage("ar", dictionaries.ar);
      addLanguage("ku", dictionaries.ku);
      vi.stubGlobal("navigator", { language: locale });
      vi.stubGlobal("localStorage", { getItem: () => null });
      expect(preferredLang()).toBe(
        locale.startsWith("ar") ? "ar" : locale.startsWith("en") ? "en" : "ku",
      );
    },
  );
});
