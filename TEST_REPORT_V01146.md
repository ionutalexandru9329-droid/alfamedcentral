# TEST REPORT v0.11.46

## Scope
Reducerea consumului extern pentru preview-urile produselor eMAG RO/BG.

## Verificari
- PHP lint pe intreg proiectul.
- JavaScript syntax check.
- Toate regresiile v0.11.32-v0.11.45 au ramas verzi.
- Test nou `v01146_emag_image_budget_regression.php`: cache PNK, catalog central, limita ReefAPI, refresh manual, protectie SSRF, PNK RO/BG.

## Comportament
Ordinea de rezolvare este: cache PNK -> catalog central/WooCommerce -> pagina publica eMAG -> ReefAPI. Un cache local sanatos nu mai este reverificat automat.
