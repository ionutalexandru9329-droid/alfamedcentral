# TEST REPORT v0.11.37

Data: 2026-09-18

## Scop
Import automat al echivalarilor sigure din nomenclatorul Oblio in ALFAMED CENTRAL, cu prioritate pentru maparile manuale si fara mapari speculative intre canale.

## Verificari
- PHP lint: toate fisierele PHP valide.
- JavaScript: `node --check public/assets/app.js` OK.
- Schema Oblio MySQL/SQLite: `mapping_source` + `match_reason` prezente.
- Upgrade baze existente: randurile vechi raman `manual` si sunt protejate.
- `v01137_oblio_auto_mapping_regression.php`: PASS.
- `v01136_oblio_catalogue_sync_regression.php`: PASS.
- `v01135_oblio_code_mapping_regression.php`: PASS.
- `v01134_emag_write_regression.php`: PASS.
- `v01133_order_ops_regression.php`: PASS.
- `v01132_cleanup_ui_regression.php`: PASS.
- `critical_emag_regression.php`: PASS.
- `awb_local_mirror_regression.php`: PASS.
- `nonblocking_ui_regression.php`: PASS.
- `emag_client_http_smoke.php` cu server mock local: PASS.

## Protectii verificate
- `codeClient`/`codeEAN` devin mapari doar daca identificatorul exista deja pe canalul local corespunzator.
- Maparile Manuale nu sunt suprascrise sau sterse de sync.
- Maparile Auto Oblio directe sunt reconstruite la fiecare sync pentru a elimina legaturi invechite.
- Payload-urile istorice ale comenzilor pot furniza SKU/EAN fara a incetini pagina de comenzi, deoarece scanarea se face doar la sync.
- Sincronizarea automata ramane la intervalul configurat (implicit 12 ore) si pastreaza cooldown-ul de 15 minute la erori.

Rezultat: PASS
