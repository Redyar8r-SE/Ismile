// node backend/tests/qr-scanner.mjs /path/to/storage/tmp/qr-test.json
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const decoder = readFileSync(new URL('../../admin/vendor/jsQR.js', import.meta.url), 'utf8');
const scanner = readFileSync(new URL('../../admin/admin.js', import.meta.url), 'utf8');
const rgba = new Uint8ClampedArray(Buffer.from(fixture.rgba, 'base64'));

async function run(mode) {
  const handlers = [];
  const timers = [];
  let stopped = 0;
  let submissions = 0;
  const element = (properties = {}) => ({ hidden: true, listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; }, ...properties });
  const elements = {
    scanCamera: element(), scanStop: element(), scanStatus: element({ textContent: '' }), scanInput: element({ value: '' }),
    scanVideo: element({ videoWidth: fixture.width, videoHeight: fixture.height, readyState: 2, async play() {} }),
    scanForm: element({ selectedDay: 2, requestSubmit() { submissions++; } }),
  };
  const box = {
    document: {
      addEventListener(type, fn) { if (type === 'DOMContentLoaded') handlers.push(fn); },
      querySelectorAll() { return []; }, querySelector() { return null; },
      getElementById(id) { return elements[id] || null; },
      createElement() { return { getContext() { return { drawImage() {}, getImageData() { return { data: rgba }; } }; } }; },
    },
    navigator: { mediaDevices: { async getUserMedia() {
      if (mode === 'denied') throw new Error('Permission denied');
      return { getTracks() { return [{ stop() { stopped++; } }]; } };
    } } },
    setTimeout(fn) { timers.push(fn); }, addEventListener() {},
  };
  box.window = box;
  if (mode === 'no-camera-api') box.navigator = {};
  if (mode === 'native' || mode === 'broken-native') box.BarcodeDetector = class {
    async detect() { if (mode === 'broken-native') throw new Error('Unsupported'); return [{ rawValue: fixture.payload }]; }
  };
  vm.createContext(box);
  if (mode !== 'missing-decoder') vm.runInContext(decoder, box);
  if (mode === 'stop') box.jsQR = () => null;
  vm.runInContext(scanner, box);
  handlers.forEach(fn => fn());
  assert.equal(elements.scanCamera.hidden, false);
  await elements.scanCamera.listeners.click();
  await new Promise(resolve => setImmediate(resolve));
  if (mode === 'stop') elements.scanStop.listeners.click();
  if (mode === 'broken-native') { await timers.shift()(); await new Promise(resolve => setImmediate(resolve)); }
  if (mode === 'no-camera-api' || mode === 'missing-decoder') {
    assert.equal(submissions, 0);
    assert.equal(elements.scanCamera.hidden, false, 'Scan QR remains visible when scanning is unavailable');
    assert.match(elements.scanStatus.textContent, mode === 'no-camera-api' ? /Camera access is unavailable/ : /QR scanner could not load/);
  } else if (mode === 'denied') {
    assert.equal(submissions, 0);
    assert.match(elements.scanStatus.textContent, /Camera could not start/);
  } else if (mode === 'stop') {
    assert.equal(submissions, 0); assert.equal(stopped, 1); assert.equal(elements.scanVideo.hidden, true);
  } else {
    assert.equal(submissions, 1); assert.equal(stopped, 1); assert.equal(elements.scanInput.value, fixture.payload);
    assert.equal(elements.scanForm.selectedDay, 2, 'QR scanning retains the selected day');
  }
  console.log(`PASS: ${mode} QR camera flow`);
}
for (const mode of ['native', 'fallback', 'broken-native', 'denied', 'stop', 'no-camera-api', 'missing-decoder']) await run(mode);

for (const wakeFromSleep of [false, true]) {
  const handlers = [];
  const events = {};
  let clock = 1000;
  let reloads = 0;
  let timer;
  const box = {
    Date: { now() { return clock; } },
    document: {
      visibilityState: 'visible',
      addEventListener(type, fn) { if (type==='DOMContentLoaded') handlers.push(fn); else events[type]=fn; },
      querySelectorAll() { return []; }, getElementById() { return null; },
      querySelector(selector) { return selector==='[data-checkin-rollover-ms]' ? { dataset: { checkinRolloverMs: '2000' } } : null; },
    },
    setTimeout(fn) { timer=fn; }, location: { reload() { reloads++; } },
  };
  box.window=box;
  vm.createContext(box); vm.runInContext(scanner,box); handlers.forEach(fn=>fn());
  clock=2999; timer(); assert.equal(reloads,0,'no early refresh before midnight');
  clock=3000;
  if (wakeFromSleep) events.visibilitychange(); else timer();
  assert.equal(reloads,1);
  console.log(`PASS: midnight scanner refresh ${wakeFromSleep ? 'after phone sleep' : 'while open'}`);
}
