import { buildBooths } from '../js/config/booth-layout.js?v=148';
import { floorPlanSVG } from '../js/sections/floor-plan.js?v=148';

const el = (id) => document.getElementById(id);
const canManage = el('operationsMap').dataset.canManage === '1';
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = (value) => value == null ? 'Not agreed' : `${Number(value).toLocaleString('en')} IQD`;
const title = (value) => String(value ?? '').replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
let booths = [], colors = {}, bookings = new Map(), selected = null, detail = null;
let connected = false, zoom = 1, controller, timer;
const initial = Number(new URL(location.href).searchParams.get('number'));
if (Number.isInteger(initial) && initial >= 1 && initial <= 44) selected = initial;

function label(booth) {
  return `Position ${booth.number}, ${title(booth.tier)}, ${booth.size}, ${!connected ? 'availability unknown' : bookings.has(booth.number) ? `reserved by ${bookings.get(booth.number).company}` : 'available'}`;
}

function renderMap() {
  const focused = el('mapDrawing').contains(document.activeElement) ? document.activeElement?.dataset.booth : null;
  el('mapDrawing').innerHTML = floorPlanSVG({booths, colors, label, booked: new Set(bookings.keys()), selected, gatePills: true, translate: (key) => ({bm_map_label:'Exhibition floor plan',bm_hall:'Exhibition hall',bm_entrance:'Entrance',bm_exit:'Exit'}[key] || key)});
  if (focused) el('mapDrawing').querySelector(`[data-booth="${Number(focused)}"]`)?.focus({preventScroll: true});
  el('mapDrawing').style.width = `${zoom * 100}%`;
  el('mapZoomReset').textContent = `${Math.round(zoom * 100)}%`;
  el('mapZoomOut').disabled = zoom <= 1;
  el('mapZoomIn').disabled = zoom >= 2;
  el('operationsMap').classList.toggle('map-stale', !connected);
}

function renderDirectory() {
  const focused = el('mapDirectory').contains(document.activeElement) ? document.activeElement?.dataset.booth : null;
  const q = el('mapSearch').value.trim().toLowerCase(), filter = el('mapFilter').value;
  const visible = booths.filter((b) => {
    const r = bookings.get(b.number);
    const matches = /^\d{1,2}$/.test(q) ? b.number === Number(q) : [b.number, b.tier, r?.company, r?.contact_name, r?.phone, r?.ref].some((v) => String(v ?? '').toLowerCase().includes(q));
    return (!q || matches) &&
      (filter === 'all' || (connected && (filter === 'reserved' ? !!r : !r)));
  });
  el('mapDirectory').innerHTML = visible.map((b) => {
    const r = bookings.get(b.number);
    return `<button type="button" class="map-directory-item${r ? ' is-booked' : ''}${selected === b.number ? ' is-selected' : ''}" data-booth="${b.number}" aria-pressed="${selected === b.number}"><b>${b.number}</b><span><strong>${escape(title(b.tier))} · ${escape(b.size)}</strong><small>${escape(!connected ? 'Availability unknown' : r ? r.company : 'Available')}</small></span><small>${escape(r ? title(r.status) : '')}</small></button>`;
  }).join('') || '<p>No matching positions.</p>';
  if (focused) el('mapDirectory').querySelector(`[data-booth="${Number(focused)}"]`)?.focus({preventScroll: true});
}

function renderDetails() {
  if (!selected) return;
  const b = booths.find((b) => b.number === selected);
  let html = `<h2>Position ${selected}</h2><p>${escape(title(b.tier))} · ${escape(b.size)}</p>`;
  if (!connected) {
    el('mapDetails').innerHTML = html + '<p role="status">Booking details are unavailable. Reconnecting to the database...</p>';
    return;
  }
  if (!detail) {
    el('mapDetails').innerHTML = html + '<h3>Available</h3><p>No active sponsor reservation holds this position.</p>' + (canManage ? '<button type="button" class="btn green" data-book-position>Book this position</button>' : '');
    return;
  }
  const r = detail;
  const fields = [
    ['Reference', r.ref], ['Status', title(r.status)], ['Package', r.package_name],
    ['Contact', r.contact_name], ['Position / role', r.contact_role], ['Phone', r.phone],
    ['Email', r.email], ['City', r.city], ['Website / social', r.website], ['Assigned staff', r.assigned_name],
    ['Price quoted', r.price_quoted == null ? 'Not quoted' : money(r.price_quoted)],
    ['Agreed total', money(r.amount_agreed)], ['Received', money(r.amount_paid ?? 0)],
    ['Outstanding', r.amount_agreed == null ? 'Not agreed' : money(Math.max(0, Number(r.amount_agreed) - Number(r.amount_paid ?? 0)))],
    ['Payment method', title(r.paid_how)], ['Payment date', r.paid_at],
    ['Last call', r.last_call_at], ['Next follow-up', r.next_call_at], ['Created', r.created_at], ['Updated', r.updated_at],
  ];
  html += `<h3>${escape(r.company)}</h3>${canManage ? `<a class="btn" href="sponsors.php?id=${Number(r.id)}">Manage sponsor booking</a>` : ''}<dl>${fields.map(([k,v]) => `<div><dt>${escape(k)}</dt><dd>${escape(v || '—')}</dd></div>`).join('')}</dl>`;
  html += `<h3>Notes</h3><p class="map-note">${escape(r.notes || 'No internal notes.')}</p><h3>Original message</h3><p class="map-note">${escape(r.message || 'No message.')}</p><h3>Call history</h3>`;
  html += r.calls.length ? r.calls.map((c) => `<article class="map-call"><strong>${escape(title(c.outcome))}</strong><p>${escape(c.called_at)} · ${escape(c.caller || 'Staff')}</p><p class="map-note">${escape(c.note || 'No note.')}</p>${c.amount_quoted != null ? `<p>Quoted: ${money(c.amount_quoted)}</p>` : ''}${c.next_call_at ? `<p>Follow-up: ${escape(c.next_call_at)}</p>` : ''}</article>`).join('') : '<p>No calls recorded.</p>';
  el('mapDetails').innerHTML = html;
}

async function refresh() {
  clearTimeout(timer);
  controller?.abort();
  const request = new AbortController();
  controller = request;
  const timeout = setTimeout(() => request.abort(), 12000);
  try {
    const url = new URL('map.php', location.href);
    url.searchParams.set('format', 'json');
    if (selected) url.searchParams.set('number', selected);
    const response = await fetch(url, {signal: request.signal, cache: 'no-store', headers: {Accept: 'application/json'}});
    if (!response.ok) throw new Error('Map unavailable');
    const data = await response.json();
    if (!data.ok || !Array.isArray(data.bookings)) throw new Error('Invalid map response');
    if (controller !== request) return;
    bookings = new Map(data.bookings.map((r) => [Number(r.reserved_booth), r]));
    detail = data.detail;
    connected = true;
    el('mapLive').textContent = '';
    el('mapLive').hidden = true;
    el('mapLive').className = 'exhibition-live is-live';
    el('mapAvailable').textContent = 44 - bookings.size;
    el('mapBooked').textContent = bookings.size;
  } catch {
    if (controller !== request) return;
    connected = false;
    el('mapLive').hidden = false;
    el('mapLive').textContent = 'Connection unavailable · Retrying';
    el('mapLive').className = 'exhibition-live is-unavailable';
    el('mapAvailable').textContent = el('mapBooked').textContent = '—';
  } finally {
    clearTimeout(timeout);
    if (controller === request) {
      renderMap(); renderDirectory(); renderDetails();
      if (!document.hidden) timer = setTimeout(refresh, 5000);
    }
  }
}

function select(number) {
  if (!booths.some((b) => b.number === number)) return;
  selected = number; detail = null; connected = false;
  const url = new URL(location.href);
  url.searchParams.set('number', number);
  history.replaceState(null, '', url);
  renderMap(); renderDirectory(); renderDetails();
  refresh();
}

try {
  const response = await fetch('../data/booth-tiers.json', {cache: 'no-store'});
  if (!response.ok) throw new Error('Floor plan unavailable');
  const plan = await response.json();
  booths = buildBooths(plan.rows); colors = plan.colors;
  if (canManage) {
    el('mapDetails').addEventListener('click', (event) => {
      if (!event.target.closest('[data-book-position]') || !connected || bookings.has(selected)) return;
      el('mapBookingNumber').textContent = selected;
      el('mapBookingBooth').value = selected;
      const select = el('mapBookingPackage');
      select.value = '';
      const matching = [...select.options].filter((option) => {
        if (!option.value) return false;
        const valid = JSON.parse(option.dataset.booths).includes(selected);
        option.disabled = !valid; option.hidden = !valid;
        return valid;
      });
      if (matching.length === 1) select.value = matching[0].value;
      el('mapBookingPanel').hidden = false;
      el('mapBookingPanel').scrollIntoView({behavior: 'smooth', block: 'start'});
      el('mapBookingForm').elements.company.focus({preventScroll:true});
    });
    el('mapBookingCancel').addEventListener('click', () => {el('mapBookingPanel').hidden = true;});
  }
  for (const id of ['mapDrawing', 'mapDirectory']) {
    el(id).addEventListener('click', (event) => {
      const target = event.target.closest('[data-booth]');
      if (target) select(Number(target.dataset.booth));
    });
  }
  el('mapDrawing').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      const target = event.target.closest('[data-booth]');
      if (target) {event.preventDefault(); select(Number(target.dataset.booth));}
    }
  });
  el('mapSearch').addEventListener('input', renderDirectory);
  el('mapFilter').addEventListener('change', renderDirectory);
  for (const [id, change] of [['mapZoomIn', .25], ['mapZoomOut', -.25], ['mapZoomReset', 0]]) {
    el(id).addEventListener('click', () => {zoom = change ? Math.max(1, Math.min(2, zoom + change)) : 1; renderMap();});
  }
  document.addEventListener('visibilitychange', () => {clearTimeout(timer); if (!document.hidden) refresh();});
  renderMap(); renderDirectory();
  await refresh();
} catch {
  el('mapLive').textContent = 'Floor plan could not load. Reload this page to retry.';
  el('mapLive').className = 'exhibition-live is-unavailable';
}
