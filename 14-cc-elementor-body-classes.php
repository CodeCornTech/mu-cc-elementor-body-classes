<?php

/**
 * Plugin Name:       Elementor Custom Body Classes (MU)
 * Plugin URI:        https://github.com/CodeCornTech/mu-cc-elementor-body-classes
 * Description:       Adds custom body CSS classes to Elementor page settings, with Select2 tag rehydration and live editor preview support.
 * Version:           1.6.2
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Requires Plugins:  elementor
 * Author:            CodeCornTech
 * Author URI:        https://github.com/CodeCornTech
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cc-elementor-body-classes
 * Domain Path:       /languages
 * Update URI:        https://github.com/CodeCornTech/mu-cc-elementor-body-classes
 */

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;

/**
 * Integrazione MU per classi custom sul body da impostazioni documento Elementor.
 *
 * Architettura:
 *
 * - Il controllo vive in "Impostazioni pagina" di Elementor.
 * - Il valore viene salvato nel documento Elementor come array Select2.
 * - Le classi vengono iniettate:
 *   - nel frontend pubblico tramite `body_class`;
 *   - nella preview/editor iframe tramite hook Elementor;
 *   - live durante l'editing tramite JS sul model del documento.
 *
 * Nota importante:
 * Elementor/Select2 con `tags: true` salva correttamente classi custom,
 * ma alla riapertura del pannello può mostrare vuoto se le classi salvate
 * non vengono reinserite nelle `options` del controllo.
 *
 * Per questo le options sono costruite dinamicamente da:
 *
 * 1. preset opzionali via filtro;
 * 2. classi già salvate nel documento corrente.
 *
 * Vietato hardcodare classi progetto-specifiche dentro questo MU.
 */
final class CC_Elementor_Body_Classes_MU
{
    /**
     * Setting Elementor usato dal controllo documento.
     */
    private const SETTING_BODY_CLASSES = 'cc_custom_body_classes';

    /**
     * Sezione custom nel pannello impostazioni pagina.
     */
    private const SECTION_BODY_CLASSES = 'cc_custom_body_section';

    /**
     * Filtro per preset opzionali.
     *
     * Esempio da tema / altro MU:
     *
     * add_filter('cc_elementor_body_class_options', static function (array $options): array {
     *     $options['contatti-page'] = 'contatti-page';
     *     return $options;
     * });
     */
    private const FILTER_CLASS_OPTIONS = 'cc_elementor_body_class_options';

    /**
     * Text domain plugin.
     */
    private const TEXT_DOMAIN = 'codecorn';

    /**
     * Bootstrap minimale.
     */
    public function __construct()
    {
        // Inizializziamo solo dopo che tutti i plugin sono caricati,
        // così possiamo verificare Elementor in sicurezza.
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Registra hook solo se Elementor è disponibile.
     *
     * @return void
     */
    public function init(): void
    {
        if (! did_action('elementor/loaded') || ! class_exists('\Elementor\Plugin')) {
            return;
        }

        // Campo Select2 nelle impostazioni documento/pagina.
        add_action('elementor/documents/register_controls', array($this, 'register_document_controls'));

        // Frontend pubblico + preview Elementor non-editor.
        add_filter('body_class', array($this, 'inject_frontend_body_classes'));

        // Body iframe dell'editor al primo caricamento.
        add_filter('elementor/editor/v2/body_class', array($this, 'inject_editor_body_classes'));
        add_filter('elementor/editor/body_class', array($this, 'inject_editor_body_classes')); // Fallback vecchie versioni Elementor.

        // Reattività live del body iframe quando cambia il Select2.
        add_action('elementor/editor/footer', array($this, 'inject_editor_reactivity_js'));
    }

    /**
     * Aggiunge il controllo Select2 nelle impostazioni pagina Elementor.
     *
     * Nota Intelephense:
     * evitiamo il typehint concreto `Elementor\Core\Base\Document` perché alcune
     * installazioni/stub Elementor non espongono quella classe in modo stabile.
     * Elementor passa comunque un document object compatibile con i metodi usati.
     *
     * @param object $document Documento Elementor corrente.
     *
     * @return void
     */
    public function register_document_controls(object $document): void
    {
        $document->start_controls_section(
            self::SECTION_BODY_CLASSES,
            array(
                'label' => __('Custom Body Layout', self::TEXT_DOMAIN),
                'tab'   => Controls_Manager::TAB_SETTINGS,
            )
        );

        $document->add_control(
            self::SETTING_BODY_CLASSES,
            array(
                'label'          => __('Body CSS Classes', self::TEXT_DOMAIN),
                'type'           => Controls_Manager::SELECT2,
                'multiple'       => true,
                'options'        => $this->get_body_class_options_for_document($document),
                'select2options' => array(
                    'tags'            => true,
                    'tokenSeparators' => array(' '),
                ),
                'description'    => __('Seleziona una classe esistente o scrivine una nuova premendo Invio.', self::TEXT_DOMAIN),
                'label_block'    => true,
                'separator'      => 'before',
            )
        );

        $document->end_controls_section();
    }

    /**
     * Costruisce le options del Select2 per il documento corrente.
     *
     * Non hardcodiamo classi progetto-specifiche:
     *
     * - i preset arrivano dal filtro `cc_elementor_body_class_options`;
     * - le classi custom già salvate vengono reinserite nelle options,
     *   così Select2 può reidratarle alla riapertura dell'editor.
     *
     * Nota Intelephense:
     * vedi `register_document_controls()`. Qui serve solo `get_settings()`.
     *
     * @param object $document Documento Elementor corrente.
     *
     * @return array<string, string>
     */
    private function get_body_class_options_for_document(object $document): array
    {
        $options = $this->normalize_options(
            apply_filters(self::FILTER_CLASS_OPTIONS, array(), $document)
        );

        $saved_classes = $this->normalize_classes(
            $document->get_settings(self::SETTING_BODY_CLASSES)
        );

        foreach ($saved_classes as $class) {
            $options[$class] = $class;
        }

        return $options;
    }

    /**
     * Normalizza preset options provenienti da filtro.
     *
     * Accetta sia:
     *
     * - array('foo' => 'Foo label')
     * - array('foo', 'bar')
     *
     * @param mixed $options Valore grezzo del filtro.
     *
     * @return array<string, string>
     */
    private function normalize_options(mixed $options): array
    {
        if (! is_array($options)) {
            return array();
        }

        $normalized = array();

        foreach ($options as $key => $label) {
            $value = is_string($key) ? $key : (string) $label;
            $value = $this->sanitize_class_token($value);

            if ($value === '') {
                continue;
            }

            $normalized[$value] = is_string($label) && $label !== '' ? $label : $value;
        }

        return $normalized;
    }

    /**
     * Recupera le classi salvate per il frontend pubblico / preview.
     *
     * @return mixed Array Select2, stringa legacy o valore vuoto.
     */
    private function get_frontend_classes(): mixed
    {
        $page_id = get_the_ID();

        if (! $page_id && isset($_GET['elementor-preview'])) {
            $page_id = absint(wp_unslash($_GET['elementor-preview']));
        }

        if (! $page_id) {
            return array();
        }

        $document = \Elementor\Plugin::$instance->documents->get($page_id);

        return $document ? $document->get_settings(self::SETTING_BODY_CLASSES) : array();
    }

    /**
     * Recupera le classi del documento corrente nell'iframe editor.
     *
     * @return mixed Array Select2, stringa legacy o valore vuoto.
     */
    private function get_editor_classes(): mixed
    {
        $current_document = \Elementor\Plugin::$instance->documents->get_current();

        return $current_document ? $current_document->get_settings(self::SETTING_BODY_CLASSES) : array();
    }

    /**
     * Inietta le classi nel frontend pubblico.
     *
     * Nota:
     * quando siamo nella shell editor Elementor (`action=elementor`) lasciamo stare il body
     * della finestra principale; il body utile è quello dell'iframe preview.
     *
     * @param array<int, string> $classes Classi body native.
     *
     * @return array<int, string>
     */
    public function inject_frontend_body_classes(array $classes): array
    {
        if (isset($_GET['action']) && 'elementor' === sanitize_key(wp_unslash($_GET['action']))) {
            return $classes;
        }

        return $this->append_classes($classes, $this->get_frontend_classes());
    }

    /**
     * Inietta le classi nel body iframe dell'editor al primo caricamento.
     *
     * @param array<int, string> $classes Classi body native editor iframe.
     *
     * @return array<int, string>
     */
    public function inject_editor_body_classes(array $classes): array
    {
        return $this->append_classes($classes, $this->get_editor_classes());
    }

    /**
     * Aggiunge classi custom all'array body nativo.
     *
     * @param array<int, string> $classes        Classi native.
     * @param mixed              $custom_classes Array Select2, stringa legacy o vuoto.
     *
     * @return array<int, string>
     */
    private function append_classes(array $classes, mixed $custom_classes): array
    {
        foreach ($this->normalize_classes($custom_classes) as $class) {
            if (! in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Normalizza qualunque formato supportato in array di classi CSS sane.
     *
     * Formati gestiti:
     *
     * - array Select2;
     * - stringa legacy separata da spazi;
     * - valori scalari inattesi, convertiti con cautela.
     *
     * @param mixed $value Valore grezzo.
     *
     * @return array<int, string>
     */
    private function normalize_classes(mixed $value): array
    {
        if (empty($value)) {
            return array();
        }

        if (is_array($value)) {
            $raw_classes = $value;
        } elseif (is_string($value)) {
            $raw_classes = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $raw_classes = array((string) $value);
        }

        $classes = array();

        foreach ($raw_classes as $class) {
            $class = $this->sanitize_class_token((string) $class);

            if ($class !== '' && ! in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Sanitizza un singolo token classe.
     *
     * @param string $class Classe grezza.
     *
     * @return string Classe CSS valida per WordPress.
     */
    private function sanitize_class_token(string $class): string
    {
        return trim(sanitize_html_class($class));
    }

    /**
     * Inietta lo script JS inline nell'interfaccia editor Elementor.
     *
     * Il JS:
     *
     * - ascolta il model del documento pagina;
     * - normalizza vecchie stringhe e array Select2;
     * - aggiorna live il `<body>` dell'iframe preview;
     * - usa un guard globale per evitare binding duplicati;
     * - viene cacheato in memoria PHP via metodo dedicato, senza creare asset file.
     *
     * @return void
     */
    public function inject_editor_reactivity_js(): void
    {
        echo $this->get_editor_reactivity_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Ritorna lo script inline editor, cacheato in memoria PHP.
     *
     * Nota interna:
     * niente file JS separato per tenere questo MU portabile e single-file.
     *
     * @return string
     */

    private function get_editor_reactivity_script(): string
    {
        static $script = null;

        if (is_string($script)) {
            return $script;
        }

        $setting = esc_js(self::SETTING_BODY_CLASSES);

        $script = <<<'HTML'
<script>
(function(window, document, $) {
	'use strict';

	var setting = '__CC_SETTING_BODY_CLASSES__';
	var guardKey = '__ccElementorBodyClassesLiveBound';

	/**
	 * Normalizza array Select2 o stringa legacy in lista di classi.
	 *
	 * @param {*} value
	 * @returns {string[]}
	 */
	function normalizeClasses(value) {
		var raw = [];

		if (Array.isArray(value)) {
			raw = value;
		} else if (typeof value === 'string') {
			raw = value.trim() ? value.trim().split(/\s+/) : [];
		} else if (value) {
			raw = [String(value)];
		}

		return raw
			.map(function(item) {
				return String(item || '').trim();
			})
			.filter(Boolean)
			.filter(function(item, index, list) {
				return list.indexOf(item) === index;
			});
	}

	/**
	 * Applica il delta classi al body dell'iframe preview.
	 *
	 * @param {*} previousValue
	 * @param {*} nextValue
	 * @returns {void}
	 */
	function syncPreviewBodyClasses(previousValue, nextValue) {
		if (!window.elementor || !window.elementor.$previewContents) {
			return;
		}

		var $body = window.elementor.$previewContents.find('body');

		if (!$body.length) {
			return;
		}

		var oldClasses = normalizeClasses(previousValue);
		var newClasses = normalizeClasses(nextValue);

		oldClasses.forEach(function(className) {
			if (newClasses.indexOf(className) === -1) {
				$body.removeClass(className);
			}
		});

		newClasses.forEach(function(className) {
			$body.addClass(className);
		});
	}

	/**
	 * Collega il listener al model impostazioni pagina.
	 *
	 * @returns {void}
	 */
	function bindElementorBodyClasses() {
		if (window[guardKey]) {
			return;
		}

		if (
			!window.elementor ||
			!window.elementor.settings ||
			!window.elementor.settings.page ||
			!window.elementor.settings.page.model
		) {
			return;
		}

		window[guardKey] = true;

		window.elementor.settings.page.model.on('change:' + setting, function(model, newValue) {
			syncPreviewBodyClasses(model.previous(setting) || [], newValue || []);
		});
	}

	$(window).on('elementor:init', bindElementorBodyClasses);

	if (window.elementor) {
		bindElementorBodyClasses();
	}
})(window, document, jQuery);
</script>
HTML;

        $script = str_replace('__CC_SETTING_BODY_CLASSES__', $setting, $script);

        return $script;
    }
}

new CC_Elementor_Body_Classes_MU();
