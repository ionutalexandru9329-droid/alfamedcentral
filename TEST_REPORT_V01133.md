# ALFAMED CENTRAL v0.11.33 - Test report

Data: 2026-09-18

## Verificari automate

- 80 fisiere PHP verificate cu `php -l`: fara erori.
- `public/assets/app.js` verificat cu `node --check`: fara erori.
- `critical_emag_regression.php`: PASS.
- `awb_local_mirror_regression.php`: PASS.
- `nonblocking_ui_regression.php`: PASS.
- `v01132_cleanup_ui_regression.php`: PASS.
- `v01133_order_ops_regression.php`: PASS.
- `emag_client_http_smoke.php` pe mock HTTP local: PASS.

## Audit Actiuni rapide - Comenzi

### Creeaza factura
- Foloseste `InvoiceService::bulkCreate(..., invoice)`.
- Maxim 25 documente/lot pentru protectia rate-limit Oblio.
- Nu emite a doua factura fiscala `issued` pentru aceeasi comanda.
- Factura emisa este salvata local si se incearca sincronizarea catre canalul sursa.

### Creeaza storno Oblio
- Foloseste ultima factura fiscala Oblio emisa a comenzii.
- Refuza o a doua stornare pentru aceeasi factura.
- Documentul storno este salvat local si sincronizat catre canal cand este posibil.

### Creeaza proforma / Creeaza aviz
- Folosesc fluxul Oblio existent.
- Refuza duplicarea unui document `issued` de acelasi tip pentru aceeasi comanda.

### Genereaza AWB-uri
- eMAG: datele destinatarului, rambursul si lockerul sunt preluate din comanda.
- eMAG pickup/easybox/FANbox: un AWB = un colet; un AWB suplimentar se solicita explicit din comanda si numai daca primul exista deja.
- eMAG domiciliu: `parcel_number` permite mai multe colete in acelasi AWB.
- Comenzile eMAG cu AWB local existent sunt omise in bulk, pentru a preveni dublurile.
- WooCommerce: foloseste curierul dedus din metoda reala de livrare sau configuratia curierului.
- Reparat fluxul manual WooCommerce `generate_awb`, care avea formular dar nu era inregistrat ca actiune.
- Handler-ele returneaza `success_ids`; dupa bulk raman bifate numai comenzile pentru care generarea a reusit.

### Descarca AWB(uri)
- A4/A6.
- eMAG: include toate AWB-urile locale descarcabile ale fiecarei comenzi selectate.
- WooCommerce: include eticheta curierului configurat.
- Erorile individuale sunt incluse in `_ERORI.txt` cand arhiva contine si fisiere valide.

## UI / responsive

- Card Documente Oblio refacut in grila responsive Factura / Proforma / Aviz / Storno.
- Cardurile eMAG pentru mai multe expedieri au actiuni A4/A6 responsive.
- Orders Details eliminat complet din UI/rute/runtime.
- Cardul AWB duplicat din modulul scanare este ascuns pentru eMAG; modulul Curieri gestioneaza unitar eMAG.

## Imagini eMAG

- Hyperlinkurile `/pd/...` sunt acceptate ca sursa canonica.
- Parserul extrage `og:image`, `twitter:image` sau, ca fallback, primul URL oficial eMAG din `img/srcset`.
- Fetch-ul paginii are timeout mai tolerant; download-ul CDN trimite referer eMAG.
- AJAX-ul media ruleaza separat de pagina comenzii si are timeout client 30 secunde.
- Testul parserului pe o pagina eMAG reprezentativa este PASS.
