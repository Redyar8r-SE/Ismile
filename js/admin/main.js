// iSmile admin: edit the site's text in three languages and save it to GitHub.
import { GROUPS, LANGS, HTML_FALLBACK } from "./fields.js";
import { getToken, setToken, whoAmI, readFile, writeFile, REPO } from "./github.js";

const $ = (id) => document.getElementById(id);
const filePath = (lang) => `data/i18n/${lang}.json`;

// Everything the page knows, per language: the whole file plus what was loaded.
const state = { files: {}, original: {}, dirty: false };

// ---------- loading ----------
async function loadLanguage(lang) {
  const response = await fetch(`${filePath(lang)}?t=${Date.now()}`);
  if (!response.ok) throw new Error(`Could not read ${filePath(lang)}`);
  return response.json();
}

async function loadAll() {
  for (const { code } of LANGS) {
    const data = await loadLanguage(code);
    if (code === "en") {
      // English lives in index.html until it is edited here for the first time.
      for (const [key, value] of Object.entries(HTML_FALLBACK)) {
        if (!(key in data)) data[key] = value;
      }
    }
    state.files[code] = data;
    state.original[code] = JSON.stringify(data);
  }
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
    tab.addEventListener("click", () => showGroup(group.id));
    tab.dataset.for = group.id;
    nav.append(tab);

    const section = document.createElement("section");
    section.className = "group";
    section.id = `group-${group.id}`;
    section.hidden = index !== 0;
    section.innerHTML = `<h2>${group.title}</h2><p class="ghint">${group.hint}</p>`;

    group.fields.forEach((field) => {
      const row = document.createElement("div");
      row.className = "field" + (field.short ? " short" : "");
      row.innerHTML = `<p class="flabel">${field.label}</p>`;
      const langs = document.createElement("div");
      langs.className = "flangs";

      LANGS.forEach(({ code, label, dir }) => {
        const cell = document.createElement("label");
        cell.className = "fcell";
        const id = `f-${field.key}-${code}`;
        const value = state.files[code]?.[field.key] ?? "";
        const input = field.type === "area"
          ? document.createElement("textarea")
          : document.createElement("input");
        if (field.type === "area") input.rows = 3;
        input.id = id;
        input.value = value;
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

    main.append(section);
  });
}

function showGroup(id) {
  document.querySelectorAll(".group").forEach((s) => { s.hidden = s.id !== `group-${id}`; });
  document.querySelectorAll(".gnav").forEach((b) => b.classList.toggle("is-active", b.dataset.for === id));
}

function markDirty() {
  state.dirty = LANGS.some(({ code }) => JSON.stringify(state.files[code]) !== state.original[code]);
  $("saveBtn").disabled = !state.dirty;
  $("dirty").hidden = !state.dirty;
}

// ---------- messages ----------
function say(text, kind = "info") {
  const box = $("msg");
  box.textContent = text;
  box.className = `msg is-${kind}`;
  box.hidden = !text;
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
  const changed = LANGS.filter(({ code }) => JSON.stringify(state.files[code]) !== state.original[code]);
  if (!changed.length) return;

  $("saveBtn").disabled = true;
  say("Saving…");
  try {
    for (const { code, label } of changed) {
      const path = filePath(code);
      const current = await readFile(path);           // newest version + its id
      const merged = { ...JSON.parse(current.text), ...state.files[code] };
      const text = `${JSON.stringify(merged, null, 2)}\n`;
      await writeFile(path, text, `Admin: update ${label} text`, current.sha);
      state.files[code] = merged;
      state.original[code] = JSON.stringify(merged);
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
  LANGS.forEach(({ code }) => {
    const text = `${JSON.stringify(state.files[code], null, 2)}\n`;
    const url = URL.createObjectURL(new Blob([text], { type: "application/json" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = `${code}.json`;
    link.click();
    URL.revokeObjectURL(url);
  });
  say("Three files downloaded. Upload them into data/i18n/ on GitHub, replacing the old ones.", "ok");
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
  window.addEventListener("beforeunload", (event) => {
    if (state.dirty) event.preventDefault();
  });

  if (getToken()) await connect(getToken());
}

start();
