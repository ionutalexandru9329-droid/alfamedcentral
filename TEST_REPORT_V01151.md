# ALFAMED CENTRAL v0.11.51 - eMAG image bot + preview UI

Validated on 2026-09-19:

- exact eMAG hyperlinks already present in Marketplace order payloads are preserved;
- raw order payload image/url hints are consumed only with strict product identity checks;
- public eMAG product pages have a browser-like cookie-session fallback and explicit 403/429/511 block detection;
- local catalogue fallback requires a unique SKU plus a strong product-name match (or the existing stronger EAN/manual rules);
- an unverified image is never shown as a guessed thumbnail;
- image preview is compact/responsive on desktop, tablet and mobile;
- native file input is hidden, eliminating the oversized `Choose file` control;
- manual upload and resync controls remain inside Image Preview only;
- `Leaga produsul` remains absent from order UI.

Result: PASS.
