// Workshop & Register: results appear while the volunteer types a name,
// phone, email or reference. Without this script the Find button still works.
document.addEventListener("DOMContentLoaded", () => {
  const input = document.querySelector("[data-desk-find]");
  const box = document.querySelector("[data-desk-results]");
  if (!input || !box) return;
  const workshop = input.dataset.deskFind;
  let timer = null;
  let asked = 0;

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function show(answer) {
    box.replaceChildren();
    if (answer.short) {
      if (answer.q) box.append(el("p", "dk-hint", "Type at least 2 letters or 4 digits."));
      return;
    }
    if (!answer.people.length) {
      const empty = el("p", "desk-empty", `Nobody found for “${answer.q}”. Try only the first name, or the last 4 digits of the phone. Not registered yet? Register them first in the Register tab.`);
      box.append(empty);
      return;
    }
    const list = el("div", "dk-people");
    for (const p of answer.people) {
      const row = el(p.url ? "a" : "div", "dk-person" + (p.url ? "" : " is-off"));
      if (p.url) row.href = p.url;
      const initials = p.name.split(" ").slice(0, 2).map((w) => w.charAt(0)).join("").toUpperCase();
      row.append(el("span", "dk-avatar", initials));
      const text = el("span", "dk-person-text");
      text.append(el("b", "", p.name));
      const line = el("small", "", `${p.email} · ${p.phone} · ${p.ref} · ${p.ticket}`);
      line.dir = "ltr";
      text.append(line);
      if (p.why) text.append(el("small", "dk-why", p.why));
      row.append(text);
      if (p.url) row.append(el("em", "", "Choose"));
      list.append(row);
    }
    box.append(list);
  }

  async function ask() {
    const q = input.value.trim();
    const mine = ++asked;
    if (q === "") { box.replaceChildren(); return; }
    try {
      const url = `desk.php?${new URLSearchParams({ tab: "workshop", ws: workshop, q, live: "1" })}`;
      const response = await fetch(url, { headers: { Accept: "application/json" }, cache: "no-store" });
      if (!response.ok) return;
      const answer = await response.json();
      if (mine === asked) show(answer);   // only the latest answer is shown
    } catch {
      // The Find button still works.
    }
  }

  input.addEventListener("input", () => {
    clearTimeout(timer);
    timer = setTimeout(ask, 250);
  });
});
