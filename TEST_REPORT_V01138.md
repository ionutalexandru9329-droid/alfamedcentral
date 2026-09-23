# TEST REPORT v0.11.38

Data: 2026-09-18

## Scop
Corectarea sincronizarii care importa nomenclatorul Oblio, dar poate produce 0 echivalari atunci cand API-ul nu expune `codeClient`/`codeEAN`.

## Modificari verificate
- matching prin cod Oblio direct ↔ SKU/cod canal;
- matching prin legatura produsului central (SKU/EAN);
- matching prin coduri alternative/EAN atunci cand sunt disponibile;
- matching prin denumire exacta doar cand este unica pe canal si unica in Oblio;
- niciun fuzzy matching;
- maparile Manuale au prioritate si nu sunt suprascrise;
- maparile generate la sincronizare sunt reconstruite controlat;
- diagnosticul ultimei analize este persistat si afisat in Setari > Oblio.

## Teste
- PHP lint pentru toate fisierele: PASS.
- JavaScript `node --check public/assets/app.js`: PASS.
- `v01138_oblio_safe_match_regression.php`: PASS.
- `v01137_oblio_auto_mapping_regression.php`: PASS dupa actualizarea asteptarilor la noul matcher.
- `v01136_oblio_catalogue_sync_regression.php`: PASS.
- `v01135_oblio_code_mapping_regression.php`: PASS.
- `v01134_emag_write_regression.php`: PASS.
- `v01133_order_ops_regression.php`: PASS.
- `v01132_cleanup_ui_regression.php`: PASS.
- `critical_emag_regression.php`: PASS.
- `awb_local_mirror_regression.php`: PASS.
- `nonblocking_ui_regression.php`: PASS.

## Limitare mediu de build
`emag_client_http_smoke.php` nu poate rula in acest container deoarece PHP nu are cURL/allow_url_fopen pentru request-uri HTTP locale. Nu este o regresie introdusa de v0.11.38; testele statice si de regresie relevante pentru aceasta schimbare au trecut.

Rezultat: PASS pentru schimbarea v0.11.38.
