// Payment status page (payment.html): where the payment page sends people
// back, and the person's own status link from the emails.
//
// It never decides anything itself. It asks the server (api/status.php),
// which checks with the payment company, and shows what the server says:
// checking, paid and registered (with the QR ticket), not completed (with
// "Try again"), too late (fill in the form again), or cancelled.
import { loadJSON } from "./utils/load-json.js?v=78";
import { initI18n, addLanguage, initLangSwitch, preferredLang, setLang, t, onLangChange } from "./i18n.js?v=78";
import { initNav } from "./components/nav.js?v=78";
import { initTheme } from "./components/theme.js?v=78";
import { initFooter } from "./sections/footer.js?v=78";

const FAST_TRIES = 40;      // every 3 seconds for the first two minutes
const SLOW_TRIES = 40;      // then every 15 seconds for ten more minutes

function initPayment() {
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const ref = params.get("r") || "";
  const key = params.get("k") || "";
  const paymentId = params.get("p") || "";
  const problem = params.get("e") || "";
  const card = $("pmCard");

  let view = { state: "checking", title: "pm_checking_title", text: "pm_checking_text" };
  let status = null;
  let tries = 0;

  function render() {
    card.dataset.state = view.state;
    $("pmTitle").textContent = t(view.title);
    $("pmText").textContent = t(view.text);
    $("pmHello").hidden = !status?.firstName;
    if (status?.firstName) $("pmHello").textContent = t("pm_hello").replace("{name}", status.firstName);
    $("pmRefBox").hidden = !status?.ref;
    $("pmRef").textContent = status?.ref || "";
    const paid = view.state === "paid";
    $("pmTicket").hidden = !paid;
    $("pmWorkshops").hidden = !paid;
    if (paid && status.qr && $("pmQr").getAttribute("src") !== status.qr) $("pmQr").src = status.qr;
    $("pmTicketNo").textContent = paid ? status.ticketNo : "";
    const retry = $("pmRetry");
    retry.hidden = !view.retry && !view.again;
    if (view.retry) retry.href = `api/pay.php?r=${encodeURIComponent(ref)}&k=${encodeURIComponent(key)}`;
    if (view.again) retry.href = "register.html";
    retry.textContent = t(view.again ? "pm_again" : "pm_retry");
  }

  function show(next) {
    view = next;
    render();
  }

  async function poll() {
    let answer;
    try {
      const url = `api/status.php?r=${encodeURIComponent(ref)}&k=${encodeURIComponent(key)}${paymentId ? `&p=${encodeURIComponent(paymentId)}` : ""}`;
      const response = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
      answer = await response.json();
    } catch {
      answer = null;
    }
    tries++;

    if (answer && answer.ok === false && answer.error === "link") {
      show({ state: "failed", title: "pm_link_title", text: "pm_link_text" });
      return;
    }
    if (answer && answer.ok) {
      status = answer;
      if (answer.status === "paid" || answer.status === "complimentary") {
        show({ state: "paid", title: "pm_paid_title", text: "pm_paid_text" });
        return;
      }
      if (answer.status === "expired") {
        show({ state: "failed", title: "pm_expired_title", text: "pm_expired_text", again: true });
        return;
      }
      if (answer.status === "cancelled") {
        show({ state: "failed", title: "pm_cancelled_title", text: "pm_cancelled_text" });
        return;
      }
      // Unpaid: still checking, or this attempt did not go through.
      if (problem === "pay_expired") {
        show({ state: "failed", title: "pm_expired_title", text: "pm_expired_text", again: true });
        return;
      }
      if (problem === "lunch_full") {
        show({ state: "failed", title: "pm_lunch_title", text: "pm_lunch_text", again: true });
        return;
      }
      if (problem === "reg_full" || problem === "reg_closed") {
        show({ state: "failed", title: `${problem}_title`, text: `${problem}_text`, again: problem === "reg_full" });
        return;
      }
      if (problem === "pay_start_failed") {
        show({ state: "failed", title: "pm_unpaid_title", text: "pm_start_failed", retry: true });
        return;
      }
      if (answer.payment === "failed" || answer.payment === "expired" || answer.payment === null || (!paymentId && answer.payment !== "waiting")) {
        show({ state: "failed", title: answer.payment ? "pm_failed_title" : "pm_unpaid_title", text: answer.payment ? "pm_failed_text" : "pm_unpaid_text", retry: true });
        return;
      }
      if (answer.payment === "mismatch" || answer.payment === "duplicate") {
        show({ state: "checking", title: "pm_checking_title", text: "pm_slow_text" });
        return;
      }
    }

    // Still waiting for the bank / the payment company.
    if (tries < FAST_TRIES) {
      show({ state: "checking", title: "pm_checking_title", text: "pm_checking_text" });
      setTimeout(poll, 3000);
    } else if (tries < FAST_TRIES + SLOW_TRIES) {
      show({ state: "checking", title: "pm_checking_title", text: "pm_slow_text" });
      setTimeout(poll, 15000);
    } else {
      show({ state: "checking", title: "pm_checking_title", text: "pm_slow_text" });
    }
  }

  onLangChange(render);

  if (!ref || !key) {
    show({ state: "failed", title: "pm_link_title", text: problem === "busy" ? "err_busy" : "pm_link_text" });
    return;
  }
  render();
  poll();
}

async function start() {
  initNav();
  initTheme();
  try {
    const [english, footer] = await Promise.all([loadJSON("data/i18n/en.json"), loadJSON("data/footer.json")]);
    initI18n(english);
    const [arabic, kurdish] = await Promise.all([
      loadJSON("data/i18n/ar.json").catch(() => null),
      loadJSON("data/i18n/ku.json").catch(() => null),
    ]);
    if (arabic) addLanguage("ar", arabic);
    if (kurdish) addLanguage("ku", kurdish);
    initFooter(footer);
    initLangSwitch();
    setLang(preferredLang());
    initPayment();
  } catch (error) {
    console.error(error);
  } finally {
    if (typeof window.siteReady === "function") window.siteReady();
  }
}

start();
