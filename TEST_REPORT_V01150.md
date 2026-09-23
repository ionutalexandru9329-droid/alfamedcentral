# TEST REPORT v0.11.50

## Scop
Curatarea interfetei comenzilor si mutarea mentenantei imaginilor exclusiv in preview-ul thumbnailului, pastrand sincronizarea automata si adaugand fallback manual sigur.

## Modificari
- Eliminat complet din UI-ul comenzii butonul `Leaga produsul` si modalul aferent.
- Eliminat butonul vizibil `Actualizeaza imaginile` din antetul produselor.
- Resincronizarea automata eMAG ramane activa la deschiderea comenzii.
- Thumbnailul sau placeholderul `fara imagine` deschide acelasi image preview.
- In preview, doar pentru utilizatorii cu permisiune `orders.manage`, exista:
  - `Resincronizeaza automat`;
  - `Incarca poza manual`.
- Uploadul manual este validat ca JPG/PNG/WEBP/GIF, maximum 10 MB, salvat in cache-ul local `public/uploads/emag-media` si marcat ca imagine manuala.
- Imaginea manuala are prioritate fata de auto-sync si este propagata pentru aceeasi identitate eMAG atunci cand `remote_product_id`/PNK este disponibil.
- Nicio actiune noua de imagine nu este afisata direct in tabelul comenzii.

## Verificari
- PHP lint: 98 fisiere verificate, PASS.
- JavaScript `node --check public/assets/app.js`: PASS.
- Toate testele de regresie existente: PASS.
- `v01150_order_image_preview_regression.php`: PASS.
- `critical_emag_regression.php`: PASS.
- `emag_client_http_smoke.php` cu server mock local: PASS.

## Nota
Testele folosesc mock-uri/local static checks pentru API; nu au fost folosite credentiale eMAG de productie.

Rezultat build: PASS.
