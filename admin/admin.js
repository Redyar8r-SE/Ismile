// Small helpers for the admin pages (no inline scripts, so the page's content
// security policy can forbid them).

// "Are you sure?" before buttons that cancel, remove or disable something.
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-confirm]");
  if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
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
