// Tickets tab: find a name instantly, jump to a letter, and tick guests to
// save them as a badge batch (Create batch).
document.addEventListener("DOMContentLoaded", () => {
  const root = document.querySelector("[data-ticket-directory]");
  if (!root) return;
  const rows = [...root.querySelectorAll("tbody tr[data-search]")];
  const empty = root.querySelector("[data-ticket-empty]");
  const search = root.querySelector("[data-ticket-search]");
  const count = root.querySelector("[data-ticket-count]");
  const scroller = root.querySelector(".ticket-scroll");
  const all = root.querySelector("[data-ticket-all]");
  const labels = [...root.querySelectorAll("[data-ticket-selected]")];
  const selbar = root.querySelector("[data-ticket-selbar]");
  const batchButtons = [...root.querySelectorAll("[data-ticket-batch]")];
  const form = document.querySelector("[data-badge-batch-form]");
  const boxes = () => [...root.querySelectorAll("input[data-guest]")];
  const shownBoxes = () => boxes().filter(box => !box.closest("tr").hidden);

  const refresh = () => {
    const ticked = boxes().filter(box => box.checked).length;
    labels.forEach(label => { label.textContent = ticked ? ticked + " guest" + (ticked === 1 ? "" : "s") + " selected" : "None selected"; });
    selbar?.classList.toggle("has-selection", ticked > 0);
    batchButtons.forEach(button => { button.disabled = ticked === 0; });
    if (all) {
      const shown = shownBoxes();
      all.checked = shown.length > 0 && shown.every(box => box.checked);
      all.indeterminate = !all.checked && shown.some(box => box.checked);
    }
  };

  // Typing filters the list at once; pressing Enter still searches the whole database.
  search?.addEventListener("input", () => {
    const words = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    rows.forEach(row => {
      row.hidden = !words.every(word => row.dataset.search.includes(word));
      if (!row.hidden) shown++;
    });
    if (count) count.textContent = shown;
    if (empty) empty.hidden = shown > 0;
    refresh();
  });

  root.querySelectorAll(".ticket-az [data-letter]").forEach(button => button.addEventListener("click", () => {
    const row = rows.find(r => !r.hidden && r.dataset.letter === button.dataset.letter);
    if (!row || !scroller) return;
    const head = scroller.querySelector("thead");
    scroller.scrollTo({ top: row.offsetTop - (head ? head.offsetHeight : 0), behavior: "smooth" });
    row.classList.remove("ticket-flash");
    void row.offsetWidth;
    row.classList.add("ticket-flash");
  }));

  all?.addEventListener("change", () => { shownBoxes().forEach(box => { box.checked = all.checked; }); refresh(); });
  root.querySelector("[data-ticket-clear]")?.addEventListener("click", () => { boxes().forEach(box => { box.checked = false; }); refresh(); });
  root.addEventListener("change", event => { if (event.target.matches("input[data-guest]")) refresh(); });
  root.querySelectorAll("select[data-auto-submit]").forEach(select => select.addEventListener("change", () => select.form.requestSubmit()));

  form?.addEventListener("submit", () => {
    const ids = boxes().filter(box => box.checked).map(box => Number(box.dataset.guest));
    form.querySelector("[data-badge-guests]").value = JSON.stringify(ids);
    const ticked = ids.length;
    labels.forEach(label => { label.textContent = "Creating a batch of " + ticked + " guest(s)…"; });
    // After the browser has sent the form, so the button cannot be pressed twice.
    setTimeout(() => batchButtons.forEach(button => { button.disabled = true; }));
  });
  refresh();
});
