## v0.11.54 - 2026-09-19

- Adaugat fallback automat sigur pentru thumbnail-urile eMAG care lipsesc din API: catalogul Alfamed/Univera este folosit numai cand SKU-ul este exact si denumirea produsului este identica dupa normalizare.
- Eliminat complet fuzzy matching-ul din criteriile considerate sigure pentru imagini; o asociere `sku_name_similar` nu poate furniza thumbnail.
- Imaginile preluate din catalog prin aceasta regula sunt copiate in cache-ul local, marcate `CATALOG_EXACT` si refolosite numai pentru acelasi PNK validat.
- Sync-ul de comenzi si OrderOperations accepta cache-ul `CATALOG_EXACT` doar daca exista o legatura PNK -> produs salvata cu motiv sigur `sku_name`.
- Upgrade-ul v0.11.54 reprogrameaza imediat imaginile lipsa pentru resincronizare si sterge eventualele legaturi automate fuzzy vechi.
- Uploadul manual ramane prioritar si nu este suprascris de sincronizarea automata.

## v0.11.53 - 2026-09-19

- Eliminat titlul duplicat din headerul Image Preview; denumirea produsului apare o singura data, in corpul preview-ului.
- Daca un URL de imagine este rupt, preview-ul ascunde imaginea defecta si afiseaza curat starea `Imagine indisponibila`, fara icon/alt text duplicat.
- Reparata resincronizarea automata: `product_offer/read` poate returna URL-ul imaginii furnizate de seller, nu doar CDN-ul eMAG. Cand oferta este validata pe PNK exact, URL-ul sigur este acum acceptat, descarcat si salvat in cache-ul local.
- Imaginile seller provenite din API sunt marcate `EMAG_API_EXACT` si sunt considerate valide numai daca exista cache local si produsul pastreaza acelasi PNK; nu se reintroduce matching aproximativ.
- Mesajele din preview au fost simplificate pentru cazul in care API-ul nu contine deloc o imagine.

## v0.11.52 - 2026-09-19

- Simplificat complet sistemul de thumbnail-uri eMAG: imaginile automate provin numai din date exacte ale comenzii/API eMAG si sunt acceptate doar daca PNK-ul si CDN-ul eMAG sunt validate.
- Eliminat din fluxul activ scrapingul paginii publice eMAG si orice fallback automat dupa denumire/SKU catre catalogul Alfamed/Univera; daca nu exista imagine exacta, produsul ramane fara imagine.
- Uploadul manual din Image Preview ramane fallback-ul sigur si are prioritate permanenta; adaugat drag & drop direct pe zona de preview, fara butoane noi in comanda.
- Upgrade-ul curata o singura data imaginile automate vechi provenite din fallback-uri de catalog/public page, pastrand uploadurile manuale si cache-ul cu sursa oficiala eMAG.
- Mesajele din preview au fost simplificate: fara diagnostice WAF/bot, doar stare API exacta sau incarcare manuala.

## v0.11.51 - 2026-09-19

- Redesigned the product image preview as a compact professional modal for desktop, tablet and mobile; the native file input is now fully hidden and controls no longer expand awkwardly.
- The eMAG image resolver now preserves exact product hyperlinks from order payloads, reads exact image/url hints already present in saved Marketplace order JSON, and keeps strict PNK validation before accepting media.
- Added a browser-like cookie-session fallback for direct eMAG product hyperlinks and explicit detection of storefront blocks (403/429/511) instead of silently treating them as a normal missing image.
- Added a safe exact-SKU + strong-name-similarity fallback to the local Alfamed/Univera catalogue when eMAG blocks automated storefront access; no approximate image is accepted without both signals.
- Missing verified thumbnails remain blank, retry automatically, and manual uploads still have absolute priority.

## v0.11.50 - 2026-09-19
- Eliminat din pagina comenzii butonul `Leaga produsul` si fereastra lui de asociere manuala.
- Eliminat butonul vizibil `Actualizeaza imaginile`; resincronizarea automata continua in fundal la deschiderea comenzilor eMAG.
- Actiunile pentru imagine sunt mutate in preview-ul thumbnailului: resincronizare automata si upload manual, fara butoane suplimentare in tabelul comenzii.
- Thumbnailul lipsa poate fi apasat pentru a deschide preview-ul si instrumentele de imagine.
- Pozele incarcate manual sunt pastrate local, propagate produselor eMAG cu aceeasi identitate si au prioritate fata de resincronizarea automata.

## v0.11.49 - 2026-09-19
- Reparat sistemul de thumbnail-uri eMAG din Comenzi: o imagine este afisata numai daca identitatea produsului este dovedita prin acelasi PNK; redirecturile, paginile generice/WAF si imaginile asociate altui PNK sunt respinse.
- Calea principala foloseste `product_offer/read` cu identificatori exacti eMAG; imaginea publica este acceptata numai de pe CDN-ul de produse eMAG si numai dupa validarea PNK-ului.
- Eliminata increderea permanenta in cache: imaginile verificate sunt reverificate periodic (implicit la 6 ore), iar cele lipsa/suspecte sunt reincercate automat la maximum 5 minute; deschiderea unei comenzi declanseaza verificarea in fundal.
- Fallback-ul catre catalogul Alfamed/Univera este permis numai pentru o asociere manuala sau o potrivire puternica (`EAN` unic, `SKU+EAN`, `SKU+nume exact`); SKU simplu, part number simplu si numele singur nu mai pot furniza thumbnail.
- Importul comenzilor si afisarea preview-ului refuza media neverificata, astfel incat un cache vechi nu poate reaparea dupa o resincronizare a comenzii.
- Upgrade-ul v0.11.49 sterge o singura data cache-ul vechi de thumbnail-uri si maparile automate PNK -> produs, pastreaza maparile manuale si reconstruieste imaginile din surse verificate.
- Adaugat testul de regresie `v01149_emag_thumbnail_integrity_regression.php` si extins smoke test-ul API pentru rezolvarea exacta dupa PNK.

## v0.11.48
- Preview-ul produselor eMAG nu mai depinde de scrapingul paginii publice: calea principala foloseste API-ul Marketplace oficial pentru a rezolva PNK-ul in `part_number`/nume, apoi reutilizeaza imaginea produsului identic din catalogul ALFAMED CENTRAL / Univera / Alfamedclinic.
- Matching-ul automat este conservator: SKU/part number exact, varianta cu prefixul intern configurat, EAN exact unic sau denumire exacta unica; nu exista fuzzy matching.
- Legatura `PNK eMAG -> produs central` se salveaza persistent si este refolosita la comenzile viitoare.
- Daca potrivirea automata nu este posibila, administrator/operator cu drept de gestionare comenzi poate folosi `Leaga produsul` o singura data; asocierea manuala are prioritate fata de maparile automate.
- Imaginea gasita in catalogul propriu este copiata in cache-ul local `public/uploads/emag-media`, astfel incat preview-ul comenzilor urmatoare nu depinde de WooCommerce/eMAG la fiecare deschidere.
- Scrapingul public eMAG ramane doar fallback best-effort; blocarea WAF/CAPTCHA nu mai blocheaza fluxul principal.

## v0.11.47
- Eliminat complet ReefAPI din resolverul de imagini eMAG si din Setari > Integrari; preview-ul nu mai depinde de credite sau chei externe.
- Resolver gratuit nou pentru eMAG: cache/catalog local -> pagina publica a produsului -> JSON-LD / OpenGraph -> cautare publica dupa PNK.
- Pentru requesturile eMAG sunt incercate mai multe profile legitime de browser/crawler (Chrome, Facebook crawler, Twitter crawler), deoarece WAF-ul poate raspunde diferit in functie de client.
- Daca storefront-ul initial este blocat, se face si un fallback best-effort pe storefront-urile eMAG RO/BG/HU, dar imaginea este acceptata numai daca linkul contine exact acelasi PNK.
- Parser dedicat pentru cardurile de cautare, JSON-ul de listing incorporat in pagina si `Product.image` din JSON-LD, cu validare stricta `/pd/<PNK>/` pentru a evita thumbnail-uri gresite.
- Nu se folosesc endpointuri interne eMAG cu parametri neverificati; resolverul lucreaza numai cu suprafete publice si cache-ul local.
- Imaginea gasita este descarcata in continuare in `public/uploads/emag-media`; dupa prima rezolvare, comenzile ulterioare nu mai fac requesturi externe pentru acelasi PNK.
- Butonul manual `Actualizeaza imaginile` ramane disponibil pentru o reverificare la cerere.

## v0.11.44
- Administrare utilizatori refacuta complet, vizibila si accesibila doar administratorilor.
- Actiuni dedicate pentru editare, suspendare/reactivare si stergere controlata a utilizatorilor; contul administratorului curent este protejat.
- Stergerea utilizatorilor este soft-delete: accesul este eliminat, emailul este eliberat pentru reutilizare, iar istoricul ramane pastrat.
- Jurnal nou de activitate utilizatori, exclusiv pentru administratori, cu filtre dupa utilizator, categorie, perioada si rezultat.
- Audit centralizat pentru actiuni importante din comenzi, produse, documente, AWB, setari, module, chat si autentificare, fara a salva parole/secrete sau continutul mesajelor.
- Dashboard-ul afiseaza un flux compact de activitate operationala a echipei si un rezumat de vanzari mai simplu.
- Pagina Statistici a fost refacuta vizual: KPI-uri clare, evolutie zilnica simplificata, comparatii, performanta pe canale, statusuri si top produse.
- Layout responsive dedicat pentru PC, tableta si mobil in Utilizatori, Jurnal activitate, Dashboard si Statistici.

## v0.11.43
- Redesign complet pentru paginile Setari ale modulelor: layout nou cu rezumat lateral, navigare rapida pe sectiuni si grupare vizuala uniforma a campurilor.
- Campurile din setarile modulelor sunt afisate acum in carduri compacte, mai ordonate si cu mai putin text bold, iar butoanele au dimensiuni mai mici.
- Optiunile tip checkbox folosesc acum switch-uri mai curate si mai usor de parcurs vizual.
- Setarile pe module cu heading-uri interne (chat, curieri, notificari, facturi) sunt impartite automat in subsectiuni clare.
- Blocurile suplimentare pentru Oblio / documente raman in acelasi stil si se aliniaza mai bine sub zona principala de setari.

## v0.11.38 - 2026-09-18
- Corecteaza cazul in care Oblio sincronizeaza nomenclatorul complet, dar nu returneaza `codeClient`/`codeEAN`, ceea ce lasa 0 echivalari automate in v0.11.37.
- Matching-ul automat foloseste acum cod direct, coduri alternative/EAN, legatura prin produsul central si denumirea exacta unica.
- Potrivirile dupa nume sunt strict unice pe ambele parti; nu se foloseste fuzzy matching.
- Maparile manuale raman protejate si au prioritate.
- Sunt afisate contoare de diagnostic pentru ultima analiza, astfel incat cazurile ramase fara echivalare sa poata fi explicate.

## v0.11.37 - 2026-09-18
- Sincronizarea nomenclatorului Oblio importa acum si echivalarile sigure in tabelul de mapari ALFAMED CENTRAL.
- `codeClient` si `codeEAN` din Oblio sunt folosite numai daca acel identificator exista deja pe canalul concret eMAG/WooCommerce, evitand mapari speculative intre canale.
- Sunt luate in calcul SKU/EAN din produse, channel_products, order_items si payload-urile istorice ale comenzilor, astfel incat maparea poate fi invatata si pentru produse vechi.
- Echivalarile introduse manual au prioritate absoluta si nu sunt suprascrise de sincronizarea automata.
- Echivalarile `Auto Oblio` sunt reconstruite la fiecare sincronizare pentru ca legaturile invechite sa nu ramana active.
- Interfata Oblio afiseaza separat maparile `Auto Oblio` si `Manual`, precum si numarul total de echivalari automate.
- Maparile invatate sigur in timpul facturarii sunt marcate automat, nu manual.

## v0.11.36 - 2026-09-18
- Oblio devine sursa principala pentru codurile produselor: ALFAMED CENTRAL poate sincroniza local intreg nomenclatorul prin API-ul oficial `/nomenclature/products`, paginat la 250 produse.
- Noul cache local Oblio pastreaza cod, denumire, codeClient, codeEAN, TVA, tip produs, gestiuni/stoc si raspunsul brut, fara a modifica produsele din Oblio.
- Facturarea cauta mai intai produsul in cache-ul local Oblio, apoi foloseste API-ul live doar ca fallback; emiterea bulk nu mai descarca nomenclatorul complet pentru fiecare factura.
- Sincronizarea nomenclatorului poate fi fortata din Setari > Module > Oblio si ruleaza automat prin worker/CRON la interval configurabil (implicit 12 ore).
- Echivalarile manuale raman disponibile intr-o sectiune fallback compacta doar pentru cazurile ambigue.
- Interfata Oblio afiseaza numarul produselor importate, ultima sincronizare si ultima eroare.

# v0.11.35 - sincronizare coduri eMAG ↔ Oblio

- codul Oblio devine codul autoritar al produsului pe factura (`code`)
- SKU/part number eMAG este transmis separat ca `codeClient`
- EAN-ul este transmis separat ca `codeEAN`
- maparea cauta in nomenclatorul Oblio dupa cod, cod client/EAN si nume exact
- nomenclatorul Oblio este indexat paginat (maxim 10.000 produse) si cache-uit pe durata cererii pentru actiuni bulk
- daca potrivirea reuseste prin EAN/nume, ALFAMED CENTRAL memoreaza automat si relatia SKU eMAG → cod Oblio
- ID-ul liniei de comanda eMAG nu mai este tratat ca un cod de produs
- se folosesc, ca surse suplimentare sigure, SKU/EAN din catalogul central si maparile WooCommerce existente


## v0.11.39
- UI refresh pentru paginile Module, Setari > Header si navigare, setarile modulului Oblio si sectiunea Documente din comenzi.
- Headerul permite acum selectarea separata a fiecarei scurtaturi de navigare, nu doar pe modul complet.
- Eliminat cardul redundant Diagnostic rapid eMAG din Setari > Integrari; testarea ramane in pagina fiecarei integrari.
- Formularul de echivalare manuala Oblio si lista maparilor sunt mai ordonate si mai responsive.
- Uploadul de factura din comenzi foloseste acum un layout compact, mai curat pe desktop/tableta/mobile.


## v0.11.40
- Refacere completa a uploadului Facturi/documente: buton compact, checkbox si explicatie aliniate, responsive real PC/tableta/mobile.
- Redesign Module: mai putine bold-uri, un singur status vizual intr-un badge elegant, carduri mai aerisite.
- Produse: taburi Total / Publicate / In asteptare / Gunoi cu filtrare reala si contoare.
- Produse: ordonare implicita de la cel mai vechi la cel mai nou.
- Sincronizarea WooCommerce cere toate statusurile disponibile, inclusiv trash unde API-ul magazinului il suporta.
- Catalogul central deduplica sigur produse intre Univera si Alfamedclinic dupa SKU si EAN si reconciliaza duplicatele istorice sigure.

## v0.11.41
- Moved the product status filters into their own panel between bulk actions and the catalogue list, with always-visible bordered buttons and clearer hover/active states.
- Manual product sync now uses a fast incremental WooCommerce refresh through AJAX; full reconciliation remains scheduled in the background.
- Rank Math SEO score is imported from `rank_math_seo_score`, backfilled from existing WooCommerce cache data during upgrade, and displayed in the product list and editor.
- Product create/edit now includes a richer Rank Math editor with General, Advanced and Social tabs; shared products send the selected SEO changes to all checked WooCommerce stores.
- Added Rank Math canonical URL, robots, pillar content, Facebook/OpenGraph and X/Twitter metadata support.
- Expanded the visual product description editor with additional formatting, source HTML mode and fullscreen editing while preserving WooCommerce-compatible HTML output.


## v0.11.42
- Redesign Scanare AWB: mai compact, fonturi si butoane mai echilibrate, layout responsive simplificat.
- Eliminat autofocus-ul HTML din campul AWB; pe telefon/tableta tastatura nu se mai deschide la intrarea pe pagina.
- Pe desktop cu pointer fin campul AWB ramane focalizat automat pentru scanner USB.
- Chat: deschiderea unei conversatii nu mai focalizeaza automat textarea pe dispozitive touch; tastatura apare numai dupa tap in campul de mesaj.
- Focusurile automate ale composerului dupa emoji/fotografie sunt suprimate pe dispozitive touch.

## v0.11.45
- Preview imagini eMAG RO/BG: resolverul incearca mai intai pagina publica eMAG si foloseste optional ReefAPI product/detail atunci cand WAF/CAPTCHA blocheaza requestul server-side.
- PNK-ul este extras direct din hyperlinkul `/pd/<CODE>/`, iar raspunsul extern este validat sa corespunda aceluiasi cod si aceleiasi tari inainte sa fie acceptat.
- Prima imagine din galeria eMAG este descarcata in cache-ul local `public/uploads/emag-media`, astfel incat comenzile ulterioare afiseaza thumbnailul instant fara un request extern la fiecare deschidere.
- Cheia ReefAPI este configurabila in Setari > Integrari > eMAG RO/BG prin campul comun `ReefAPI Key pentru preview imagini eMAG`; campul este secret si ramane optional.
## v0.11.46
- Preview eMAG free-first: reutilizeaza mai intai cache-ul local pentru acelasi PNK, apoi imaginea produsului central mapat sigur din Univera/Alfamedclinic.
- Imaginile WooCommerce folosite ca fallback sunt descarcate in cache-ul local `public/uploads/emag-media`; URL-ul Woo nu este afisat direct in comenzile eMAG.
- Thumbnailurile eMAG deja salvate local nu mai sunt reverificate automat periodic, reducand drastic apelurile externe.
- ReefAPI ramane doar ultimul fallback, dupa cache/catalog/pagina eMAG, si are limita zilnica configurabila prin `EMAG_REEFAPI_DAILY_LIMIT` (implicit 20, 0 = dezactivat).
- Cache-ul este reutilizat pe PNK, astfel incat acelasi produs aparut in mai multe comenzi nu consuma din nou un apel extern.
- Comenzile eMAG au buton explicit `Actualizeaza imaginile`; doar aceasta actiune forteaza o verificare live.
- Resolverul local poate potrivi sigur produsul dupa legatura centrala, SKU exact sau EAN exact unic.

