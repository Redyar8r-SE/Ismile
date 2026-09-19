// The list editors: workshops, sponsor tiers, partners, and the program.
// main.js passes a small context so this file does not import it back.
import { LANGS } from "./fields.js?v=13";

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
    const options = field.options || (extras.options || []).map((o) => (Array.isArray(o) ? o : [o, o]));
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

// A time box: hour, minutes and AM / PM. Stored as "HH:MM" on a 24-hour clock.
function timeField(session, key, label, ctx) {
  const cell = el("label", "fcell time");
  cell.append(el("span", "fcode", label));
  const row = el("div", "timebox");

  const [rawH, rawM] = String(session[key] || "").split(":");
  let hour24 = Number(rawH);
  if (!Number.isFinite(hour24)) hour24 = 9;
  const minutes = (rawM || "00").padStart(2, "0");

  const hours = el("select", "plain");
  for (let h = 1; h <= 12; h++) {
    const option = el("option", null, String(h));
    option.value = String(h);
    hours.append(option);
  }
  hours.value = String(hour24 % 12 === 0 ? 12 : hour24 % 12);

  const mins = el("select", "plain");
  for (let m = 0; m < 60; m += 5) {
    const value = String(m).padStart(2, "0");
    const option = el("option", null, value);
    option.value = value;
    mins.append(option);
  }
  if (![...mins.options].some((o) => o.value === minutes)) {
    const extra = el("option", null, minutes);
    extra.value = minutes;
    mins.append(extra);
  }
  mins.value = minutes;

  const half = el("select", "plain");
  [["AM", "AM"], ["PM", "PM"]].forEach(([value, text]) => {
    const option = el("option", null, text);
    option.value = value;
    half.append(option);
  });
  half.value = hour24 < 12 ? "AM" : "PM";

  const write = () => {
    let h = Number(hours.value) % 12;
    if (half.value === "PM") h += 12;
    session[key] = `${String(h).padStart(2, "0")}:${mins.value}`;
    ctx.markDirty();
  };
  [hours, mins, half].forEach((box) => box.addEventListener("change", write));

  row.append(hours, el("span", "tsep", ":"), mins, half);
  cell.append(row);
  return cell;
}

// ---------- session types ----------
export function buildTypes(section, ctx) {
  // Renaming or adding a type must also redraw the session dropdowns.
  const own = { ...ctx, markDirty: () => { ctx.markDirty(); ctx.refresh?.("program"); } };
  const list = el("div", "rows");
  section.append(list);

  const add = el("button", "btn btn-outline add-row", "+ Add a type");
  add.type = "button";
  add.addEventListener("click", () => {
    const types = ctx.program().types;
    let id = "type1";
    for (let n = 1; types[id]; n++) id = `type${n}`;
    types[id] = { en: "", ar: "", ku: "" };
    render();
    own.markDirty();
  });
  section.append(add);

  function usedBy(id) {
    return ctx.program().days.reduce((count, day) => count + day.sessions.filter((s) => s.type === id).length, 0);
  }

  function render() {
    const types = ctx.program().types;
    list.replaceChildren();
    Object.keys(types).forEach((id) => {
      const row = el("div", "lrow");
      const head = el("div", "lhead");
      const uses = usedBy(id);
      head.append(el("span", "lnum", `${id} · used in ${uses} session${uses === 1 ? "" : "s"}`));
      const remove = el("button", "srow-x", "✕");
      remove.type = "button";
      remove.title = uses ? "Used by sessions — change those first" : "Remove this type";
      remove.disabled = uses > 0;
      remove.addEventListener("click", () => {
        delete types[id];
        render();
        own.markDirty();
      });
      head.append(remove);

      const body = el("div", "lbody");
      body.append(fieldRow(types, { key: id, label: "Shown on the badge", type: "i18n" }, { ...own, rerender: render }));
      row.append(head, body);
      list.append(row);
    });
  }

  ctx.register("types", render);
  render();
}

// ---------- one object, not a list (the map settings) ----------
export function buildSingle(group, section, ctx) {
  const item = ctx.data()[group.file];
  const body = el("div", "lbody plain-body");
  group.itemFields.forEach((field) => {
    body.append(fieldRow(item, field, { ...ctx, rerender: () => {} }, { options: ctx.optionsFor(group, field) }));
  });
  section.append(body);
}

// ---------- a whole list (workshops, tiers, partners) ----------
export function buildList(group, section, ctx) {
  const list = el("div", "rows");
  section.append(list);

  const add = el("button", "btn btn-outline add-row", `+ Add a ${group.itemName}`);
  add.type = "button";
  add.addEventListener("click", () => {
    const items = ctx.getItems(group);
    items.push(group.newItem());
    render();
    changed();
    const box = list.querySelector(".lrow:last-child input, .lrow:last-child select");
    box?.scrollIntoView({ behavior: "smooth", block: "center" });
    box?.focus();
  });
  section.append(add);

  // Some lists feed another one (tiers fill the sponsor dropdown).
  function changed() {
    ctx.markDirty();
    (group.refreshes || []).forEach((id) => ctx.refresh?.(id));
  }

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
      const info = group.rowInfo ? group.rowInfo(item, ctx.data()) : null;
      head.append(el("span", "lnum", info?.label ? `${index + 1} · ${info.label}` : String(index + 1)));
      const remove = el("button", "srow-x", "✕");
      remove.type = "button";
      remove.disabled = Boolean(info?.lock);
      remove.title = info?.lock ? info.lockReason || "In use — change that first" : `Remove this ${group.itemName}`;
      remove.addEventListener("click", () => {
        items.splice(index, 1);
        render();
        changed();
      });
      head.append(remove);

      const body = el("div", "lbody");
      const rowCtx = { ...ctx, rerender: render, markDirty: changed };
      group.itemFields.forEach((field) => {
        body.append(fieldRow(item, field, rowCtx, { options: ctx.optionsFor(group, field) }));
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
    const label = (key) => {
      const value = types[key];
      const text = value && typeof value === "object" ? value.en || value.ar || value.ku : value;
      return text ? `${text} (${key})` : key;
    };
    return Object.keys(types).map((key) => [key, label(key)]).concat([["break", "Break"]]);
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
        const shead = el("div", "shead");
        shead.append(el("span", "lnum", `Session ${sessionIndex + 1}`));
        const top = el("div", "srow-top");

        const typeCell = el("label", "fcell type");
        typeCell.append(el("span", "fcode", "Type"));
        const typeSelect = el("select", "plain");
        typeOptions().forEach(([value, text]) => {
          const option = el("option", null, text);
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

        shead.append(removeSession);
        top.append(timeField(session, "start", "Start", ctx), timeField(session, "end", "End", ctx), typeCell);
        item.append(shead, top);
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

      body.append(el("p", "flabel", `Sessions (${day.sessions.length})`), sessions, addSession);
      card.append(head, body);
      wrap.append(card);
    });
  }

  ctx.register("program", render);
  render();
}
