<?php
/**
 * SEO System - Gestor de Landing Pages.
 *
 * Inventaria landings existentes, mantiene candidatas, muestra huecos de
 * cobertura y registra visitas. Las fuentes externas (Search Console, Trends,
 * analitica, margen, conversion, etc.) se integran mediante filtros para no
 * acoplar este modulo a tablas que pueden variar entre instalaciones.
 */

defined('ABSPATH') || exit;

$seo_landing_google_adapter = __DIR__ . '/seo-landing-google-signals.php';
if (is_readable($seo_landing_google_adapter)) {
    require_once $seo_landing_google_adapter;
}

if (!defined('SEO_LANDING_SCHEMA_VERSION')) {
    define('SEO_LANDING_SCHEMA_VERSION', 1);
}

/**
 * Nombre de tabla de candidatas.
 */
function seo_landing_candidates_table()
{
    global $wpdb;
    return $wpdb->prefix . 'seo_landing_candidates';
}

/**
 * Nombre de tabla de visitas diarias.
 */
function seo_landing_stats_table()
{
    global $wpdb;
    return $wpdb->prefix . 'seo_landing_stats';
}

/**
 * Crea/actualiza las tablas del modulo.
 */
function seo_landing_maybe_install()
{
    $installed = (int) get_option('seo_landing_schema_version', 0);
    if ($installed >= SEO_LANDING_SCHEMA_VERSION) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $candidates = seo_landing_candidates_table();
    $stats = seo_landing_stats_table();

    dbDelta("CREATE TABLE {$candidates} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        intent text NULL,
        landing_type varchar(30) NOT NULL DEFAULT '',
        status varchar(30) NOT NULL DEFAULT 'candidate',
        source varchar(80) NOT NULL DEFAULT 'manual',
        requirements_json longtext NULL,
        scores_json longtext NULL,
        total_score decimal(6,2) NOT NULL DEFAULT 0,
        existing_destination text NULL,
        differentiation_reason text NULL,
        page_id bigint(20) unsigned NOT NULL DEFAULT 0,
        external_key varchar(191) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY landing_type (landing_type),
        KEY total_score (total_score),
        KEY page_id (page_id),
        KEY external_key (external_key)
    ) {$charset};");

    dbDelta("CREATE TABLE {$stats} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        page_id bigint(20) unsigned NOT NULL,
        stat_date date NOT NULL,
        views bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY page_date (page_id, stat_date),
        KEY stat_date (stat_date),
        KEY views (views)
    ) {$charset};");

    update_option('seo_landing_schema_version', SEO_LANDING_SCHEMA_VERSION, false);
}
add_action('admin_init', 'seo_landing_maybe_install', 5);

/**
 * Tipos editoriales admitidos.
 */
function seo_landing_types()
{
    return array(
        'profession'  => 'Profesion',
        'application' => 'Aplicacion / trabajo',
        'need'        => 'Necesidad / solucion',
        'choice'      => 'Eleccion / comparacion',
        'campaign'    => 'Campana',
    );
}

/**
 * Estados del inventario.
 */
function seo_landing_statuses()
{
    return array(
        'detected'              => 'Detectada',
        'rejected_requirements' => 'Rechazada por requisitos',
        'candidate'             => 'Candidata',
        'review'                => 'En revision',
        'approved'              => 'Aprobada',
        'created'               => 'Creada',
        'published'             => 'Publicada',
        'paused'                => 'Pausada',
    );
}

/**
 * Requisitos obligatorios. Todos deben cumplirse para aprobar una candidata.
 */
function seo_landing_requirement_labels()
{
    return array(
        'differentiated_intent' => 'Intencion diferenciada',
        'unites_catalog'        => 'Une catalogo separado',
        'enough_coverage'       => 'Cobertura suficiente',
        'purchase_utility'      => 'Utilidad real para comprar',
        'stable_destination'    => 'Destino estable',
    );
}

/**
 * Pesos de scoring. Total: 100.
 */
function seo_landing_score_weights()
{
    return array(
        'commercial' => array('label' => 'Potencial comercial', 'max' => 30),
        'organic'    => array('label' => 'Oportunidad organica', 'max' => 25),
        'demand'     => array('label' => 'Demanda externa', 'max' => 15),
        'catalog'    => array('label' => 'Cobertura de catalogo', 'max' => 15),
        'visibility' => array('label' => 'Distribucion de visibilidad', 'max' => 10),
        'editorial'  => array('label' => 'Capacidad editorial', 'max' => 5),
    );
}

function seo_landing_decode_json($value)
{
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : array();
}

/** Normaliza un requisito: 1=cumple, 0=no cumple, -1=pendiente. */
function seo_landing_requirement_value($value)
{
    if (true === $value || 1 === $value || '1' === $value) {
        return 1;
    }
    if (false === $value || 0 === $value || '0' === $value) {
        return 0;
    }
    return -1;
}

/** Estado conjunto: pass, fail o pending. */
function seo_landing_requirements_state($requirements)
{
    $pending = false;
    foreach (seo_landing_requirement_labels() as $key => $label) {
        $value = seo_landing_requirement_value($requirements[$key] ?? -1);
        if (0 === $value) {
            return 'fail';
        }
        if (-1 === $value) {
            $pending = true;
        }
    }
    return $pending ? 'pending' : 'pass';
}

function seo_landing_requirements_pass($requirements)
{
    return 'pass' === seo_landing_requirements_state($requirements);
}

/** Resumen legible para el panel. */
function seo_landing_requirements_summary($requirements)
{
    $ok = 0;
    $no = 0;
    $pending = 0;
    foreach (seo_landing_requirement_labels() as $key => $label) {
        $value = seo_landing_requirement_value($requirements[$key] ?? -1);
        if (1 === $value) {
            $ok++;
        } elseif (0 === $value) {
            $no++;
        } else {
            $pending++;
        }
    }
    if (5 === $ok) {
        return '5/5 OK';
    }
    $parts = [];
    if ($ok > 0) {
        $parts[] = $ok . '/5 OK';
    }
    if ($pending > 0) {
        $parts[] = $pending . ' pendiente' . (1 === $pending ? '' : 's');
    }
    if ($no > 0) {
        $parts[] = $no . ' NO';
    }
    return implode(' · ', $parts);
}

function seo_landing_sanitize_scores($scores)
{
    $scores = is_array($scores) ? $scores : array();
    $clean = array();
    foreach (seo_landing_score_weights() as $key => $config) {
        $value = isset($scores[$key]) && is_numeric($scores[$key]) ? (float) $scores[$key] : 0;
        $clean[$key] = max(0, min((float) $config['max'], $value));
    }
    return $clean;
}

function seo_landing_score_total($scores)
{
    return round(array_sum(seo_landing_sanitize_scores($scores)), 2);
}

/**
 * Devuelve las paginas que realmente estan marcadas como landing.
 */
function seo_landing_get_existing()
{
    global $wpdb;
    $nodes = $wpdb->prefix . 'seo_nodes';
    $stats = seo_landing_stats_table();

    $has_stats = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats)) === $stats);
    $stats_join = '';
    $stats_select = '0 AS views_30d, 0 AS views_total';

    if ($has_stats) {
        $stats_join = "LEFT JOIN (
            SELECT page_id,
                   SUM(views) AS views_total,
                   SUM(CASE WHEN stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN views ELSE 0 END) AS views_30d
            FROM {$stats}
            GROUP BY page_id
        ) s ON s.page_id = p.ID";
        $stats_select = 'COALESCE(s.views_30d,0) AS views_30d, COALESCE(s.views_total,0) AS views_total';
    }

    $rows = $wpdb->get_results(
        "SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_date, p.post_modified,
                {$stats_select}
         FROM {$wpdb->posts} p
         INNER JOIN {$nodes} n
             ON n.object_id = p.ID
            AND n.object_type = 'page'
            AND n.seo_role = 'landing'
            AND n.status = 1
         {$stats_join}
         WHERE p.post_type = 'page'
           AND p.post_status NOT IN ('trash','auto-draft')
         ORDER BY p.post_status = 'publish' DESC, views_30d DESC, p.post_modified DESC"
    );

    return is_array($rows) ? $rows : array();
}

/**
 * KPIs principales.
 */
function seo_landing_get_kpis()
{
    global $wpdb;
    $existing = seo_landing_get_existing();
    $candidates = seo_landing_candidates_table();

    $published = 0;
    $views_30d = 0;
    $views_total = 0;
    foreach ($existing as $landing) {
        if ('publish' === $landing->post_status) {
            $published++;
        }
        $views_30d += (int) $landing->views_30d;
        $views_total += (int) $landing->views_total;
    }

    $candidate_count = 0;
    $approved_count = 0;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $candidates)) === $candidates) {
        $candidate_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$candidates} WHERE status IN ('detected','candidate','review')");
        $approved_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$candidates} WHERE status = 'approved'");
    }

    return array(
        'existing'   => count($existing),
        'published'  => $published,
        'candidates' => $candidate_count,
        'approved'   => $approved_count,
        'views_30d'  => $views_30d,
        'views_total'=> $views_total,
    );
}

/**
 * Huecos de cobertura: hubs secundarios con catalogo real y sin relacion
 * explicita desde una landing. Es una senal de revision, no una recomendacion
 * automatica de crear una landing.
 */
function seo_landing_get_coverage_gaps($limit = 40)
{
    global $wpdb;
    $nodes = $wpdb->prefix . 'seo_nodes';
    $relations = $wpdb->prefix . 'seo_relations';
    $limit = max(1, min(200, absint($limit)));

    $sql = "
        SELECT hs.ID, hs.post_title,
               COUNT(DISTINCT hc.target_id) AS category_count,
               COALESCE(SUM(tt.count),0) AS product_count,
               COUNT(DISTINCT lr.source_id) AS landing_count
        FROM {$wpdb->posts} hs
        INNER JOIN {$nodes} n
            ON n.object_type = 'page'
           AND n.object_id = hs.ID
           AND n.seo_role = 'hub_secondary'
           AND n.status = 1
        LEFT JOIN {$relations} hc
            ON hc.source_type = 'hub_secondary'
           AND hc.source_id = hs.ID
           AND hc.target_type = 'product_cat'
           AND hc.relation_type = 'hub_secondary_to_category'
        LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.taxonomy = 'product_cat'
           AND tt.term_id = hc.target_id
        LEFT JOIN {$relations} lr
            ON lr.source_type = 'landing'
           AND lr.target_type = 'hub_secondary'
           AND lr.target_id = hs.ID
        WHERE hs.post_type = 'page'
          AND hs.post_status <> 'trash'
        GROUP BY hs.ID, hs.post_title
        HAVING category_count > 0
           AND product_count >= 8
           AND landing_count = 0
        ORDER BY product_count DESC, category_count DESC, hs.post_title ASC
        LIMIT {$limit}
    ";

    $rows = $wpdb->get_results($sql);
    return is_array($rows) ? $rows : array();
}

/**
 * Fuentes externas. Otros modulos pueden inyectar senales de GSC, Trends,
 * analitica o datos comerciales sin que este modulo conozca su esquema.
 *
 * Cada elemento puede contener:
 * title, intent, landing_type, source, external_key, requirements[], scores[].
 */
function seo_landing_get_external_signals()
{
    $signals = apply_filters('seo_landing_candidate_signals', array());
    return is_array($signals) ? $signals : array();
}

/**
 * Sincroniza senales externas en el inventario de candidatas.
 */
function seo_landing_sync_external_signals()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para sincronizar candidatas.', 'seo-system'));
    }
    check_admin_referer('seo_landing_sync_signals');

    seo_landing_maybe_install();

    // La misma acción que sincroniza candidatas refresca primero Google Trends.
    // Se reutiliza la caché de 12 h para no provocar ráfagas ni HTTP 429.
    if (function_exists('seo_google_trends_sync')) {
        seo_google_trends_sync(false, 5);
    }

    global $wpdb;
    $table = seo_landing_candidates_table();
    $signals = seo_landing_get_external_signals();
    $created = 0;
    $updated = 0;
    $deduplicated = 0;
    $preserved = 0;
    $active_auto_signals = array();

    foreach ($signals as $signal) {
        if (!is_array($signal)) {
            continue;
        }

        $title = sanitize_text_field($signal['title'] ?? '');
        $external_key = sanitize_text_field($signal['external_key'] ?? '');
        if ($title === '' || $external_key === '') {
            continue;
        }

        if (0 === strpos($external_key, 'google_landing_')) {
            $active_auto_signals[] = array('title' => $title, 'external_key' => $external_key);
        }

        $requirements = array();
        foreach (seo_landing_requirement_labels() as $key => $label) {
            $requirements[$key] = seo_landing_requirement_value($signal['requirements'][$key] ?? -1);
        }
        $scores = seo_landing_sanitize_scores($signal['scores'] ?? array());
        $requirements_state = seo_landing_requirements_state($requirements);
        // Un candidato pendiente conserva un pre-score; un requisito explicitamente fallido lo invalida.
        $total = 'fail' === $requirements_state ? 0 : seo_landing_score_total($scores);
        $status = 'pass' === $requirements_state ? 'candidate' : 'detected';

        $data = array(
            'title'                  => $title,
            'intent'                 => sanitize_textarea_field($signal['intent'] ?? ''),
            'landing_type'           => sanitize_key($signal['landing_type'] ?? ''),
            'status'                 => $status,
            'source'                 => sanitize_text_field($signal['source'] ?? 'external'),
            'requirements_json'      => wp_json_encode($requirements),
            'scores_json'            => wp_json_encode($scores),
            'total_score'            => $total,
            'existing_destination'   => sanitize_textarea_field($signal['existing_destination'] ?? ''),
            'differentiation_reason' => sanitize_textarea_field($signal['differentiation_reason'] ?? ''),
            'external_key'           => $external_key,
            'updated_at'             => current_time('mysql'),
        );

        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE external_key = %s LIMIT 1",
            $external_key
        ));
        $existing_id = $existing_row ? absint($existing_row->id) : 0;
        $existing_status = $existing_row ? sanitize_key((string) $existing_row->status) : '';

        // Compatibilidad con claves antiguas: reutiliza solo candidatas automaticas aun no revisadas.
        if ($existing_id <= 0 && 0 === strpos($external_key, 'google_landing_') && function_exists('seo_landing_google_similarity')) {
            $possible = (array) $wpdb->get_results(
                "SELECT id, title, status FROM {$table}
                 WHERE external_key LIKE 'google_landing_%'
                   AND status IN ('detected','candidate')
                 ORDER BY updated_at DESC
                 LIMIT 250"
            );
            $best_row = null;
            $best_similarity = 0;
            foreach ($possible as $row) {
                $similarity = seo_landing_google_similarity($title, (string) $row->title);
                if ($similarity >= 0.88 && $similarity > $best_similarity) {
                    $best_similarity = $similarity;
                    $best_row = $row;
                }
            }
            if ($best_row) {
                $existing_id = absint($best_row->id);
                $existing_status = sanitize_key((string) $best_row->status);
            }
        }

        // Una sincronizacion automatica nunca pisa una decision/revision humana ya iniciada.
        if ($existing_id > 0 && in_array($existing_status, array('review','approved','created','published','paused','rejected_requirements'), true)) {
            $preserved++;
            continue;
        }

        if ($existing_id > 0) {
            $wpdb->update($table, $data, array('id' => $existing_id));
            $updated++;
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $created++;
        }
    }

    // Elimina solo duplicados automaticos antiguos que son equivalentes a una senal activa.
    if (!empty($active_auto_signals) && function_exists('seo_landing_google_similarity')) {
        $active_keys = array_fill_keys(wp_list_pluck($active_auto_signals, 'external_key'), true);
        $old_auto_rows = (array) $wpdb->get_results(
            "SELECT id, title, external_key, source, status FROM {$table}
             WHERE external_key LIKE 'google_landing_%'
               AND status IN ('detected','candidate')"
        );
        foreach ($old_auto_rows as $old_row) {
            if (isset($active_keys[(string) $old_row->external_key])) {
                continue;
            }
            if (0 !== strpos((string) $old_row->source, 'Google Intelligence')) {
                continue;
            }
            $is_duplicate = false;
            foreach ($active_auto_signals as $active_signal) {
                if (seo_landing_google_similarity((string) $old_row->title, (string) $active_signal['title']) >= 0.84) {
                    $is_duplicate = true;
                    break;
                }
            }
            if ($is_duplicate && false !== $wpdb->delete($table, array('id' => absint($old_row->id)), array('%d'))) {
                $deduplicated++;
            }
        }
    }

    $url = add_query_arg(
        array(
            'page' => 'seo-menu-marketing',
            'tab' => 'landings',
            'landing_msg' => 'synced',
            'created' => $created,
            'updated' => $updated,
            'deduplicated' => $deduplicated,
            'preserved' => $preserved,
        ),
        admin_url('admin.php')
    );
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_seo_landing_sync_signals', 'seo_landing_sync_external_signals');

/**
 * Guarda una candidata manual o una revision de hueco.
 */
function seo_landing_handle_save_candidate()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para guardar candidatas.', 'seo-system'));
    }
    check_admin_referer('seo_landing_save_candidate');
    seo_landing_maybe_install();

    global $wpdb;
    $table = seo_landing_candidates_table();
    $id = absint($_POST['candidate_id'] ?? 0);
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    if ($title === '') {
        wp_safe_redirect(add_query_arg(array('page'=>'seo-menu-marketing','tab'=>'landings','landing_msg'=>'missing_title'), admin_url('admin.php')));
        exit;
    }

    $requirements = array();
    foreach (seo_landing_requirement_labels() as $key => $label) {
        $requirements[$key] = !empty($_POST['requirements'][$key]) ? 1 : 0;
    }

    $scores = seo_landing_sanitize_scores(isset($_POST['scores']) ? (array) wp_unslash($_POST['scores']) : array());
    $requirements_pass = seo_landing_requirements_pass($requirements);
    $status = sanitize_key(wp_unslash($_POST['status'] ?? 'candidate'));
    if (!isset(seo_landing_statuses()[$status])) {
        $status = 'candidate';
    }
    if (!$requirements_pass && in_array($status, array('approved','created','published'), true)) {
        $status = 'rejected_requirements';
    }

    $landing_type = sanitize_key(wp_unslash($_POST['landing_type'] ?? ''));
    if ($landing_type !== '' && !isset(seo_landing_types()[$landing_type])) {
        $landing_type = '';
    }

    $data = array(
        'title'                  => $title,
        'intent'                 => sanitize_textarea_field(wp_unslash($_POST['intent'] ?? '')),
        'landing_type'           => $landing_type,
        'status'                 => $status,
        'source'                 => sanitize_text_field(wp_unslash($_POST['source'] ?? 'manual')),
        'requirements_json'      => wp_json_encode($requirements),
        'scores_json'            => wp_json_encode($scores),
        'total_score'            => $requirements_pass ? seo_landing_score_total($scores) : 0,
        'existing_destination'   => sanitize_textarea_field(wp_unslash($_POST['existing_destination'] ?? '')),
        'differentiation_reason' => sanitize_textarea_field(wp_unslash($_POST['differentiation_reason'] ?? '')),
        'page_id'                => absint($_POST['page_id'] ?? 0),
        'updated_at'             => current_time('mysql'),
    );

    if ($id > 0) {
        $wpdb->update($table, $data, array('id' => $id));
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
    }

    wp_safe_redirect(add_query_arg(array('page'=>'seo-menu-marketing','tab'=>'landings','landing_msg'=>'saved','candidate_id'=>$id), admin_url('admin.php')));
    exit;
}
add_action('admin_post_seo_landing_save_candidate', 'seo_landing_handle_save_candidate');

/**
 * Devuelve un valor de meta SEO conocido cuando existe un plugin SEO activo.
 * Se mantiene como lectura tolerante: si no hay plugin, devuelve cadena vacia.
 *
 * @param int    $post_id ID de la pagina.
 * @param string $field   title o description.
 * @return string
 */
function seo_landing_export_get_seo_meta($post_id, $field)
{
    $post_id = absint($post_id);
    $field = sanitize_key($field);

    if ($post_id <= 0 || !in_array($field, array('title', 'description'), true)) {
        return '';
    }

    $keys = 'title' === $field
        ? array('_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title', '_aioseo_title')
        : array('_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc', '_aioseo_description');

    foreach ($keys as $key) {
        $value = trim((string) get_post_meta($post_id, $key, true));
        if ('' !== $value) {
            return $value;
        }
    }

    return '';
}

/**
 * Genera y descarga un CSV con el inventario SEO de Landing Pages.
 */
function seo_landing_export_seo_csv()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar el informe SEO.', 'seo-system'));
    }

    check_admin_referer('seo_landing_export_seo_csv');
    seo_landing_maybe_install();

    $landings = seo_landing_get_existing();
    $filename = 'informe-seo-landings-' . wp_date('Y-m-d') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'w');
    if (false === $output) {
        wp_die(esc_html__('No se pudo generar el archivo CSV.', 'seo-system'));
    }

    // BOM UTF-8 para que Excel abra correctamente tildes y eñes.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, array(
        'ID',
        'Landing',
        'URL',
        'Estado',
        'SEO title',
        'Meta description',
        'Palabras',
        'Contador 30d',
        'Contador total',
        'GA4 sesiones 30d',
        'GSC clics 28d',
        'GSC impresiones 28d',
        'GSC CTR 28d',
        'GSC posicion 28d',
        'Publicada',
        'Actualizada',
    ), ';');

    foreach ($landings as $landing) {
        $post_id = absint($landing->ID);
        $external_metrics = function_exists('seo_landing_google_metrics_for_page')
            ? (array) seo_landing_google_metrics_for_page($post_id)
            : array('ga4' => array(), 'gsc' => array());

        $ga4 = isset($external_metrics['ga4']) && is_array($external_metrics['ga4']) ? $external_metrics['ga4'] : array();
        $gsc = isset($external_metrics['gsc']) && is_array($external_metrics['gsc']) ? $external_metrics['gsc'] : array();

        $seo_title = seo_landing_export_get_seo_meta($post_id, 'title');
        if ('' === $seo_title) {
            $seo_title = get_the_title($post_id);
        }

        $meta_description = seo_landing_export_get_seo_meta($post_id, 'description');
        if ('' === $meta_description) {
            $meta_description = trim((string) get_post_field('post_excerpt', $post_id));
        }
        if ('' === $meta_description) {
            $meta_description = wp_trim_words(
                wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $post_id))),
                30,
                ''
            );
        }

        $plain_content = trim(wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $post_id))));
        $word_count = '' === $plain_content ? 0 : str_word_count($plain_content);

        $clicks = (int) ($gsc['clicks'] ?? 0);
        $impressions = (int) ($gsc['impressions'] ?? 0);
        $ctr = isset($gsc['ctr']) ? (float) $gsc['ctr'] : ($impressions > 0 ? ($clicks / $impressions) * 100 : 0);

        fputcsv($output, array(
            $post_id,
            get_the_title($post_id),
            get_permalink($post_id),
            (string) $landing->post_status,
            $seo_title,
            $meta_description,
            $word_count,
            (int) $landing->views_30d,
            (int) $landing->views_total,
            (int) ($ga4['sessions'] ?? 0),
            $clicks,
            $impressions,
            number_format($ctr, 2, '.', ''),
            number_format((float) ($gsc['position'] ?? 0), 2, '.', ''),
            mysql2date('Y-m-d H:i:s', (string) $landing->post_date),
            mysql2date('Y-m-d H:i:s', (string) $landing->post_modified),
        ), ';');
    }

    fclose($output);
    exit;
}
add_action('admin_post_seo_landing_export_seo_csv', 'seo_landing_export_seo_csv');

/**
 * Registra una visita de una landing publicada. Solo contabiliza front-end y
 * excluye administradores conectados para reducir ruido operativo.
 */
function seo_landing_track_view()
{
    if (is_admin() || is_preview() || is_feed() || wp_doing_ajax()) {
        return;
    }
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return;
    }
    if (!is_singular('page')) {
        return;
    }

    $page_id = get_queried_object_id();
    if ($page_id <= 0) {
        return;
    }

    global $wpdb;
    $nodes = $wpdb->prefix . 'seo_nodes';
    $is_landing = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$nodes}
         WHERE object_type = 'page' AND object_id = %d AND seo_role = 'landing' AND status = 1
         LIMIT 1",
        $page_id
    ));
    if (!$is_landing) {
        return;
    }

    seo_landing_maybe_install();
    $stats = seo_landing_stats_table();
    $date = current_time('Y-m-d');
    $now = current_time('mysql');

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$stats} (page_id, stat_date, views, updated_at)
         VALUES (%d, %s, 1, %s)
         ON DUPLICATE KEY UPDATE views = views + 1, updated_at = VALUES(updated_at)",
        $page_id,
        $date,
        $now
    ));
}
add_action('template_redirect', 'seo_landing_track_view', 40);

/**
 * Mini serie de 30 dias para el grafico del panel.
 */
function seo_landing_get_views_series($days = 30)
{
    global $wpdb;
    $stats = seo_landing_stats_table();
    $days = max(7, min(90, absint($days)));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT stat_date, SUM(views) AS views
         FROM {$stats}
         WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
         GROUP BY stat_date
         ORDER BY stat_date ASC",
        $days - 1
    ));

    $map = array();
    foreach ((array) $rows as $row) {
        $map[(string) $row->stat_date] = (int) $row->views;
    }

    $series = array();
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = wp_date('Y-m-d', strtotime('-' . $i . ' days', current_time('timestamp')));
        $series[] = array('date' => $date, 'views' => $map[$date] ?? 0);
    }
    return $series;
}

function seo_landing_get_candidates($limit = 100)
{
    global $wpdb;
    $table = seo_landing_candidates_table();
    $limit = max(1, min(500, absint($limit)));
    return (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY FIELD(status,'approved','review','candidate','detected','created','published','paused','rejected_requirements'), total_score DESC, updated_at DESC LIMIT {$limit}");
}

function seo_landing_render_notice()
{
    $msg = sanitize_key(wp_unslash($_GET['landing_msg'] ?? ''));
    if ($msg === '') {
        return;
    }
    $messages = array(
        'saved'         => array('success', 'Candidata guardada.'),
        'missing_title' => array('error', 'La candidata necesita un titulo de trabajo.'),
        'synced'        => array('success', sprintf(
            'Senales sincronizadas: %d nuevas, %d actualizadas, %d duplicadas retiradas y %d decisiones manuales preservadas.',
            absint($_GET['created'] ?? 0),
            absint($_GET['updated'] ?? 0),
            absint($_GET['deduplicated'] ?? 0),
            absint($_GET['preserved'] ?? 0)
        )),
    );
    if (!isset($messages[$msg])) {
        return;
    }
    echo '<div class="notice notice-' . esc_attr($messages[$msg][0]) . ' is-dismissible"><p>' . esc_html($messages[$msg][1]) . '</p></div>';
}

function seo_landing_render_kpis($kpis)
{
    $items = array(
        'Landings inventariadas' => number_format_i18n($kpis['existing']),
        'Publicadas'             => number_format_i18n($kpis['published']),
        'Candidatas pendientes'  => number_format_i18n($kpis['candidates']),
        'Aprobadas por crear'    => number_format_i18n($kpis['approved']),
        'Visitas ultimos 30 dias'=> number_format_i18n($kpis['views_30d']),
        'Visitas registradas'    => number_format_i18n($kpis['views_total']),
    );
    echo '<div class="seo-landing-kpis">';
    foreach ($items as $label => $value) {
        echo '<div class="seo-landing-kpi"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>';
    }
    echo '</div>';
}

function seo_landing_render_views_chart()
{
    $series = seo_landing_get_views_series(30);
    $max = 1;
    foreach ($series as $item) {
        $max = max($max, (int) $item['views']);
    }

    echo '<div class="seo-landing-chart" aria-label="Visitas de landings durante los ultimos 30 dias">';
    foreach ($series as $item) {
        $height = max(2, round(((int) $item['views'] / $max) * 100));
        echo '<span class="seo-landing-bar" style="height:' . esc_attr((string) $height) . '%" title="' . esc_attr($item['date'] . ': ' . $item['views'] . ' visitas') . '"></span>';
    }
    echo '</div>';
}

function seo_landing_render_candidate_form($prefill = array())
{
    $requirements = $prefill['requirements'] ?? array();
    $scores = $prefill['scores'] ?? array();
    echo '<details class="seo-marketing-card" ' . (!empty($_GET['new_candidate']) ? 'open' : '') . '>';
    echo '<summary style="cursor:pointer;font-weight:700;font-size:15px;">Nueva candidata / evaluar oportunidad</summary>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-landing-form">';
    echo '<input type="hidden" name="action" value="seo_landing_save_candidate">';
    wp_nonce_field('seo_landing_save_candidate');

    echo '<div class="seo-landing-form-grid">';
    echo '<label><strong>Titulo de trabajo</strong><input type="text" name="title" value="' . esc_attr($prefill['title'] ?? '') . '" required></label>';
    echo '<label><strong>Tipo</strong><select name="landing_type"><option value="">Se decide despues de la intencion</option>';
    foreach (seo_landing_types() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label><strong>Fuente</strong><input type="text" name="source" value="' . esc_attr($prefill['source'] ?? 'manual') . '"></label>';
    echo '<label><strong>Estado</strong><select name="status">';
    foreach (seo_landing_statuses() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($key, 'candidate', false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '</div>';

    echo '<label><strong>Intencion / que quiere resolver el comprador</strong><textarea name="intent" rows="3">' . esc_textarea($prefill['intent'] ?? '') . '</textarea></label>';
    echo '<label><strong>Destino existente parecido</strong><textarea name="existing_destination" rows="2"></textarea></label>';
    echo '<label><strong>Por que necesita una pagina diferente</strong><textarea name="differentiation_reason" rows="2"></textarea></label>';

    echo '<h3>1. Requisitos obligatorios</h3><div class="seo-landing-checks">';
    foreach (seo_landing_requirement_labels() as $key => $label) {
        echo '<label><input type="checkbox" name="requirements[' . esc_attr($key) . ']" value="1" ' . checked(!empty($requirements[$key]), true, false) . '> ' . esc_html($label) . '</label>';
    }
    echo '</div><p class="description">Si falla uno, la landing no debe aprobarse aunque su puntuacion sea alta.</p>';

    echo '<h3>2. Prioridad de la candidata valida</h3><div class="seo-landing-score-grid">';
    foreach (seo_landing_score_weights() as $key => $config) {
        echo '<label><strong>' . esc_html($config['label']) . ' (0-' . esc_html((string) $config['max']) . ')</strong><input type="number" min="0" max="' . esc_attr((string) $config['max']) . '" step="1" name="scores[' . esc_attr($key) . ']" value="' . esc_attr((string) ($scores[$key] ?? 0)) . '"></label>';
    }
    echo '</div>';
    echo '<p><button type="submit" class="button button-primary">Guardar candidata</button></p>';
    echo '</form></details>';
}

/**
 * Pantalla del gestor dentro de SEO Marketing.
 */
function seo_landing_render_admin_tab()
{
    seo_landing_maybe_install();
    seo_landing_render_notice();

    $kpis = seo_landing_get_kpis();
    $existing = seo_landing_get_existing();
    $candidates = seo_landing_get_candidates(100);
    $gaps = seo_landing_get_coverage_gaps(40);
    $external = seo_landing_get_external_signals();

    $export_url = wp_nonce_url(
        add_query_arg(
            array('action' => 'seo_landing_export_seo_csv'),
            admin_url('admin-post.php')
        ),
        'seo_landing_export_seo_csv'
    );

    echo '<style>
        .seo-landing-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:0 0 20px}.seo-landing-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px}.seo-landing-kpi strong{display:block;font-size:28px;line-height:1.1}.seo-landing-kpi span{display:block;margin-top:6px;color:#646970}.seo-landing-chart{height:170px;display:flex;align-items:flex-end;gap:4px;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-landing-bar{flex:1;min-width:3px;background:#2271b1;border-radius:3px 3px 0 0}.seo-landing-table{width:100%;border-collapse:collapse}.seo-landing-table th,.seo-landing-table td{padding:10px 9px;border-bottom:1px solid #e2e4e7;text-align:left;vertical-align:top}.seo-landing-table th{font-size:12px;text-transform:uppercase;color:#50575e}.seo-landing-badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#f0f0f1;font-size:11px;font-weight:700}.seo-landing-score{font-size:20px;font-weight:800}.seo-landing-score.good{color:#1d6b43}.seo-landing-score.mid{color:#996800}.seo-landing-score.low{color:#b32d2e}.seo-landing-form{margin-top:16px}.seo-landing-form label{display:block;margin-bottom:12px}.seo-landing-form input[type=text],.seo-landing-form input[type=number],.seo-landing-form select,.seo-landing-form textarea{width:100%;margin-top:5px}.seo-landing-form-grid,.seo-landing-score-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.seo-landing-checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;margin:10px 0}.seo-landing-help{max-width:1050px;line-height:1.65}.seo-landing-grid-2{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.6fr);gap:18px}@media(max-width:1050px){.seo-landing-grid-2{grid-template-columns:1fr}}
    </style>';


echo '<details class="seo-marketing-card seo-landing-help">';
echo '<summary style="cursor:pointer;font-weight:700;font-size:16px;">Requisitos que debe cumplir una Landing Page</summary>';

echo '<div style="margin-top:16px;max-width:1100px;line-height:1.7;">';

echo '<p><strong>Objetivo de una landing:</strong> ayudar a una persona que tiene una necesidad de compra a entender qué solución necesita, qué opciones debe comparar, qué datos debe comprobar y qué errores debe evitar antes de elegir un producto.
Debes conocer los productos que quieres evaluar en la landing para que las comparativas sean acordes a los productos ysando incluso etiquetas h atributos. no quiero definiciones genericas y superficiales. la landing debe aportar valor y ayudar a la compra o almenos a decidir. debemos ganar credibilidad y posicionamiento como expertos en el tema
</p>';
echo '<p>Una landing <strong>no se crea porque exista una categoría, un hub, muchos productos o una palabra clave con búsquedas</strong>. Solo debe existir cuando responde a una intención propia y aporta una ayuda de compra que ninguna URL existente resuelve correctamente.</p>';

echo '<h3>Requisitos obligatorios</h3>';

echo '<ol>';

echo '<li><strong>1. Intención de compra diferenciada.</strong><br>';
echo 'La página debe responder a una necesidad concreta del comprador. Esa necesidad debe ser diferente de la que ya resuelven las categorías, hubs, productos, guías o landings existentes. Cambiar el título o utilizar otra palabra clave para explicar prácticamente lo mismo no justifica una nueva landing.</li>';

echo '<li><strong>2. Comprobación previa de canibalización.</strong><br>';
echo 'Antes de crearla debe verificarse que ninguna URL existente responda ya a la misma intención. Si una categoría, hub, guía o landing existente puede mejorarse para resolver correctamente esa necesidad, se actualizará esa página en lugar de crear otra. Una landing nueva solo se justifica cuando necesita un destino claramente diferente.</li>';

echo '<li><strong>3. Debe conectar catálogo que actualmente está separado.</strong><br>';
echo 'Una de las principales funciones de una landing es reunir productos, categorías o familias que la arquitectura normal mantiene separadas pero que el comprador necesita combinar para resolver un mismo problema. Esa transversalidad es una diferencia fundamental respecto a una categoría o un hub.</li>';

echo '<li><strong>4. Debe ayudar realmente a decidir una compra.</strong><br>';
echo 'Al terminar de leerla, el usuario debe entender qué tipo de producto o solución necesita, qué alternativas existen, qué características importan para su caso y qué debe comprobar antes de comprar. Una definición genérica del producto no es suficiente.</li>';

echo '<li><strong>5. Debe existir una solución comercial suficiente.</strong><br>';
echo 'La necesidad debe poder resolverse con una selección suficientemente amplia y estable del catálogo. Como referencia, pueden existir varias categorías útiles, unos 8 productos relevantes o una combinación equivalente con suficiente profundidad comercial. Esta cifra es orientativa y nunca debe utilizarse como una regla automática.</li>';

echo '<li><strong>6. Debe poder aportar contenido útil y específico.</strong><br>';
echo 'La página debe permitir explicar criterios de elección, diferencias entre alternativas, medidas, capacidades, compatibilidades, condiciones de uso, limitaciones, errores frecuentes u otros factores que cambien realmente la decisión de compra. No debe rellenarse con texto SEO genérico.</li>';

echo '<li><strong>7. Debe ser una necesidad estable.</strong><br>';
echo 'La landing debe seguir teniendo sentido aunque cambien productos concretos, precios, promociones o stock. La necesidad del comprador debe permanecer en el tiempo y permitir sustituir unos productos por otros sin que la página pierda su utilidad.</li>';

echo '</ol>';

echo '<h3>Test del comprador</h3>';

echo '<p>Antes de considerar válida una landing debemos comprobar que su contenido ayuda de verdad a tomar una decisión.</p>';

echo '<ul>';
echo '<li>¿Queda claro <strong>qué problema quiere resolver</strong> el comprador?</li>';
echo '<li>¿Explicamos qué información necesita conocer antes de elegir?</li>';
echo '<li>¿Indicamos qué características cambian realmente la decisión?</li>';
echo '<li>¿Ayudamos a distinguir entre diferentes tipos de solución?</li>';
echo '<li>¿Explicamos cuándo una alternativa puede ser mejor que otra?</li>';
echo '<li>¿Indicamos medidas, capacidades, conexiones o compatibilidades que sea necesario comprobar?</li>';
echo '<li>¿Explicamos qué opciones pueden descartarse y por qué?</li>';
echo '<li>¿Advertimos de errores habituales de compra?</li>';
echo '<li>¿El usuario termina sabiendo qué debe comparar entre varios productos?</li>';
echo '<li>¿Existe una transición natural desde la explicación hacia una selección comercial útil?</li>';
echo '</ul>';

echo '<h3>Estructura editorial recomendada</h3>';

echo '<p>Los siguientes bloques son una guía. <strong>No es obligatorio utilizar todos</strong> cuando no aporten valor a la intención concreta. La estructura debe adaptarse a la decisión que necesita tomar el comprador.</p>';

echo '<ol>';
echo '<li><strong>Respuesta inicial:</strong> explicar inmediatamente qué problema o necesidad de compra resuelve la página.</li>';
echo '<li><strong>Datos previos:</strong> qué debe saber, medir o comprobar el usuario antes de elegir.</li>';
echo '<li><strong>Criterios de elección:</strong> factores que cambian realmente la decisión.</li>';
echo '<li><strong>Tipos de solución:</strong> diferencias entre alternativas y cuándo conviene cada una.</li>';
echo '<li><strong>Compatibilidades y medidas:</strong> límites, conexiones, capacidades o requisitos que no deben darse por supuestos.</li>';
echo '<li><strong>Errores frecuentes:</strong> decisiones equivocadas que la página puede ayudar a evitar.</li>';
echo '<li><strong>Selección comercial razonada:</strong> presentar las familias o tipos de producto que resuelven cada escenario explicado.</li>';
echo '<li><strong>Preguntas frecuentes:</strong> incluir únicamente dudas reales de compra que no hayan quedado resueltas suficientemente en el contenido principal.</li>';
echo '<li><strong>Llamada a la acción:</strong> conducir al siguiente paso natural: comparar opciones, consultar categorías o revisar productos adecuados.</li>';
echo '</ol>';

echo '<h3>Qué NO es una buena landing</h3>';

echo '<ul>';
echo '<li>Una página que solo explica qué es un producto.</li>';
echo '<li>Una copia de una categoría, hub o guía con otro título.</li>';
echo '<li>Una página creada únicamente porque una palabra clave tiene búsquedas.</li>';
echo '<li>Una página creada únicamente porque un hub contiene muchos productos.</li>';
echo '<li>Una sucesión de mensajes genéricos como "calidad profesional", "gran variedad" o "soluciones para todas las necesidades".</li>';
echo '<li>Una página que enumera características pero no explica cómo afectan a la elección.</li>';
echo '<li>Una página que recomienda productos sin explicar para qué comprador o situación resulta apropiado cada tipo de solución.</li>';
echo '<li>Una página que muestra productos o categorías sin relación real con la intención explicada.</li>';
echo '<li>Una página que inventa compatibilidades, prestaciones, certificaciones, accesorios incluidos o usos no verificados.</li>';
echo '</ul>';

echo '<h3>Regla de decisión antes de crearla</h3>';

echo '<p><strong>Si existe una URL que ya puede resolver esa intención mejorándola, no se crea una landing nueva.</strong></p>';

echo '<p><strong>Si la necesidad no conecta suficiente catálogo, no ayuda a tomar una decisión o no permite producir contenido específico, tampoco se crea.</strong></p>';

echo '<p>Solo después de superar estos requisitos se considera que existe una candidata válida.</p>';

echo '<h3>Test definitivo</h3>';

echo '<p><strong>Una landing está bien hecha cuando una persona que llega sin saber exactamente qué comprar termina la página sabiendo:</strong></p>';

echo '<ul>';
echo '<li>Qué tipo de solución necesita.</li>';
echo '<li>Qué características debe buscar.</li>';
echo '<li>Qué tiene que medir, conocer o comprobar.</li>';
echo '<li>Qué alternativas debería comparar.</li>';
echo '<li>Qué opciones puede descartar.</li>';
echo '<li>Qué errores debe evitar.</li>';
echo '<li>Y qué información debe verificar en la ficha del producto antes de comprar.</li>';
echo '</ul>';

echo '<p><strong>Si el contenido no consigue esto, la landing todavía no está terminada.</strong></p>';

echo '<h3>Priorización de candidatas</h3>';

echo '<p>El scoring de 100 puntos se utiliza únicamente después de que la candidata haya superado los requisitos anteriores. Su función es decidir <strong>qué landing válida conviene crear primero</strong>, no decidir si una landing debe existir.</p>';

echo '<p>La prioridad se calcula utilizando potencial comercial, oportunidad orgánica, demanda externa, cobertura de catálogo, distribución de visibilidad y capacidad editorial.</p>';

echo '</div>';
echo '</details>';
    echo '<div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin:-4px 0 16px;flex-wrap:wrap;">';
    echo '<span class="description">Exporta inventario, URL, metadatos y metricas SEO de todas las landings.</span>';
    echo '<a class="button button-primary" href="' . esc_url($export_url) . '">Descargar informe SEO (CSV)</a>';
    echo '</div>';
    seo_landing_render_kpis($kpis);

    echo '<div class="seo-landing-grid-2">';
    echo '<section class="seo-marketing-card"><h2>Rendimiento de landings</h2><p class="description">Contador propio desde la activacion de este modulo. Excluye administradores conectados.</p>';
    seo_landing_render_views_chart();
    echo '</section>';
    echo '<section class="seo-marketing-card"><h2>Senales externas</h2>';
    if (function_exists('seo_landing_google_source_status')) {
        $source_status = seo_landing_google_source_status();
        echo '<ul style="margin:0 0 12px 18px;">';
        foreach (array('search_console'=>'Search Console','analytics'=>'Analytics','trends'=>'Google Trends') as $source_key=>$source_label) {
            $src = $source_status[$source_key] ?? array('connected'=>false,'detail'=>'No disponible');
            echo '<li><strong>' . esc_html($source_label) . ':</strong> ' . (!empty($src['connected']) ? '<span style="color:#1d6b43">conectado</span>' : '<span style="color:#996800">pendiente</span>') . ' <small>' . esc_html($src['detail']) . '</small></li>';
        }
        echo '</ul>';
    }
    if (empty($external)) {
        echo '<p><strong>0 oportunidades externas listas para revisar.</strong></p><p class="description">Las conexiones pueden estar activas aunque todavia no exista una oportunidad que cumpla los criterios minimos.</p>';
    } else {
        echo '<p><strong>' . esc_html(number_format_i18n(count($external))) . ' oportunidades externas disponibles.</strong></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_landing_sync_signals">';
        wp_nonce_field('seo_landing_sync_signals');
        echo '<button type="submit" class="button button-primary">Sincronizar para revision</button></form>';
        echo '<p class="description">Las senales no demostrables quedan como pendientes y conservan un pre-score. Un solapamiento claro con una landing existente invalida la creacion de una nueva URL.</p>';
    }
    echo '</section></div>';

    echo '<section class="seo-marketing-card"><h2>Landings existentes</h2>';
    if (empty($existing)) {
        echo '<p>No hay paginas marcadas como <code>page + landing</code>.</p>';
    } else {
        echo '<div style="overflow:auto"><table class="seo-landing-table"><thead><tr><th>ID</th><th>Landing</th><th>Estado</th><th>Contador 30d</th><th>GA4 sesiones 30d</th><th>GSC 28d</th><th>Actualizada</th><th></th></tr></thead><tbody>';
        foreach ($existing as $landing) {
            $external_metrics = function_exists('seo_landing_google_metrics_for_page') ? seo_landing_google_metrics_for_page($landing->ID) : array('ga4'=>array(),'gsc'=>array());
            $ga4_sessions = (int) ($external_metrics['ga4']['sessions'] ?? 0);
            $gsc_clicks = (int) ($external_metrics['gsc']['clicks'] ?? 0);
            $gsc_impressions = (int) ($external_metrics['gsc']['impressions'] ?? 0);
            $gsc_position = (float) ($external_metrics['gsc']['position'] ?? 0);
            $gsc_text = number_format_i18n($gsc_clicks) . ' clics / ' . number_format_i18n($gsc_impressions) . ' imp.';
            if ($gsc_position > 0) { $gsc_text .= ' / pos. ' . number_format_i18n($gsc_position, 1); }
            echo '<tr><td><code>#' . esc_html((string) $landing->ID) . '</code></td><td><strong>' . esc_html($landing->post_title) . '</strong></td><td><span class="seo-landing-badge">' . esc_html($landing->post_status) . '</span></td><td>' . esc_html(number_format_i18n((int) $landing->views_30d)) . '</td><td>' . esc_html(number_format_i18n($ga4_sessions)) . '</td><td>' . esc_html($gsc_text) . '</td><td>' . esc_html(mysql2date('d/m/Y', $landing->post_modified)) . '</td><td><a class="button button-small" href="' . esc_url(get_edit_post_link($landing->ID, '')) . '">Editar</a> <a class="button button-small" target="_blank" rel="noopener" href="' . esc_url(get_permalink($landing->ID)) . '">Ver</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    echo '<section class="seo-marketing-card"><h2>Candidatas y decisiones</h2>';
    if (empty($candidates)) {
        echo '<p>Todavia no hay candidatas guardadas. Puedes crear una manualmente o sincronizar senales externas.</p>';
    } else {
        echo '<div style="overflow:auto"><table class="seo-landing-table"><thead><tr><th>Candidata</th><th>Tipo</th><th>Fuente</th><th>Requisitos</th><th>Score</th><th>Diagnostico</th><th>Estado</th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            $requirements = seo_landing_decode_json($candidate->requirements_json);
            $requirements_state = seo_landing_requirements_state($requirements);
            $score = (float) $candidate->total_score;
            $score_class = $score >= 65 ? 'good' : ($score >= 50 ? 'mid' : 'low');
            $type_label = seo_landing_types()[$candidate->landing_type] ?? 'Sin clasificar';
            $status_label = seo_landing_statuses()[$candidate->status] ?? $candidate->status;
            $score_prefix = 'pending' === $requirements_state ? '<small>Pre-score</small><br>' : '';
            $diagnostic = trim((string) $candidate->existing_destination);
            if ('' !== trim((string) $candidate->differentiation_reason)) {
                $diagnostic .= ($diagnostic ? ' — ' : '') . trim((string) $candidate->differentiation_reason);
            }
            if ('' === $diagnostic) {
                $diagnostic = 'Pendiente de revision editorial.';
            }
            echo '<tr><td><strong>' . esc_html($candidate->title) . '</strong><br><small>' . esc_html(wp_trim_words((string) $candidate->intent, 22)) . '</small></td><td>' . esc_html($type_label) . '</td><td>' . esc_html($candidate->source) . '</td><td><span class="seo-landing-badge">' . esc_html(seo_landing_requirements_summary($requirements)) . '</span></td><td>' . $score_prefix . '<span class="seo-landing-score ' . esc_attr($score_class) . '">' . esc_html(number_format_i18n($score, 0)) . '</span>/100</td><td><small>' . esc_html(wp_trim_words($diagnostic, 28)) . '</small></td><td>' . esc_html($status_label) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    echo '<section class="seo-marketing-card"><h2>Huecos de cobertura a revisar</h2>';
    echo '<p class="description">Son hubs secundarios con al menos 8 productos y sin una relacion explicita desde ninguna landing. <strong>No significa que haya que crear una landing.</strong> Sirve para encontrar zonas del catalogo con profundidad que merecen analizarse con Search Console, Trends y datos comerciales.</p>';
    if (empty($gaps)) {
        echo '<p>No se han detectado huecos con este criterio.</p>';
    } else {
        echo '<div style="overflow:auto"><table class="seo-landing-table"><thead><tr><th>Hub secundario</th><th>Categorias</th><th>Productos</th><th>Landings vinculadas</th><th>Accion</th></tr></thead><tbody>';
        foreach ($gaps as $gap) {
            $prefill_url = add_query_arg(array('page'=>'seo-menu-marketing','tab'=>'landings','new_candidate'=>1,'gap_id'=>$gap->ID), admin_url('admin.php'));
            echo '<tr><td><strong>' . esc_html($gap->post_title) . '</strong><br><code>#' . esc_html((string) $gap->ID) . '</code></td><td>' . esc_html(number_format_i18n((int) $gap->category_count)) . '</td><td>' . esc_html(number_format_i18n((int) $gap->product_count)) . '</td><td>' . esc_html(number_format_i18n((int) $gap->landing_count)) . '</td><td><a class="button button-small" href="' . esc_url($prefill_url) . '">Evaluar oportunidad</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    $prefill = array();
    $gap_id = absint($_GET['gap_id'] ?? 0);
    if ($gap_id > 0) {
        $gap_post = get_post($gap_id);
        if ($gap_post && 'page' === $gap_post->post_type) {
            $prefill = array(
                'title'  => 'Revisar oportunidad: ' . $gap_post->post_title,
                'intent' => 'Definir la intencion diferenciada que podria unir varias categorias o productos de esta rama.',
                'source' => 'coverage_gap',
            );
        }
    }
    seo_landing_render_candidate_form($prefill);
}