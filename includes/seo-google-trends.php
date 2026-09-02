<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Trends v2.
 *
 * Principios:
 * - Search Console y Analytics conservan sus conexiones y finalidades.
 * - Trends actua como fuente externa; no usa las paginas propias para decidir
 *   que demanda existe.
 * - El radar automatico consume el RSS oficial de Tendencias actuales.
 * - Los analisis de mercado de Explore se incorporan mediante CSV o mediante
 *   un proveedor registrado en el filtro seo_google_trends_market_provider_sync.
 */
if (!defined('SEO_GOOGLE_TRENDS_VERSION')) define('SEO_GOOGLE_TRENDS_VERSION', '2.0.0');
if (!defined('SEO_GOOGLE_TRENDS_DB_VERSION')) define('SEO_GOOGLE_TRENDS_DB_VERSION', '2.0.0');
if (!defined('SEO_GOOGLE_TRENDS_DB_OPTION')) define('SEO_GOOGLE_TRENDS_DB_OPTION', 'seo_google_trends_db_version');
if (!defined('SEO_GOOGLE_TRENDS_SETTINGS_OPTION')) define('SEO_GOOGLE_TRENDS_SETTINGS_OPTION', 'seo_google_trends_settings_v2');
if (!defined('SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION')) define('SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION', 'seo_google_trends_last_sync_v2');
if (!defined('SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT')) define('SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT', 'seo_google_trends_last_error_v2');
if (!defined('SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT')) define('SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT', 'seo_google_trends_sync_lock_v2');
if (!defined('SEO_GOOGLE_TRENDS_CRON_HOOK')) define('SEO_GOOGLE_TRENDS_CRON_HOOK', 'seo_google_trends_cron_sync_v2');
if (!defined('SEO_GOOGLE_TRENDS_RUNTIME_MIGRATION_OPTION')) define('SEO_GOOGLE_TRENDS_RUNTIME_MIGRATION_OPTION', 'seo_google_trends_runtime_migration_v2');

add_action('admin_init', 'seo_google_trends_maybe_install', 2);
add_action('admin_init', 'seo_google_trends_ensure_schedule', 20);
add_action(SEO_GOOGLE_TRENDS_CRON_HOOK, 'seo_google_trends_cron_sync');
add_action('admin_post_seo_google_trends_import', 'seo_google_trends_import_handler');
add_action('admin_post_seo_google_trends_sync', 'seo_google_trends_sync_handler');
add_action('admin_post_seo_google_trends_clear', 'seo_google_trends_clear_handler');
add_action('admin_post_seo_google_trends_save_settings', 'seo_google_trends_save_settings_handler');

function seo_google_trends_table() {
    global $wpdb;
    return $wpdb->prefix . 'seo_google_trends';
}

function seo_google_trends_maybe_install() {
    global $wpdb;

    $table = seo_google_trends_table();
    $installed_version = (string) get_option(SEO_GOOGLE_TRENDS_DB_OPTION, '');
    $runtime_version = (string) get_option(SEO_GOOGLE_TRENDS_RUNTIME_MIGRATION_OPTION, '');
    $exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    ) === $table;

    if (
        $exists
        && SEO_GOOGLE_TRENDS_DB_VERSION === $installed_version
        && SEO_GOOGLE_TRENDS_VERSION === $runtime_version
    ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        seed varchar(255) NOT NULL,
        seed_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        query_text varchar(512) NOT NULL,
        query_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        result_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'related',
        trend_value double NOT NULL DEFAULT 0,
        is_breakout tinyint(1) unsigned NOT NULL DEFAULT 0,
        geo varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ES',
        timeframe varchar(40) NOT NULL DEFAULT '',
        source_note varchar(255) NOT NULL DEFAULT '',
        provider varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy',
        signal_meta longtext NULL,
        observed_at datetime NULL,
        imported_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_signal (seed_hash,query_hash,result_type,geo,timeframe),
        KEY seed_hash (seed_hash),
        KEY query_hash (query_hash),
        KEY result_type (result_type),
        KEY provider (provider),
        KEY observed_at (observed_at),
        KEY imported_at (imported_at)
    ) {$collate};";

    dbDelta($sql);

    /*
     * Migra sin borrar los CSV existentes. Las filas obtenidas por el antiguo
     * endpoint web se conservan como histórico, pero quedan fuera del motor V2
     * porque ese proveedor no era estable ni documentado.
     */
    if (SEO_GOOGLE_TRENDS_VERSION !== $runtime_version) {
        $wpdb->query(
            "UPDATE {$table}
             SET provider = 'csv'
             WHERE provider IN ('', 'legacy')
               AND source_note LIKE 'CSV%'"
        );
        $wpdb->query(
            "UPDATE {$table}
             SET provider = 'legacy_web'
             WHERE provider IN ('', 'legacy')
               AND source_note LIKE 'Google Trends web%'"
        );

        delete_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);
        delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);

        $previous_state = get_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION, array());
        if (is_array($previous_state) && !isset($previous_state['radar_status'])) {
            delete_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION);
        }
    }

    update_option(SEO_GOOGLE_TRENDS_DB_OPTION, SEO_GOOGLE_TRENDS_DB_VERSION, false);
    update_option(SEO_GOOGLE_TRENDS_RUNTIME_MIGRATION_OPTION, SEO_GOOGLE_TRENDS_VERSION, false);
}

function seo_google_trends_get_settings() {
    $defaults = array(
        'geo'           => 'ES',
        'auto_sync'     => 1,
        'min_relevance' => 52,
        'manual_seeds'  => '',
    );

    $settings = get_option(SEO_GOOGLE_TRENDS_SETTINGS_OPTION, array());
    $settings = wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
    $settings['geo'] = preg_match('/^[A-Z]{2}$/', strtoupper((string) $settings['geo']))
        ? strtoupper((string) $settings['geo'])
        : 'ES';
    $settings['auto_sync'] = empty($settings['auto_sync']) ? 0 : 1;
    $settings['min_relevance'] = max(25, min(90, absint($settings['min_relevance'])));
    $settings['manual_seeds'] = (string) $settings['manual_seeds'];

    return apply_filters('seo_google_trends_settings', $settings);
}

function seo_google_trends_save_settings_handler() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para configurar Google Trends.', 'seo-system'));
    }

    check_admin_referer('seo_google_trends_save_settings', 'seo_google_trends_settings_nonce');

    $geo = isset($_POST['geo'])
        ? strtoupper(sanitize_text_field(wp_unslash($_POST['geo'])))
        : 'ES';
    if (!preg_match('/^[A-Z]{2}$/', $geo)) {
        $geo = 'ES';
    }

    $settings = array(
        'geo'           => $geo,
        'auto_sync'     => empty($_POST['auto_sync']) ? 0 : 1,
        'min_relevance' => max(25, min(90, absint($_POST['min_relevance'] ?? 52))),
        'manual_seeds'  => sanitize_textarea_field(wp_unslash($_POST['manual_seeds'] ?? '')),
    );

    update_option(SEO_GOOGLE_TRENDS_SETTINGS_OPTION, $settings, false);
    seo_google_trends_ensure_schedule(true);

    wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_notice' => 'settings_saved')));
    exit;
}

function seo_google_trends_ensure_schedule($force = false) {
    $settings = seo_google_trends_get_settings();
    $next = wp_next_scheduled(SEO_GOOGLE_TRENDS_CRON_HOOK);

    if (!empty($settings['auto_sync'])) {
        if (!$next || $force) {
            if ($next && $force) {
                wp_clear_scheduled_hook(SEO_GOOGLE_TRENDS_CRON_HOOK);
            }
            wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), 'hourly', SEO_GOOGLE_TRENDS_CRON_HOOK);
        }
    } elseif ($next) {
        wp_clear_scheduled_hook(SEO_GOOGLE_TRENDS_CRON_HOOK);
    }
}

function seo_google_trends_cron_sync() {
    seo_google_trends_sync(false, 0);
}

function seo_google_trends_normalize($text) {
    $text = remove_accents(wp_strip_all_tags((string) $text));
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
    return trim((string) preg_replace('/\s+/', ' ', $text));
}

function seo_google_trends_token_root($token) {
    $token = seo_google_trends_normalize($token);
    if ($token === '') {
        return '';
    }

    if (strlen($token) > 7 && substr($token, -5) === 'iones') {
        return substr($token, 0, -5) . 'ion';
    }
    if (strlen($token) > 6 && substr($token, -3) === 'ces') {
        return substr($token, 0, -3) . 'z';
    }
    if (strlen($token) > 6 && substr($token, -2) === 'es') {
        return substr($token, 0, -2);
    }
    if (strlen($token) > 5 && substr($token, -1) === 's') {
        return substr($token, 0, -1);
    }

    return $token;
}

function seo_google_trends_tokens($text) {
    $stop = array(
        'a','al','ante','bajo','con','contra','de','del','desde','durante','e','el','ella','ellas','ellos',
        'en','entre','era','es','esta','este','esto','estos','la','las','lo','los','mas','muy','o','para',
        'pero','por','que','se','sin','sobre','su','sus','un','una','uno','unos','unas','y','ya',
        'nuevo','nueva','nuevos','nuevas','ultima','ultimo','ultimos','ultimas','hoy','ahora',
        'producto','productos','profesional','profesionales','industrial','industriales',
    );

    $tokens = array();
    foreach (explode(' ', seo_google_trends_normalize($text)) as $token) {
        if ($token === '' || strlen($token) < 3 || in_array($token, $stop, true)) {
            continue;
        }
        $root = seo_google_trends_token_root($token);
        if ($root !== '' && strlen($root) >= 3) {
            $tokens[$root] = true;
        }
    }
    return array_keys($tokens);
}

function seo_google_trends_explore_url($term, $geo = 'ES', $time = 'today 12-m') {
    return add_query_arg(
        array('geo' => $geo, 'date' => $time, 'q' => $term),
        'https://trends.google.com/trends/explore'
    );
}

function seo_google_trends_rss_url($geo = 'ES') {
    return add_query_arg(array('geo' => strtoupper((string) $geo)), 'https://trends.google.com/trending/rss');
}

function seo_google_trends_manual_seed_lines($raw) {
    $parts = preg_split('/[\r\n,;]+/', (string) $raw);
    $out = array();
    foreach ((array) $parts as $part) {
        $part = sanitize_text_field(trim((string) $part));
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Construye el perimetro comercial. No consume consultas de Search Console ni
 * rastrea el contenido de paginas, landings o posts como fuente de demanda.
 */
function seo_google_trends_business_universe($limit = 400) {
    global $wpdb;

    $items = array();
    $add = static function ($label, $source, $weight, $object_type = '', $object_id = 0) use (&$items) {
        $label = sanitize_text_field(trim((string) $label));
        $key = seo_google_trends_normalize($label);
        if ($key === '') {
            return;
        }
        $row = array(
            'label'       => $label,
            'source'      => $source,
            'weight'      => max(1, min(100, (int) $weight)),
            'object_type' => sanitize_key($object_type),
            'object_id'   => absint($object_id),
            'tokens'      => seo_google_trends_tokens($label),
        );
        if (!isset($items[$key]) || $row['weight'] > $items[$key]['weight']) {
            $items[$key] = $row;
        }
    };

    $settings = seo_google_trends_get_settings();
    foreach (seo_google_trends_manual_seed_lines($settings['manual_seeds']) as $manual_seed) {
        $add($manual_seed, 'Área manual', 100, 'manual', 0);
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $nodes_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($nodes_table))
    ) === $nodes_table;

    if ($nodes_exists) {
        $rows = $wpdb->get_results(
            "SELECT DISTINCT p.ID, p.post_title, n.seo_role
             FROM {$nodes_table} n
             INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id
             WHERE n.object_type = 'page'
               AND n.seo_role IN ('cluster','hub_primary','hub_secondary')
               AND n.status = 1
               AND p.post_type = 'page'
               AND p.post_status NOT IN ('trash','auto-draft')
             ORDER BY FIELD(n.seo_role,'cluster','hub_primary','hub_secondary'), p.post_title ASC
             LIMIT 300",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $role = (string) ($row['seo_role'] ?? '');
            $weights = array('cluster' => 92, 'hub_primary' => 87, 'hub_secondary' => 82);
            $labels = array('cluster' => 'Cluster', 'hub_primary' => 'Hub principal', 'hub_secondary' => 'Hub secundario');
            $add(
                $row['post_title'] ?? '',
                $labels[$role] ?? 'Estructura SEO',
                $weights[$role] ?? 75,
                $role,
                $row['ID'] ?? 0
            );
        }
    }

    if (taxonomy_exists('product_cat')) {
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'number'     => 300,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $count = max(0, (int) $term->count);
                $weight = min(82, 58 + (int) round(log(1 + $count) * 5));
                $add($term->name, 'Categoría de producto', $weight, 'product_cat', $term->term_id);
            }
        }
    }

    if (function_exists('wc_get_attribute_taxonomies') && function_exists('wc_attribute_taxonomy_name')) {
        foreach ((array) wc_get_attribute_taxonomies() as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name ?? '');
            if (!$taxonomy || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => 50,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ));
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $add($term->name, 'Atributo de producto', 48, $taxonomy, $term->term_id);
            }
        }
    }

    $items = apply_filters('seo_google_trends_business_universe', $items, $settings);
    $items = is_array($items) ? $items : array();

    uasort($items, static function ($left, $right) {
        if ((int) $left['weight'] === (int) $right['weight']) {
            return strcmp((string) $left['label'], (string) $right['label']);
        }
        return (int) $right['weight'] <=> (int) $left['weight'];
    });

    return array_slice(array_values($items), 0, max(20, min(1000, absint($limit))));
}

/** Compatibilidad con el nombre usado por las versiones anteriores. */
function seo_google_trends_seed_candidates($limit = 24) {
    $rows = seo_google_trends_business_universe(max(24, absint($limit)));
    $out = array();
    foreach ($rows as $row) {
        $out[] = array(
            'label'    => $row['label'],
            'score'    => $row['weight'],
            'source'   => $row['source'],
            'strategy' => 'Perímetro comercial',
        );
    }
    return array_slice($out, 0, max(5, min(100, absint($limit))));
}

function seo_google_trends_relevance($text, array $universe, array $context_texts = array()) {
    $text = trim((string) $text);
    $normalized = seo_google_trends_normalize($text);
    $title_tokens = seo_google_trends_tokens($text);
    $context = trim(implode(' ', array_filter(array_map('strval', $context_texts))));
    $all_tokens = array_values(array_unique(array_merge($title_tokens, seo_google_trends_tokens($context))));

    if ($normalized === '' || !$all_tokens || !$universe) {
        return array('score' => 0, 'matched_seeds' => array(), 'matched_tokens' => array());
    }

    $matches = array();
    foreach ($universe as $seed) {
        $seed_label = (string) ($seed['label'] ?? '');
        $seed_normalized = seo_google_trends_normalize($seed_label);
        $seed_tokens = !empty($seed['tokens']) && is_array($seed['tokens'])
            ? $seed['tokens']
            : seo_google_trends_tokens($seed_label);
        if ($seed_normalized === '' || !$seed_tokens) {
            continue;
        }

        $shared_title = array_values(array_intersect($title_tokens, $seed_tokens));
        $shared_all = array_values(array_intersect($all_tokens, $seed_tokens));
        $exact_phrase = false !== strpos(' ' . $normalized . ' ', ' ' . $seed_normalized . ' ');
        $reverse_phrase = count($title_tokens) >= 2
            && false !== strpos(' ' . $seed_normalized . ' ', ' ' . $normalized . ' ');

        $score = 0.0;
        if ($exact_phrase || $reverse_phrase) {
            $score = 96.0;
        } elseif (count($shared_title) >= 3) {
            $score = 90.0;
        } elseif (count($shared_title) === 2) {
            $score = 77.0;
        } elseif (count($shared_title) === 1) {
            $token = reset($shared_title);
            $score = strlen((string) $token) >= 7 ? 67.0 : (strlen((string) $token) >= 5 ? 58.0 : 43.0);
        } elseif (count($shared_all) >= 2) {
            $score = 62.0;
        } elseif (count($shared_all) === 1) {
            $token = reset($shared_all);
            $score = strlen((string) $token) >= 7 ? 51.0 : 38.0;
        }

        if ($score <= 0) {
            continue;
        }

        $coverage = count($shared_all) / max(1, min(count($seed_tokens), count($all_tokens)));
        $score += min(4.0, $coverage * 4.0);
        $weight = max(1, min(100, (int) ($seed['weight'] ?? 60)));
        $score *= (0.82 + ($weight / 560));
        $score = max(0, min(100, $score));

        $matches[] = array(
            'score'  => round($score, 2),
            'label'  => $seed_label,
            'tokens' => $shared_all,
            'source' => (string) ($seed['source'] ?? ''),
        );
    }

    usort($matches, static function ($left, $right) {
        return (float) $right['score'] <=> (float) $left['score'];
    });

    $best_score = $matches ? (float) $matches[0]['score'] : 0.0;
    $matched_seeds = array();
    $matched_tokens = array();
    foreach (array_slice($matches, 0, 5) as $match) {
        if ((float) $match['score'] < max(35, $best_score - 18)) {
            continue;
        }
        $matched_seeds[] = $match['label'];
        $matched_tokens = array_merge($matched_tokens, (array) $match['tokens']);
    }

    $result = array(
        'score'          => (int) round($best_score),
        'matched_seeds'  => array_values(array_unique($matched_seeds)),
        'matched_tokens' => array_values(array_unique($matched_tokens)),
    );

    return apply_filters('seo_google_trends_radar_relevance', $result, $text, $universe, $context_texts);
}

function seo_google_trends_parse_traffic($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 0;
    }

    $normalized = seo_google_trends_normalize(str_replace("\xC2\xA0", ' ', $raw));
    if (!preg_match('/([0-9]+(?:[.,][0-9]+)*)/', $raw, $match)) {
        return 0;
    }

    $numeric = (string) $match[1];
    if (preg_match('/^[0-9]{1,3}(?:[.,][0-9]{3})+$/', $numeric)) {
        $numeric = str_replace(array('.', ','), '', $numeric);
    } elseif (false !== strpos($numeric, ',') && false === strpos($numeric, '.')) {
        $numeric = str_replace(',', '.', $numeric);
    }
    $number = (float) $numeric;

    $multiplier = 1;
    if (preg_match('/\b(millon|millones|million|millions)\b/', $normalized)) {
        $multiplier = 1000000;
    } elseif (preg_match('/\bmil\b/', $normalized) || preg_match('/[0-9]\s*k\b/i', $raw)) {
        $multiplier = 1000;
    } elseif (preg_match('/[0-9]\s*m\b/i', $raw)) {
        $multiplier = 1000000;
    }

    return (int) round($number * $multiplier);
}

function seo_google_trends_parse_date($raw) {
    $timestamp = strtotime((string) $raw);
    if (!$timestamp) {
        return current_time('mysql');
    }
    return wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
}

function seo_google_trends_xml_text($value) {
    return sanitize_text_field(trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8')));
}

function seo_google_trends_parse_rss_simplexml($body) {
    if (!function_exists('simplexml_load_string')) {
        return array();
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string) $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$xml || empty($xml->channel->item)) {
        return array();
    }

    $rows = array();
    foreach ($xml->channel->item as $item) {
        $namespaces = $item->getNameSpaces(true);
        $ht_uri = isset($namespaces['ht']) ? $namespaces['ht'] : 'https://trends.google.com/trending/rss';
        $ht = $item->children($ht_uri);
        $news = array();

        if ($ht && isset($ht->news_item)) {
            foreach ($ht->news_item as $news_item) {
                $news_children = $news_item->children($ht_uri);
                $title = seo_google_trends_xml_text($news_children->news_item_title ?? '');
                $source = seo_google_trends_xml_text($news_children->news_item_source ?? '');
                $url = esc_url_raw((string) ($news_children->news_item_url ?? ''));
                if ($title !== '' || $source !== '' || $url !== '') {
                    $news[] = array('title' => $title, 'source' => $source, 'url' => $url);
                }
            }
        }

        $title = seo_google_trends_xml_text($item->title ?? '');
        if ($title === '') {
            continue;
        }

        $traffic_label = seo_google_trends_xml_text($ht->approx_traffic ?? '');
        $rows[] = array(
            'title'         => $title,
            'traffic_label' => $traffic_label,
            'traffic'       => seo_google_trends_parse_traffic($traffic_label),
            'pub_date'      => seo_google_trends_parse_date($item->pubDate ?? ''),
            'description'   => seo_google_trends_xml_text($item->description ?? ''),
            'link'          => esc_url_raw((string) ($item->link ?? '')),
            'picture'       => esc_url_raw((string) ($ht->picture ?? '')),
            'news'          => $news,
        );
    }

    return $rows;
}

function seo_google_trends_regex_tag($xml, $tag) {
    $tag_pattern = preg_quote($tag, '/');
    if (!preg_match('/<' . $tag_pattern . '[^>]*>(.*?)<\/' . $tag_pattern . '>/si', (string) $xml, $match)) {
        return '';
    }
    $value = preg_replace('/<!\[CDATA\[(.*?)\]\]>/si', '$1', $match[1]);
    return seo_google_trends_xml_text(wp_strip_all_tags((string) $value));
}

function seo_google_trends_parse_rss_regex($body) {
    if (!preg_match_all('/<item\b[^>]*>(.*?)<\/item>/si', (string) $body, $matches)) {
        return array();
    }

    $rows = array();
    foreach ($matches[1] as $item_xml) {
        $title = seo_google_trends_regex_tag($item_xml, 'title');
        if ($title === '') {
            continue;
        }

        $news = array();
        if (preg_match_all('/<ht:news_item\b[^>]*>(.*?)<\/ht:news_item>/si', (string) $item_xml, $news_matches)) {
            foreach ($news_matches[1] as $news_xml) {
                $news_title = seo_google_trends_regex_tag($news_xml, 'ht:news_item_title');
                $news_source = seo_google_trends_regex_tag($news_xml, 'ht:news_item_source');
                $news_url = esc_url_raw(seo_google_trends_regex_tag($news_xml, 'ht:news_item_url'));
                if ($news_title !== '' || $news_source !== '' || $news_url !== '') {
                    $news[] = array('title' => $news_title, 'source' => $news_source, 'url' => $news_url);
                }
            }
        }

        $traffic_label = seo_google_trends_regex_tag($item_xml, 'ht:approx_traffic');
        $rows[] = array(
            'title'         => $title,
            'traffic_label' => $traffic_label,
            'traffic'       => seo_google_trends_parse_traffic($traffic_label),
            'pub_date'      => seo_google_trends_parse_date(seo_google_trends_regex_tag($item_xml, 'pubDate')),
            'description'   => seo_google_trends_regex_tag($item_xml, 'description'),
            'link'          => esc_url_raw(seo_google_trends_regex_tag($item_xml, 'link')),
            'picture'       => esc_url_raw(seo_google_trends_regex_tag($item_xml, 'ht:picture')),
            'news'          => $news,
        );
    }
    return $rows;
}

function seo_google_trends_parse_rss($body) {
    $rows = seo_google_trends_parse_rss_simplexml($body);
    if (!$rows) {
        $rows = seo_google_trends_parse_rss_regex($body);
    }
    return $rows;
}

function seo_google_trends_fetch_rss($geo = 'ES') {
    $url = seo_google_trends_rss_url($geo);
    $response = wp_safe_remote_get($url, array(
        'timeout'             => 20,
        'redirection'         => 3,
        'limit_response_size' => 2 * MB_IN_BYTES,
        'headers'     => array(
            'Accept'          => 'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5',
            'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.6',
            'User-Agent'      => 'SEO-System-Trends/' . SEO_GOOGLE_TRENDS_VERSION . '; ' . home_url('/'),
        ),
    ));

    if (is_wp_error($response)) {
        return new WP_Error(
            'seo_google_trends_rss_connection',
            'No se pudo conectar con el RSS oficial de Google Trends: ' . $response->get_error_message()
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'seo_google_trends_rss_http',
            'Google Trends respondió, pero no permitió descargar el RSS (HTTP ' . $code . ').'
        );
    }

    $body = (string) wp_remote_retrieve_body($response);
    if (trim($body) === '') {
        return new WP_Error('seo_google_trends_rss_empty', 'Google Trends respondió con un RSS vacío.');
    }

    $rows = seo_google_trends_parse_rss($body);
    if (!$rows) {
        return new WP_Error('seo_google_trends_rss_invalid', 'Se descargó el RSS, pero no se pudieron interpretar sus tendencias.');
    }

    return array('url' => $url, 'rows' => $rows, 'bytes' => strlen($body));
}

function seo_google_trends_meta_decode($value) {
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : array();
}

function seo_google_trends_upsert_signal(array $row) {
    global $wpdb;

    seo_google_trends_maybe_install();
    $table = seo_google_trends_table();
    $seed = sanitize_text_field((string) ($row['seed'] ?? ''));
    $query = sanitize_text_field((string) ($row['query_text'] ?? ''));
    $result_type = sanitize_key((string) ($row['result_type'] ?? 'related'));
    $geo = strtoupper(sanitize_text_field((string) ($row['geo'] ?? 'ES')));
    $timeframe = sanitize_text_field((string) ($row['timeframe'] ?? ''));
    $provider = sanitize_key((string) ($row['provider'] ?? 'legacy'));

    if ($seed === '' || $query === '') {
        return false;
    }

    $meta = isset($row['signal_meta']) && is_array($row['signal_meta'])
        ? wp_json_encode($row['signal_meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string) ($row['signal_meta'] ?? '');
    $observed_at = !empty($row['observed_at'])
        ? sanitize_text_field((string) $row['observed_at'])
        : current_time('mysql');
    $imported_at = !empty($row['imported_at'])
        ? sanitize_text_field((string) $row['imported_at'])
        : current_time('mysql');

    $sql = $wpdb->prepare(
        "INSERT INTO {$table}
        (seed,seed_hash,query_text,query_hash,result_type,trend_value,is_breakout,geo,timeframe,source_note,provider,signal_meta,observed_at,imported_at)
        VALUES (%s,%s,%s,%s,%s,%f,%d,%s,%s,%s,%s,%s,%s,%s)
        ON DUPLICATE KEY UPDATE
            query_text=VALUES(query_text),
            trend_value=VALUES(trend_value),
            is_breakout=VALUES(is_breakout),
            source_note=VALUES(source_note),
            provider=VALUES(provider),
            signal_meta=VALUES(signal_meta),
            observed_at=VALUES(observed_at),
            imported_at=VALUES(imported_at)",
        $seed,
        hash('sha256', seo_google_trends_normalize($seed)),
        $query,
        hash('sha256', seo_google_trends_normalize($query)),
        $result_type,
        (float) ($row['trend_value'] ?? 0),
        !empty($row['is_breakout']) ? 1 : 0,
        $geo,
        $timeframe,
        sanitize_text_field((string) ($row['source_note'] ?? '')),
        $provider,
        $meta,
        $observed_at,
        $imported_at
    );

    return false !== $wpdb->query($sql);
}

function seo_google_trends_store_radar_rows(array $rows, array $universe, array $settings, $source_url) {
    $minimum = max(25, min(90, (int) $settings['min_relevance']));
    $candidates = array();

    foreach ($rows as $row) {
        $context = array((string) ($row['description'] ?? ''));
        foreach ((array) ($row['news'] ?? array()) as $news) {
            if (!empty($news['title'])) {
                $context[] = (string) $news['title'];
            }
        }

        $match = seo_google_trends_relevance((string) ($row['title'] ?? ''), $universe, $context);
        if ((int) ($match['score'] ?? 0) < $minimum) {
            continue;
        }

        $row['match'] = $match;
        $candidates[] = $row;
    }

    usort($candidates, static function ($left, $right) {
        $score_compare = (int) ($right['match']['score'] ?? 0) <=> (int) ($left['match']['score'] ?? 0);
        if (0 !== $score_compare) {
            return $score_compare;
        }
        return (int) ($right['traffic'] ?? 0) <=> (int) ($left['traffic'] ?? 0);
    });

    $stored = 0;
    foreach (array_slice($candidates, 0, 250) as $row) {
        $meta = array(
            'traffic_label'  => (string) ($row['traffic_label'] ?? ''),
            'traffic'        => (int) ($row['traffic'] ?? 0),
            'description'    => (string) ($row['description'] ?? ''),
            'link'           => (string) ($row['link'] ?? ''),
            'picture'        => (string) ($row['picture'] ?? ''),
            'news'           => array_values((array) ($row['news'] ?? array())),
            'relevance'      => (int) ($row['match']['score'] ?? 0),
            'matched_seeds'  => array_values((array) ($row['match']['matched_seeds'] ?? array())),
            'matched_tokens' => array_values((array) ($row['match']['matched_tokens'] ?? array())),
            'source_url'     => (string) $source_url,
        );

        $ok = seo_google_trends_upsert_signal(array(
            'seed'         => 'Radar Trends ' . $settings['geo'],
            'query_text'   => $row['title'] ?? '',
            'result_type'  => 'trending_now',
            'trend_value'  => (float) ($row['traffic'] ?? 0),
            'is_breakout'  => 0,
            'geo'          => $settings['geo'],
            'timeframe'    => 'now_24h',
            'source_note'  => 'Google Trends · Tendencias actuales (RSS oficial)',
            'provider'     => 'trending_now_rss',
            'signal_meta'  => $meta,
            'observed_at'  => $row['pub_date'] ?? current_time('mysql'),
            'imported_at'  => current_time('mysql'),
        ));
        if ($ok) {
            $stored++;
        }
    }

    return array('relevant' => count($candidates), 'stored' => $stored);
}

/**
 * Punto de extensión para la API oficial o para otro proveedor autorizado.
 * El filtro puede devolver null, WP_Error o un array con rows.
 */
function seo_google_trends_sync_market_provider(array $settings, array $universe, $force) {
    $payload = apply_filters('seo_google_trends_market_provider_sync', null, $settings, $universe, $force);
    if (null === $payload) {
        return array('status' => 'not_configured', 'stored' => 0, 'provider' => 'none');
    }
    if (is_wp_error($payload)) {
        return array(
            'status'   => 'error',
            'stored'   => 0,
            'provider' => 'external',
            'error'    => $payload->get_error_message(),
        );
    }
    if (!is_array($payload)) {
        return array('status' => 'invalid', 'stored' => 0, 'provider' => 'external');
    }

    $provider = sanitize_key((string) ($payload['provider'] ?? 'official_api'));
    $stored = 0;
    foreach ((array) ($payload['rows'] ?? array()) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['provider'] = $provider;
        $row['source_note'] = $row['source_note'] ?? 'Proveedor de mercado Google Trends';
        $row['geo'] = $row['geo'] ?? $settings['geo'];
        $row['timeframe'] = $row['timeframe'] ?? '12m';
        if (seo_google_trends_upsert_signal($row)) {
            $stored++;
        }
    }

    return array(
        'status'   => 'operational',
        'stored'   => $stored,
        'provider' => $provider,
    );
}

function seo_google_trends_last_error() {
    $error = get_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);
    return is_string($error) ? trim($error) : '';
}

function seo_google_trends_last_sync() {
    $state = get_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION, array());
    return is_array($state) ? $state : array();
}

/**
 * Actualiza el radar externo. El segundo argumento se conserva por
 * compatibilidad con llamadas anteriores, pero ya no limita semillas.
 */
function seo_google_trends_sync($force = false, $limit = 0) {
    unset($limit);
    seo_google_trends_maybe_install();

    $state = seo_google_trends_last_sync();
    if (
        !$force
        && !empty($state['timestamp'])
        && (time() - (int) $state['timestamp']) < (30 * MINUTE_IN_SECONDS)
        && 'operational' === (string) ($state['radar_status'] ?? '')
    ) {
        return $state;
    }

    if (get_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT)) {
        return new WP_Error('seo_google_trends_busy', 'Ya hay una actualización del radar de Trends en curso.');
    }
    set_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT, 1, 3 * MINUTE_IN_SECONDS);

    $settings = seo_google_trends_get_settings();
    $universe = seo_google_trends_business_universe(700);

    // Las dos fuentes se ejecutan de forma independiente. Un fallo del radar
    // RSS no debe impedir que un proveedor autorizado de Explore actualice sus
    // datos, y un fallo del proveedor Explore no invalida el radar.
    $market_provider = seo_google_trends_sync_market_provider($settings, $universe, $force);
    $rss = seo_google_trends_fetch_rss($settings['geo']);

    if (is_wp_error($rss)) {
        delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);
        $message = $rss->get_error_message();
        set_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT, $message, DAY_IN_SECONDS);
        $failed_state = array(
            'timestamp'              => time(),
            'synced_at'              => current_time('mysql'),
            'radar_status'           => 'error',
            'provider'               => 'trending_now_rss',
            'fetched'                => 0,
            'relevant'               => 0,
            'signals'                => 0,
            'universe_terms'         => count($universe),
            'geo'                    => $settings['geo'],
            'market_provider_status' => (string) ($market_provider['status'] ?? 'not_configured'),
            'market_provider'        => (string) ($market_provider['provider'] ?? 'none'),
            'market_signals'         => (int) ($market_provider['stored'] ?? 0),
            'error'                  => $message,
        );
        if (!empty($market_provider['error'])) {
            $failed_state['market_provider_error'] = (string) $market_provider['error'];
        }
        update_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION, $failed_state, false);
        return $rss;
    }

    $stored = seo_google_trends_store_radar_rows(
        (array) $rss['rows'],
        $universe,
        $settings,
        (string) $rss['url']
    );

    global $wpdb;
    $table = seo_google_trends_table();
    $cutoff = wp_date('Y-m-d H:i:s', time() - (8 * DAY_IN_SECONDS), wp_timezone());
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE provider = %s
               AND COALESCE(observed_at, imported_at) < %s",
            'trending_now_rss',
            $cutoff
        )
    );

    delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);
    delete_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);

    $state = array(
        'timestamp'              => time(),
        'synced_at'              => current_time('mysql'),
        'radar_status'           => 'operational',
        'provider'               => 'trending_now_rss',
        'fetched'                => count((array) $rss['rows']),
        'relevant'               => (int) $stored['relevant'],
        'signals'                => (int) $stored['stored'],
        'universe_terms'         => count($universe),
        'geo'                    => $settings['geo'],
        'source_bytes'           => (int) ($rss['bytes'] ?? 0),
        'market_provider_status' => (string) ($market_provider['status'] ?? 'not_configured'),
        'market_provider'        => (string) ($market_provider['provider'] ?? 'none'),
        'market_signals'         => (int) ($market_provider['stored'] ?? 0),
    );
    if (!empty($market_provider['error'])) {
        $state['market_provider_error'] = (string) $market_provider['error'];
    }
    update_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION, $state, false);

    return $state;
}

function seo_google_trends_sync_handler() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para actualizar Google Trends.', 'seo-system'));
    }
    check_admin_referer('seo_google_trends_sync', 'seo_google_trends_sync_nonce');

    $result = seo_google_trends_sync(true, 0);
    $args = is_wp_error($result)
        ? array('trends_error' => 'sync', 'detail' => $result->get_error_message())
        : array(
            'trends_notice' => 'synced',
            'rows'          => (int) ($result['signals'] ?? 0),
            'fetched'       => (int) ($result['fetched'] ?? 0),
            'relevant'      => (int) ($result['relevant'] ?? 0),
        );

    wp_safe_redirect(seo_google_admin_url('trends_market', $args));
    exit;
}

function seo_google_trends_detect_columns($header) {
    $map = array();
    foreach ((array) $header as $index => $heading) {
        $normalized = seo_google_trends_normalize($heading);
        if (preg_match('/^(query|queries|consulta|consultas|related query|related queries|busqueda relacionada|busquedas relacionadas|termino|term)$/', $normalized)) {
            $map['query'] = $index;
        } elseif (preg_match('/^(value|valor|growth|crecimiento|interest|interes|searches|busquedas|volumen)$/', $normalized)) {
            $map['value'] = $index;
        } elseif (preg_match('/^(type|tipo)$/', $normalized)) {
            $map['type'] = $index;
        } elseif (preg_match('/^(seed|semilla|topic|tema)$/', $normalized)) {
            $map['seed'] = $index;
        }
    }
    return $map;
}

function seo_google_trends_parse_value($raw) {
    if (is_int($raw) || is_float($raw)) {
        return array((float) $raw, false);
    }

    $value = trim((string) $raw);
    if ($value === '') {
        return array(0.0, false);
    }

    $normalized = seo_google_trends_normalize($value);
    if (false !== strpos($normalized, 'breakout') || false !== strpos($normalized, 'aumento puntual')) {
        return array(5000.0, true);
    }

    $numeric = preg_replace('/[^0-9,.-]+/', '', $value);
    if ($numeric === '') {
        return array(0.0, false);
    }
    if (preg_match('/^-?\d{1,3}(?:[.,]\d{3})+$/', $numeric)) {
        $numeric = str_replace(array('.', ','), '', $numeric);
    } elseif (false !== strpos($numeric, ',') && false === strpos($numeric, '.')) {
        $numeric = str_replace(',', '.', $numeric);
    } elseif (false !== strpos($numeric, ',') && false !== strpos($numeric, '.')) {
        $last_comma = strrpos($numeric, ',');
        $last_dot = strrpos($numeric, '.');
        $numeric = $last_comma > $last_dot
            ? str_replace(array('.', ','), array('', '.'), $numeric)
            : str_replace(',', '', $numeric);
    }

    return array((float) $numeric, false);
}

/**
 * Detecta el separador aunque el CSV empiece con líneas de metadatos sin
 * columnas. Google Trends puede exportar coma o punto y coma según el idioma.
 */
function seo_google_trends_detect_delimiter($handle) {
    if (!is_resource($handle)) {
        return ',';
    }

    $position = ftell($handle);
    if (false === $position) {
        $position = 0;
    }
    rewind($handle);

    $delimiters = array(',', ';', "\t");
    $scores = array(',' => 0, ';' => 0, "\t" => 0);
    $lines = array();
    for ($line = 0; $line < 20; $line++) {
        $raw = fgets($handle);
        if (false === $raw) {
            break;
        }
        if (trim($raw) !== '') {
            $lines[] = $raw;
        }
    }

    foreach ($delimiters as $delimiter) {
        foreach ($lines as $raw) {
            $fields = str_getcsv($raw, $delimiter, '"', '\\');
            $field_count = count($fields);
            if ($field_count > 1) {
                $scores[$delimiter] += min(12, $field_count * 2);
            }
            $columns = seo_google_trends_detect_columns($fields);
            if (isset($columns['query'])) {
                $scores[$delimiter] += 100;
            }
            if (isset($columns['value'])) {
                $scores[$delimiter] += 25;
            }
        }
    }

    fseek($handle, $position);
    arsort($scores, SORT_NUMERIC);
    $winner = (string) key($scores);
    return in_array($winner, $delimiters, true) ? $winner : ',';
}

function seo_google_trends_import_handler() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para importar Google Trends.', 'seo-system'));
    }
    check_admin_referer('seo_google_trends_import', 'seo_google_trends_nonce');
    seo_google_trends_maybe_install();

    $seed = sanitize_text_field(wp_unslash($_POST['seed'] ?? ''));
    $type = sanitize_key(wp_unslash($_POST['result_type'] ?? 'rising'));
    if (!in_array($type, array('rising', 'top', 'related'), true)) {
        $type = 'related';
    }
    $geo = strtoupper(sanitize_text_field(wp_unslash($_POST['geo'] ?? 'ES')));
    $timeframe = sanitize_text_field(wp_unslash($_POST['timeframe'] ?? '12m'));

    if ($seed === '' || empty($_FILES['trends_csv']['tmp_name'])) {
        wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_error' => 'missing')));
        exit;
    }
    if (!empty($_FILES['trends_csv']['size']) && (int) $_FILES['trends_csv']['size'] > 5 * MB_IN_BYTES) {
        wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_error' => 'size')));
        exit;
    }

    $handle = fopen($_FILES['trends_csv']['tmp_name'], 'r');
    if (!$handle) {
        wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_error' => 'file')));
        exit;
    }

    $delimiter = seo_google_trends_detect_delimiter($handle);
    rewind($handle);
    $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
    $columns = $header ? seo_google_trends_detect_columns($header) : array();

    if (!isset($columns['query'])) {
        for ($line = 0; $line < 12; $line++) {
            $candidate = fgetcsv($handle, 0, $delimiter, '"', '\\');
            if (!$candidate) {
                break;
            }
            $candidate_columns = seo_google_trends_detect_columns($candidate);
            if (isset($candidate_columns['query'])) {
                $columns = $candidate_columns;
                break;
            }
        }
    }

    if (!isset($columns['query'])) {
        fclose($handle);
        wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_error' => 'columns')));
        exit;
    }

    $count = 0;
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $query = sanitize_text_field($row[$columns['query']] ?? '');
        if ($query === '') {
            continue;
        }
        $row_seed = isset($columns['seed'])
            ? sanitize_text_field($row[$columns['seed']] ?? '')
            : $seed;
        if ($row_seed === '') {
            $row_seed = $seed;
        }
        $row_type = isset($columns['type'])
            ? sanitize_key($row[$columns['type']] ?? $type)
            : $type;
        if (!in_array($row_type, array('rising', 'top', 'related'), true)) {
            $row_type = $type;
        }
        list($value, $breakout) = seo_google_trends_parse_value(
            isset($columns['value']) ? $row[$columns['value']] : 0
        );

        if (seo_google_trends_upsert_signal(array(
            'seed'         => $row_seed,
            'query_text'   => $query,
            'result_type'  => $row_type,
            'trend_value'  => $value,
            'is_breakout'  => $breakout ? 1 : 0,
            'geo'          => $geo,
            'timeframe'    => $timeframe,
            'source_note'  => 'CSV exportado desde Google Trends',
            'provider'     => 'csv',
            'signal_meta'  => array('file' => sanitize_file_name($_FILES['trends_csv']['name'] ?? 'trends.csv')),
            'observed_at'  => current_time('mysql'),
            'imported_at'  => current_time('mysql'),
        ))) {
            $count++;
        }
    }
    fclose($handle);

    wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_notice' => 'imported', 'rows' => $count)));
    exit;
}

function seo_google_trends_clear_handler() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para borrar Google Trends.', 'seo-system'));
    }
    check_admin_referer('seo_google_trends_clear', 'seo_google_trends_clear_nonce');

    global $wpdb;
    seo_google_trends_maybe_install();
    $wpdb->query('TRUNCATE TABLE ' . seo_google_trends_table());
    delete_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION);
    delete_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);
    delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);

    wp_safe_redirect(seo_google_admin_url('trends_market', array('trends_notice' => 'cleared')));
    exit;
}

function seo_google_trends_get_signals($limit = 500) {
    global $wpdb;
    seo_google_trends_maybe_install();
    $table = seo_google_trends_table();
    $limit = max(1, min(5000, absint($limit)));

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE provider <> 'legacy_web'
             ORDER BY
                CASE WHEN provider = 'trending_now_rss' THEN 0 ELSE 1 END,
                COALESCE(observed_at, imported_at) DESC,
                is_breakout DESC,
                trend_value DESC
             LIMIT %d",
            $limit
        ),
        ARRAY_A
    );
}

function seo_google_trends_provider_counts() {
    global $wpdb;
    seo_google_trends_maybe_install();
    $table = seo_google_trends_table();
    $rows = $wpdb->get_results(
        "SELECT provider, COUNT(*) AS total, MAX(imported_at) AS latest
         FROM {$table}
         GROUP BY provider",
        ARRAY_A
    );
    $out = array();
    foreach ((array) $rows as $row) {
        $out[(string) $row['provider']] = array(
            'total'  => (int) $row['total'],
            'latest' => (string) $row['latest'],
        );
    }
    return $out;
}

function seo_google_trends_provider_status() {
    $state = seo_google_trends_last_sync();
    $error = seo_google_trends_last_error();
    $counts = seo_google_trends_provider_counts();
    $rss_count = (int) ($counts['trending_now_rss']['total'] ?? 0);
    $market_count = 0;
    foreach ($counts as $provider => $row) {
        if (!in_array($provider, array('trending_now_rss', 'legacy_web'), true)) {
            $market_count += (int) $row['total'];
        }
    }

    $radar_status = (string) ($state['radar_status'] ?? 'not_synced');
    $radar_connected = 'operational' === $radar_status;
    if ($radar_connected) {
        $radar_detail = sprintf(
            'Descarga correcta: %s tendencias recibidas; %s relacionadas con el negocio; %s señales vigentes.',
            number_format_i18n((int) ($state['fetched'] ?? 0)),
            number_format_i18n((int) ($state['relevant'] ?? 0)),
            number_format_i18n($rss_count)
        );
    } elseif ('error' === $radar_status || $error !== '') {
        $radar_detail = 'Error de conexión/descarga: ' . ($error !== '' ? $error : (string) ($state['error'] ?? 'error desconocido'));
    } else {
        $radar_detail = 'Todavía no se ha probado la descarga del RSS oficial.';
    }

    $market_connected = $market_count > 0;
    $market_status = (string) ($state['market_provider_status'] ?? 'not_configured');
    $market_error = sanitize_text_field((string) ($state['market_provider_error'] ?? ''));
    if ('error' === $market_status && $market_error !== '') {
        $market_detail = 'El proveedor de Explore devolvió un error: ' . $market_error;
        if ($market_connected) {
            $market_detail .= ' Se conservan ' . number_format_i18n($market_count) . ' señales almacenadas anteriormente.';
        }
    } else {
        $market_detail = $market_connected
            ? number_format_i18n($market_count) . ' señales de mercado almacenadas mediante CSV o proveedor autorizado.'
            : 'Sin datos de Explore: importa CSV o conecta un adaptador para la API oficial cuando esté disponible para tu cuenta.';
    }

    return array(
        'radar' => array(
            'connected' => $radar_connected,
            'status'    => $radar_status,
            'detail'    => $radar_detail,
            'rows'      => $rss_count,
            'last_sync' => (string) ($state['synced_at'] ?? ''),
        ),
        'market' => array(
            'connected' => $market_connected,
            'status'    => $market_connected ? 'data_available' : 'not_configured',
            'detail'    => $market_detail,
            'rows'      => $market_count,
        ),
        'overall' => array(
            'connected' => $radar_connected || $market_connected,
            'detail'    => $radar_detail . ' ' . $market_detail,
        ),
        'providers' => $counts,
        'state'     => $state,
    );
}

/**
 * Alias estable para paneles que necesiten distinguir descarga y datos.
 */
function seo_google_trends_source_status() {
    $status = seo_google_trends_provider_status();
    return array(
        'connected' => !empty($status['overall']['connected']),
        'detail'    => (string) ($status['overall']['detail'] ?? ''),
        'radar'     => (array) ($status['radar'] ?? array()),
        'explore'   => (array) ($status['market'] ?? array()),
        'providers' => (array) ($status['providers'] ?? array()),
        'state'     => (array) ($status['state'] ?? array()),
    );
}

function seo_google_trends_market_summary($limit = 250) {
    $rows = seo_google_trends_get_signals(max(1, min(5000, absint($limit))));
    $out = array();
    $now = time();

    foreach ($rows as $row) {
        $key = seo_google_trends_normalize($row['query_text'] ?? '');
        if ($key === '') {
            continue;
        }

        $provider = (string) ($row['provider'] ?? 'legacy');
        $meta = seo_google_trends_meta_decode($row['signal_meta'] ?? '');
        $growth = (float) ($row['trend_value'] ?? 0);
        $is_radar = 'trending_now_rss' === $provider || 'trending_now' === (string) ($row['result_type'] ?? '');
        $observed = (string) ($row['observed_at'] ?? $row['imported_at'] ?? '');
        $age_hours = $observed ? max(0, ($now - (int) strtotime($observed)) / HOUR_IN_SECONDS) : 999;

        if ($is_radar) {
            $relevance = (float) ($meta['relevance'] ?? 0);
            $traffic = (float) ($meta['traffic'] ?? $growth);
            $traffic_score = $traffic > 0 ? min(100, 18 + log(1 + $traffic) * 7.2) : 20;
            $recency_score = max(0, 100 - min(100, $age_hours * 3));
            $score = ($relevance * 0.66) + ($traffic_score * 0.22) + ($recency_score * 0.12);
            $signal_kind = 'emerging';
            $seeds = array_values((array) ($meta['matched_seeds'] ?? array()));
        } else {
            $score = !empty($row['is_breakout'])
                ? 100
                : min(100, 20 + log(1 + max(0, $growth)) * 12);
            $signal_kind = 'market';
            $seeds = array((string) ($row['seed'] ?? ''));
            $relevance = 0;
            $traffic = 0;
        }

        if (!isset($out[$key])) {
            $out[$key] = array(
                'query'           => (string) $row['query_text'],
                'score'           => 0,
                'max_growth'      => 0,
                'breakout'        => false,
                'seeds'           => array(),
                'types'           => array(),
                'providers'       => array(),
                'signal_kind'     => $signal_kind,
                'relevance_score' => 0,
                'traffic'         => 0,
                'traffic_label'   => '',
                'observed_at'     => $observed,
                'source_note'     => (string) ($row['source_note'] ?? ''),
                'meta'            => array(),
            );
        }

        $out[$key]['score'] = max((float) $out[$key]['score'], round($score, 2));
        $out[$key]['max_growth'] = max((float) $out[$key]['max_growth'], $is_radar ? 0 : $growth);
        $out[$key]['breakout'] = !empty($out[$key]['breakout']) || !empty($row['is_breakout']);
        $out[$key]['relevance_score'] = max((float) $out[$key]['relevance_score'], $relevance);
        $out[$key]['traffic'] = max((float) $out[$key]['traffic'], $traffic);
        if (!empty($meta['traffic_label'])) {
            $out[$key]['traffic_label'] = (string) $meta['traffic_label'];
        }
        if ($is_radar) {
            $out[$key]['signal_kind'] = 'emerging';
        }
        if ($observed && (!$out[$key]['observed_at'] || strtotime($observed) > strtotime($out[$key]['observed_at']))) {
            $out[$key]['observed_at'] = $observed;
        }
        $out[$key]['seeds'] = array_merge($out[$key]['seeds'], array_filter($seeds));
        $out[$key]['types'][] = (string) ($row['result_type'] ?? 'related');
        $out[$key]['providers'][] = $provider;
        if ($meta) {
            $out[$key]['meta'] = $meta;
        }
    }

    foreach ($out as &$row) {
        $row['seeds'] = array_values(array_unique($row['seeds']));
        $row['types'] = array_values(array_unique($row['types']));
        $row['providers'] = array_values(array_unique($row['providers']));
    }
    unset($row);

    uasort($out, static function ($left, $right) {
        if ((float) $left['score'] === (float) $right['score']) {
            return strcmp((string) $right['observed_at'], (string) $left['observed_at']);
        }
        return (float) $right['score'] <=> (float) $left['score'];
    });

    return array_slice(array_values($out), 0, max(1, min(5000, absint($limit))));
}

function seo_google_trends_status_badge($connected, $label) {
    $background = $connected ? '#edfaef' : '#fcf0f1';
    $color = $connected ? '#116329' : '#8a2424';
    return '<span style="display:inline-block;padding:4px 9px;border-radius:999px;background:' . esc_attr($background) . ';color:' . esc_attr($color) . ';font-weight:700;font-size:12px;">' . esc_html($label) . '</span>';
}

function seo_google_render_trends_market() {
    seo_google_trends_maybe_install();
    $settings = seo_google_trends_get_settings();
    $status = seo_google_trends_provider_status();
    $universe = seo_google_trends_business_universe(60);
    $signals = seo_google_trends_get_signals(180);

    echo '<style>
    .seo-trends-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin:14px 0}.seo-trends-card{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:16px}.seo-trends-card h3{margin-top:0}.seo-trends-muted{color:#646970;font-size:12px;line-height:1.5}.seo-trends-seed{display:inline-block;padding:4px 8px;margin:3px;border:1px solid #dcdcde;border-radius:999px;background:#f6f7f7;font-size:12px}.seo-trends-source{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef4fb;color:#135e96;font-size:11px;font-weight:700}
    </style>';

    echo '<div class="seo-trends-card">';
    echo '<h2 style="margin:0 0 6px;">Mercado Google · Trends</h2>';
    echo '<p style="margin:0;max-width:1000px;"><strong>Objetivo:</strong> descubrir demanda externa y temas emergentes del entorno comercial. El catálogo, las categorías y los hubs solo delimitan el negocio; las páginas, landings y posts se consultan después para medir cobertura y decidir qué mejorar.</p>';
    echo '<p class="seo-trends-muted"><code>V' . esc_html(SEO_GOOGLE_TRENDS_VERSION) . '</code> · Search Console y Analytics mantienen sus conexiones independientes. Esta versión no llama a endpoints internos no documentados de Trends.</p>';

    $notice = sanitize_key(wp_unslash($_GET['trends_notice'] ?? ''));
    if ('synced' === $notice) {
        echo '<div class="notice notice-success inline"><p>Radar actualizado: ' . number_format_i18n(absint($_GET['fetched'] ?? 0)) . ' tendencias recibidas, ' . number_format_i18n(absint($_GET['relevant'] ?? 0)) . ' relacionadas y ' . number_format_i18n(absint($_GET['rows'] ?? 0)) . ' guardadas/actualizadas.</p></div>';
    } elseif ('imported' === $notice) {
        echo '<div class="notice notice-success inline"><p>CSV importado: ' . number_format_i18n(absint($_GET['rows'] ?? 0)) . ' señales procesadas.</p></div>';
    } elseif ('cleared' === $notice) {
        echo '<div class="notice notice-success inline"><p>Se han eliminado las señales almacenadas de Trends.</p></div>';
    } elseif ('settings_saved' === $notice) {
        echo '<div class="notice notice-success inline"><p>Configuración del radar guardada.</p></div>';
    }
    if (!empty($_GET['trends_error'])) {
        $detail = sanitize_text_field(wp_unslash($_GET['detail'] ?? 'No se pudo completar la operación.'));
        echo '<div class="notice notice-error inline"><p>' . esc_html($detail) . '</p></div>';
    }
    echo '</div>';

    echo '<div class="seo-trends-grid">';
    echo '<div class="seo-trends-card"><h3>Radar emergente</h3>';
    $radar_badge = $status['radar']['connected']
        ? 'OPERATIVO'
        : ('error' === (string) ($status['radar']['status'] ?? '') ? 'ERROR DE DESCARGA' : 'PENDIENTE DE PRUEBA');
    echo seo_google_trends_status_badge($status['radar']['connected'], $radar_badge);
    echo '<p>' . esc_html($status['radar']['detail']) . '</p>';
    if (!empty($status['radar']['last_sync'])) {
        echo '<p class="seo-trends-muted">Última prueba: ' . esc_html($status['radar']['last_sync']) . '</p>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_google_trends_sync">';
    wp_nonce_field('seo_google_trends_sync', 'seo_google_trends_sync_nonce');
    submit_button('Actualizar radar externo', 'primary', 'submit', false);
    echo '</form></div>';

    echo '<div class="seo-trends-card"><h3>Exploración de mercado</h3>';
    echo seo_google_trends_status_badge($status['market']['connected'], $status['market']['connected'] ? 'DATOS DISPONIBLES' : 'SIN DATOS DE EXPLORE');
    echo '<p>' . esc_html($status['market']['detail']) . '</p>';
    echo '<p class="seo-trends-muted">Aquí se guardan búsquedas relacionadas, principales y en aumento. El CSV sigue siendo válido; un adaptador para la API oficial puede conectarse sin cambiar el motor de oportunidades.</p></div>';

    $gsc_connected = function_exists('seo_google_connection_status') && 'connected' === seo_google_connection_status();
    echo '<div class="seo-trends-card"><h3>Search Console</h3>';
    echo seo_google_trends_status_badge($gsc_connected, $gsc_connected ? 'CONECTADO' : 'REVISAR');
    echo '<p>Se usa para saber dónde ya aparece vuestra web y con qué consultas. No alimenta el radar ni sustituye la demanda externa.</p>';
    if (function_exists('seo_google_admin_url')) {
        echo '<a class="button" href="' . esc_url(seo_google_admin_url('sync')) . '">Ver sincronización</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="seo-trends-card"><h3>Perímetro comercial del radar</h3>';
    echo '<p>Se genera con áreas manuales, clusters, hubs, categorías y atributos de producto. <strong>No se escanean landings ni posts para decidir qué busca el mercado.</strong></p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_google_trends_save_settings">';
    wp_nonce_field('seo_google_trends_save_settings', 'seo_google_trends_settings_nonce');
    echo '<table class="form-table"><tr><th><label for="seo-trends-manual-seeds">Áreas adicionales</label></th><td><textarea id="seo-trends-manual-seeds" name="manual_seeds" rows="5" class="large-text" placeholder="Ej.: herramientas de taller&#10;equipamiento para camión&#10;seguridad vial">' . esc_textarea($settings['manual_seeds']) . '</textarea><p class="description">Una por línea. Úsalas para áreas estratégicas que todavía no estén bien representadas en el catálogo.</p></td></tr>';
    echo '<tr><th><label for="seo-trends-geo">País</label></th><td><input id="seo-trends-geo" name="geo" value="' . esc_attr($settings['geo']) . '" size="6" maxlength="2"></td></tr>';
    echo '<tr><th><label for="seo-trends-relevance">Relevancia mínima</label></th><td><input id="seo-trends-relevance" type="number" name="min_relevance" min="25" max="90" value="' . esc_attr((string) $settings['min_relevance']) . '"> / 100 <p class="description">Bájala si el radar omite temas próximos; súbela si entra demasiado ruido.</p></td></tr>';
    echo '<tr><th>Actualización automática</th><td><label><input type="checkbox" name="auto_sync" value="1" ' . checked(!empty($settings['auto_sync']), true, false) . '> Actualizar cada hora mediante WP-Cron</label></td></tr></table>';
    submit_button('Guardar configuración', 'secondary');
    echo '</form><div style="margin-top:10px;">';
    foreach (array_slice($universe, 0, 40) as $item) {
        echo '<span class="seo-trends-seed" title="' . esc_attr($item['source']) . '">' . esc_html($item['label']) . '</span>';
    }
    if (count($universe) > 40) {
        echo '<span class="seo-trends-muted"> +' . number_format_i18n(count($universe) - 40) . ' áreas</span>';
    }
    echo '</div></div>';

    echo '<div class="seo-trends-card"><h3>Importación de Explore · respaldo y análisis dirigido</h3>';
    echo '<p>Exporta desde Google Trends las búsquedas <strong>En aumento</strong>, <strong>Principales</strong> o <strong>Relacionadas</strong> de un área comercial e impórtalas aquí. Estos datos complementan el radar de actualidad.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_google_trends_import">';
    wp_nonce_field('seo_google_trends_import', 'seo_google_trends_nonce');
    echo '<table class="form-table"><tr><th>Semilla analizada</th><td><input required name="seed" class="regular-text" placeholder="Ej.: neveras para camión"></td></tr>';
    echo '<tr><th>Tipo</th><td><select name="result_type"><option value="rising">En aumento</option><option value="top">Principales</option><option value="related">Relacionadas</option></select></td></tr>';
    echo '<tr><th>País</th><td><input name="geo" value="' . esc_attr($settings['geo']) . '" size="6"></td></tr>';
    echo '<tr><th>Periodo</th><td><input name="timeframe" value="12m" size="12"></td></tr>';
    echo '<tr><th>CSV</th><td><input required type="file" name="trends_csv" accept=".csv,text/csv"></td></tr></table>';
    submit_button('Importar CSV de Trends');
    echo '</form></div>';

    echo '<div class="seo-trends-card" style="overflow:auto;"><h3>Señales externas almacenadas</h3>';
    if (!$signals) {
        echo '<p>Todavía no hay señales almacenadas. Actualiza el radar o importa un CSV de Explore.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Señal</th><th>Fuente</th><th>Área relacionada</th><th>Indicador</th><th>Observada</th></tr></thead><tbody>';
        foreach ($signals as $row) {
            $meta = seo_google_trends_meta_decode($row['signal_meta'] ?? '');
            $is_radar = 'trending_now_rss' === (string) ($row['provider'] ?? '');
            $matched = $is_radar ? implode(', ', array_slice((array) ($meta['matched_seeds'] ?? array()), 0, 3)) : (string) $row['seed'];
            $indicator = $is_radar
                ? ((string) ($meta['traffic_label'] ?? '') !== '' ? (string) $meta['traffic_label'] : number_format_i18n((float) $row['trend_value'], 0)) . ' · relevancia ' . absint($meta['relevance'] ?? 0) . '/100'
                : (!empty($row['is_breakout']) ? 'BREAKOUT' : number_format_i18n((float) $row['trend_value'], 0) . ('rising' === $row['result_type'] ? '%' : ''));
            echo '<tr><td><strong>' . esc_html($row['query_text']) . '</strong><br><span class="seo-trends-muted">' . esc_html($row['result_type']) . '</span></td>';
            echo '<td><span class="seo-trends-source">' . esc_html($is_radar ? 'Trending Now RSS' : strtoupper((string) $row['provider'])) . '</span></td>';
            echo '<td>' . esc_html($matched !== '' ? $matched : 'Sin área') . '</td>';
            echo '<td>' . esc_html($indicator) . '</td>';
            echo '<td>' . esc_html((string) ($row['observed_at'] ?: $row['imported_at'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px;" onsubmit="return confirm(\'¿Borrar todas las señales almacenadas de Trends?\');"><input type="hidden" name="action" value="seo_google_trends_clear">';
    wp_nonce_field('seo_google_trends_clear', 'seo_google_trends_clear_nonce');
    submit_button('Vaciar datos de Trends', 'delete', 'submit', false);
    echo '</form></div>';
}
