// iSmile admin: edit the site's text, speakers and photos, and save to GitHub.
import { GROUPS, LANGS, HTML_FALLBACK } from "./fields.js";
import { getToken, setToken, whoAmI, readFile, writeFile, REPO } from "./github.js";
import { upload, listPhotos, imageFromClipboard } from "./images.js";

const $ = (id) => document.getElementById(id);
const langPath = (lang) => `data/i18n/${lang}.json`;
const SPEAKERS = "data/speakers.json";

const state = {
  files: {},       // language code -> whole dictionary
  original: {},    // the same, as text, to see what changed
  speakers: [],
  speakersOriginal: "[]",
  photos: [],
  dirty: false,
  pasteTarget: null, // speaker row waiting for a pasted photo
  speakerList: null, // set while the form is built (not in the page yet)
  gallery: null,
};

// ---------- loading ----------
async function loadJSON(path) {
  const response = await fetch(`${path}?t=${Date.now()}`);
  if (!response.ok) throw new Error(`Could not read ${path}`);
  return response.json();
}

async function loadAll() {
  for (const { code } of LANGS) {
    const data = await loadJSON(langPath(code));
    if (code === "en") {
      // English lives in index.html until it is edited here for the first time.
      for (const [key, value] of Object.entries(HTML_FALLBACK)) {
        if (!(key in data)) data[key] = value;
      }
    }
    state.files[code] = data;
    state.original[code] = JSON.stringify(data);
  }
  state.speakers = await loadJSON(SPEAKERS);
  state.speakersOriginal = JSON.stringify(state.speakers);
}

// ---------- shared helpers ----------
function say(text, kind = "info") {
  const box = $("msg");
  box.textContent = text;
  box.className = `msg is-${kind}`;
  box.hidden = !text;
}

function markDirty() {
  state.dirty =
    LANGS.some(({ code }) => JSON.stringify(state.files[code]) !== state.original[code]) ||
    JSON.stringify(state.speakers) !== state.speakersOriginal;
  $("saveBtn").disabled = !state.dirty;
  $("dirty").hidden = !state.dirty;
}

const langValue = (item, field, code) =>
  (item[field] && typeof item[field] === "object" ? item[field][code] : code === "en" ? item[field] : "") || "";

function setLangValue(item, field, code, value) {
  if (!item[field] || typeof item[field] !== "object") {
    const old = typeof item[field] === "string" ? item[field] : "";
    item[field] = { en: old, ar: "", ku: "" };
  }
  item[field][code] = value;
}

// ---------- text groups ----------
function buildTextGroup(group, section) {
  group.fields.forEach((field) => {
    const row = document.createElement("div");
    row.className = "field" + (field.short ? " short" : "");
    row.innerHTML = `<p class="flabel">${field.label}</p>`;
    const langs = document.createElement("div");
    langs.className = "flangs";

    LANGS.forEach(({ code, label, dir }) => {
      const cell = document.createElement("label");
      cell.className = "fcell";
      const input = field.type === "area" ? document.createElement("textarea") : document.createElement("input");
      if (field.type === "area") input.rows = 3;
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
  list.className = "speakers";
  state.speakerList = list;
  section.append(list);

  const add = document.createElement("button");
  add.type = "button";
  add.className = "btn btn-outline add-row";
  add.textContent = "+ Add a speaker";
  add.addEventListener("click", () => {
    state.speakers.push({ name: { en: "", ar: "", ku: "" }, role: { en: "", ar: "", ku: "" }, photo: null });
    renderSpeakers();
    markDirty();
  });
  section.append(add);
  renderSpeakers();
}

function renderSpeakers() {
  const list = state.speakerList;
  if (!list) return;
  list.replaceChildren();

  if (!state.speakers.length) {
    list.innerHTML = `<p class="ghint">No speakers yet. Press “Add a speaker”.</p>`;
    return;
  }

  state.speakers.forEach((speaker, index) => {
    const row = document.createElement("div");
    row.className = "srow";
    row.addEventListener("focusin", () => { state.pasteTarget = index; });
    row.addEventListener("click", () => { state.pasteTarget = index; });

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

    const photoBtns = document.createElement("div");
    photoBtns.className = "sph-btns";
    const file = document.createElement("input");
    file.type = "file";
    file.accept = "image/*";
    file.hidden = true;
    file.addEventListener("change", async () => {
      if (file.files[0]) await uploadFor(index, file.files[0]);
      file.value = "";
    });
    const pick = document.createElement("button");
    pick.type = "button";
    pick.className = "btn btn-outline btn-sm";
    pick.textContent = speaker.photo ? "Change photo" : "Add photo";
    pick.addEventListener("click", () => file.click());
    photoBtns.append(pick, file);
    if (speaker.photo) {
      const clear = document.createElement("button");
      clear.type = "button";
      clear.className = "linkish";
      clear.textContent = "remove";
      clear.addEventListener("click", () => {
        speaker.photo = null;
        renderSpeakers();
        markDirty();
      });
      photoBtns.append(clear);
    }

    const fields = document.createElement("div");
    fields.className = "sfields";
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
        input.value = langValue(speaker, key, code);
        input.dir = dir;
        input.lang = code === "ku" ? "ckb" : code;
        input.addEventListener("input", () => {
          setLangValue(speaker, key, code, input.value);
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
      state.speakers.splice(index, 1);
      state.pasteTarget = null;
      renderSpeakers();
      markDirty();
    });

    const left = document.createElement("div");
    left.className = "sleft";
    left.append(photo, photoBtns);
    row.append(left, fields, remove);
    list.append(row);
  });
}

async function uploadFor(index, file) {
  if (!getToken()) return say("Connect your GitHub key first — photos are saved straight to the website.", "bad");
  say("Uploading the photo…");
  try {
    const { path } = await upload(file);
    state.speakers[index].photo = path;
    state.photos.unshift(path);
    renderSpeakers();
    renderPhotos();
    markDirty();
    say("Photo uploaded. Press “Save to the website” to show it on the speakers page.", "ok");
  } catch (error) {
    say(`Photo not uploaded: ${error.message}`, "bad");
  }
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
    for (const one of file.files) await uploadPhoto(one);
    file.value = "";
  });

  drop.addEventListener("click", () => file.click());
  drop.addEventListener("dragover", (event) => { event.preventDefault(); drop.classList.add("is-over"); });
  drop.addEventListener("dragleave", () => drop.classList.remove("is-over"));
  drop.addEventListener("drop", async (event) => {
    event.preventDefault();
    drop.classList.remove("is-over");
    for (const one of event.dataTransfer.files) await uploadPhoto(one);
  });

  const gallery = document.createElement("div");
  gallery.className = "gallery";
  state.gallery = gallery;

  section.append(drop, file, gallery);
  renderPhotos();
}

function renderPhotos() {
  const gallery = state.gallery;
  if (!gallery) return;
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
      try {
        await navigator.clipboard.writeText(path);
        say(`Copied: ${path}`, "ok");
      } catch {
        say(`Path: ${path}`, "info");
      }
    });
    card.append(img, caption, copy);
    gallery.append(card);
  });
}

async function uploadPhoto(file) {
  if (!getToken()) return say("Connect your GitHub key first — photos are saved straight to the website.", "bad");
  say(`Uploading ${file.name || "photo"}…`);
  try {
    const { path, width, height } = await upload(file);
    state.photos.unshift(path);
    renderPhotos();
    say(`Photo saved as ${path} (${width}×${height}). It is on the website already.`, "ok");
  } catch (error) {
    say(`Photo not uploaded: ${error.message}`, "bad");
  }
}

async function refreshPhotos() {
  if (!getToken()) return;
  try {
    state.photos = await listPhotos();
    renderPhotos();
  } catch { /* the folder may not exist yet */ }
}

// ---------- form ----------
function buildForm() {
  const nav = $("groupNav");
  const main = $("groups");
  nav.replaceChildren();
  main.replaceChildren();

  GROUPS.forEach((group, index) => {
    const tab = document.createElement("button");
    tab.type = "button";
    tab.className = "gnav" + (index === 0 ? " is-active" : "");
    tab.textContent = group.title;
    tab.dataset.for = group.id;
    tab.addEventListener("click", () => showGroup(group.id));
    nav.append(tab);

    const section = document.createElement("section");
    section.className = "group";
    section.id = `group-${group.id}`;
    section.hidden = index !== 0;
    section.innerHTML = `<h2>${group.title}</h2><p class="ghint">${group.hint}</p>`;

    if (group.kind === "speakers") buildSpeakers(section);
    else if (group.kind === "photos") buildPhotos(section);
    else buildTextGroup(group, section);

    main.append(section);
  });
}

function showGroup(id) {
  document.querySelectorAll(".group").forEach((s) => { s.hidden = s.id !== `group-${id}`; });
  document.querySelectorAll(".gnav").forEach((b) => b.classList.toggle("is-active", b.dataset.for === id));
}

// ---------- connection ----------
async function connect(token) {
  setToken(token);
  try {
    const user = await whoAmI();
    $("who").textContent = user;
    $("connected").hidden = false;
    $("connectForm").hidden = true;
    say(`Connected as ${user}. Changes you save go straight to the website.`, "ok");
    await refreshPhotos();
    return true;
  } catch (error) {
    setToken("");
    $("connected").hidden = true;
    $("connectForm").hidden = false;
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
    if (JSON.stringify(state.speakers) !== state.speakersOriginal) {
      const current = await readFile(SPEAKERS);
      await writeFile(SPEAKERS, `${JSON.stringify(state.speakers, null, 2)}\n`, "Admin: update speakers", current.sha);
      state.speakersOriginal = JSON.stringify(state.speakers);
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
  download("speakers.json", `${JSON.stringify(state.speakers, null, 2)}\n`);
  say("Files downloaded: the three language files go in data/i18n/, speakers.json goes in data/. Photos need a GitHub key.", "ok");
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
    say("Key removed from this browser.", "info");
  });
  $("saveBtn").addEventListener("click", saveToGitHub);
  $("downloadBtn").addEventListener("click", downloadFiles);

  // Ctrl + V anywhere: a pasted picture goes to the speaker row you last
  // clicked, otherwise into the photo list.
  document.addEventListener("paste", async (event) => {
    const file = imageFromClipboard(event);
    if (!file) return;
    event.preventDefault();
    const target = state.pasteTarget;
    if (target !== null && state.speakers[target]) await uploadFor(target, file);
    else { showGroup("photos"); await uploadPhoto(file); }
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
