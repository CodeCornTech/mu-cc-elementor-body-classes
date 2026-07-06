# Elementor Custom Body Classes MU

MU plugin WordPress per aggiungere un campo **Body CSS Classes** nelle impostazioni pagina di Elementor.

Permette di assegnare classi custom al `<body>` per singola pagina/template, con supporto a:

- frontend live;
- preview Elementor;
- iframe editor Elementor;
- aggiornamento live senza reload;
- Select2 multi-value con tag liberi;
- rehydration automatica delle classi custom salvate.

## Perché esiste

Elementor permette controlli custom sui document settings, ma un campo `SELECT2` con `tags: true` può perdere visivamente i valori custom alla riapertura dell’editor se quei valori non sono presenti nelle `options`.

Questo plugin ricostruisce dinamicamente le options partendo da:

1. preset opzionali via filtro;
2. classi già salvate nel documento Elementor corrente.

Così classi come `servizi-page`, `landing-dark`, `no-header-custom` o qualunque token custom restano visibili e non vengono sovrascritte al salvataggio successivo.

## Installazione

Copia il file plugin in:

~~~text
wp-content/mu-plugins/
~~~

oppure dentro una sottocartella MU caricata da un loader.

## Uso

Apri Elementor:

~~~text
Impostazioni pagina → Impostazioni → Custom Body Layout → Body CSS Classes
~~~

Scrivi una o più classi e premi `Invio`.

Le classi vengono applicate al body del frontend e alla preview editor.

## Preset opzionali

Il plugin non hardcoda classi progetto-specifiche.

Puoi aggiungere preset da tema o altro MU plugin:

~~~php
<?php

add_filter('cc_elementor_body_class_options', static function (array $options): array {
    $options['contatti-page'] = 'contatti-page';
    $options['servizi-page'] = 'servizi-page';

    return $options;
});
~~~

## Note tecniche

- Compatibile con valori salvati come array Select2.
- Compatibile con stringhe legacy separate da spazi.
- Non usa `prefix_class` o `selectors`, per evitare conversioni Array → String nel core Elementor.
- JS editor inline single-file, con cache PHP statica e guard anti doppio binding.
- Nessun asset aggiuntivo richiesto.

## Versione

Current: `1.6.2`

## License

GPLv2 or later.
