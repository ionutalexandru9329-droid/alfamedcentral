# ALFAMED CENTRAL v0.11.54

Versiune cu nomenclator Oblio sincronizat local si matching automat extins pentru codurile eMAG/WooCommerce → Oblio, inclusiv cand API-ul Oblio nu expune codeClient/codeEAN.


## Matching Oblio extins v0.11.38

- Sincronizarea nu mai depinde exclusiv de `codeClient` / `codeEAN`, deoarece aceste campuri pot lipsi din raspunsul nomenclatorului.
- Maparea automata foloseste, in ordine sigura: cod direct, cod alternativ/EAN, legatura prin produsul central (SKU/EAN) si denumire exacta unica.
- Potrivirea dupa denumire este acceptata numai daca exista un singur produs cu acea denumire normalizata pe canal si un singur produs Oblio corespunzator; nu exista fuzzy matching.
- Echivalarile manuale raman prioritare si nu sunt suprascrise.
- Setarile Oblio afiseaza diagnosticul ultimei analize: produse de canal analizate, cod direct, produs central, cod alternativ/EAN, denumire exacta, ambigue si fara potrivire.

## Echivalari automate din Oblio v0.11.37

- Butonul **Sincronizeaza acum din Oblio** si sincronizarea automata la intervalul configurat actualizeaza acum si tabelul de echivalari.
- ALFAMED CENTRAL foloseste `codeClient` si `codeEAN` numai cand acel identificator este cunoscut pe canalul respectiv din catalogul local sau din comenzile istorice.
- Maparile create astfel apar cu sursa **Auto Oblio**; maparile create de operator apar **Manual**.
- O mapare Manuala nu este niciodata suprascrisa de sincronizarea automata.
- Maparile Auto Oblio provenite direct din `codeClient`/`codeEAN` sunt reconstruite la fiecare sincronizare, astfel incat relatiile vechi sa nu ramana active daca nomenclatorul Oblio se schimba.
- Daca o factura gaseste o potrivire sigura pe care sync-ul nu o putea vedea anterior, relatia invatata este salvata tot ca mapare automata.

## Nomenclator Oblio sincronizat v0.11.36

- `Setari > Module > Oblio` are acum butonul **Sincronizeaza acum din Oblio**.
- ALFAMED CENTRAL descarca nomenclatorul oficial Oblio in pagini de maximum 250 produse si pastreaza local cod, denumire, `codeClient`, `codeEAN`, TVA, tip produs, pret, gestiuni/stoc si raspunsul brut.
- Cache-ul local este read-only fata de Oblio: sincronizarea nu modifica produse, preturi sau stocuri in contul Oblio.
- Workerul/CRON actualizeaza automat nomenclatorul la interval configurabil, implicit 12 ore.
- Facturarea cauta mai intai in cache-ul local; API-ul Oblio live ramane fallback doar cand produsul nu poate fi rezolvat local.
- Echivalarile manuale sunt pastrate doar ca fallback pentru cazurile ambigue si sunt ascunse intr-o sectiune compacta.

## Sincronizare coduri eMAG ↔ Oblio

- Facturile si storno trimise catre eMAG folosesc acum `order/attachments/save` in forma JSON oficiala, cu `order_id` si `order_type`.
- Daca o factura exista deja in Oblio/ALFAMED CENTRAL, relansarea actiunii bulk reincearca sincronizarea catre canal fara sa emita o dublura.
- Generarea AWB eMAG foloseste `awb/save` in forma JSON `data[]` ceruta de API 4.5.1.
- Daca eMAG intoarce doar identificatorii rezervarii, ALFAMED CENTRAL recupereaza numarul prin `awb/read` fara sa genereze a doua expediere.
- `awb/read` nu mai trimite `currentPage/itemsPerPage`; sunt folosite doar `emag_id` / `reservation_id`.
- Stocul Oblio ramane validat inainte de emiterea documentului; un stoc insuficient trebuie corectat in Oblio sau in maparea produsului.

## Comenzi si AWB

- Fluxurile experimentale de descoperire/import automat al AWB-urilor eMAG existente au fost eliminate complet.
- Pagina AWB afiseaza doar expedierile salvate local si scanarea coletelor.
- Generarea eMAG foloseste datele reale ale comenzii: destinatar, telefon, adresa, ramburs si locker.
- Pentru easybox/FANbox/locker, prima generare creeaza un AWB cu un singur colet. Daca expedierea nu incape, din comanda se poate solicita explicit un AWB suplimentar pentru aceeasi comanda.
- Pentru livrarea la domiciliu, un singur AWB poate contine numarul de colete introdus in formular.
- In lista Comenzi, `Genereaza AWB-uri` proceseaza eMAG si WooCommerce. Comenzile care au deja un AWB sunt omise automat din fluxul bulk pentru a evita dublurile.
- Dupa generarea bulk, comenzile procesate raman bifate, astfel incat urmatoarea actiune poate fi direct `Descarca AWB(uri)`.
- Descarcarea bulk eMAG include toate AWB-urile locale descarcabile ale fiecarei comenzi, in A4 sau A6.

## Documente Oblio

- Cardul Factura / Proforma / Aviz / Storno are layout nou pentru PC, tableta si mobil.
- Storno este disponibil numai cand exista o factura fiscala Oblio emisa si nestornata.
- Factura, proforma si avizul au protectie la dublura.
- Actiunile in masa Oblio pastreaza limitarile de protectie pentru API.

## Imagini eMAG in comenzi

- Calea principala foloseste PNK-ul exact si datele oferite de Marketplace API; o imagine returnata de eMAG este acceptata numai daca identitatea produsului este verificata.
- Daca API-ul nu furnizeaza o imagine, ALFAMED CENTRAL poate folosi catalogul propriu Alfamed/Univera numai cand exista simultan un SKU exact si o denumire identica dupa normalizare.
- Nu se foloseste fuzzy matching pentru thumbnail-uri si nu se alege niciodata prima imagine aproximativa gasita.
- Imaginea sigura este copiata in `public/uploads/emag-media`, astfel incat comenzile urmatoare o afiseaza local fara acces repetat la eMAG sau WooCommerce.
- Imaginile din fallback-ul local sunt marcate intern `CATALOG_EXACT` si sunt afisate doar pentru acelasi PNK asociat sigur.
- Daca nu exista nici imagine eMAG, nici potrivire exacta in catalog, produsul ramane fara imagine si poate primi o poza manuala din Image Preview.
- Poza manuala are prioritate absoluta si nu este inlocuita automat.

## Produse si UI responsive

- Adauga produs / Editeaza produs folosesc taxonomiile locale sincronizate si nu asteapta zeci de request-uri WooCommerce la deschidere.
- Cardurile, formularele, tabelele si actiunile au reguli unitare de spacing si responsive pentru PC/tableta/mobil.
- Editorul de produs are sectiuni aerisite si bara de salvare adaptiva.


### Coduri produse pe facturile Oblio
- `code` foloseste codul real al produsului din nomenclatorul Oblio.
- `codeClient` pastreaza SKU/part number primit din eMAG.
- `codeEAN` pastreaza EAN-ul, cand este disponibil.
- Prima potrivire sigura este memorata local si refolosita la facturile urmatoare.

## Instalare / upgrade

1. Fa backup la baza de date si folderul aplicatiei.
2. Copiaza continutul v0.11.54 peste instalatia existenta.
3. Pastreaza `.env`, `storage/installed.lock`, `public/uploads` si fisierele locale de date.
4. Fa `Ctrl+F5` dupa upgrade pentru CSS/JS nou.
5. Nu este necesara reinstalarea bazei de date.

## Cerinte recomandate

- PHP 8.1+;
- MySQL/MariaDB + PDO MySQL;
- cURL, OpenSSL, DOM/XML;
- Fileinfo si ZipArchive recomandate;
- HTTPS in productie.

## v0.11.40 - UI + catalog produse
- Facturi/documente: formular upload PDF compact si responsive.
- Module: carduri simplificate, status Activ/Inactiv in badge unic.
- Produse: filtre Total/Publicate/In asteptare/Gunoi, contoare si ordonare vechi -> nou.
- Sincronizare WooCommerce: toate statusurile disponibile, inclusiv trash cand endpointul magazinului il suporta.
- Catalog central: reconciliere sigura a duplicatelor intre Univera si Alfamedclinic dupa SKU/EAN.

### v0.11.41 product editor
The central product catalogue now includes a dedicated WooCommerce-style status filter panel, fast incremental manual synchronization, Rank Math SEO score display, broader Rank Math metadata editing and an expanded HTML-compatible visual description editor.


### v0.11.42
Scanarea AWB are UI mai compact si nu mai deschide tastatura automat pe dispozitive touch. Chat-ul pastreaza aceeasi regula la intrarea intr-o conversatie.
