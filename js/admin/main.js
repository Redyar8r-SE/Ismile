// iSmile admin: edit every text, list and photo on the site, and save to GitHub.
import { GROUPS, LANGS, DATA_FILES } from "./fields.js?v=15";
import * as store from "./store.js?v=15";
import { upload, imageFromClipboard } from "./images.js?v=15";
import { buildList, buildProgram, buildTypes, buildSingle } from "./lists.js?v=15";
import { loadAccounts, makeAccount, check, forget, fileText, ACCOUNTS_FILE } from "./accounts.js?v=15";

// Tells the small script in admin.html that the admin code did load, so it
// does not offer to reload the page.
window.adminLoaded = true;

const $ = (id) => document.getElementById(id);
const langPath = (lang) => `data/i18n/${lang}.json`;

const state = {
  files: {},        // language code -> whole dictionary
  original: {},     // same, as text, to see what changed
  data: {},         // speakers / workshops / sponsors / partners / program
  dataOriginal: {},
  dirty: false,
  pasteTarget: null, // { apply(path) } waiting for a pasted picture
  renderers: {},     // group id -> redraw function
  accounts: [],      // who may open the admin page
};

// ---------- loading ----------
async function loadJSON(path) {
  const response = await fetch(`${path}?t=${Date.now()}`);
  if (!response.ok) throw new Error(`Could not read ${path}`);
  return response.json();
}

// The English text written in index.html is the default for every key.
async function englishFromPage() {
  const response = await fetch(`index.html?t=${Date.now()}`);
  if (!response.ok) return {};
  const page = new DOMParser().parseFromString(await response.text(), "text/html");
  const english = {};
  page.querySelectorAll("[data-i18n]").forEach((node) => {
    const key = node.dataset.i18n;
    if (!(key in english)) english[key] = node.textContent.trim();
  });
  page.querySelectorAll("[data-i18n-ph]").forEach((node) => { english[node.dataset.i18nPh] ??= node.getAttribute("placeholder") || ""; });
  page.querySelectorAll("[data-i18n-label]").forEach((node) => { english[node.dataset.i18nLabel] ??= node.getAttribute("aria-label") || ""; });
  english.page_title ??= page.querySelector("title")?.textContent.trim() || "";
  return english;
}

async function loadAll() {
  const fallback = await englishFromPage();
  for (const { code } of LANGS) {
    const data = await loadJSON(langPath(code));
    if (code === "en") {
      for (const [key, value] of Object.entries(fallback)) {
        if (!(key in data)) data[key] = value;
      }
    }
    state.files[code] = data;
    state.original[code] = JSON.stringify(data);
  }
  for (const [name, path] of Object.entries(DATA_FILES)) {
    state.data[name] = await loadJSON(path);
    state.dataOriginal[name] = JSON.stringify(state.data[name]);
  }
}

// ---------- shared ----------
let msgTimer = null;

function say(text, kind = "info") {
  const box = $("msg");
  clearTimeout(msgTimer);
  box.textContent = text;
  box.className = `msg is-${kind}`;
  box.hidden = !text;
  // Notes and confirmations clear themselves; a problem stays until it is fixed.
  if (text && kind !== "bad") msgTimer = setTimeout(() => { box.hidden = true; }, 5000);
  // The message sits at the top of the page: bring it into view, otherwise a
  // warning can go unnoticed while you are working further down.
  if (text && box.getBoundingClientRect().top < 60) {
    box.scrollIntoView({ behavior: "smooth", block: "center" });
  }
}

let savedTimer = null;

function showBadge(kind, text) {
  const badge = $("dirty");
  badge.dataset.state = kind;           // "dirty" or "saved"
  badge.querySelector(".warn-text").textContent = text;
  badge.hidden = false;
}

function markDirty() {
  const wasClean = !state.dirty;
  state.dirty =
    LANGS.some(({ code }) => JSON.stringify(state.files[code]) !== state.original[code]) ||
    Object.keys(DATA_FILES).some((name) => JSON.stringify(state.data[name]) !== state.dataOriginal[name]);
  $("saveBtn").disabled = !state.dirty;

  if (state.dirty) {
    clearTimeout(savedTimer);
    showBadge("dirty", "Not saved yet");
    // A new change makes an old "Saved" message stale.
    if (wasClean && $("msg").classList.contains("is-ok")) say("");
  } else if ($("dirty").dataset.state !== "saved") {
    $("dirty").hidden = true;
  }
}

// ---------- text groups ----------
function buildTextGroup(group, section) {
  group.fields.forEach((field) => {
    const english = state.files.en?.[field.key] ?? "";
    const long = english.length > 70;
    const row = document.createElement("div");
    row.className = "field" + (field.short ? " short" : "");
    row.innerHTML = `<p class="flabel">${field.label || field.key}</p>`;
    const langs = document.createElement("div");
    langs.className = "flangs";

    LANGS.forEach(({ code, label, dir }) => {
      const cell = document.createElement("label");
      cell.className = "fcell";
      const input = long ? document.createElement("textarea") : document.createElement("input");
      if (long) input.rows = 3;
      input.id = `f-${field.key}-${code}`;
      input.value = state.files[code]?.[field.key] ?? "";
      input.dir = dir;
      input.lang = code === "ku" ? "ckb" : code;
      input.addEventListener("input", () => {
        state.files[code][field.key] = input.value;
        markDirty();
      });
      cell.innerHTML = `<span class="fcode">${label}</span>`;
      cell.append(input);
      langs.append(cell);
    });

    row.append(langs);
    section.append(row);
  });
}

// ---------- speakers ----------
function buildSpeakers(section) {
  const list = document.createElement("div");
  list.className = "rows";
  section.append(list);

  const add = document.createElement("button");
  add.type = "button";
  add.className = "btn btn-outline add-row";
  add.textContent = "+ Add a speaker";
  add.addEventListener("click", () => {
    state.data.speakers.push({ name: { en: "", ar: "", ku: "" }, role: { en: "", ar: "", ku: "" }, photo: null });
    render();
    markDirty();
    const last = list.querySelector(".srow:last-child .lbody input");
    last?.scrollIntoView({ behavior: "smooth", block: "center" });
    last?.focus();
    say("New speaker added. Write the name, then press Save to the website.", "info");
  });
  section.append(add);

  function render() {
    const speakers = state.data.speakers;
    list.replaceChildren();
    if (!speakers.length) {
      list.innerHTML = `<p class="ghint">No speakers yet. Press “Add a speaker”.</p>`;
      return;
    }
    const flagFor = (row, speaker) => {
      const empty = isEmpty(speaker);
      row.classList.toggle("is-empty-row", empty);
      let flag = row.querySelector(":scope > .emptyflag");
      if (empty && !flag) {
        flag = document.createElement("p");
        flag.className = "emptyflag";
        flag.textContent = "Empty — shows as “Coming soon” on the website";
        row.prepend(flag);
      }
      if (!empty && flag) flag.remove();
    };

    const isEmpty = (speaker) => {
      const any = (value) => value && (typeof value === "object" ? Object.values(value).some(Boolean) : Boolean(value));
      return !any(speaker.name) && !any(speaker.role) && !speaker.photo;
    };

    speakers.forEach((speaker, index) => {
      const row = document.createElement("div");
      row.className = "srow" + (isEmpty(speaker) ? " is-empty-row" : "");
      if (isEmpty(speaker)) {
        const flag = document.createElement("p");
        flag.className = "emptyflag";
        flag.textContent = "Empty — shows as “Coming soon” on the website";
        row.append(flag);
      }
      const focus = () => { state.pasteTarget = { apply: (path) => { speaker.photo = path; } , redraw: render }; };
      row.addEventListener("focusin", focus);
      row.addEventListener("click", focus);

      const photo = document.createElement("div");
      photo.className = "sphoto";
      if (speaker.photo) {
        const img = document.createElement("img");
        img.src = speaker.photo;
        img.alt = "";
        img.addEventListener("error", () => { photo.innerHTML = `<span class="sph-empty">not found</span>`; });
        photo.append(img);
      } else {
        photo.innerHTML = `<span class="sph-empty">No photo</span>`;
      }

      const buttons = document.createElement("div");
      buttons.className = "sph-btns";
      const file = document.createElement("input");
      file.type = "file";
      file.accept = "image/*";
      file.hidden = true;
      file.addEventListener("change", async () => {
        if (file.files[0]) await uploadImage(file.files[0], (path) => { speaker.photo = path; }, render);
        file.value = "";
      });
      const pick = document.createElement("button");
      pick.type = "button";
      pick.className = "btn btn-outline btn-sm";
      pick.textContent = speaker.photo ? "Change photo" : "Add photo";
      pick.addEventListener("click", () => file.click());
      buttons.append(pick, file);
      if (speaker.photo) {
        const clear = document.createElement("button");
        clear.type = "button";
        clear.className = "linkish";
        clear.textContent = "remove";
        clear.addEventListener("click", () => { speaker.photo = null; render(); markDirty(); });
        buttons.append(clear);
      }

      const fields = document.createElement("div");
      fields.className = "lbody";
      [["name", "Name"], ["role", "Specialty, country"]].forEach(([key, label]) => {
        const block = document.createElement("div");
        block.className = "field";
        block.innerHTML = `<p class="flabel">${label}</p>`;
        const langs = document.createElement("div");
        langs.className = "flangs";
        LANGS.forEach(({ code, label: name, dir }) => {
          const cell = document.createElement("label");
          cell.className = "fcell";
          const input = document.createElement("input");
          input.value = (speaker[key] && typeof speaker[key] === "object" ? speaker[key][code] : code === "en" ? speaker[key] : "") || "";
          input.dir = dir;
          input.lang = code === "ku" ? "ckb" : code;
          input.addEventListener("input", () => {
            if (!speaker[key] || typeof speaker[key] !== "object") speaker[key] = { en: "", ar: "", ku: "" };
            speaker[key][code] = input.value;
            if (!LANGS.some(({ code: c }) => speaker[key][c])) speaker[key] = null;
            flagFor(row, speaker);
            markDirty();
          });
          cell.innerHTML = `<span class="fcode">${name}</span>`;
          cell.append(input);
          langs.append(cell);
        });
        block.append(langs);
        fields.append(block);
      });

      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "srow-x";
      remove.title = "Remove this speaker";
      remove.textContent = "✕";
      remove.addEventListener("click", () => {
        state.data.speakers.splice(index, 1);
        state.pasteTarget = null;
        render();
        markDirty();
      });

      const left = document.createElement("div");
      left.className = "sleft";
      left.append(photo, buttons);
      row.append(left, fields, remove);
      list.append(row);
    });
  }

  state.renderers.speakers = render;
  render();
}

// One upload path for every picture on the page.
async function uploadImage(file, apply, redraw) {
  if (!store.ready()) {
    say(store.mode() === "key"
      ? "Paste your GitHub key at the top of this page first, then add the picture again."
      : "Pictures cannot be added yet: saving to the website is not set up. See “Saving changes” in the README.", "bad");
    return null;
  }
  say(`Uploading ${file.name || "picture"}…`);
  try {
    const { path, width, height } = await upload(file);
    if (apply) {
      apply(path);
      (redraw || (() => Object.values(state.renderers).forEach((r) => r())))();
      markDirty();
      say(`Picture added (${width}×${height}). Press “Save to the website” to show it.`, "ok");
    } else {
      say(`Picture saved as ${path} (${width}×${height}).`, "ok");
    }
    return path;
  } catch (error) {
    say(`Picture not uploaded: ${error.message}`, "bad");
    return null;
  }
}

// Who may open the admin page. The list is saved in data/admin-accounts.json.
function buildAccounts(body) {
  const head = document.createElement("h3");
  head.className = "flabel";
  head.textContent = "People who can open the admin";

  const list = document.createElement("div");

  const form = document.createElement("div");
  form.className = "pw-grid";
  form.style.marginTop = "16px";
  form.innerHTML = `
    <label>Email<input type="email" id="acEmail" autocomplete="username"></label>
    <label>Password (at least 8 characters)<input type="password" id="acOne" autocomplete="new-password"></label>
    <label>Repeat the password<input type="password" id="acTwo" autocomplete="new-password"></label>`;

  const actions = document.createElement("div");
  actions.className = "pw-actions";
  const add = document.createElement("button");
  add.type = "button";
  add.className = "btn btn-primary btn-sm";
  add.textContent = "Add this person";
  const save = document.createElement("button");
  save.type = "button";
  save.className = "btn btn-primary btn-sm";
  save.textContent = "Save to the website";
  save.hidden = true;
  const copy = document.createElement("button");
  copy.type = "button";
  copy.className = "btn btn-outline btn-sm";
  copy.textContent = "Copy the file";
  copy.hidden = true;
  const out = document.createElement("button");
  out.type = "button";
  out.className = "btn btn-outline btn-sm";
  out.textContent = "Lock the admin now";
  actions.append(add, save, copy, out);

  const text = document.createElement("pre");
  text.className = "pw-out";
  text.hidden = true;

  const hint = document.createElement("p");
  hint.className = "ghint";
  hint.style.marginTop = "12px";

  const warn = document.createElement("p");
  warn.className = "ghint";
  warn.textContent = "This asks for a password before the admin opens. It keeps ordinary visitors out — it is not a safe for secrets, because the check happens in the browser.";

  // Nothing is written until you save or paste the file, so show what changed.
  let pending = false;

  function refresh() {
    list.replaceChildren();
    if (!state.accounts.length) {
      const none = document.createElement("p");
      none.className = "ghint";
      none.textContent = "Nobody is set yet, so the admin opens without asking. Add yourself below.";
      list.append(none);
    }
    state.accounts.forEach((account, index) => {
      const row = document.createElement("div");
      row.className = "acct";
      const who = document.createElement("span");
      who.innerHTML = `<b></b>`;
      who.querySelector("b").textContent = account.email;
      const drop = document.createElement("button");
      drop.type = "button";
      drop.className = "btn btn-outline btn-sm";
      drop.textContent = "Remove";
      drop.addEventListener("click", () => {
        if (!confirm(`Remove ${account.email}?`)) return;
        state.accounts.splice(index, 1);
        mark();
      });
      row.append(who, drop);
      list.append(row);
    });
    save.hidden = !(pending && store.ready());
    copy.hidden = !pending;
    text.hidden = !pending;
    text.textContent = pending ? fileText(state.accounts) : "";
    hint.hidden = !pending;
    hint.textContent = store.ready()
      ? "Press “Save to the website” to keep this list."
      : `Not saved yet. Press “Copy the file”, then open ${ACCOUNTS_FILE} on github.com, press the pencil, replace everything with what you copied and commit.`;
  }

  function mark() {
    pending = true;
    refresh();
  }

  add.addEventListener("click", async () => {
    const email = form.querySelector("#acEmail").value.trim();
    const one = form.querySelector("#acOne").value;
    const two = form.querySelector("#acTwo").value;
    if (!email.includes("@")) return say("Write a real email address.", "bad");
    if (one.length < 8) return say("Use a password of at least 8 characters.", "bad");
    if (one !== two) return say("The two passwords are not the same.", "bad");
    const account = await makeAccount(email, one);
    const at = state.accounts.findIndex((existing) => existing.email === account.email);
    if (at >= 0) state.accounts[at] = account;      // same email: change the password
    else state.accounts.push(account);
    form.querySelector("#acOne").value = "";
    form.querySelector("#acTwo").value = "";
    form.querySelector("#acEmail").value = "";
    mark();
    say(at >= 0 ? `Password changed for ${account.email}.` : `${account.email} added.`, "ok");
  });

  save.addEventListener("click", async () => {
    say("Saving the list…");
    try {
      await store.writeText(ACCOUNTS_FILE, fileText(state.accounts), "Admin: update who can open the admin");
      pending = false;
      refresh();
      say("Saved. The admin will ask for one of these passwords from now on.", "ok");
    } catch (error) {
      say(`Not saved: ${error.message}`, "bad");
    }
  });

  copy.addEventListener("click", async () => {
    try { await navigator.clipboard.writeText(text.textContent); say(`Copied. Paste it into ${ACCOUNTS_FILE} on github.com.`, "ok"); }
    catch { say("Select the text below and copy it.", "info"); }
  });

  out.addEventListener("click", () => location.reload());

  body.append(head, warn, list, form, actions, text, hint);
  refresh();
  state.renderers.security = refresh;
}

// ---------- building the page ----------
const listContext = {
  markDirty,
  say,
  getItems: (group) => (group.listKey ? state.data[group.file][group.listKey] : state.data[group.file]),
  optionsFor: (group, field) => {
    const label = (value) => (value && typeof value === "object" ? value.en || value.ar || value.ku : value) || "";
    // Partners match their tier by its English name; sponsors match by id.
    if (field.optionsFrom === "tiers") return (state.data[group.file].tiers || []).map((tier) => [tier.name, label(tier.name)]);
    if (field.optionsFrom === "sponsorTiers") return (state.data.sponsors.tiers || []).map((tier) => [tier.id, label(tier.name)]);
    return [];
  },
  program: () => state.data.program,
  data: () => state.data,
  register: (id, render) => { state.renderers[id] = render; },
  refresh: (id) => state.renderers[id]?.(),
  uploadImage: async (file, apply) => {
    const path = await uploadImage(file, apply);
    if (path) Object.values(state.renderers).forEach((render) => render());
  },
};

function buildForm() {
  const nav = $("groupNav");
  const main = $("groups");
  nav.replaceChildren();
  main.replaceChildren();

  GROUPS.forEach((group, index) => {
    const tab = document.createElement("button");
    tab.type = "button";
    tab.className = "gnav" + (index === 0 ? " is-active" : "");
    tab.dataset.for = group.id;
    tab.innerHTML = `<span class="n">${index + 1}</span><span class="t"></span>`;
    tab.querySelector(".t").textContent = group.title;
    tab.addEventListener("click", () => showGroup(group.id));
    nav.append(tab);

    const section = document.createElement("section");
    section.className = "group";
    section.id = `group-${group.id}`;
    section.hidden = index !== 0;

    const head = document.createElement("div");
    head.className = "ghead";
    head.innerHTML = `<h2></h2><p></p>`;
    head.querySelector("h2").textContent = `${index + 1}. ${group.title}`;
    head.querySelector("p").textContent = group.where || "";
    section.append(head);

    group.blocks.forEach((block) => {
      const card = document.createElement("div");
      card.className = "block";

      if (block.title) {
        const bhead = document.createElement("div");
        bhead.className = "bhead";
        const h3 = document.createElement("h3");
        h3.textContent = block.title;
        bhead.append(h3);
        if (block.type === "text") {
          const count = document.createElement("span");
          count.className = "count";
          count.textContent = `${block.fields.length} texts`;
          bhead.append(count);
        }
        card.append(bhead);
      }

      const body = document.createElement("div");
      body.className = "bbody";
      if (block.hint) {
        const hint = document.createElement("p");
        hint.className = "ghint";
        hint.textContent = block.hint;
        body.append(hint);
      }

      if (block.type === "security") buildAccounts(body);
      else if (block.type === "speakers") buildSpeakers(body);
      else if (block.type === "program") buildProgram(body, listContext);
      else if (block.type === "types") buildTypes(body, listContext);
      else if (block.type === "list") buildList(block, body, listContext);
      else if (block.type === "single") buildSingle(block, body, listContext);
      else buildTextGroup(block, body);

      card.append(body);
      section.append(card);
    });

    main.append(section);
  });
}

function showGroup(id) {
  document.querySelectorAll(".group").forEach((s) => { s.hidden = s.id !== `group-${id}`; });
  document.querySelectorAll(".gnav").forEach((b) => b.classList.toggle("is-active", b.dataset.for === id));
  window.scrollTo({ top: 0, behavior: "smooth" });
}

// ---------- signing in ----------
function showSignedIn(name) {
  $("who").textContent = name;
  $("connected").hidden = false;
  $("signinForm").hidden = true;
  $("connectForm").hidden = true;
  $("connState").dataset.state = "on";
  $("connText").textContent = `Signed in as ${name}`;
  if (store.repo()) $("repo").textContent = store.repo();
}

// With no server there is nothing to sign in to. Say so in the card itself
// rather than letting the sign-in fail with a puzzling error such as 405.
function showNoServer(problem) {
  const form = $("signinForm");
  form.querySelector("h2").textContent = "Saving is not switched on yet";
  form.querySelector("p").textContent = problem;
  form.querySelector(".row").hidden = true;
  $("connText").textContent = "Saving off";
}

function showSignedOut() {
  $("connected").hidden = true;
  $("signinForm").hidden = store.mode() !== "server";
  $("connectForm").hidden = store.mode() !== "key";
  $("connState").dataset.state = "off";
  $("connText").textContent = "Not signed in";
}

async function signIn(email, password) {
  try {
    const name = await store.signIn(email, password);
    showSignedIn(name);
    say("Signed in. Your changes save straight to the website.", "ok");
    return true;
  } catch (error) {
    showSignedOut();
    say(error.message, "bad");
    return false;
  }
}

// Key mode: the browser talks to GitHub itself.
async function connect(token) {
  try {
    const name = await store.connectKey(token);
    showSignedIn(name);
    say(`Connected as ${name}. What you save now goes straight to the website.`, "ok");
    return true;
  } catch (error) {
    showSignedOut();
    say(error.message, "bad");
    return false;
  }
}

// ---------- saving ----------
// Friendly names for the "are you sure" list.
const PART_NAMES = {
  en: "English text", ar: "Arabic text", ku: "Kurdish text",
  speakers: "Speakers", journey: "Years on the timeline", projects: "italk projects",
  workshops: "Workshops", sponsors: "Sponsors and tiers", partners: "Trusted partners",
  program: "Program days and sessions",
};

function whatChanged() {
  const changed = [];
  LANGS.forEach(({ code }) => {
    if (JSON.stringify(state.files[code]) !== state.original[code]) changed.push(PART_NAMES[code] || code);
  });
  Object.keys(DATA_FILES).forEach((name) => {
    if (JSON.stringify(state.data[name]) !== state.dataOriginal[name]) changed.push(PART_NAMES[name] || name);
  });
  return changed;
}

// Ask first, so nothing reaches the website by accident.
function askBeforeSaving() {
  const box = $("confirmSave");
  const list = $("confirmList");
  list.replaceChildren(...whatChanged().map((name) => {
    const item = document.createElement("li");
    item.textContent = name;
    return item;
  }));

  return new Promise((resolve) => {
    const done = (answer) => {
      box.close();
      $("confirmYes").removeEventListener("click", yes);
      $("confirmNo").removeEventListener("click", no);
      box.removeEventListener("cancel", no);
      resolve(answer);
    };
    const yes = () => done(true);
    const no = (event) => { event?.preventDefault?.(); done(false); };
    $("confirmYes").addEventListener("click", yes);
    $("confirmNo").addEventListener("click", no);
    box.addEventListener("cancel", no);
    box.showModal();
    $("confirmYes").focus();
  });
}

async function saveToGitHub() {
  if (!state.dirty) return;
  // Nothing can be written to the website until a server is set up. Your admin
  // password is a different thing: it guards this page, it cannot save.
  if (!store.ready()) {
    return say(store.mode() === "key"
      ? "Paste your GitHub key at the top of this page first, then press Save again."
      : "Saving to the website is not set up yet, so nothing was sent. Press “Download files” to get your changes as files, then put them in the data folder on github.com.", "bad");
  }
  if (!(await askBeforeSaving())) return say("Nothing was saved. Your changes are still here.", "info");

  $("saveBtn").disabled = true;
  say("Saving…");
  try {
    for (const { code, label } of LANGS) {
      if (JSON.stringify(state.files[code]) === state.original[code]) continue;
      const current = await store.readFile(langPath(code));
      const merged = { ...JSON.parse(current.text), ...state.files[code] };
      await store.writeText(langPath(code), `${JSON.stringify(merged, null, 2)}\n`, `Admin: update ${label} text`);
      state.files[code] = merged;
      state.original[code] = JSON.stringify(merged);
    }
    for (const [name, path] of Object.entries(DATA_FILES)) {
      if (JSON.stringify(state.data[name]) === state.dataOriginal[name]) continue;
      await store.writeText(path, `${JSON.stringify(state.data[name], null, 2)}\n`, `Admin: update ${name}`);
      state.dataOriginal[name] = JSON.stringify(state.data[name]);
    }
    markDirty();
    // Say it plainly on the button itself, then let it fade away.
    showBadge("saved", "All saved");
    clearTimeout(savedTimer);
    savedTimer = setTimeout(() => {
      const badge = $("dirty");
      if (badge.dataset.state === "saved") { badge.hidden = true; badge.dataset.state = "dirty"; }
    }, 4000);
    const blanks = state.data.speakers.filter((speaker) => {
      const any = (value) => value && (typeof value === "object" ? Object.values(value).some(Boolean) : Boolean(value));
      return !any(speaker.name) && !any(speaker.role) && !speaker.photo;
    }).length;
    say(blanks
      ? `Saved. Note: ${blanks} speaker${blanks > 1 ? "s are" : " is"} still empty, so ${blanks > 1 ? "they show" : "it shows"} as “Coming soon”. The website updates in 1–2 minutes.`
      : "Saved. The website updates in 1–2 minutes — then press Ctrl + F5 on it.", "ok");
  } catch (error) {
    say(`Not saved: ${error.message}`, "bad");
  } finally {
    $("saveBtn").disabled = !state.dirty;
  }
}

function downloadFiles() {
  const download = (name, text) => {
    const url = URL.createObjectURL(new Blob([text], { type: "application/json" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
  };
  LANGS.forEach(({ code }) => download(`${code}.json`, `${JSON.stringify(state.files[code], null, 2)}\n`));
  Object.keys(DATA_FILES).forEach((name) => download(`${name}.json`, `${JSON.stringify(state.data[name], null, 2)}\n`));
  say("Files downloaded: the three language files go in data/i18n/, the rest in data/. Pictures are only saved when you are signed in.", "ok");
}

// ---------- start ----------
// Asks for a password before the admin appears. Only shown when someone is
// listed in data/admin-accounts.json, so an empty list can never lock you out.
async function askForPassword() {
  const lockBox = $("lock");
  const form = $("lockForm");
  const message = $("lockMsg");
  lockBox.hidden = false;
  document.body.classList.add("lock-on");
  $("lockEmail").focus();

  await new Promise((resolve) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = $("lockBtn");
      button.disabled = true;
      const ok = await check(state.accounts, $("lockEmail").value, $("lockPass").value);
      button.disabled = false;
      if (!ok) {
        message.hidden = false;
        message.textContent = "Wrong email or password.";
        $("lockPass").select();
        return;
      }
      lockBox.hidden = true;
      document.body.classList.remove("lock-on");
      resolve();
    });
  });
}

async function start() {
  forget();                                   // clear the old "remembered" mark
  const cover = (text) => {
    if (text) $("bootText").textContent = text;
    document.body.classList.add("checking");
  };
  const uncover = () => document.body.classList.remove("checking");

  state.accounts = await loadAccounts();
  // Decide first, reveal second: the admin must never flash up before the
  // login screen covers it.
  if (state.accounts.length) {
    document.body.classList.add("lock-on");
    uncover();                                // let the login screen through
    await askForPassword();
    cover("Opening the admin…");          // and cover it again while it is built
  }

  // Says what is wrong when the server is not reachable or not set up yet.
  const problem = await store.init();
  if (store.repo()) $("repo").textContent = store.repo();

  try {
    await loadAll();
  } catch (error) {
    uncover();                                // the message must be readable
    say(`${error.message}. Open this page through the website, not by double-clicking the file.`, "bad");
    return;
  }
  buildForm();
  markDirty();
  uncover();                                  // everything is ready to use

  showSignedOut();
  $("connectBtn").addEventListener("click", async () => {
    const token = $("token").value.trim();
    if (!token) return say("Paste your GitHub key first.", "bad");
    if (await connect(token)) $("token").value = "";
  });
  $("token").addEventListener("keydown", (event) => { if (event.key === "Enter") $("connectBtn").click(); });
  $("signinBtn").addEventListener("click", async () => {
    const email = $("email").value.trim();
    const password = $("password").value;
    if (!email || !password) return say("Write your email and password.", "bad");
    if (await signIn(email, password)) $("password").value = "";
  });
  $("password").addEventListener("keydown", (event) => { if (event.key === "Enter") $("signinBtn").click(); });
  $("disconnectBtn").addEventListener("click", () => {
    store.signOut();
    showSignedOut();
    say("Signed out of this browser.", "info");
  });
  $("saveBtn").addEventListener("click", saveToGitHub);
  $("downloadBtn").addEventListener("click", downloadFiles);

  // Ctrl + V anywhere: the picture goes to the speaker row you last clicked,
  // otherwise into the photo list.
  document.addEventListener("paste", async (event) => {
    const file = imageFromClipboard(event);
    if (!file) return;
    event.preventDefault();
    const target = state.pasteTarget;
    if (target) await uploadImage(file, target.apply, target.redraw);
    else say("Click the speaker row first, then paste the photo with Ctrl + V.", "info");
  });
  document.addEventListener("click", (event) => {
    if (!event.target.closest(".srow")) state.pasteTarget = null;
  });

  window.addEventListener("beforeunload", (event) => {
    if (state.dirty) event.preventDefault();
  });

  if (store.ready()) showSignedIn(store.who());
  // Said last so it stays on screen: without the server nothing can be saved,
  // but every text is still editable and "Download files" still works.
  if (problem) showNoServer(problem);
}

start();
