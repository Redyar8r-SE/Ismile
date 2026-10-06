// Small helpers for the admin pages (no inline scripts, so the page's content
// security policy can forbid them).

// Package options carry their floor-plan numbers from the shared backend data.
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-booth-picker]").forEach((form) => {
    const packageSelect = form.querySelector('[name="package_id"]');
    const boothSelect = form.querySelector('[name="booth_number"]');
    if (!packageSelect || !boothSelect) return;
    const emptyLabel = boothSelect.options[0].textContent;
    const booked = new Set(JSON.parse(boothSelect.dataset.booked || "[]"));
    const render = () => {
      const previous = boothSelect.value;
      const numbers = JSON.parse(packageSelect.selectedOptions[0]?.dataset.booths || "[]");
      boothSelect.replaceChildren(new Option(numbers.length ? emptyLabel : "Choose a package first", ""));
      numbers.forEach((number) => {
        const own = String(number) === boothSelect.dataset.current && boothSelect.dataset.currentActive === "1";
        const taken = booked.has(number) && !own;
        const option = new Option(`Booth ${number}${taken ? " — Booked" : ""}`, String(number));
        option.disabled = taken;
        boothSelect.add(option);
      });
      boothSelect.value = numbers.some((number) => String(number) === previous) ? previous : "";
    };
    packageSelect.addEventListener("change", render);
    render();
  });
});

// The sidebar stays visible on desktop and becomes a keyboard-friendly drawer on phones.
document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.getElementById("admin-sidebar");
  const workspace = document.getElementById("admin-workspace");
  const toggle = document.querySelector(".sidebar-toggle");
  const close = document.querySelector(".sidebar-close");
  const backdrop = document.querySelector(".sidebar-backdrop");
  if (!sidebar || !workspace || !toggle || !close || !backdrop) return;
  const mobile = window.matchMedia("(max-width: 1000px)");
  let opened = false;
  document.body.classList.add("nav-enhanced");

  const setOpen = (requested, restoreFocus = true) => {
    opened = requested && mobile.matches;
    document.body.classList.toggle("sidebar-open", opened);
    toggle.setAttribute("aria-expanded", String(opened));
    toggle.setAttribute("aria-label", opened ? "Close navigation" : "Open navigation");
    backdrop.hidden = !opened;
    sidebar.inert = mobile.matches && !opened;
    workspace.inert = opened;
    if (opened) {
      sidebar.setAttribute("role", "dialog");
      sidebar.setAttribute("aria-modal", "true");
      sidebar.removeAttribute("aria-hidden");
      close.focus();
    } else {
      sidebar.removeAttribute("role");
      sidebar.removeAttribute("aria-modal");
      if (mobile.matches) sidebar.setAttribute("aria-hidden", "true");
      else sidebar.removeAttribute("aria-hidden");
      if (restoreFocus && mobile.matches) toggle.focus();
    }
  };
  const sync = () => {
    toggle.hidden = !mobile.matches;
    close.hidden = !mobile.matches;
    setOpen(false, false);
  };
  toggle.addEventListener("click", () => setOpen(!opened));
  close.addEventListener("click", () => setOpen(false));
  backdrop.addEventListener("click", () => setOpen(false));
  sidebar.addEventListener("click", (event) => {
    if (opened && event.target.closest("a")) setOpen(false);
  });
  document.addEventListener("keydown", (event) => {
    if (!opened) return;
    if (event.key === "Escape") {
      event.preventDefault();
      setOpen(false);
    } else if (event.key === "Tab") {
      const focusable = [...sidebar.querySelectorAll('a[href], button:not([hidden])')];
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  mobile.addEventListener("change", sync);
  sync();
});

// "Are you sure?" before buttons that cancel, remove or disable something.
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-confirm]");
  if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
});

// Phone registrations use the same student-ticket rule as the public form.
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("caller-form");
  if (!form) return;
  const specialty = form.querySelector('[name="specialty"]');
  const ticket = form.querySelector('[name="ticket"]');
  const fields = document.getElementById("caller-student-fields");
  const university = form.querySelector('[name="university"]');
  const sync = () => {
    const dentalStudent = specialty.value === "student";
    if (dentalStudent) ticket.value = "student";
    // Keep the select enabled so its value is included in the submitted form.
    ticket.querySelector('[value="professional"]').disabled = dentalStudent;
    const studentTicket = ticket.value === "student";
    fields.hidden = !studentTicket;
    fields.querySelectorAll("input").forEach((input) => { input.disabled = !studentTicket; });
    university.required = studentTicket;
  };
  specialty.addEventListener("change", sync);
  ticket.addEventListener("change", sync);
  sync();
});

// Check-in: scan the ticket's QR code with the phone camera, where the browser
// supports it (Chrome on Android does). Everyone else types or searches.
document.addEventListener("DOMContentLoaded", () => {
  const start = document.getElementById("scanCamera");
  const video = document.getElementById("scanVideo");
  const input = document.getElementById("scanInput");
  const form = document.getElementById("scanForm");
  if (!start || !video || !("BarcodeDetector" in window) || !navigator.mediaDevices?.getUserMedia) return;
  start.hidden = false;
  let stream = null;

  start.addEventListener("click", async () => {
    if (stream) return;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
    } catch {
      start.hidden = true;
      return;
    }
    const detector = new window.BarcodeDetector({ formats: ["qr_code"] });
    video.srcObject = stream;
    video.hidden = false;
    await video.play();
    const look = async () => {
      try {
        const codes = await detector.detect(video);
        if (codes.length) {
          stream.getTracks().forEach((track) => track.stop());
          input.value = codes[0].rawValue;
          form.submit();
          return;
        }
      } catch {
        /* keep looking */
      }
      requestAnimationFrame(look);
    };
    look();
  });
});

// Booking a workshop: show "amount received / how" only when "Paid now" is
// chosen, and put the workshop's price in the price box.
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".book-form").forEach((form) => {
    const sync = () => {
      const choice = form.querySelector('input[name="payment_status"]:checked');
      form.classList.toggle("is-paid", !!choice && choice.value === "paid");
    };
    form.addEventListener("change", (event) => {
      if (event.target.matches("[data-price-source]")) {
        const option = event.target.selectedOptions[0];
        const price = form.querySelector("[data-price-target]");
        if (price && option && option.dataset.price && option.dataset.price !== "0") price.value = option.dataset.price;
      }
      sync();
    });
    sync();
  });
});

// "Print" buttons (no inline scripts: the page's security policy forbids them).
document.addEventListener("click", (event) => {
  if (event.target.closest("[data-print]")) window.print();
});
