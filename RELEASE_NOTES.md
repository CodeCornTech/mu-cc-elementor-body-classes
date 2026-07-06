# v1.6.1 — Initial public release

Prima release pubblica del MU plugin **Elementor Custom Body Classes**.

## Highlights

- Campo `Body CSS Classes` nelle impostazioni pagina Elementor.
- Supporto Select2 multiplo con tag liberi.
- Rehydration automatica delle classi custom salvate.
- Iniezione classi su frontend, preview ed editor iframe.
- Aggiornamento live del body iframe durante l’editing.
- Preset opzionali tramite filtro `cc_elementor_body_class_options`.
- Nessun hardcode di classi progetto-specifiche.
- Plugin single-file, senza asset JS/CSS esterni.

## Technical notes

- Evitati `prefix_class` e `selectors` per non generare fatal su valori array.
- Normalizzazione centralizzata delle classi.
- JS inline cacheato in memoria PHP tramite `static $script`.
