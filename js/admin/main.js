// iSmile admin: edit every text, list and photo on the site, and save to GitHub.
import { GROUPS, LANGS, DATA_FILES } from "./fields.js";
import { getToken, setToken, whoAmI, readFile, writeFile, REPO } from "./github.js";
import { upload, listPhotos, imageFromClipboard } from "./images.js";
import { buildList, buildProgram } from "./lists.js";

const $ = (id) => document.getElementById(id);
const langPath = (lang) => `data/i18n/${lang}.json`;

const state = {
  files: {},        // language code -> whole dictionary
  original: {},     // same, as text, to see what changed
  data: {},         // speakers / workshops / sponsors / partners / program
  dataOriginal: {},
  photos: [],
  dirty: false,
  pasteTarget: null, // { apply(path) } waiting for a pasted picture
  renderers: {},     // group id -> redraw function
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
function say(text, kind = "info") {
  const box = $("msg");
  box.textContent = text;
  box.className = `msg is-${kind}`;
  box.hidden = !text;
  // The message sits at the top of the page: bring it into view, otherwise a
  // warning can go unnoticed while you are working further down.
  if (text && box.getBoundingClientRect().top < 60) {
    box.scrollIntoView({ behavior: "smooth", block: "center" });
  }
}

function markDirty() {
  state.dirty =
    LANGS.some(({ code }) => JSON.stringify(state.files[code]) !== state.original[code]) ||
    Object.keys(DATA_FILES).some((name) => JSON.stringify(state.data[name]) !== state.dataOriginal[name]);
  $("saveBtn").disabled = !state.dirty;
  $("dirty").hidden = !state.dirty;
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
  });
  section.append(add);

  function render() {
    const speakers = state.data.speakers;
    list.replaceChildren();
    if (!speakers.length) {
      list.innerHTML = `<p class="ghint">No speakers yet. Press “Add a speaker”.</p>`;
      return;
    }
    speakers.forEach((speaker, index) => {
      const row = document.createElement("div");
      row.className = "srow";
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

// ---------- photos ----------
function buildPhotos(section) {
  const drop = document.createElement("div");
  drop.className = "drop";
  drop.innerHTML = `<b>Paste a photo with Ctrl + V</b><span>or drop it here, or click to choose a file</span>`;

  const file = document.createElement("input");
  file.type = "file";
  file.accept = "image/*";
  file.multiple = true;
  file.hidden = true;
  file.addEventListener("change", async () => {
    for (const one of file.files) await uploadImage(one);
    file.value = "";
  });

  drop.addEventListener("click", () => file.click());
  drop.addEventListener("dragover", (event) => { event.preventDefault(); drop.classList.add("is-over"); });
  drop.addEventListener("dragleave", () => drop.classList.remove("is-over"));
  drop.addEventListener("drop", async (event) => {
    event.preventDefault();
    drop.classList.remove("is-over");
    for (const one of event.dataTransfer.files) await uploadImage(one);
  });

  const gallery = document.createElement("div");
  gallery.className = "gallery";
  section.append(drop, file, gallery);

  function render() {
    gallery.replaceChildren();
    if (!state.photos.length) {
      gallery.innerHTML = `<p class="ghint">No photos yet.</p>`;
      return;
    }
    state.photos.forEach((path) => {
      const card = document.createElement("figure");
      card.className = "gitem";
      const img = document.createElement("img");
      img.src = path;
      img.alt = "";
      img.loading = "lazy";
      const caption = document.createElement("figcaption");
      caption.textContent = path;
      const copy = document.createElement("button");
      copy.type = "button";
      copy.className = "btn btn-outline btn-sm";
      copy.textContent = "Copy path";
      copy.addEventListener("click", async () => {
        try { await navigator.clipboard.writeText(path); say(`Copied: ${path}`, "ok"); }
        catch { say(`Path: ${path}`, "info"); }
      });
      card.append(img, caption, copy);
      gallery.append(card);
    });
  }

  state.renderers.photos = render;
  render();
}

// One upload path for every picture on the page.
async function uploadImage(file, apply, redraw) {
  if (!getToken()) {
    say("Connect your GitHub key first — pictures are saved straight to the website.", "bad");
    return null;
  }
  say(`Uploading ${file.name || "picture"}…`);
  try {
    const { path, width, height } = await upload(file);
    state.photos.unshift(path);
    state.renderers.photos?.();
    if (apply) {
      apply(path);
      (redraw || (() => Object.values(state.renderers).forEach((r) => r())))();
      markDirty();
      say(`Picture added (${width}×${height}). Press “Save to the website” to show it.`, "ok");
    } else {
      say(`Picture saved as ${path} (${width}×${height}). It is on the website already.`, "ok");
    }
    return path;
  } catch (error) {
    say(`Picture not uploaded: ${error.message}`, "bad");
    return null;
  }
}

async function refreshPhotos() {
  if (!getToken()) return;
  try {
    state.photos = await listPhotos();
    state.renderers.photos?.();
  } catch { /* the folder may not exist yet */ }
}

// ---------- building the page ----------
const listContext = {
  markDirty,
  say,
  getItems: (group) => (group.listKey ? state.data[group.file][group.listKey] : state.data[group.file]),
  optionsFor: (group, field) => {
    if (field.optionsFrom === "tiers") return (state.data[group.file].tiers || []).map((tier) => tier.name);
    return [];
  },
  program: () => state.data.program,
  register: (id, render) => { state.renderers[id] = render; },
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
    tab.innerHTML = `<span class="n">${index + 1}</span><span class="t"></span><span class="hits" hidden></span>`;
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

      if (block.type === "speakers") buildSpeakers(body);
      else if (block.type === "photos") buildPhotos(body);
      else if (block.type === "program") buildProgram(body, listContext);
      else if (block.type === "list") buildList(block, body, listContext);
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

// Search: hide the fields that do not match, and show how many each step has.
function runSearch(query) {
  const needle = query.trim().toLowerCase();
  let firstHit = null;

  GROUPS.forEach((group) => {
    const section = document.getElementById(`group-${group.id}`);
    const tab = document.querySelector(`.gnav[data-for="${group.id}"]`);
    const hits = tab.querySelector(".hits");
    const fields = [...section.querySelectorAll(".field")];
    let found = 0;

    fields.forEach((field) => {
      if (!needle) { field.hidden = false; return; }
      const text = field.textContent.toLowerCase();
      const values = [...field.querySelectorAll("input, textarea")].map((i) => i.value.toLowerCase()).join(" ");
      const match = text.includes(needle) || values.includes(needle);
      field.hidden = !match;
      if (match) found += 1;
    });

    section.querySelectorAll(".block").forEach((block) => {
      const own = [...block.querySelectorAll(".field")];
      block.hidden = Boolean(needle) && own.length > 0 && own.every((f) => f.hidden);
    });

    hits.hidden = !needle || !found;
    hits.textContent = found ? String(found) : "";
    tab.classList.toggle("is-empty", Boolean(needle) && !found);
    if (needle && found && !firstHit) firstHit = group.id;
  });

  if (needle && firstHit) showGroup(firstHit);
}

// ---------- connection ----------
async function connect(token) {
  setToken(token);
  try {
    const user = await whoAmI();
    $("who").textContent = user;
    $("connected").hidden = false;
    $("connectForm").hidden = true;
    $("connState").dataset.state = "on";
    $("connText").textContent = `Connected as ${user}`;
    say(`Connected as ${user}. Changes you save go straight to the website.`, "ok");
    await refreshPhotos();
    return true;
  } catch (error) {
    setToken("");
    $("connected").hidden = true;
    $("connectForm").hidden = false;
    $("connState").dataset.state = "off";
    $("connText").textContent = "Not connected";
    say(error.message, "bad");
    return false;
  }
}

// ---------- saving ----------
async function saveToGitHub() {
  if (!state.dirty) return;
  if (!getToken()) return say("Connect your GitHub key first, or use “Download files” instead.", "bad");

  $("saveBtn").disabled = true;
  say("Saving…");
  try {
    for (const { code, label } of LANGS) {
      if (JSON.stringify(state.files[code]) === state.original[code]) continue;
      const current = await readFile(langPath(code));
      const merged = { ...JSON.parse(current.text), ...state.files[code] };
      await writeFile(langPath(code), `${JSON.stringify(merged, null, 2)}\n`, `Admin: update ${label} text`, current.sha);
      state.files[code] = merged;
      state.original[code] = JSON.stringify(merged);
    }
    for (const [name, path] of Object.entries(DATA_FILES)) {
      if (JSON.stringify(state.data[name]) === state.dataOriginal[name]) continue;
      const current = await readFile(path);
      await writeFile(path, `${JSON.stringify(state.data[name], null, 2)}\n`, `Admin: update ${name}`, current.sha);
      state.dataOriginal[name] = JSON.stringify(state.data[name]);
    }
    markDirty();
    say("Saved. The website updates in 1–2 minutes — then press Ctrl + F5 on it.", "ok");
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
  say("Files downloaded: the three language files go in data/i18n/, the rest in data/. Pictures need a GitHub key.", "ok");
}

// ---------- start ----------
async function start() {
  $("repo").textContent = `${REPO.owner}/${REPO.name}`;
  try {
    await loadAll();
  } catch (error) {
    say(`${error.message}. Open this page through the website, not by double-clicking the file.`, "bad");
    return;
  }
  buildForm();
  markDirty();

  $("connectBtn").addEventListener("click", async () => {
    const token = $("token").value.trim();
    if (!token) return say("Paste your GitHub key first.", "bad");
    if (await connect(token)) $("token").value = "";
  });
  $("disconnectBtn").addEventListener("click", () => {
    setToken("");
    $("connected").hidden = true;
    $("connectForm").hidden = false;
    $("connState").dataset.state = "off";
    $("connText").textContent = "Not connected";
    say("Key removed from this browser.", "info");
  });
  $("saveBtn").addEventListener("click", saveToGitHub);
  $("downloadBtn").addEventListener("click", downloadFiles);
  $("search").addEventListener("input", (event) => runSearch(event.target.value));

  // Ctrl + V anywhere: the picture goes to the speaker row you last clicked,
  // otherwise into the photo list.
  document.addEventListener("paste", async (event) => {
    const file = imageFromClipboard(event);
    if (!file) return;
    event.preventDefault();
    const target = state.pasteTarget;
    if (target) await uploadImage(file, target.apply, target.redraw);
    else { showGroup("photos"); await uploadImage(file); }
  });
  document.addEventListener("click", (event) => {
    if (!event.target.closest(".srow")) state.pasteTarget = null;
  });

  window.addEventListener("beforeunload", (event) => {
    if (state.dirty) event.preventDefault();
  });

  if (getToken()) await connect(getToken());
}

start();
