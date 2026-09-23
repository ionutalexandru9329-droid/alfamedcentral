# ALFAMED CENTRAL v0.11.34 - test report

Data verificare: 2026-09-18

## Scop

Corectarea celor doua erori observate in productie:

1. eMAG `order/attachments/save`: `order_id este necesar pentru fiecare atasament` dupa emiterea facturii Oblio.
2. eMAG `awb/save`: cererea este acceptata, dar numarul AWB nu este identificat local.

## Reparatii validate

- `order/attachments/save` trimite JSON `{"data":[{...}]}` cu `order_id`, `order_type`, `name`, `url`, `type`, `force_download`.
- `order_type` este preluat din comanda eMAG si normalizat la 2/3.
- `awb/save` trimite JSON `{"data":[{...}]}`.
- `awb/read` foloseste JSON top-level `emag_id` / `reservation_id` si nu mai trimite parametri de paginare.
- Daca `awb/save` returneaza numai `emag_id` / `reservation_id`, clientul face un singur `awb/read` si nu repeta `awb/save`.
- Bulk `Creeaza factura` este idempotent: o factura deja emisa cu sincronizare esuata este retrimisa catre canal, fara factura fiscala duplicata.
- Validarea reala de stoc Oblio ramane activa.

## Teste executate

- PHP lint: 81 fisiere PHP, fara erori.
- JavaScript: `node --check public/assets/app.js` - OK.
- `awb_local_mirror_regression.php` - PASS.
- `critical_emag_regression.php` - PASS.
- `nonblocking_ui_regression.php` - PASS.
- `v01132_cleanup_ui_regression.php` - PASS.
- `v01133_order_ops_regression.php` - PASS.
- `v01134_emag_write_regression.php` - PASS.
- `emag_client_http_smoke.php` impotriva mock HTTP - PASS.

Mock-ul HTTP valideaza explicit forma JSON a `awb/save`, `awb/read` si `order/attachments/save`, inclusiv scenariul in care `awb/save` intoarce initial numai identificatorii rezervarii si numarul tiparit este recuperat prin `awb/read`.

## Nota operationala

Pentru comenzile care au primit in v0.11.33 mesajul „eMAG a acceptat cererea, dar numarul AWB nu a putut fi identificat”, trebuie verificat Marketplace inainte de o noua generare; cererea veche poate sa fi creat deja AWB-ul remote.
