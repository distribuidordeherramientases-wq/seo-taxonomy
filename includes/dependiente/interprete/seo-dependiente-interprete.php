<?php

defined('ABSPATH') || exit;

/**
 * Interprete del Dependiente.
 *
 * Convierte frases naturales del cliente en una consulta corta y canonica que
 * el buscador ya sabe resolver. La v0.1.0 es deliberadamente conservadora:
 * solo reescribe cuando existe una señal de alta confianza y nunca aprende ni
 * modifica datos automaticamente.
 */
final class SEO_Dependiente_Interprete {
    const VERSION = '0.1.0';

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
        );

        if ('' === $normalized) {
            return $result;
        }

        /*
         * Si el cliente ya escribe "extractor" y ademas nombra el objeto,
         * reducimos la conversacion a la busqueda literal que ya sabemos que
         * funciona. Esto evita que palabras como "necesito", "herramienta" o
         * "recomiendas" bloqueen la recuperacion por AND.
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
                        'Se conserva la palabra literal extractor y el objeto principal.',
                        array('herramienta' => 'extractor', 'objeto' => $canonical)
                    );
                }
            }
        }

        /*
         * Intencion de extraccion expresada en lenguaje natural.
         * Ejemplo: "tengo que sacar un rodamiento muy agarrado".
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
                        array('intencion' => 'extraer', 'herramienta' => 'extractor', 'objeto' => $canonical)
                    );
                }
            }
        }

        /*
         * Desambiguacion de compresor de aire frente a compresores mecanicos de
         * muelles/suspension. Solo se activa con contexto inequivoco de aire.
         */
        $air_context = array(
            'inflar', 'inflado', 'rueda', 'ruedas', 'neumatico', 'neumaticos',
            'aire', 'neumatica', 'neumaticas', 'neumatico', 'neumaticos',
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
                array('producto' => 'compresor', 'medio' => 'aire')
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
                array('necesidad' => 'aire_comprimido', 'producto' => 'compresor')
            );
        }

        return $result;
    }

    /**
     * Vista simple para probar el Interprete en STAGING sin modificar datos.
     */
    public static function render_tab() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder al Intérprete.', 'seo-taxonomy'));
        }

        $query = isset($_GET['interpreter_q'])
            ? self::clean_query(wp_unslash((string) $_GET['interpreter_q']))
            : '';
        $test = '' !== $query ? self::interpret($query) : array();
        ?>
        <div class="postbox seo-dependiente-admin__box" style="margin-top:16px; padding:18px;">
            <h2 style="margin-top:0;">Intérprete <small>v<?php echo esc_html(self::VERSION); ?></small></h2>
            <p>Convierte lenguaje natural del cliente en una búsqueda corta que el Dependiente pueda resolver.</p>
            <p><strong>v0.1:</strong> alta confianza, sin aprendizaje automático y sin modificar conocimiento.</p>

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
                <?php if (!empty($test['reason'])) : ?>
                    <p><strong>Motivo:</strong> <?php echo esc_html((string) $test['reason']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="postbox seo-dependiente-admin__box" style="padding:18px;">
            <h2 style="margin-top:0;">Pruebas iniciales</h2>
            <p><code>Tengo que sacar un rodamiento muy agarrado</code> → <code>extractor rodamientos</code></p>
            <p><code>Necesito un extractor de rodamientos para sacar uno agarrado</code> → <code>extractor rodamientos</code></p>
            <p><code>Necesito un compresor para inflar ruedas y usar herramientas neumáticas</code> → <code>compresor aire</code></p>
        </div>
        <?php
    }

    private static function rewrite($result, $search_query, $confidence, $rule, $reason, $concepts = array()) {
        $result['search_query'] = self::clean_query($search_query);
        $result['changed'] = self::normalize((string) $result['search_query']) !== (string) $result['normalized'];
        $result['confidence'] = min(1, max(0, (float) $confidence));
        $result['rule'] = sanitize_key((string) $rule);
        $result['reason'] = (string) $reason;
        $result['concepts'] = is_array($concepts) ? $concepts : array();
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
}
