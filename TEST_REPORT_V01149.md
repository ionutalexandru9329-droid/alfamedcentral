# TEST REPORT v0.11.49

## Scop
Eliminarea thumbnail-urilor gresite din comenzile eMAG si resincronizarea automata numai din surse care pot demonstra identitatea exacta a produsului.

## Protectii introduse
- PNK exact obligatoriu pentru metadata publica eMAG; un canonical/redirect catre alt produs este respins.
- Sunt acceptate ca sursa eMAG numai imaginile de produs de pe CDN-ul eMAG (`/products/`).
- Un raspuns `product_offer/read` fara PNK nu poate furniza imagine pentru un PNK deja cunoscut.
- Fallback-ul central nu mai accepta SKU simplu, part number simplu sau nume simplu; sunt permise doar maparea manuala, EAN unic, SKU+EAN sau SKU+nume exact.
- `OrderOperations` si `SyncService` verifica provenienta media inainte de afisare/reintroducere in `order_items`.
- Cache-ul vechi si maparile automate vechi sunt invalidate o singura data la upgrade; maparile manuale sunt pastrate.
- Thumbnail-urile suspecte/lipsa intra la retry automat in maximum 5 minute; cele verificate sunt reverificate periodic, implicit la 6 ore.

## Verificari executate
- PHP lint: 63 fisiere verificate, PASS.
- JavaScript `node --check public/assets/app.js`: PASS.
- toate testele de regresie non-HTTP existente: PASS.
- `v01145_emag_image_resolver_regression.php`: PASS.
- `v01146_emag_image_budget_regression.php`: PASS.
- `v01147_emag_free_search_resolver_regression.php`: PASS.
- `v01148_emag_official_catalogue_link_regression.php`: PASS.
- `v01149_emag_thumbnail_integrity_regression.php`: PASS.
- `emag_client_http_smoke.php` cu server mock local, inclusiv `product_offer/read` dupa PNK: PASS.

## Limitare de verificare
Mediul de build are extensia PDO, dar nu are driver PDO MySQL/SQLite, deci migrarea nu a putut fi executata aici pe o baza de date reala. Nu au fost folosite credentialele eMAG de productie. Migrarea este idempotenta si este acoperita prin verificari statice/regresie; dupa instalare se va executa o singura data pe baza reala si va reconstrui cache-ul pe masura ce comenzile sunt deschise sau workerul ruleaza.

Rezultat build: PASS.
