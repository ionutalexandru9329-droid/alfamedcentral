# TEST REPORT v0.11.42

## Scanare AWB
- campul `scanInput` nu mai foloseste autofocus HTML;
- focusul automat este permis doar pentru desktop/pointer fin, pentru fluxul cu scanner USB;
- UI scanare refacut mai compact si responsive.

## Chat
- deschiderea unei conversatii nu mai focalizeaza automat textarea pe dispozitive touch;
- focusurile programatice dupa emoji/fotografie sunt suprimate pe touch;
- tastatura software apare doar dupa interactiunea utilizatorului cu textarea.

## Verificari
- PHP syntax: 88 fisiere OK
- JavaScript syntax: OK
- v01142_scan_chat_touch_regression.php: PASS
- v01141_rankmath_fast_products_regression.php: PASS
- v01140_ui_products_regression.php: PASS
- nonblocking_ui_regression.php: PASS
