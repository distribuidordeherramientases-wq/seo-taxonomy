<?php

defined('ABSPATH') || exit;

/**
 * Interprete minimo del Dependiente.
 *
 * Contrato de runtime:
 * - escucha el campo de texto del cliente;
 * - normaliza texto y corrige erratas conservadoras;
 * - elimina articulos, pronombres, preposiciones seguras y ruido funcional;
 * - lleva formas verbales conocidas a infinitivo;
 * - entrega una consulta filtrada y enriquecida linguisticamente al Dependiente;
 * - usa la memoria validada de Linguista para identificar accion y vocabulario
 *   canonico (TIPO/ROL/APLICACION/PLATAFORMA/SUBTIPO/categoria/etiqueta);
 * - no consulta productos, no selecciona catalogo, no aplica filtros y no pregunta.
 *
 * La memoria de Linguista se usa como diccionario de traduccion entre el lenguaje
 * del cliente y el idioma semantico que ya conoce Dependiente.
 */
final class SEO_Dependiente_Interprete {
    const VERSION = '1.2.0';

    private static $morphology = null;

    /**
     * Filtra lenguaje humano y devuelve una unica consulta para Dependiente.
     *
     * @param string $query Consulta original.
     * @return array
     */
    public static function interpret($query) {
        $original = self::clean_query($query);
        $normalized = self::normalize($original);
        $result = array(
            'version'         => self::VERSION,
            'original'        => $original,
            'normalized'      => $normalized,
            'search_query'    => $normalized,
            'changed'         => false,
            'confidence'      => 1.0,
            'rule'            => 'interpreter_linguistic_bridge',
            'reason'          => 'Filtro linguistico + traduccion al vocabulario semantico aprendido por Linguista.',
            'concepts'        => array(),
            'lesson_key'      => '',
            'intent'          => 'linguistic_bridge',
            'language'        => array(),
            'structured'      => array(),
            'assist_terms'    => array(),
            'dependiente_query' => $normalized,
            'transformations' => array(),
            'lexicon_matches' => array(),
        );

        if ('' === $normalized) {
            $result['confidence'] = 0.0;
            return $result;
        }

        $trace = array();

        // 1. Ortografia: solo correcciones conservadoras contra lenguaje ya conocido.
        $corrected = self::correct_typos($normalized, $trace);

        // 2. Filtro linguistico base: ruido seguro + infinitivo.
        $language = self::analyze_language($corrected);
        $working = self::dedupe_query((string) ($language['semantic_query'] ?? $corrected));

        // Nunca dejamos la consulta vacia por exceso de limpieza.
        if ('' === $working) {
            $working = $corrected ?: $normalized;
        }

        // 3. Traduccion al idioma de Dependiente. Solo consulta la memoria
        // linguistica ya aprendida; no toca productos ni facetas del catalogo.
        $structured = self::classify_with_linguista($corrected, $working);
        $assist_terms = isset($structured['assist_terms']) && is_array($structured['assist_terms'])
            ? $structured['assist_terms']
            : array();
        $dependiente_query = self::dedupe_query(trim($working . ' ' . implode(' ', $assist_terms)));

        $language['typo_corrected'] = self::normalize($corrected) !== $normalized;
        $result['search_query'] = self::clean_query($working);
        $result['dependiente_query'] = self::clean_query($dependiente_query ?: $working);
        $result['changed'] = self::normalize($result['search_query']) !== $normalized;
        $result['language'] = $language;
        $result['structured'] = $structured;
        $result['assist_terms'] = $assist_terms;
        $result['lexicon_matches'] = isset($structured['matches']) ? $structured['matches'] : array();
        $result['transformations'] = array_values(array_unique($trace));

        if (!empty($language['removed_tokens'])) {
            $result['confidence'] = min($result['confidence'], 0.99);
        }
        if (!empty($language['typo_corrected'])) {
            $result['confidence'] = min($result['confidence'], 0.92);
        }

        return $result;
    }

    /**
     * El Dependiente recibe exactamente la consulta filtrada. Las antiguas
     * respuestas de dialogo/hints no participan en el runtime.
     */
    public static function refine_search_query($interpretation, $confirmed_hints = array()) {
        $interpretation = is_array($interpretation) ? $interpretation : array();
        $query = self::clean_query((string) ($interpretation['dependiente_query'] ?? ''));
        if ('' !== $query) {
            return $query;
        }
        $query = self::clean_query((string) ($interpretation['search_query'] ?? ''));
        if ('' !== $query) {
            return $query;
        }
        return self::clean_query((string) ($interpretation['original'] ?? ''));
    }

    /** Intérprete mínimo nunca pregunta al cliente. */
    public static function plan_clarification($context = array()) {
        return array(
            'should_ask'          => false,
            'question'            => '',
            'role'                => '',
            'reason'              => 'interpreter_minimal_no_dialogue',
            'delay_ms'            => 0,
            'step'                => 0,
            'max_steps'           => 0,
            'strategy'            => 'interpreter_minimal_filter',
            'axis'                => '',
            'estimated_reduction' => 0,
            'options'             => array(),
        );
    }

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
        <?php if (!empty($_GET['linguista_notice'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['linguista_notice'])))); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['linguista_error'])) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['linguista_error'])))); ?></p></div>
        <?php endif; ?>
        <div class="postbox seo-dependiente-admin__box" style="margin-top:16px; padding:18px;">
            <h2 style="margin-top:0;">Intérprete · puente lingüístico <small>v<?php echo esc_html(self::VERSION); ?></small></h2>
            <p><strong>Objetivo:</strong> limpiar el texto, identificar su función lingüística y traducirlo al vocabulario semántico que entiende Dependiente, sin buscar productos ni decidir catálogo.</p>
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
                <p><strong>Texto filtrado:</strong> <code><?php echo esc_html((string) $test['search_query']); ?></code></p>
                <p><strong>Dependiente recibe:</strong> <code><?php echo esc_html((string) ($test['dependiente_query'] ?? $test['search_query'])); ?></code></p>
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
            <p><strong>Objetivo:</strong> enseñar al Intérprete a comprender cómo habla el cliente y entregar al Dependiente una petición clara. Academia enseña catálogo al Dependiente; Lingüista enseña lenguaje al Intérprete.</p>
            <?php if (!$linguista) : ?>
                <div class="notice notice-error inline"><p><strong>Lingüista no está cargado.</strong> Revisa el bootstrap del Intérprete antes de iniciar la formación.</p></div>
            <?php else :
                $ling_state = isset($linguista['state']) && is_array($linguista['state']) ? $linguista['state'] : array();
                $ling_current = isset($linguista['current']) && is_array($linguista['current']) ? $linguista['current'] : array();
                $ling_rows = isset($linguista['lesson_statuses']) && is_array($linguista['lesson_statuses']) ? $linguista['lesson_statuses'] : array();
                $ling_progress = absint($linguista['progress'] ?? 0);
                $ling_status = sanitize_key((string) ($ling_state['status'] ?? 'stopped'));
                $ling_running = !empty($linguista['running']);
                $status_labels = array(
                    'stopped' => 'Preparado',
                    'running' => 'En formación',
                    'paused' => 'Pausado',
                    'error' => 'Error',
                    'completed' => 'Curso completado',
                );
                $status_label = $status_labels[$ling_status] ?? $ling_status;
            ?>
                <p>
                    Estado: <strong><?php echo esc_html($status_label); ?></strong>
                    · progreso: <strong><?php echo esc_html(number_format_i18n($ling_progress)); ?>%</strong>
                    · lote actual: <strong><?php echo esc_html(number_format_i18n(absint($ling_state['batch_size'] ?? 0))); ?></strong>
                    · memoria activa: <strong><?php echo esc_html(number_format_i18n((int) ($stats['active'] ?? 0))); ?></strong>
                    · evidencias: <strong><?php echo esc_html(number_format_i18n((int) ($stats['evidence'] ?? 0))); ?></strong>
                </p>
                <div style="height:10px;background:#dcdcde;border-radius:8px;overflow:hidden;max-width:760px;margin:8px 0 14px;">
                    <div style="height:100%;width:<?php echo esc_attr((string) $ling_progress); ?>%;background:#2271b1;"></div>
                </div>
                <?php if ($ling_current) : ?>
                    <p>Lección actual: <strong>L<?php echo esc_html((string) absint($ling_current['order'] ?? 0)); ?> · <?php echo esc_html((string) ($ling_current['title'] ?? '')); ?></strong></p>
                <?php endif; ?>
                <?php if (!empty($ling_state['last_message'])) : ?>
                    <p class="description"><?php echo esc_html((string) $ling_state['last_message']); ?></p>
                <?php endif; ?>
                <?php if (!empty($ling_state['last_error'])) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html((string) $ling_state['last_error']); ?></p></div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px;">
                    <?php if ($ling_running) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="pause">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button">Pausar formación</button>
                        </form>
                    <?php elseif (in_array($ling_status, array('paused','error'), true)) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="resume">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button button-primary">Reanudar formación</button>
                        </form>
                    <?php elseif ('completed' !== $ling_status) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="start">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button button-primary">Iniciar formación Lingüista</button>
                        </form>
                    <?php endif; ?>
                    <?php if ('completed' === $ling_status) : ?>
                        <a class="button button-primary" href="<?php echo esc_url(SEO_Dependiente_Linguista::export_url('course')); ?>">Descargar evolución completa JSON</a>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Reentrenar Lingüista desde L1? La memoria aprendida no se borra; se vuelve a validar.');">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="restart_course">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button">Reentrenar desde L1</button>
                        </form>
                    <?php endif; ?>
                </div>

                <table class="widefat striped" style="max-width:1100px;">
                    <thead><tr><th>Lección</th><th>Estado</th><th>Progreso</th><th>Aprendido / revisado</th><th>Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($ling_rows as $row) :
                        $row_status = sanitize_key((string) ($row['status'] ?? 'pending'));
                        $row_labels = array('completed'=>'Completada','running'=>'En curso','paused'=>'Pausada','error'=>'Error','pending'=>'Pendiente','locked'=>'Bloqueada');
                    ?>
                        <tr>
                            <td><strong>L<?php echo esc_html((string) absint($row['order'] ?? 0)); ?> · <?php echo esc_html((string) ($row['title'] ?? '')); ?></strong><br><small><?php echo esc_html((string) ($row['goal'] ?? '')); ?></small></td>
                            <td><?php echo esc_html($row_labels[$row_status] ?? $row_status); ?></td>
                            <td><?php echo esc_html(number_format_i18n(absint($row['progress'] ?? 0))); ?>%</td>
                            <td><?php echo esc_html(number_format_i18n(absint($row['learned'] ?? 0))); ?> aprendidas · <?php echo esc_html(number_format_i18n(absint($row['rejected'] ?? 0))); ?> rechazadas · <?php echo esc_html(number_format_i18n(absint($row['processed'] ?? 0))); ?> procesadas</td>
                            <td>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <?php if ('completed' === $row_status) : ?>
                                        <a class="button button-small" href="<?php echo esc_url(SEO_Dependiente_Linguista::export_url('lesson', (string) ($row['key'] ?? ''))); ?>">Descargar JSON</a>
                                    <?php endif; ?>
                                    <?php if (!$ling_running && in_array($row_status, array('completed','paused','error'), true)) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Reentrenar desde esta lección? Los resultados posteriores se invalidarán, pero no se borrará la memoria lingüística ya aprendida.');" style="margin:0;">
                                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                                            <input type="hidden" name="command" value="retrain_from">
                                            <input type="hidden" name="lesson_key" value="<?php echo esc_attr((string) ($row['key'] ?? '')); ?>">
                                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                                            <button type="submit" class="button button-small">Reentrenar desde aquí</button>
                                        </form>
                                    <?php elseif ('completed' !== $row_status) : ?>—<?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="description" style="margin-top:14px;"><strong>Informes:</strong> al completar cada lección queda disponible su JSON con métricas y fotografía de memoria antes/después. Al terminar L8 aparece además el JSON de evolución completa L1 → L8.</p>
                <p class="description"><strong>Worker:</strong> Lingüista solo empieza cuando lo arrancas aquí. Después el gestor le concede ventanas de trabajo y procesa lotes pequeños/adaptativos; nunca lanza toda una lección de golpe.</p>
            <?php endif; ?>
            <p class="description"><strong>Contrato:</strong> filtra lenguaje y usa solo memoria lingüística validada para identificar acción y vocabulario canónico. No busca productos, no consulta facetas, no aplica filtros y no pregunta al cliente.</p>
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
     * Corrige erratas de forma conservadora. Solo usa expresiones que ya conoce
     * la memoria linguistica; nunca target_search, categorias ni productos.
     */
    private static function correct_typos($normalized, &$trace) {
        $normalized = self::normalize($normalized);
        if ('' === $normalized) {
            return '';
        }

        // Error mecanico obvio: una consonante duplicada accidentalmente.
        // Conservamos rr, ll y cc porque son secuencias validas en castellano.
        $collapsed = preg_replace('/([^aeiourlc])\\1+/u', '$1', $normalized);
        if (is_string($collapsed) && $collapsed !== $normalized) {
            $trace[] = 'typo:duplicated_letter';
            $normalized = self::normalize($collapsed);
        }

        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            return $normalized;
        }

        // Pedimos distancia 2 para poder detectar una transposicion adyacente
        // (itenda -> tienda), pero aplicamos criterios mas estrictos despues.
        $rows = SEO_Dependiente_Interprete_DB::fuzzy_matching_rows($normalized, 2);
        if (!$rows) {
            return $normalized;
        }

        $tokens = array_values(array_filter(explode(' ', $normalized)));
        $best = array();
        foreach ((array) $rows as $row) {
            $expr = self::normalize((string) ($row['normalized_expression'] ?? $row['expression'] ?? ''));
            if ('' === $expr || false !== strpos($expr, ' ') || strlen($expr) < 5) {
                continue;
            }
            foreach ($tokens as $index => $token) {
                if ($token === $expr || strlen($token) < 5) {
                    continue;
                }
                // Si el token ya existe literalmente en la memoria, es una palabra
                // conocida y queda protegida frente al corrector.
                if (self::known_lexicon_expression($token)) {
                    continue;
                }
                // Una forma verbal reconocible no es una errata. Evita, por
                // ejemplo, corregir "taladrado" a "taladro".
                if (self::known_verb_form($token)) {
                    continue;
                }
                $distance = self::typo_distance($token, $expr);
                $allowed = strlen($token) >= 8 ? 2 : 1;
                if ($distance < 1 || $distance > $allowed) {
                    continue;
                }

                // Nunca corregimos una sustitucion simple entre dos palabras de
                // igual longitud (pared -> pares). Solo permitimos transposicion
                // adyacente o insercion/eliminacion conservadora.
                if (strlen($token) === strlen($expr) && !self::is_adjacent_transposition($token, $expr)) {
                    continue;
                }
                // Para distancia 2 exigimos anclas externas iguales para evitar
                // convertir una palabra valida en otra distinta por semejanza.
                if (2 === $distance && (substr($token, 0, 1) !== substr($expr, 0, 1) || substr($token, -1) !== substr($expr, -1))) {
                    continue;
                }
                if (!isset($best[$index]) || $distance < $best[$index]['distance']) {
                    $best[$index] = array('value' => $expr, 'distance' => $distance, 'from' => $token);
                }
            }
        }

        foreach ($best as $index => $candidate) {
            $tokens[$index] = $candidate['value'];
            $trace[] = 'typo:' . $candidate['from'] . '->' . $candidate['value'];
        }
        return self::normalize(implode(' ', $tokens));
    }

    private static function known_lexicon_expression($token) {
        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            return false;
        }
        $row = SEO_Dependiente_Interprete_DB::find_target_for_expression($token);
        return !empty($row);
    }

    private static function is_adjacent_transposition($left, $right) {
        $left = self::normalize($left);
        $right = self::normalize($right);
        if (strlen($left) !== strlen($right) || $left === $right) {
            return false;
        }
        $length = strlen($left);
        for ($i = 0; $i < $length - 1; $i++) {
            if ($left[$i] === $right[$i + 1] && $left[$i + 1] === $right[$i]) {
                $swapped = $left;
                $swapped[$i] = $left[$i + 1];
                $swapped[$i + 1] = $left[$i];
                if ($swapped === $right) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Distancia conservadora con soporte para una transposicion adyacente. */
    private static function typo_distance($left, $right) {
        $left = self::normalize($left);
        $right = self::normalize($right);
        if ($left === $right) {
            return 0;
        }
        if (strlen($left) === strlen($right)) {
            $length = strlen($left);
            for ($i = 0; $i < $length - 1; $i++) {
                if ($left[$i] === $right[$i + 1] && $left[$i + 1] === $right[$i]) {
                    $swapped = $left;
                    $swapped[$i] = $left[$i + 1];
                    $swapped[$i + 1] = $left[$i];
                    if ($swapped === $right) {
                        return 1;
                    }
                }
            }
        }
        return levenshtein($left, $right);
    }

    /**
     * Filtro linguistico base. No resuelve sinonimos ni familias semanticas.
     */
    private static function analyze_language($normalized) {
        $normalized = self::normalize($normalized);
        $base = array(
            'enabled'        => true,
            'semantic_query' => $normalized,
            'content_tokens' => array(),
            'removed_tokens' => array(),
            'verbs'          => array(),
            'tokens'         => array(),
            'typo_corrected' => false,
        );
        if ('' === $normalized) {
            return $base;
        }

        $grammar = self::grammar_settings();

        // Solo ruido seguro. Deliberadamente NO quitamos: no, sin, con, entre,
        // sobre, bajo; pueden cambiar el significado comercial de la consulta.
        $stopwords = array(
            'el','la','los','las','un','una','unos','unas','de','del','a','al','en','por','para',
            'y','o','que','me','te','se','mi','tu','su','mis','tus','sus','este','esta','estos','estas',
            'ese','esa','esos','esas','lo','le','les','nos','os','yo','usted','ustedes','ellos','ellas'
        );
        $noise = array('algo','cosa','cosas','aparato','aparatos');
        // En modo mínimo no importamos stopwords ni stop-verbs aprendidos:
        // queremos que la limpieza sea estable y totalmente predecible.
        $stopword_map = array_fill_keys($stopwords, true);
        $noise_map = array_fill_keys($noise, true);

        // Verbos funcionales que expresan peticion, no la tarea del producto.
        $stop_verbs = array_fill_keys(array(
            'querer','necesitar','buscar','poder','haber','ir','hacer','deber','gustar','preferir','encontrar','tener'
        ), true);

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
        $input_tokens = array_values(array_filter(explode(' ', $normalized)));
        foreach ($input_tokens as $index => $token) {
            $lemma = '';
            $source = '';
            if (isset($forms[$token]) && is_string($forms[$token]) && '' !== $forms[$token]) {
                $learned_lemma = self::normalize($forms[$token]);
                // La memoria de Lingüista puede contener relaciones semánticas históricas.
                // En modo mínimo solo aceptamos una forma aprendida si es morfología
                // pura: el destino debe ser un infinitivo y nunca se sustituye un
                // infinitivo escrito por el cliente por otro concepto distinto.
                if (self::safe_learned_lemma($token, $learned_lemma)) {
                    $lemma = $learned_lemma;
                    $source = 'learned_morphology';
                }
            }
            if ('' === $lemma && isset($irregular[$token])) {
                $lemma = $irregular[$token];
                $source = 'irregular_dictionary';
            } else {
                $lemma = self::base_verb_lemma($token);
                $source = '' !== $lemma ? 'base_morphology' : '';
            }

            // Algunas formas verbales coinciden con nombres de producto
            // (p. ej. "taladro"). Si el contexto es nominal, no las tocamos.
            if ('' !== $lemma && self::looks_like_noun_usage($input_tokens, $index, $token, $lemma)) {
                $lemma = '';
                $source = '';
            }

            if ('' !== $lemma) {
                $base['verbs'][] = array('surface' => $token, 'lemma' => $lemma, 'source' => $source);
            }

            $remove = isset($stopword_map[$token]) || isset($noise_map[$token]) || ('' !== $lemma && isset($stop_verbs[$lemma]));
            $base['tokens'][] = array(
                'token'   => $token,
                'lemma'   => $lemma,
                'removed' => $remove ? 1 : 0,
            );
            if ($remove) {
                $base['removed_tokens'][] = $token;
                continue;
            }

            // Si es verbo de tarea, enviamos infinitivo; si no, conservamos token.
            $semantic[] = '' !== $lemma ? $lemma : $token;
        }

        $base['content_tokens'] = array_values(array_filter($semantic));
        $base['semantic_query'] = implode(' ', $base['content_tokens']);
        return $base;
    }

    /**
     * Traduce el texto filtrado al idioma semantico que conoce Dependiente.
     * Solo usa seo_interprete_lexicon: no consulta productos, facetas ni resultados.
     */
    private static function classify_with_linguista($corrected_query, $filtered_query) {
        $out = array(
            'actions'         => array(),
            'semantic_groups' => array(
                'rol' => array(), 'tipo' => array(), 'aplicacion' => array(),
                'plataforma' => array(), 'subtipo' => array(), 'category' => array(), 'tag' => array(),
            ),
            'related_concepts'=> array(),
            'context'         => array(),
            'assist_terms'    => array(),
            'matches'         => array(),
        );
        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            $out['context'] = array_values(array_filter(explode(' ', self::normalize($filtered_query))));
            return $out;
        }

        $corrected_query = self::normalize($corrected_query);
        $filtered_query = self::normalize($filtered_query);
        $rows = array_merge(
            SEO_Dependiente_Interprete_DB::matching_rows($corrected_query),
            SEO_Dependiente_Interprete_DB::matching_rows($filtered_query)
        );
        $rows_by_id = array();
        foreach ($rows as $row) {
            $row_id = absint($row['id'] ?? 0);
            $signature = $row_id ? 'id:' . $row_id : md5(wp_json_encode($row));
            if (!isset($rows_by_id[$signature])) {
                $rows_by_id[$signature] = $row;
            }
        }
        $rows = array_values($rows_by_id);
        $content_tokens = array_values(array_filter(explode(' ', $filtered_query)));
        $verb_map = array();
        foreach (self::analyze_language($corrected_query)['verbs'] ?? array() as $verb) {
            $surface = self::normalize((string) ($verb['surface'] ?? ''));
            $lemma = self::normalize((string) ($verb['lemma'] ?? ''));
            if ($surface && $lemma) {
                $verb_map[$surface] = $lemma;
                $verb_map[$lemma] = $lemma;
            }
        }

        $matched_tokens = array();
        foreach ((array) $rows as $row) {
            $expression = self::normalize((string) ($row['normalized_expression'] ?? $row['expression'] ?? ''));
            if (!$expression) {
                continue;
            }
            $relation = sanitize_key((string) ($row['relation_type'] ?? ''));
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            $canonical = self::normalize((string) ($row['canonical_term'] ?? ''));
            $target = self::normalize((string) ($row['target_search'] ?? $canonical));
            $confidence = (float) ($row['confidence'] ?? 0);
            $vocabulary_id = absint($row['vocabulary_id'] ?? 0);

            $match_view = array(
                'expression' => $expression,
                'relation' => $relation,
                'canonical' => $canonical,
                'target' => $target,
                'group' => $group,
                'vocabulary_id' => $vocabulary_id,
                'confidence' => $confidence,
            );
            $out['matches'][] = $match_view;

            foreach (explode(' ', $expression) as $part) {
                if (in_array($part, $content_tokens, true)) {
                    $matched_tokens[$part] = true;
                }
            }

            if (in_array($relation, array('verb_to_tool','phrase_to_tool'), true)) {
                $surface_action = '';
                foreach (explode(' ', $expression) as $part) {
                    if (isset($verb_map[$part])) {
                        $surface_action = $verb_map[$part];
                        break;
                    }
                }
                if (!$surface_action && preg_match('/(?:ar|er|ir)$/', $expression)) {
                    $surface_action = $expression;
                }
                $canonical_action = self::canonical_action_for_concept($canonical ?: $target);
                if (!$canonical_action) {
                    $canonical_action = $surface_action ?: $expression;
                }
                $out['actions'][] = array(
                    'surface' => $expression,
                    'lemma' => $surface_action ?: $expression,
                    'canonical_action' => $canonical_action,
                    'related_concept' => $target ?: $canonical,
                    'confidence' => $confidence,
                    'source' => $relation,
                );
                if ($canonical_action) {
                    $out['assist_terms'][] = $canonical_action;
                }
                if ($target) {
                    $out['related_concepts'][] = array(
                        'term' => $target,
                        'group' => $group,
                        'vocabulary_id' => $vocabulary_id,
                        'confidence' => $confidence,
                        'source' => $relation,
                    );
                    $out['assist_terms'][] = $target;
                }
            }

            if (array_key_exists($group, $out['semantic_groups']) && $canonical) {
                $out['semantic_groups'][$group][] = array(
                    'term' => $canonical,
                    'target' => $target ?: $canonical,
                    'vocabulary_id' => $vocabulary_id,
                    'confidence' => $confidence,
                    'source' => $relation,
                );
                $out['assist_terms'][] = $target ?: $canonical;
            }
        }

        // Todo token que no tenga una clasificacion segura sigue viajando como
        // contexto. No se inventa categoria/tipo/rol para palabras como "pared".
        foreach ($content_tokens as $token) {
            if (isset($verb_map[$token])) {
                $has_action = false;
                foreach ($out['actions'] as $action) {
                    if ($action['lemma'] === $verb_map[$token] || $action['surface'] === $token) {
                        $has_action = true;
                        break;
                    }
                }
                if (!$has_action) {
                    $out['actions'][] = array(
                        'surface' => $token,
                        'lemma' => $verb_map[$token],
                        'canonical_action' => $verb_map[$token],
                        'related_concept' => '',
                        'confidence' => 1.0,
                        'source' => 'morphology',
                    );
                }
                continue;
            }
            if (!isset($matched_tokens[$token])) {
                $out['context'][] = $token;
            }
        }

        $out['actions'] = self::unique_structured_rows($out['actions'], array('canonical_action','related_concept','surface'));
        $out['related_concepts'] = self::unique_structured_rows($out['related_concepts'], array('term','group'));
        foreach ($out['semantic_groups'] as $group => $items) {
            $out['semantic_groups'][$group] = self::unique_structured_rows($items, array('term','target'));
        }
        $out['context'] = array_values(array_unique(array_filter($out['context'])));

        // La memoria puede contener muchas relaciones válidas. Para no convertir
        // Intérprete en un buscador, solo enviamos a Dependiente la mejor acción,
        // su concepto relacionado y como máximo un término por grupo semántico.
        $assist = array();
        if (!empty($out['actions'][0])) {
            $assist[] = self::normalize((string) ($out['actions'][0]['canonical_action'] ?? ''));
            $assist[] = self::normalize((string) ($out['actions'][0]['related_concept'] ?? ''));
        }
        foreach (array('rol','tipo','aplicacion','plataforma','subtipo','category','tag') as $group) {
            if (!empty($out['semantic_groups'][$group][0])) {
                $assist[] = self::normalize((string) ($out['semantic_groups'][$group][0]['target'] ?? $out['semantic_groups'][$group][0]['term'] ?? ''));
            }
        }
        $out['assist_terms'] = array_slice(array_values(array_unique(array_filter($assist))), 0, 8);
        $out['matches'] = array_slice($out['matches'], 0, 20);
        return $out;
    }

    /**
     * Para una relacion accion -> concepto, busca el infinitivo canonico mejor
     * respaldado que apunte al mismo concepto (p.ej. agujerear -> taladro -> taladrar).
     */
    private static function canonical_action_for_concept($concept) {
        $concept = self::normalize($concept);
        if (!$concept || !class_exists('SEO_Dependiente_Interprete_DB')) {
            return '';
        }
        $candidates = array();
        foreach ((array) SEO_Dependiente_Interprete_DB::active_rows() as $row) {
            $relation = sanitize_key((string) ($row['relation_type'] ?? ''));
            if (!in_array($relation, array('verb_to_tool','phrase_to_tool'), true)) {
                continue;
            }
            $canonical = self::normalize((string) ($row['canonical_term'] ?? ''));
            $target = self::normalize((string) ($row['target_search'] ?? ''));
            if ($concept !== $canonical && $concept !== $target) {
                continue;
            }
            $expression = self::normalize((string) ($row['normalized_expression'] ?? $row['expression'] ?? ''));
            if (!preg_match('/^(?:[a-z0-9]+)(?:ar|er|ir)$/', $expression)) {
                continue;
            }
            $candidates[] = array(
                'verb' => $expression,
                'priority' => absint($row['priority'] ?? 5),
                'confidence' => (float) ($row['confidence'] ?? 0),
            );
        }
        if (!$candidates) {
            return '';
        }
        usort($candidates, static function ($a, $b) {
            $priority = $a['priority'] <=> $b['priority'];
            if (0 !== $priority) {
                return $priority;
            }
            $confidence = $b['confidence'] <=> $a['confidence'];
            if (0 !== $confidence) {
                return $confidence;
            }
            return strlen($a['verb']) <=> strlen($b['verb']);
        });
        return (string) ($candidates[0]['verb'] ?? '');
    }

    private static function unique_structured_rows($rows, $keys) {
        $out = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            $parts = array();
            foreach ((array) $keys as $key) {
                $parts[] = self::normalize((string) ($row[$key] ?? ''));
            }
            $signature = implode('|', $parts);
            if ('' === trim(str_replace('|', '', $signature)) || isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Acepta memoria aprendida solo cuando representa morfología, no semántica.
     * Ejemplos válidos: taladramos -> taladrar. Ejemplos rechazados:
     * agujerear -> taladro o agujerear -> perforar.
     */
    private static function safe_learned_lemma($surface, $lemma) {
        $surface = self::normalize($surface);
        $lemma = self::normalize($lemma);
        if ('' === $surface || '' === $lemma || false !== strpos($lemma, ' ')) {
            return false;
        }
        if (!preg_match('/(?:ar|er|ir)$/', $lemma)) {
            return false;
        }
        // Si el cliente ya escribió un infinitivo, se conserva exactamente ese
        // verbo. Intérprete mínimo no convierte sinónimos ni familias semánticas.
        if (preg_match('/(?:ar|er|ir)$/', $surface)) {
            return $surface === $lemma;
        }
        // Para formas conjugadas exigimos afinidad léxica básica. Las formas
        // irregulares verdaderas se resuelven mediante el diccionario gramatical.
        $stem = substr($lemma, 0, -2);
        $prefix = substr($stem, 0, min(3, strlen($stem)));
        return '' !== $prefix && 0 === strpos($surface, $prefix);
    }

    /** Devuelve true si el token ya parece una forma verbal valida. */
    private static function known_verb_form($token) {
        $token = self::normalize($token);
        if ('' === $token) {
            return false;
        }
        $morphology = self::morphology_settings();
        $forms = isset($morphology['forms']) && is_array($morphology['forms']) ? $morphology['forms'] : array();
        if (isset($forms[$token]) && is_string($forms[$token]) && '' !== $forms[$token]) {
            return true;
        }
        return '' !== self::base_verb_lemma($token);
    }

    /**
     * Evita convertir nombres de producto en verbos por una coincidencia
     * morfologica. Es deliberadamente conservador: si hay articulo/determinante
     * delante, o una forma en -o aparece sin sujeto explicito, preservamos el
     * termino tal como lo escribio el cliente.
     */
    private static function looks_like_noun_usage($tokens, $index, $token, $lemma) {
        $tokens = array_values((array) $tokens);
        $previous = $index > 0 ? self::normalize($tokens[$index - 1]) : '';
        $determiners = array('el','la','los','las','un','una','unos','unas','este','esta','ese','esa','mi','tu','su');
        if (in_array($previous, $determiners, true)) {
            return true;
        }

        $lemma = self::normalize($lemma);
        $token = self::normalize($token);
        if (in_array($lemma, array('querer','necesitar','buscar','poder','haber','ir','hacer','deber','gustar','preferir','encontrar','tener'), true)) {
            return false;
        }
        if (strlen($lemma) > 2 && in_array(substr($lemma, -2), array('ar','er','ir'), true)) {
            $stem = substr($lemma, 0, -2);
            // Primera persona presente: taladro, corto, lijo... puede ser nombre.
            if ($token === $stem . 'o') {
                return 'yo' !== $previous;
            }
        }
        return false;
    }

    /** Morfologia minima disponible incluso sin ninguna leccion de Linguista. */
    private static function base_verb_lemma($token) {
        $token = self::normalize($token);
        if ('' === $token || false !== strpos($token, ' ')) {
            return '';
        }

        $irregular = array(
            'he'=>'haber','has'=>'haber','ha'=>'haber','hemos'=>'haber','habeis'=>'haber','han'=>'haber',
            'habia'=>'haber','habias'=>'haber','habiamos'=>'haber','habian'=>'haber','hubo'=>'haber','hubieron'=>'haber',
            'voy'=>'ir','vas'=>'ir','va'=>'ir','vamos'=>'ir','vais'=>'ir','van'=>'ir','iba'=>'ir','ibas'=>'ir','ibamos'=>'ir','iban'=>'ir',
            'hago'=>'hacer','haces'=>'hacer','hace'=>'hacer','hacemos'=>'hacer','hacen'=>'hacer','hice'=>'hacer','hizo'=>'hacer','hicimos'=>'hacer','hicieron'=>'hacer','hecho'=>'hacer','haciendo'=>'hacer',
            'tengo'=>'tener','tienes'=>'tener','tiene'=>'tener','tenemos'=>'tener','teneis'=>'tener','tienen'=>'tener','tenia'=>'tener','tenias'=>'tener','teniamos'=>'tener','tenian'=>'tener','tuve'=>'tener','tuvo'=>'tener','tuvimos'=>'tener','tuvieron'=>'tener',
            'quiero'=>'querer','quiere'=>'querer','queremos'=>'querer','quisiera'=>'querer','quisieramos'=>'querer',
            'necesito'=>'necesitar','necesita'=>'necesitar','necesitamos'=>'necesitar','necesitan'=>'necesitar',
            'busco'=>'buscar','busca'=>'buscar','buscamos'=>'buscar','buscan'=>'buscar',
            'puedo'=>'poder','puede'=>'poder','podemos'=>'poder','pueden'=>'poder','podria'=>'poder','podriamos'=>'poder',
            'rompo'=>'romper','rompes'=>'romper','rompe'=>'romper','rompen'=>'romper','roto'=>'romper',
            'abro'=>'abrir','abres'=>'abrir','abre'=>'abrir','abren'=>'abrir','abierto'=>'abrir'
        );
        if (isset($irregular[$token])) {
            return $irregular[$token];
        }

        $verbs = array(
            'taladrar','perforar','agujerear','lijar','cortar','pulir','soldar','atornillar','fresar',
            'montar','instalar','reparar','arreglar','pintar','inflar','sacar','comprar','usar','cambiar',
            'limpiar','mover','elevar','fijar','aspirar','cepillar','romper','abrir','demoler','extraer',
            'querer','necesitar','buscar','poder','deber','preferir','encontrar','hacer','tener'
        );
        foreach ($verbs as $verb) {
            if ($token === $verb) {
                return $verb;
            }
            $ending = substr($verb, -2);
            $stem = substr($verb, 0, -2);
            $forms = array();
            if ('ar' === $ending) {
                $forms = array(
                    $stem.'o',$stem.'as',$stem.'a',$stem.'amos',$stem.'ais',$stem.'an',
                    $stem.'e',$stem.'aste',$stem.'amos',$stem.'asteis',$stem.'aron',
                    $stem.'aba',$stem.'abas',$stem.'abamos',$stem.'abais',$stem.'aban',
                    $verb.'e',$verb.'as',$verb.'a',$verb.'emos',$verb.'eis',$verb.'an',
                    $verb.'ia',$verb.'ias',$verb.'iamos',$verb.'iais',$verb.'ian',
                    $stem.'ando',$stem.'ado'
                );
            } elseif ('er' === $ending) {
                $forms = array(
                    $stem.'o',$stem.'es',$stem.'e',$stem.'emos',$stem.'eis',$stem.'en',
                    $stem.'i',$stem.'iste',$stem.'io',$stem.'imos',$stem.'isteis',$stem.'ieron',
                    $stem.'ia',$stem.'ias',$stem.'iamos',$stem.'iais',$stem.'ian',
                    $verb.'e',$verb.'as',$verb.'a',$verb.'emos',$verb.'eis',$verb.'an',
                    $verb.'ia',$verb.'ias',$verb.'iamos',$verb.'iais',$verb.'ian',
                    $stem.'iendo',$stem.'ido'
                );
            } elseif ('ir' === $ending) {
                $forms = array(
                    $stem.'o',$stem.'es',$stem.'e',$stem.'imos',$stem.'is',$stem.'en',
                    $stem.'i',$stem.'iste',$stem.'io',$stem.'imos',$stem.'isteis',$stem.'ieron',
                    $stem.'ia',$stem.'ias',$stem.'iamos',$stem.'iais',$stem.'ian',
                    $verb.'e',$verb.'as',$verb.'a',$verb.'emos',$verb.'eis',$verb.'an',
                    $verb.'ia',$verb.'ias',$verb.'iamos',$verb.'iais',$verb.'ian',
                    $stem.'iendo',$stem.'ido'
                );
            }
            if (in_array($token, $forms, true)) {
                return $verb;
            }
        }
        return '';
    }

    private static function dedupe_query($query) {
        $out = array();
        $seen = array();
        foreach (array_values(array_filter(explode(' ', self::normalize($query)))) as $token) {
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $out[] = $token;
        }
        return implode(' ', $out);
    }

    private static function clean_query($value) {
        $value = sanitize_text_field((string) $value);
        $value = preg_replace('/\\s+/u', ' ', trim($value));
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
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\\s+/u', ' ', $value));
    }
}
