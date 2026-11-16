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
// payment, before anything is sent. "Yes, register" sends the form. Everything is
// looked up when it is used, so a quiet page refresh can never leave a dead button.
(() => {
  let pending = null;   // { form, submitter } waiting for "Yes, register"
  const box = () => document.getElementById("ws-confirm");
  document.addEventListener("submit", (event) => {
    const form = event.target.closest?.("form[data-ws-confirm]");
    if (!form) return;
    if (form.dataset.confirmed === "1") { delete form.dataset.confirmed; return; }
    event.preventDefault();
    const dialog = box();
    if (!dialog || typeof dialog.showModal !== "function") {   // very old browser: send as before
      form.dataset.confirmed = "1";
      if (typeof form.requestSubmit === "function") form.requestSubmit(event.submitter); else form.submit();
      return;
    }
    if (!form.reportValidity()) return;   // for example "I have received the cash" not ticked
    const submitter = event.submitter || form.querySelector("button");
    const put = (name, text) => { const el = dialog.querySelector(`[data-ws-field="${name}"]`); if (el) el.textContent = text || ""; };
    put("name", form.dataset.name);
    put("email", form.dataset.email);
    put("phone", form.dataset.phone);
    put("workshop", form.dataset.workshop);
    put("amount", submitter?.dataset.amount);
    const yes = dialog.querySelector("[data-ws-yes]");
    if (yes) yes.disabled = false;
    pending = { form, submitter };
    dialog.showModal();
  }, true);
  document.addEventListener("click", (event) => {
    const dialog = box();
    if (!dialog) return;
    if (event.target.closest("[data-ws-back]") || event.target === dialog) { dialog.close(); pending = null; return; }
    const yes = event.target.closest("[data-ws-yes]");
    if (!yes || !pending) return;
    yes.disabled = true;
    const { form, submitter } = pending;
    pending = null;
    dialog.close();
    form.dataset.confirmed = "1";
    if (typeof form.requestSubmit === "function") form.requestSubmit(submitter);
    else form.submit();
  });
})();

// Register tab: the "Are you sure?" window also shows the gender and age.
document.addEventListener("DOMContentLoaded", () => {
  const pay = document.getElementById("caller-pay");
  const out = document.querySelector('[data-desk-field="gender-age"]');
  if (!pay || !out) return;
  pay.addEventListener("click", () => {
    const form = pay.form;
    const gender = form.querySelector('[name="gender"]');
    const age = form.querySelector('[name="age"]')?.value || "";
    const genderText = gender && gender.selectedIndex > 0 ? gender.options[gender.selectedIndex].text : "";
    out.textContent = [genderText, age ? `${age} years old` : ""].filter(Boolean).join(", ");
  });
});
