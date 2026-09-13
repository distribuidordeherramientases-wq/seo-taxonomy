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
    const VERSION = '0.2.0';

    /**
     * Interpreta una consulta de cliente.
     *
     * @param string $query Consulta original.
     * @return array
     */
    public static function interpret($query) {
        $original = self::clean_query($query);
        $normalized = self::normalize($original);

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
        $lexicon_result = self::interpret_from_lexicon($result);
        if (!empty($lexicon_result['changed'])) {
            return $lexicon_result;
        }

        return $result;
    }

    private static function interpret_from_lexicon($result) {
        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            return $result;
        }

        $rows = SEO_Dependiente_Interprete_DB::matching_rows((string) ($result['normalized'] ?? ''));
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
                'lexicon_' . $relation,
                'El Intérprete traduce "' . $expression . '" al concepto "' . $canonical . '".',
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
            <h2 style="margin-top:0;">Lección I1 · sujeto ↔ verbo ↔ sinónimo</h2>
            <p><code>taladro</code> ↔ <code>taladrar</code> ↔ <code>perforar</code> ↔ <code>agujerear</code></p>
            <p><code>extractor</code> ↔ <code>extraer</code> / <code>sacar</code> + objeto mecánico</p>
            <p><code>soldadora</code> ↔ <code>soldar</code> · <code>lijadora</code> ↔ <code>lijar</code> · <code>remachadora</code> ↔ <code>remachar</code></p>
        </div>
        <?php
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
