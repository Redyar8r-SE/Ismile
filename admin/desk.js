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
    if (answer.short) return;   // empty box
    if (!answer.people.length) {
      const empty = el("p", "desk-empty", `Nobody found for “${answer.q}”. Try only the first name, or the last 4 digits of the phone. Not registered yet? Register them first in the Register tab.`);
      box.append(empty);
      return;
    }
    const count = answer.count ?? answer.people.length;
    box.append(el("p", "dk-count", `${count} ${count === 1 ? "person" : "people"} found`));
    const list = el("div", "dk-people");
    for (const p of answer.people) {
      const row = el(p.url ? "a" : "div", "dk-person" + (p.url ? "" : " is-off"));
      if (p.url) row.href = p.url;
      const initials = p.name.split(" ").slice(0, 2).map((w) => w.charAt(0)).join("").toUpperCase();
      row.append(el("span", "dk-avatar", initials));
      const text = el("span", "dk-person-text");
      text.append(el("b", "", p.name));
      // Details as small chips (no separator dots).
      const line = el("span", "dk-chips-line");
      const email = el("span", "dk-mini", p.email);
      email.dir = "ltr";
      line.append(email, el("span", "dk-mini is-phone", `WhatsApp ${p.phone}`), el("span", "dk-mini is-ticket", p.ticket));
      if (p.guest) line.append(el("span", "dk-mini is-guest", "Guest ticket"));
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

// Age: minus and plus buttons, like the website (starts at 28, 16 to 120).
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-age-step]");
  if (!button) return;
  const input = button.closest("[data-age]").querySelector('input[name="age"]');
  const now = Number(input.value || input.placeholder || 28);
  input.value = String(Math.min(120, Math.max(16, now + Number(button.dataset.ageStep))));
});

// Workshop: "Are you sure?" with the name, email, WhatsApp number, workshop and
// payment, before anything is sent. "Yes, register" sends the form.
document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("form[data-ws-confirm]");
  const box = document.getElementById("ws-confirm");
  if (!form || !box || typeof box.showModal !== "function") return;
  const field = (name) => box.querySelector(`[data-ws-field="${name}"]`);
  let confirmed = false;
  let submitter = null;
  form.addEventListener("submit", (event) => {
    if (confirmed) return;
    event.preventDefault();
    if (!form.reportValidity()) return;   // for example "I have received the cash" not ticked
    submitter = event.submitter || form.querySelector("button");
    field("name").textContent = form.dataset.name;
    field("email").textContent = form.dataset.email;
    field("phone").textContent = form.dataset.phone;
    field("workshop").textContent = form.dataset.workshop;
    field("amount").textContent = submitter?.dataset.amount || "";
    box.showModal();
  });
  box.querySelector("[data-ws-back]").addEventListener("click", () => box.close());
  box.addEventListener("click", (event) => { if (event.target === box) box.close(); });   // a click outside goes back
  box.querySelector("[data-ws-yes]").addEventListener("click", (event) => {
    event.currentTarget.disabled = true;
    confirmed = true;
    box.close();
    if (typeof form.requestSubmit === "function") form.requestSubmit(submitter);
    else form.submit();
  });
});
