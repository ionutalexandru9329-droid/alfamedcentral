# ALFAMED CENTRAL v0.11.54 - Test Report

Date: 2026-09-19

## Scope

- eMAG thumbnails: primary source remains exact Marketplace API/order data tied to the exact PNK.
- Safe local fallback added only when SKU/external SKU is exact and the normalized product title is also exact.
- Fuzzy `sku_name_similar` mappings are not accepted for thumbnails.
- Local fallback images are cached under `public/uploads/emag-media`, marked `CATALOG_EXACT`, and displayed only when the exact PNK -> product link is safe.
- Manual uploads remain highest priority and are never overwritten automatically.
- Upgrade retries previously missing thumbnails immediately and removes older fuzzy automatic links.

## Validation

- PHP syntax across app/views/public/tests/bin: PASS
- v0.11.54 dedicated regression: PASS
- v0.11.53 regression: PASS
- v0.11.52 regression updated for the new strict fallback: PASS
- Full non-smoke PHP regression suite: PASS
- eMAG client HTTP smoke test against local mock API: PASS

Result: PASS
