<?php

defined('ABSPATH') || exit;

/**
 * Interprete del Dependiente.
 *
 * Mision: convertir la forma natural de hablar del cliente en conceptos que
 * el motor del Dependiente ya sabe buscar. No aprende catalogo ni sustituye a
 * Academia: aprende lenguaje de cliente y lo traduce al lenguaje canonico.
 */
final class SEO_Dependiente_Interprete {
    const VERSION = '0.4.0';

    private static $morphology = null;

    /**
     * Interpreta una consulta de cliente.
     *
     * @param string $query Consulta original.
     * @return array
     */
    public static function interpret($query) {
        $original = self::clean_query($query);
        $normalized = self::normalize($original);

        $language = self::analyze_language($normalized);
        $result = array(
            'version'       => self::VERSION,
            'original'      => $original,
            'normalized'    => $normalized,
            'search_query'  => $original,
            'changed'       => false,
            'confidence'    => 0.0,
            'rule'          => '',
            'reason'        => '',
            'concepts'      => array(),
            'lesson_key'    => '',
            'intent'        => self::detect_intent($normalized),
            'language'      => $language,
        );

        if ('' === $normalized) {
            return $result;
        }

        /*
         * Regresion base: si el cliente ya escribe extractor + objeto,
         * conservamos la ruta literal que sabemos que funciona.
         */
        $extractor_targets = array(
            'rodamiento' => array('rodamiento', 'rodamientos', 'cojinete', 'cojinetes'),
            'polea'      => array('polea', 'poleas'),
            'engranaje'  => array('engranaje', 'engranajes'),
            'rotula'     => array('rotula', 'rotulas'),
        );

        if (self::has_any($normalized, array('extractor', 'extractores'))) {
            foreach ($extractor_targets as $canonical => $variants) {
                if (self::has_any($normalized, $variants)) {
                    return self::rewrite(
                        $result,
                        'extractor ' . self::plural_search_term($canonical),
                        0.99,
                        'literal_extractor_object',
                        'Se conserva extractor y el objeto principal.',
                        array('herramienta' => 'extractor', 'objeto' => $canonical),
                        'i1_subject_action'
                    );
                }
            }
        }

        /*
         * Intencion de extraccion expresada de forma natural.
         * Esta regla queda como regresion critica mientras la tabla linguistica
         * amplia su cobertura.
         */
        $extraction_actions = array(
            'sacar', 'sacarlo', 'sacarla', 'sacarlos', 'sacarlas',
            'extraer', 'extraerlo', 'extraerla', 'extraerlos', 'extraerlas',
            'quitar', 'quitarlo', 'quitarla', 'quitarlos', 'quitarlas',
            'retirar', 'retirarlo', 'retirarla', 'retirarlos', 'retirarlas',
            'desmontar', 'desmontarlo', 'desmontarla', 'desmontarlos', 'desmontarlas',
        );
        if (self::has_any($normalized, $extraction_actions)) {
            foreach ($extractor_targets as $canonical => $variants) {
                if (self::has_any($normalized, $variants)) {
                    return self::rewrite(
                        $result,
                        'extractor ' . self::plural_search_term($canonical),
                        0.98,
                        'natural_extraction_object',
                        'Accion de extraccion mas objeto reconocido.',
                        array('intencion' => 'extraer', 'herramienta' => 'extractor', 'objeto' => $canonical),
                        'i1_subject_action'
                    );
                }
            }
        }

        /*
         * Desambiguacion de compresor de aire frente a compresores mecanicos
         * de muelles/suspension.
         */
        $air_context = array(
            'inflar', 'inflado', 'rueda', 'ruedas', 'neumatico', 'neumaticos',
            'aire', 'neumatica', 'neumaticas',
        );
        $has_air_context = self::has_any($normalized, $air_context)
            || self::contains_phrase($normalized, 'herramienta neumatica')
            || self::contains_phrase($normalized, 'herramientas neumaticas');

        if ($has_air_context && self::has_any($normalized, array('compresor', 'compresores'))) {
            return self::rewrite(
                $result,
                'compresor aire',
                0.98,
                'air_compressor_context',
                'Compresor desambiguado por contexto de inflado o uso neumatico.',
                array('producto' => 'compresor', 'medio' => 'aire'),
                'i1_subject_action'
            );
        }

        if ($has_air_context
            && self::has_any($normalized, array('inflar', 'inflado'))
            && self::has_any($normalized, array('herramienta', 'herramientas', 'neumatica', 'neumaticas'))) {
            return self::rewrite(
                $result,
                'compresor aire',
                0.95,
                'air_need_without_product_name',
                'Necesidad de inflado y herramienta neumatica interpretada como compresor de aire.',
                array('necesidad' => 'aire_comprimido', 'producto' => 'compresor'),
                'i1_subject_action'
            );
        }

        /*
         * Memoria linguistica persistente. Aqui vive el aprendizaje del
         * Interprete: verbo/sinonimo/frase -> concepto canonico de busqueda.
         */
        $lexicon_result = self::interpret_from_lexicon($result, false);
        if (!empty($lexicon_result['changed'])) {
            return $lexicon_result;
        }

        /*
         * Segunda lectura: gramática castellana. Se eliminan palabras funcionales
         * y se convierten formas verbales conocidas a su infinitivo. Solo se usa
         * para consultar conocimiento ya aprendido; nunca crea una relación nueva
         * mientras atiende a un cliente.
         */
        $semantic_query = self::normalize((string) ($language['semantic_query'] ?? ''));
        if ('' !== $semantic_query && $semantic_query !== $normalized) {
            $semantic_result = $result;
            $semantic_result['normalized'] = $semantic_query;
            $semantic_match = self::interpret_from_lexicon($semantic_result, false);
            if (!empty($semantic_match['changed'])) {
                $semantic_match['normalized'] = $normalized;
                $semantic_match['changed'] = self::normalize((string) ($semantic_match['search_query'] ?? '')) !== $normalized;
                $semantic_match['rule'] = sanitize_key('language_' . (string) ($semantic_match['rule'] ?? 'lexicon'));
                $semantic_match['reason'] = 'Análisis lingüístico: ' . (string) ($semantic_match['reason'] ?? 'expresión comprendida.');
                $semantic_match['language'] = $language;
                return $semantic_match;
            }
        }

        // Tras L6, las erratas pequeñas se comparan solo contra expresiones ya
        // aprendidas. Para palabras cortas se limita a una edición; las largas
        // admiten dos (incluye muchas transposiciones), evitando falsos positivos.
        $grammar = self::grammar_settings();
        $fuzzy_distance = !empty($grammar['natural_language']) ? absint($grammar['fuzzy_distance'] ?? 0) : 0;
        if ($fuzzy_distance > 0) {
            $fuzzy_base = $result;
            if ('' !== $semantic_query) {
                $fuzzy_base['normalized'] = $semantic_query;
            }
            $fuzzy_result = self::interpret_from_lexicon($fuzzy_base, true, $fuzzy_distance);
            if (!empty($fuzzy_result['changed'])) {
                $fuzzy_result['normalized'] = $normalized;
                $fuzzy_result['changed'] = self::normalize((string) ($fuzzy_result['search_query'] ?? '')) !== $normalized;
                $fuzzy_result['language'] = $language;
                return $fuzzy_result;
            }
        }

        return $result;
    }

    private static function interpret_from_lexicon($result, $fuzzy = false, $fuzzy_distance = 1) {
        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            return $result;
        }

        $rows = $fuzzy
            ? SEO_Dependiente_Interprete_DB::fuzzy_matching_rows((string) ($result['normalized'] ?? ''), $fuzzy_distance)
            : SEO_Dependiente_Interprete_DB::matching_rows((string) ($result['normalized'] ?? ''));
        if (!$rows) {
            return $result;
        }

        foreach ($rows as $row) {
            $target = self::clean_query((string) ($row['target_search'] ?? ''));
            if ('' === $target) {
                continue;
            }

            $matched_context = isset($row['matched_context']) && is_array($row['matched_context'])
                ? $row['matched_context']
                : array();
            if ($matched_context) {
                $context = self::canonical_context_term((string) reset($matched_context));
                if ('' !== $context && !self::contains_phrase(self::normalize($target), self::normalize($context))) {
                    $target .= ' ' . $context;
                }
            }

            $expression = (string) ($row['expression'] ?? '');
            $canonical = (string) ($row['canonical_term'] ?? $target);
            $relation = sanitize_key((string) ($row['relation_type'] ?? 'synonym'));
            $lesson_key = sanitize_key((string) ($row['lesson_key'] ?? ''));

            return self::rewrite(
                $result,
                $target,
                (float) ($row['confidence'] ?? 0.9),
                ($fuzzy ? 'lexicon_fuzzy_' : 'lexicon_') . $relation,
                ($fuzzy ? 'Coincidencia ortográfica aproximada: ' : 'El Intérprete traduce ') . '"' . $expression . '" al concepto "' . $canonical . '".',
                array(
                    'expresion_cliente' => $expression,
                    'concepto_canonico' => $canonical,
                    'relacion' => $relation,
                ),
                $lesson_key
            );
        }

        return $result;
    }

    /**
     * Vista de prueba en STAGING. El entrenamiento real se almacena en el
     * lexico, pero esta pantalla nunca modifica datos al cargarla.
     */
    public static function render_tab() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder al Intérprete.', 'seo-taxonomy'));
        }

        $query = isset($_GET['interpreter_q'])
            ? self::clean_query(wp_unslash((string) $_GET['interpreter_q']))
            : '';
        $test = '' !== $query ? self::interpret($query) : array();
        $stats = class_exists('SEO_Dependiente_Interprete_DB')
            ? SEO_Dependiente_Interprete_DB::stats()
            : array('ready' => false, 'active' => 0, 'lesson_i1' => 0, 'linked_vocabulary' => 0);
        $linguista = class_exists('SEO_Dependiente_Linguista')
            ? SEO_Dependiente_Linguista::process_monitor_payload()
            : array();
        ?>
        <div class="postbox seo-dependiente-admin__box" style="margin-top:16px; padding:18px;">
            <h2 style="margin-top:0;">Intérprete <small>v<?php echo esc_html(self::VERSION); ?></small></h2>
            <p><strong>Objetivo:</strong> enseñar al Dependiente a entender cómo habla el cliente.</p>
            <p>
                Memoria lingüística: <strong><?php echo !empty($stats['ready']) ? 'lista' : 'no disponible'; ?></strong>
                · expresiones activas: <strong><?php echo esc_html(number_format_i18n((int) ($stats['active'] ?? 0))); ?></strong>
                · I1 sujeto ↔ acción: <strong><?php echo esc_html(number_format_i18n((int) ($stats['lesson_i1'] ?? 0))); ?></strong>
                · enlazadas a Vocabulary: <strong><?php echo esc_html(number_format_i18n((int) ($stats['linked_vocabulary'] ?? 0))); ?></strong>
            </p>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="seo-dependiente">
                <input type="hidden" name="tab" value="interpreter">
                <p>
                    <label for="seo-dependiente-interpreter-q"><strong>Probar una pregunta</strong></label><br>
                    <input id="seo-dependiente-interpreter-q" type="text" class="large-text" name="interpreter_q" value="<?php echo esc_attr($query); ?>" placeholder="Ej.: Tengo que sacar un rodamiento muy agarrado. ¿Qué herramienta necesito?">
                </p>
                <?php submit_button('Interpretar', 'primary', '', false); ?>
            </form>

            <?php if ($test) : ?>
                <hr>
                <p><strong>Original:</strong> <?php echo esc_html((string) $test['original']); ?></p>
                <p><strong>Búsqueda resultante:</strong> <code><?php echo esc_html((string) $test['search_query']); ?></code></p>
                <p><strong>Cambio:</strong> <?php echo !empty($test['changed']) ? 'Sí' : 'No'; ?> · <strong>Confianza:</strong> <?php echo esc_html(number_format_i18n(((float) $test['confidence']) * 100, 0)); ?>%</p>
                <?php if (!empty($test['lesson_key'])) : ?>
                    <p><strong>Lección:</strong> <code><?php echo esc_html((string) $test['lesson_key']); ?></code></p>
                <?php endif; ?>
                <?php if (!empty($test['reason'])) : ?>
                    <p><strong>Motivo:</strong> <?php echo esc_html((string) $test['reason']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="postbox seo-dependiente-admin__box" style="padding:18px;">
            <h2 style="margin-top:0;">Lingüista · formación del Intérprete</h2>
            <p><strong>Objetivo:</strong> que el Intérprete entienda cómo habla el cliente y entregue al Dependiente una petición clara. El catálogo específico se aprende automáticamente; no hay que mantener una lista manual de todas las palabras.</p>
            <?php if ($linguista) :
                $ling_state = isset($linguista['state']) && is_array($linguista['state']) ? $linguista['state'] : array();
                $ling_current = isset($linguista['current']) && is_array($linguista['current']) ? $linguista['current'] : array();
            ?>
                <p>Estado: <strong><?php echo esc_html((string) ($ling_state['status'] ?? 'stopped')); ?></strong>
                · aprendidas activas: <strong><?php echo esc_html(number_format_i18n((int) ($stats['active'] ?? 0))); ?></strong>
                · evidencias: <strong><?php echo esc_html(number_format_i18n((int) ($stats['evidence'] ?? 0))); ?></strong></p>
                <?php if ($ling_current) : ?><p>Actual: <strong>Lección <?php echo esc_html((string) absint($ling_current['order'] ?? 0)); ?> · <?php echo esc_html((string) ($ling_current['title'] ?? '')); ?></strong></p><?php endif; ?>
                <ol>
                    <?php foreach ((array) ($linguista['lessons'] ?? array()) as $lesson) : ?>
                        <li><strong><?php echo esc_html('L' . absint($lesson['order'] ?? 0) . ' · ' . (string) ($lesson['title'] ?? '')); ?></strong> — <?php echo esc_html((string) ($lesson['goal'] ?? '')); ?></li>
                    <?php endforeach; ?>
                </ol>
                <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(array('page'=>'seo-processes'), admin_url('admin.php'))); ?>">Abrir Gestor de procesos</a></p>
            <?php endif; ?>
            <p class="description">Regresión base conservada: <code>extractor ↔ extraer/sacar</code>, <code>taladro ↔ taladrar/perforar/agujerear</code> y desambiguación de compresor de aire.</p>
        </div>
        <?php
    }

    private static function grammar_settings() {
        $stored = get_option('seo_dependiente_interprete_grammar', array());
        return is_array($stored) ? $stored : array();
    }

    private static function morphology_settings() {
        if (is_array(self::$morphology)) {
            return self::$morphology;
        }
        $stored = get_option('seo_dependiente_interprete_morphology', array());
        self::$morphology = is_array($stored) ? $stored : array();
        return self::$morphology;
    }

    /**
     * Analiza castellano antes de buscar: reconoce palabras funcionales, formas
     * verbales (incluidas irregulares aprendidas) y construye una versión
     * semántica sin ruido. No modifica datos ni aprende durante la petición.
     */
    private static function analyze_language($normalized) {
        $normalized = self::normalize($normalized);
        $base = array(
            'enabled'        => false,
            'semantic_query' => $normalized,
            'content_tokens' => array_values(array_filter(explode(' ', $normalized))),
            'removed_tokens' => array(),
            'verbs'          => array(),
            'tokens'         => array(),
        );
        if ('' === $normalized) {
            return $base;
        }

        $grammar = self::grammar_settings();
        if (empty($grammar['natural_language'])) {
            return $base;
        }
        $base['enabled'] = true;

        $stopword_map = array();
        foreach ((array) ($grammar['stopword_groups'] ?? array()) as $group => $words) {
            foreach ((array) $words as $word) {
                $word = self::normalize($word);
                if ('' !== $word && false === strpos($word, ' ')) {
                    $stopword_map[$word] = sanitize_key((string) $group);
                }
            }
        }
        $semantic_stopwords = array_flip(array_values(array_filter(array_map(array(__CLASS__, 'normalize'), (array) ($grammar['semantic_stopwords'] ?? array())))));
        $stop_verbs = array_flip(array_values(array_filter(array_map(array(__CLASS__, 'normalize'), (array) ($grammar['stop_verbs'] ?? array())))));
        $irregular = array();
        foreach ((array) ($grammar['irregular_forms'] ?? array()) as $form => $lemma) {
            $form = self::normalize($form);
            $lemma = self::normalize($lemma);
            if ('' !== $form && '' !== $lemma && false === strpos($form, ' ') && false === strpos($lemma, ' ')) {
                $irregular[$form] = $lemma;
            }
        }
        $morphology = self::morphology_settings();
        $forms = isset($morphology['forms']) && is_array($morphology['forms']) ? $morphology['forms'] : array();

        $semantic = array();
        foreach (array_values(array_filter(explode(' ', $normalized))) as $token) {
            $role = isset($stopword_map[$token]) ? $stopword_map[$token] : 'content';
            $lemma = '';
            $verb_source = '';

            if (isset($forms[$token]) && is_string($forms[$token]) && '' !== $forms[$token]) {
                $lemma = self::normalize($forms[$token]);
                $verb_source = 'learned_morphology';
            } elseif (isset($irregular[$token])) {
                $lemma = $irregular[$token];
                $verb_source = 'irregular_dictionary';
            } elseif (preg_match('/(?:ar|er|ir)$/', $token)) {
                $lemma = $token;
                $verb_source = 'infinitive';
            }

            if ('' !== $lemma) {
                $role = isset($stop_verbs[$lemma]) ? 'stop_verb' : 'verb';
                $base['verbs'][] = array('surface' => $token, 'lemma' => $lemma, 'source' => $verb_source);
            }

            $remove = ('stop_verb' === $role) || isset($semantic_stopwords[$token]);
            $base['tokens'][] = array(
                'token'   => $token,
                'role'    => $role,
                'lemma'   => $lemma,
                'removed' => $remove ? 1 : 0,
            );
            if ($remove) {
                $base['removed_tokens'][] = $token;
                continue;
            }
            $semantic[] = '' !== $lemma ? $lemma : $token;
        }

        $semantic = array_values(array_filter($semantic));
        $base['content_tokens'] = $semantic;
        $base['semantic_query'] = implode(' ', $semantic);
        return $base;
    }

    private static function detect_intent($normalized) {
        $normalized = self::normalize($normalized);
        if ('' === $normalized) {
            return 'unknown';
        }
        $grammar = self::grammar_settings();
        if (empty($grammar['intent_detection']) || empty($grammar['intents']) || !is_array($grammar['intents'])) {
            return 'find_product';
        }
        // Las intenciones más específicas se prueban antes que la búsqueda genérica.
        $order = array('compare','compatibility','replacement','accessory','solve_problem','find_product');
        foreach ($order as $intent) {
            foreach ((array) ($grammar['intents'][$intent] ?? array()) as $phrase) {
                if (self::contains_phrase($normalized, self::normalize($phrase))) {
                    return $intent;
                }
            }
        }
        return 'find_product';
    }

    private static function rewrite($result, $search_query, $confidence, $rule, $reason, $concepts = array(), $lesson_key = '') {
        $result['search_query'] = self::clean_query($search_query);
        $result['changed'] = self::normalize((string) $result['search_query']) !== (string) $result['normalized'];
        $result['confidence'] = min(1, max(0, (float) $confidence));
        $result['rule'] = sanitize_key((string) $rule);
        $result['reason'] = (string) $reason;
        $result['concepts'] = is_array($concepts) ? $concepts : array();
        $result['lesson_key'] = sanitize_key((string) $lesson_key);
        return $result;
    }

    private static function clean_query($value) {
        $value = sanitize_text_field((string) $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1000, 'UTF-8');
        }
        return substr($value, 0, 1000);
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function has_any($normalized, $terms) {
        foreach ((array) $terms as $term) {
            if (self::contains_phrase($normalized, self::normalize($term))) {
                return true;
            }
        }
        return false;
    }

    private static function contains_phrase($haystack, $needle) {
        $haystack = trim((string) $haystack);
        $needle = trim((string) $needle);
        if ('' === $haystack || '' === $needle) {
            return false;
        }
        return false !== strpos(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }

    private static function plural_search_term($canonical) {
        $map = array(
            'rodamiento' => 'rodamientos',
            'polea'      => 'poleas',
            'engranaje'  => 'engranajes',
            'rotula'     => 'rotulas',
        );
        return $map[$canonical] ?? $canonical;
    }

    private static function canonical_context_term($term) {
        $term = self::normalize($term);
        $map = array(
            'rodamiento' => 'rodamientos',
            'rodamientos' => 'rodamientos',
            'cojinete' => 'rodamientos',
            'cojinetes' => 'rodamientos',
            'polea' => 'poleas',
            'poleas' => 'poleas',
            'engranaje' => 'engranajes',
            'engranajes' => 'engranajes',
            'rotula' => 'rotulas',
            'rotulas' => 'rotulas',
        );
        return $map[$term] ?? $term;
    }
}
