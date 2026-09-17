// The list editors: workshops, sponsor tiers, partners, and the program.
// main.js passes a small context so this file does not import it back.
import { LANGS } from "./fields.js";

const el = (tag, className, text) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
};

const langValue = (item, key, code) =>
  (item[key] && typeof item[key] === "object" ? item[key][code] : code === "en" ? item[key] : "") || "";

function setLangValue(item, key, code, value) {
  if (!item[key] || typeof item[key] !== "object") {
    const old = typeof item[key] === "string" ? item[key] : "";
    item[key] = { en: old, ar: "", ku: "" };
  }
  item[key][code] = value;
  // A row where every language is empty goes back to null, which the website
  // reads as "not announced yet".
  if (!LANGS.some(({ code: c }) => item[key][c])) item[key] = null;
}

// ---------- one field of one item ----------
function fieldRow(item, field, ctx, extras = {}) {
  const row = el("div", "field");
  row.append(el("p", "flabel", field.label));

  if (field.type === "i18n") {
    const langs = el("div", "flangs");
    LANGS.forEach(({ code, label, dir }) => {
      const cell = el("label", "fcell");
      cell.append(el("span", "fcode", label));
      const input = el("input");
      input.value = langValue(item, field.key, code);
      input.dir = dir;
      input.lang = code === "ku" ? "ckb" : code;
      input.addEventListener("input", () => {
        setLangValue(item, field.key, code, input.value);
        ctx.markDirty();
      });
      cell.append(input);
      langs.append(cell);
    });
    row.append(langs);
    return row;
  }

  if (field.type === "image") {
    const wrap = el("div", "imgfield");
    const preview = el("div", "sphoto wide");
    if (item[field.key]) {
      const img = el("img");
      img.src = item[field.key];
      img.addEventListener("error", () => { preview.replaceChildren(el("span", "sph-empty", "not found")); });
      preview.append(img);
    } else {
      preview.append(el("span", "sph-empty", "No image"));
    }
    const file = el("input");
    file.type = "file";
    file.accept = "image/*";
    file.hidden = true;
    file.addEventListener("change", async () => {
      if (file.files[0]) await ctx.uploadImage(file.files[0], (path) => { item[field.key] = path; });
      file.value = "";
    });
    const pick = el("button", "btn btn-outline btn-sm", item[field.key] ? "Change" : "Add image");
    pick.type = "button";
    pick.addEventListener("click", () => file.click());
    const buttons = el("div", "sph-btns");
    buttons.append(pick, file);
    if (item[field.key]) {
      const clear = el("button", "linkish", "remove");
      clear.type = "button";
      clear.addEventListener("click", () => { item[field.key] = null; ctx.rerender(); ctx.markDirty(); });
      buttons.append(clear);
    }
    wrap.append(preview, buttons);
    row.append(wrap);
    return row;
  }

  const input = el(field.type === "select" ? "select" : "input");
  input.className = "plain";
  if (field.type === "select") {
    const options = field.options || (extras.options || []).map((o) => [o, o]);
    options.forEach(([value, text]) => {
      const option = el("option", null, text ?? value);
      option.value = value;
      input.append(option);
    });
    input.value = item[field.key] ?? options[0]?.[0] ?? "";
    input.addEventListener("change", () => { item[field.key] = input.value; ctx.markDirty(); });
  } else if (field.type === "number") {
    input.type = "number";
    input.min = "0";
    input.value = item[field.key] ?? 0;
    input.addEventListener("input", () => { item[field.key] = Number(input.value) || 0; ctx.markDirty(); });
  } else if (field.type === "checkbox") {
    input.type = "checkbox";
    input.checked = Boolean(item[field.key]);
    input.addEventListener("change", () => {
      if (input.checked) item[field.key] = true; else delete item[field.key];
      ctx.markDirty();
    });
  } else if (field.type === "color") {
    input.type = "color";
    input.value = /^#[0-9a-f]{6}$/i.test(item[field.key] || "") ? item[field.key] : "#ffffff";
    input.addEventListener("input", () => { item[field.key] = input.value; ctx.markDirty(); });
  } else {
    input.value = item[field.key] ?? "";
    input.addEventListener("input", () => { item[field.key] = input.value; ctx.markDirty(); });
  }
  row.append(input);
  return row;
}

// ---------- a whole list (workshops, tiers, partners) ----------
export function buildList(group, section, ctx) {
  const list = el("div", "rows");
  section.append(list);

  const add = el("button", "btn btn-outline add-row", `+ Add a ${group.itemName}`);
  add.type = "button";
  add.addEventListener("click", () => {
    ctx.getItems(group).push(group.newItem());
    render();
    ctx.markDirty();
  });
  section.append(add);

  function render() {
    const items = ctx.getItems(group);
    list.replaceChildren();
    if (!items.length) {
      list.append(el("p", "ghint", `Nothing here yet. Press “Add a ${group.itemName}”.`));
      return;
    }
    items.forEach((item, index) => {
      const row = el("div", "lrow");
      const head = el("div", "lhead");
      head.append(el("span", "lnum", String(index + 1)));
      const remove = el("button", "srow-x", "✕");
      remove.type = "button";
      remove.title = `Remove this ${group.itemName}`;
      remove.addEventListener("click", () => {
        items.splice(index, 1);
        render();
        ctx.markDirty();
      });
      head.append(remove);

      const body = el("div", "lbody");
      group.itemFields.forEach((field) => {
        body.append(fieldRow(item, field, { ...ctx, rerender: render }, { options: ctx.optionsFor(group, field) }));
      });

      row.append(head, body);
      list.append(row);
    });
  }

  ctx.register(group.id, render);
  render();
}

// ---------- the program: days, each with sessions ----------
export function buildProgram(section, ctx) {
  const wrap = el("div", "rows");
  section.append(wrap);

  const addDay = el("button", "btn btn-outline add-row", "+ Add a day");
  addDay.type = "button";
  addDay.addEventListener("click", () => {
    const program = ctx.program();
    program.days.push({
      id: String(program.days.length + 1),
      label: { en: "", ar: "", ku: "" },
      subtitle: { en: "", ar: "", ku: "" },
      sessions: [],
    });
    render();
    ctx.markDirty();
  });
  section.append(addDay);

  function typeOptions() {
    const types = ctx.program().types || {};
    return Object.keys(types).concat("break").map((key) => [key, key]);
  }

  function render() {
    const program = ctx.program();
    wrap.replaceChildren();

    program.days.forEach((day, dayIndex) => {
      const card = el("div", "lrow");
      const head = el("div", "lhead");
      head.append(el("span", "lnum", `Day ${dayIndex + 1}`));
      const removeDay = el("button", "srow-x", "✕");
      removeDay.type = "button";
      removeDay.title = "Remove this day";
      removeDay.addEventListener("click", () => {
        program.days.splice(dayIndex, 1);
        render();
        ctx.markDirty();
      });
      head.append(removeDay);

      const body = el("div", "lbody");
      body.append(fieldRow(day, { key: "label", label: "Day name", type: "i18n" }, { ...ctx, rerender: render }));
      body.append(fieldRow(day, { key: "subtitle", label: "Day subtitle", type: "i18n" }, { ...ctx, rerender: render }));

      const sessions = el("div", "sessions");
      day.sessions.forEach((session, sessionIndex) => {
        const item = el("div", "session");
        const top = el("div", "srow-top");

        const time = (key, label) => {
          const cell = el("label", "fcell small");
          cell.append(el("span", "fcode", label));
          const input = el("input", "plain");
          input.value = session[key] || "";
          input.placeholder = "09:00";
          input.addEventListener("input", () => { session[key] = input.value; ctx.markDirty(); });
          cell.append(input);
          return cell;
        };
        const typeCell = el("label", "fcell small");
        typeCell.append(el("span", "fcode", "Type"));
        const typeSelect = el("select", "plain");
        typeOptions().forEach(([value]) => {
          const option = el("option", null, value);
          option.value = value;
          typeSelect.append(option);
        });
        typeSelect.value = session.type || "clinical";
        typeSelect.addEventListener("change", () => { session.type = typeSelect.value; ctx.markDirty(); });
        typeCell.append(typeSelect);

        const removeSession = el("button", "srow-x", "✕");
        removeSession.type = "button";
        removeSession.title = "Remove this session";
        removeSession.addEventListener("click", () => {
          day.sessions.splice(sessionIndex, 1);
          render();
          ctx.markDirty();
        });

        top.append(time("start", "Start"), time("end", "End"), typeCell, removeSession);
        item.append(top);
        item.append(fieldRow(session, { key: "title", label: "Session title", type: "i18n" }, { ...ctx, rerender: render }));
        if (session.type !== "break") {
          item.append(fieldRow(session, { key: "location", label: "Room", type: "i18n" }, { ...ctx, rerender: render }));
        }
        sessions.append(item);
      });

      const addSession = el("button", "btn btn-outline btn-sm add-row", "+ Add a session");
      addSession.type = "button";
      addSession.addEventListener("click", () => {
        day.sessions.push({ start: "", end: "", type: "clinical", title: { en: "", ar: "", ku: "" }, location: { en: "", ar: "", ku: "" }, topic: null, speaker: null });
        render();
        ctx.markDirty();
      });

      body.append(el("p", "flabel", "Sessions"), sessions, addSession);
      card.append(head, body);
      wrap.append(card);
    });
  }

  ctx.register("program", render);
  render();
}
