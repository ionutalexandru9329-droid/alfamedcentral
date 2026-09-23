# ALFAMED CENTRAL v0.11.52 - eMAG API + manual images only

Validat la 2026-09-19.

## Comportament nou
- Thumbnail-urile automate din comenzile eMAG sunt acceptate numai din payload/API eMAG, cu PNK exact si URL de imagine de pe CDN-ul de produse eMAG.
- Fluxul activ nu mai acceseaza pagina publica eMAG si nu mai foloseste fallback dupa nume/SKU catre Alfamed/Univera.
- Daca API-ul nu furnizeaza o imagine verificata, produsul ramane fara imagine; nu este afisata nicio poza aproximativa.
- Uploadul manual din Image Preview ramane prioritar si persistent.
- In Image Preview se poate face si drag & drop al unei imagini, fara butoane noi in tabelul comenzii.
- Upgrade-ul elimina o singura data media automata veche provenita din fallback-uri de catalog/public page, pastrand imaginile manuale si cache-ul cu sursa oficiala eMAG.

## Verificari
- PHP lint: 100 fisiere PHP, PASS.
- JavaScript `node --check public/assets/app.js`: PASS.
- Toate testele `*_regression.php`: PASS.
- `v01152_emag_api_manual_only_regression.php`: PASS.
- `emag_client_http_smoke.php` cu server mock local, inclusiv `product_offer/read` dupa PNK si imagine in raspuns: PASS.

## Nota
Nu au fost folosite credentiale eMAG de productie. Comportamentul API este verificat prin clientul existent si mock-ul local; disponibilitatea efectiva a campului de imagine depinde de raspunsul Marketplace pentru oferta respectiva. Cand imaginea lipseste din API, interfata ramane intentionat fara thumbnail pana la upload manual.

Rezultat build: PASS.
