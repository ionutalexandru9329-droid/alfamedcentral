# Module ALFAMED CENTRAL v0.8

Sistemul de module permite adaugarea de functii fara modificarea nucleului aplicatiei. Un modul poate declara rute, listeners, navigatie, actiuni, render hooks, setari si migrari proprii.

## Structura minima

```text
my-module/
  manifest.json
  bootstrap.php
```

Exemplu manifest:

```json
{
  "slug": "my-module",
  "name": "Modulul meu",
  "version": "1.0.0",
  "description": "Exemplu",
  "author": "ALFAMED",
  "min_app_version": "0.8.0",
  "default_enabled": false
}
```

## Capabilitati

`bootstrap.php` poate returna chei precum:

- `listeners` - evenimente interne, de ex. `order.created`, `product.stock_changed`;
- `routes` - rute GET/POST proprii;
- `nav` - elemente de meniu, optional cu permisiune;
- `renderers` - continut injectat in hook-uri, de ex. `products.actions`, `order.operations`, `layout.footer`;
- `actions.orders` - actiuni individuale/in masa in lista Comenzi;
- `settings` - sectiuni afisate automat in **Setari > Module > <modul>**.

Rutele sunt autentificate implicit; un endpoint public trebuie declarat explicit si trebuie sa faca propria validare stricta.

## Migrari

Un modul poate include:

```text
my-module/database/mysql.sql
my-module/database/sqlite.sql
```

Migrarile trebuie sa fie idempotente.

## Module incluse

### `new-order-popup` v3
Centru notificari, badge necitite, comenzi noi si stoc mic/fara stoc. Include Web Push prin Service Worker + VAPID pentru PC/tableta/mobile, abonamente per dispozitiv, click direct spre comanda/produs si sabloane editabile pentru titlul/textul alertelor.

### `team-chat` v2
Utilizatori, prezenta si chat intern. Include:

- chat individual/grup;
- mute per conversatie/per utilizator;
- sunet configurabil;
- emoji si atasamente;
- voce prin MediaRecorder;
- camera foto: capturare nativa pe telefon si camera live pe desktop;
- editare/stergere mesaj propriu in fereastra de 5 minute;
- placeholder pentru mesaj sters si marcaj pentru mesaj editat;
- stare popup persistenta intre pagini;
- pe mobil, interfata full-screen cu lista/conversatie separata si buton Inapoi.

Fisierele sunt salvate in `storage/chat_uploads` si livrate prin ruta protejata numai membrilor conversatiei.

### `invoices-documents` v1.7
Factura, proforma, aviz, storno si PDF client. Importa facturile/proformele existente din eMAG, sincronizeaza documentele create local catre canal si include echivalare per canal intre codul produsului din comanda si codul din nomenclatorul Oblio.

### `couriers` v1.5
Sameday, FAN Courier si profil configurabil Dragon Star pentru WooCommerce, plus AWB Marketplace pentru eMAG. Include generare AWB bulk channel-aware si descarcare eMAG A4/A6 individual sau ZIP.

### `awb-scan`
AWB manual/lista/scanare si identificarea comenzii.

### `ai-product-import`
Import inteligent din URL public:

- JSON-LD Product / ItemList;
- OpenGraph fallback;
- preview;
- selectare destinatie Univera/Alfamedclinic;
- creare fortata Draft/Ciorna;
- URL validation + blocare private/reserved.

Necesita PHP cURL si DOM/XML.

## Instalare ZIP modul

1. Arhiveaza folderul modulului.
2. Intra in **Module**.
3. Incarca ZIP-ul.
4. Modulul este instalat dezactivat.
5. Activeaza-l dupa verificare.

## v0.9.4 - setari dedicate si module first-party

Modulele pot declara sectiuni `settings` care sunt afisate in **Setari > Module**. In v0.9.4, configurarea Oblio a fost mutata integral in modulul `invoices-documents`; integrarile de canal raman in **Setari > Integrari**.

Modulul `awb-scan` poate folosi camera browserului prin `BarcodeDetector` pentru QR si coduri de bare. Camera necesita permisiune, iar pe hosting este recomandat HTTPS. Daca detectorul nativ nu este disponibil, raman functionale scannerul USB si introducerea manuala.


## Standard UI pentru module first-party (v0.10.2+)

Modulele noi incluse in distributia ALFAMED CENTRAL trebuie sa urmeze stilul paginilor **Comenzi**, **Produse** si **Statistici**. Pentru consistenta, sunt disponibile clase reutilizabile in `public/assets/app.css`:

- `module-page-shell` / `module-page-head` pentru container si header;
- `module-filterbar` impreuna cu `orders-filter-select` pentru filtre compacte;
- `module-toolbar`, `module-toolbar-main` pentru zone de actiuni;
- `module-section-panel` pentru carduri/panouri operationale.

Reguli recomandate:

1. filtrele sa fie compacte, etichetate clar si, cand este sigur, sa se aplice automat;
2. actiunile principale sa fie grupate in toolbar, nu imprastiate prin pagina;
3. KPI-urile si graficele sa foloseasca aceeasi densitate vizuala ca Dashboard/Statistici;
4. tabelele sa ramana compacte si cu hover discret;
5. modulele trebuie sa fie responsive pentru desktop, tableta si mobil.
