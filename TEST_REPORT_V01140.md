# TEST REPORT v0.11.40

## Verificari
- 86 fisiere PHP verificate cu `php -l`: OK.
- Toate testele de regresie non-HTTP existente: OK.
- `tests/v01140_ui_products_regression.php`: OK.

## Teste v0.11.40
- upload factura foloseste structura noua compacta;
- butonul de upload are inaltime/dimensiune normala;
- Module foloseste un singur badge Activ/Inactiv si elimina statusurile redundante;
- Produse are filtre Total/Publicate/In asteptare/Gunoi;
- statusurile sunt aplicate in backend, nu doar vizual;
- ordonarea este `created_at ASC, id ASC`;
- sync WooCommerce solicita `trash` prin `include_status` si are fallback la `status=any`;
- deduplicare sigura dupa SKU/EAN.

## Limitare mediu de build
`tests/emag_client_http_smoke.php` nu poate rula in acest container deoarece PHP nu are extensia cURL activa si `allow_url_fopen` nu este disponibil. Testele statice eMAG si celelalte regresii au trecut.
