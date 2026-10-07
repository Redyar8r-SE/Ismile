# QR decoder

`jsQR.js` and `jsQR.LICENSE` are vendored from the official
[cozmo/jsQR repository](https://github.com/cozmo/jsQR) on 6 October 2026.
Apache-2.0. The local copy lets the check-in camera decode QR codes without
contacting a CDN. Native BarcodeDetector is preferred when supported; jsQR
handles the fallback. Camera access requires HTTPS (or localhost).
