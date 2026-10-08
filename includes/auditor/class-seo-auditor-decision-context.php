<?php

defined('ABSPATH') || exit;

/**
 * Contexto de decisión para Auditor.
 *
 * Contrato:
 * - Auditor conserva la responsabilidad sobre calidad/coherencia interna.
 * - Esta clase NO consulta APIs externas.
 * - Sólo lee datos ya persistidos o APIs internas de lectura de otros servicios.
 * - Un dato ausente se representa con null/state=unavailable; nunca se inventa 0.
 */
final class SEO_Auditor_Decision_Context {
    const SCHEMA_VERSION = 1;
    const ANALISTA_DAYS = 28;
    const DEPENDIENTE_DAYS = 30;
    const ANALISTA_STALE_DAYS = 7;

    /**
     * Construye el contexto global y los mapas internos necesarios para
     * enriquecer tareas. Los mapas internos se eliminan antes de exportar.
     */
    public static function build($scope = '') {
        $analista = self::analista_snapshot();
        $ojeador = self::ojeador_snapshot();
        $dependiente = self::dependiente_snapshot();
        $comparador = self::comparador_snapshot();
        $suppliers = self::supplier_snapshot();

        return array(
            'schema' => array(
                'name' => 'seo_auditor_decision_context',
                'version' => self::SCHEMA_VERSION,
            ),
            'generated_at' => current_time('mysql'),
            'scope' => sanitize_key((string) $scope),
            'policy' => array(
                'auditor_role' => 'internal_quality_and_consistency',
                'analista_role' => 'demand_traffic_funnel_and_priority',
                'ojeador_role' => 'persisted_market_observation',
                'dependiente_role' => 'persisted_customer_search_behavior',
                'comparador_role' => 'persisted_comparison_coverage',
                'solucionador_role' => 'final_editorial_decision',
                'no_external_api_calls' => true,
                'zero_is_not_missing' => true,
                'missing_value' => null,
                'allowed_states' => array('fresh','partial','stale','unavailable'),
            ),
            'sources' => array(
                'analista' => self::public_source($analista),
                'ojeador' => self::public_source($ojeador),
                'dependiente' => self::public_source($dependiente),
                'comparador' => self::public_source($comparador),
                'suppliers' => self::public_source($suppliers),
            ),
            '_maps' => array(
                'analista_entities' => (array) ($analista['_entity_map'] ?? array()),
                'ojeador_categories' => (array) ($ojeador['_category_map'] ?? array()),
                'dependiente_products' => (array) ($dependiente['_product_map'] ?? array()),
                'dependiente_categories' => (array) ($dependiente['_category_map'] ?? array()),
                'comparador_categories' => (array) ($comparador['_category_map'] ?? array()),
            ),
        );
    }

    public static function public_context($bundle) {
        $bundle = is_array($bundle) ? $bundle : array();
        unset($bundle['_maps']);
        return $bundle;
    }

    /**
     * Adjunta contexto a cada tarea y usa exclusivamente la prioridad ya
     * calculada por Analista para ordenar mejor tareas dentro de la misma P.
     *
     * Nunca cambia P1/P2/P3/P4/P5 ni action_class.
     */
    public static function enrich_tasks($tasks, $bundle) {
        $tasks = array_values((array) $tasks);
        $bundle = is_array($bundle) ? $bundle : array();
        $maps = (array) ($bundle['_maps'] ?? array());
        $sources = (array) ($bundle['sources'] ?? array());

        foreach ($tasks as &$task) {
            if (!is_array($task)) {
                continue;
            }

            $type = sanitize_key((string) ($task['entity_type'] ?? ''));
            $id = absint($task['entity_id'] ?? 0);
            $entity_key = self::entity_key($type, $id);
            $category_ids = self::category_ids_for_entity($type, $id);

            $context = array(
                'entity' => array(
                    'type' => $type,
                    'id' => $id ?: null,
                    'category_ids' => $category_ids,
                ),
                'analista' => null,
                'ojeador' => null,
                'dependiente' => null,
                'comparador' => null,
                'suppliers' => self::source_reference($sources['suppliers'] ?? array()),
            );

            $analista_map = (array) ($maps['analista_entities'] ?? array());
            if ($entity_key !== '' && isset($analista_map[$entity_key])) {
                $context['analista'] = $analista_map[$entity_key];
            } else {
                $context['analista'] = self::source_reference($sources['analista'] ?? array());
            }

            $ojeador_rows = array();
            $ojeador_map = (array) ($maps['ojeador_categories'] ?? array());
            foreach ($category_ids as $term_id) {
                if (isset($ojeador_map[$term_id])) {
                    $ojeador_rows[] = $ojeador_map[$term_id];
                }
            }
            if ($ojeador_rows) {
                $context['ojeador'] = self::aggregate_category_rows($ojeador_rows, 'ojeador');
            } else {
                $context['ojeador'] = self::source_reference($sources['ojeador'] ?? array());
            }

            $comparador_rows = array();
            $comparador_map = (array) ($maps['comparador_categories'] ?? array());
            foreach ($category_ids as $term_id) {
                if (!empty($comparador_map[$term_id])) {
                    foreach ((array) $comparador_map[$term_id] as $row) {
                        $comparador_rows[] = $row;
                    }
                }
            }
            if ($comparador_rows) {
                $context['comparador'] = self::aggregate_category_rows($comparador_rows, 'comparador');
            } else {
                $context['comparador'] = self::source_reference($sources['comparador'] ?? array());
            }

            $dependiente_product_map = (array) ($maps['dependiente_products'] ?? array());
            $dependiente_category_map = (array) ($maps['dependiente_categories'] ?? array());
            if ('product' === $type && $id && isset($dependiente_product_map[$id])) {
                $context['dependiente'] = $dependiente_product_map[$id];
            } else {
                $dep_rows = array();
                foreach ($category_ids as $term_id) {
                    if (isset($dependiente_category_map[$term_id])) {
                        $dep_rows[] = $dependiente_category_map[$term_id];
                    }
                }
                $context['dependiente'] = $dep_rows
                    ? self::aggregate_category_rows($dep_rows, 'dependiente')
                    : self::source_reference($sources['dependiente'] ?? array());
            }

            $task['contexto_de_decision'] = $context;
            $task['priority_score_base'] = (int) ($task['priority_score'] ?? 0);

            $analista_priority = null;
            $analista_state = sanitize_key((string) ($context['analista']['state'] ?? 'unavailable'));
            if (
                is_array($context['analista'])
                && array_key_exists('priority', $context['analista'])
                && null !== $context['analista']['priority']
                && is_numeric($context['analista']['priority'])
            ) {
                $analista_priority = max(0, min(100, (int) $context['analista']['priority']));
            }

            $boost = 0;
            if (null !== $analista_priority && in_array($analista_state, array('fresh','partial'), true)) {
                $factor = 'partial' === $analista_state ? 0.10 : 0.20;
                $boost = (int) round($analista_priority * $factor);
            }

            $task['context_priority'] = array(
                'source' => null !== $analista_priority ? 'analista' : null,
                'analista_priority' => $analista_priority,
                'source_state' => $analista_state ?: 'unavailable',
                'boost' => $boost,
                'rule' => 'Sólo ordena dentro de la prioridad P existente; no cambia la clase de Auditor.',
            );
            $task['priority_score'] = (int) $task['priority_score_base'] + $boost;
        }
        unset($task);

        usort($tasks, static function($a, $b) {
            $as = (int) ($a['priority_score'] ?? 0);
            $bs = (int) ($b['priority_score'] ?? 0);
            if ($as !== $bs) {
                return $bs <=> $as;
            }
            return strcmp((string) ($a['task_id'] ?? ''), (string) ($b['task_id'] ?? ''));
        });

        return $tasks;
    }

    private static function public_source($source) {
        $source = is_array($source) ? $source : array();
        foreach (array_keys($source) as $key) {
            if (0 === strpos((string) $key, '_')) {
                unset($source[$key]);
            }
        }
        return $source;
    }

    private static function source_reference($source) {
        $source = is_array($source) ? $source : array();
        return array(
            'state' => sanitize_key((string) ($source['state'] ?? 'unavailable')) ?: 'unavailable',
            'available' => !empty($source['available']),
            'detail' => (string) ($source['detail'] ?? ''),
        );
    }

    private static function entity_key($type, $id) {
        $type = self::normalize_entity_type($type);
        $id = absint($id);
        return ($type !== '' && $id > 0) ? $type . '|' . $id : '';
    }

    private static function normalize_entity_type($type) {
        $type = sanitize_key((string) $type);
        $map = array(
            'product_cat' => 'category',
            'landing' => 'page',
            'hub_secondary' => 'page',
            'hub_primary' => 'page',
            'cluster' => 'page',
        );
        return $map[$type] ?? $type;
    }

    private static function category_ids_for_entity($type, $id) {
        global $wpdb;

        $type = self::normalize_entity_type($type);
        $id = absint($id);
        if (!$id) {
            return array();
        }
        if ('category' === $type) {
            return array($id);
        }
        if ('product' === $type) {
            $ids = wp_get_post_terms($id, 'product_cat', array('fields'=>'ids'));
            return is_wp_error($ids)
                ? array()
                : array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        }
        if (!in_array($type, array('post','page'), true)) {
            return array();
        }

        $relations = $wpdb->prefix . 'seo_relations';
        if (!self::table_exists($relations)) {
            return array();
        }

        $source_types = 'post' === $type ? array('post') : array('page','landing','cluster','hub_primary','hub_secondary');
        $placeholders = implode(',', array_fill(0, count($source_types), '%s'));
        $params = array_merge($source_types, array($id));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna y placeholders generados; todos los valores mutables se enlazan con prepare().
        $sql = "SELECT DISTINCT target_id
                FROM {$relations}
                WHERE source_type IN ({$placeholders})
                  AND source_id=%d
                  AND target_type='product_cat'
                  AND relation_type IN ('post_to_category','landing_to_category')
                ORDER BY target_id ASC
                LIMIT 50";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- consulta preparada inmediatamente con todos los valores.
        $prepared = $wpdb->prepare($sql, $params);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $prepared es resultado directo de $wpdb->prepare().
        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($prepared)))));
    }

    private static function analista_snapshot() {
        $out = array(
            'state' => 'unavailable',
            'available' => false,
            'detail' => 'Analista no está disponible.',
            'period_days' => self::ANALISTA_DAYS,
            'period' => null,
            'current' => null,
            'visibility_index' => null,
            'entities_contextualized' => 0,
            '_entity_map' => array(),
        );

        if (!function_exists('seo_analista_get_data')) {
            return $out;
        }

        $data = (array) seo_analista_get_data(self::ANALISTA_DAYS);
        if (empty($data['ready'])) {
            $out['detail'] = 'Analista está cargado, pero no dispone de un periodo local utilizable.';
            return $out;
        }

        $period = (array) ($data['period'] ?? array());
        $date_to = (string) ($period['date_to'] ?? '');
        $state = self::date_state($date_to, self::ANALISTA_STALE_DAYS);
        if ('unavailable' === $state) {
            $state = 'partial';
        }

        $out['state'] = $state;
        $out['available'] = true;
        $out['detail'] = 'Snapshot local de Analista; Auditor no consulta GSC/GA4/Bing directamente.';
        $out['period'] = $period;
        $out['current'] = (array) ($data['current'] ?? array());
        $out['visibility_index'] = isset($data['visibility_index']) ? (float) $data['visibility_index'] : null;

        $rows = function_exists('seo_analista_content_work')
            ? (array) seo_analista_content_work(self::ANALISTA_DAYS, 250)
            : array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entity = (array) ($row['entity'] ?? array());
            $type = self::normalize_entity_type((string) ($entity['type'] ?? ''));
            $id = absint($entity['id'] ?? 0);
            $key = self::entity_key($type, $id);
            if ($key === '') {
                continue;
            }

            $metrics = (array) ($row['metrics'] ?? array());
            $out['_entity_map'][$key] = array(
                'state' => $state,
                'available' => true,
                'priority' => isset($row['priority']) ? (int) $row['priority'] : null,
                'action' => (string) ($row['action'] ?? ''),
                'action_label' => (string) ($row['action_label'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'metrics' => array(
                    'impressions' => array_key_exists('impressions', $metrics) ? (float) $metrics['impressions'] : null,
                    'clicks' => array_key_exists('clicks', $metrics) ? (float) $metrics['clicks'] : null,
                    'ctr' => array_key_exists('ctr', $metrics) ? (float) $metrics['ctr'] : null,
                    'position' => array_key_exists('position', $metrics) ? (float) $metrics['position'] : null,
                    'previous_impressions' => array_key_exists('previous_impressions', $metrics) ? (float) $metrics['previous_impressions'] : null,
                    'previous_clicks' => array_key_exists('previous_clicks', $metrics) ? (float) $metrics['previous_clicks'] : null,
                    'previous_position' => array_key_exists('previous_position', $metrics) ? (float) $metrics['previous_position'] : null,
                ),
                'recommended_changes' => array_slice(array_values((array) ($row['recommended_changes'] ?? array())), 0, 8),
                'keywords' => array_slice(array_values((array) ($row['keywords'] ?? array())), 0, 8),
            );
        }
        $out['entities_contextualized'] = count($out['_entity_map']);
        return $out;
    }

    private static function ojeador_snapshot() {
        $out = array(
            'state' => 'unavailable',
            'available' => false,
            'detail' => 'Ojeador no está disponible o no tiene snapshots de categoría.',
            'categories_total' => null,
            'categories_scanned' => null,
            'categories_stale' => null,
            'latest_scan_at' => null,
            '_category_map' => array(),
        );

        if (!class_exists('SEO_Ojeador_DB') || !method_exists('SEO_Ojeador_DB', 'list_market_categories')) {
            return $out;
        }

        $rows = (array) SEO_Ojeador_DB::list_market_categories(array('limit'=>2000,'search'=>''));
        if (!$rows) {
            $out['available'] = true;
            $out['state'] = 'partial';
            $out['categories_total'] = 0;
            $out['categories_scanned'] = 0;
            $out['categories_stale'] = 0;
            $out['detail'] = 'Ojeador está disponible, pero todavía no hay categorías observadas.';
            return $out;
        }

        $scanned = 0;
        $stale = 0;
        $latest = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term_id = absint($row['term_id'] ?? 0);
            if (!$term_id) {
                continue;
            }

            $last = (string) ($row['last_scan_at'] ?? '');
            $next = (string) ($row['next_scan_at'] ?? '');
            $row_state = 'unavailable';
            if ($last !== '') {
                $scanned++;
                $row_state = self::is_past($next) || !empty($row['needs_trusted_scan']) ? 'stale' : 'fresh';
                if ('stale' === $row_state) {
                    $stale++;
                }
                if ($latest === '' || strcmp($last, $latest) > 0) {
                    $latest = $last;
                }
            }

            $out['_category_map'][$term_id] = array(
                'state' => $row_state,
                'available' => $last !== '',
                'term_id' => $term_id,
                'category' => (string) (($row['woo_category'] ?? '') ?: ($row['category_name'] ?? '')),
                'market_status' => (string) ($row['market_status'] ?? ''),
                'result_count' => $last !== '' ? absint($row['result_count'] ?? 0) : null,
                'unique_result_count' => $last !== '' ? absint($row['unique_result_count'] ?? 0) : null,
                'query' => (string) (($row['trusted_query'] ?? '') ?: ($row['query_text'] ?? '')),
                'last_scan_at' => $last ?: null,
                'next_scan_at' => $next ?: null,
            );
        }

        $out['available'] = true;
        $out['categories_total'] = count($rows);
        $out['categories_scanned'] = $scanned;
        $out['categories_stale'] = $stale;
        $out['latest_scan_at'] = $latest ?: null;
        if ($scanned <= 0) {
            $out['state'] = 'partial';
            $out['detail'] = 'Ojeador tiene inventario de categorías, pero todavía no hay snapshots observados.';
        } elseif ($stale >= $scanned) {
            $out['state'] = 'stale';
            $out['detail'] = 'Los snapshots de Ojeador disponibles están vencidos según su próxima revisión.';
        } elseif ($stale > 0 || $scanned < count($rows)) {
            $out['state'] = 'partial';
            $out['detail'] = 'Ojeador dispone de snapshots persistidos, con cobertura parcial o algunas categorías vencidas.';
        } else {
            $out['state'] = 'fresh';
            $out['detail'] = 'Ojeador dispone de snapshots persistidos vigentes.';
        }
        return $out;
    }

    private static function dependiente_snapshot() {
        $out = array(
            'state' => 'unavailable',
            'available' => false,
            'detail' => 'No hay log de búsquedas de Dependiente disponible.',
            'period_days' => self::DEPENDIENTE_DAYS,
            'summary' => null,
            '_product_map' => array(),
            '_category_map' => array(),
        );

        if (
            !class_exists('SEO_Dependiente_Insights')
            || !class_exists('SEO_Dependiente_Search_Log')
            || !method_exists('SEO_Dependiente_Search_Log', 'table_exists')
            || !SEO_Dependiente_Search_Log::table_exists()
        ) {
            return $out;
        }

        $summary = (array) SEO_Dependiente_Insights::summary(self::DEPENDIENTE_DAYS);
        $offered = (array) SEO_Dependiente_Insights::top_offered_products(self::DEPENDIENTE_DAYS, 100, 5000);
        $clicked = (array) SEO_Dependiente_Insights::top_clicked_products(self::DEPENDIENTE_DAYS, 100);

        $products = array();
        foreach ($offered as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id) continue;
            $products[$id] = array(
                'state' => 'fresh',
                'available' => true,
                'product_id' => $id,
                'appearances' => absint($row['appearances'] ?? 0),
                'average_position' => isset($row['average_position']) ? (float) $row['average_position'] : null,
                'top3' => absint($row['top3'] ?? 0),
                'top10' => absint($row['top10'] ?? 0),
                'clicks' => absint($row['clicks'] ?? 0),
            );
        }
        foreach ($clicked as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id) continue;
            if (!isset($products[$id])) {
                $products[$id] = array(
                    'state' => 'fresh',
                    'available' => true,
                    'product_id' => $id,
                    'appearances' => 0,
                    'average_position' => null,
                    'top3' => 0,
                    'top10' => 0,
                    'clicks' => 0,
                );
            }
            $products[$id]['clicks'] = max(
                absint($products[$id]['clicks'] ?? 0),
                absint($row['clicks'] ?? $row['qty'] ?? 0)
            );
            if (array_key_exists('average_clicked_position', $row)) {
                $products[$id]['average_clicked_position'] = (float) $row['average_clicked_position'];
            }
        }

        $category_map = array();
        foreach ($products as $product_id => $row) {
            $term_ids = wp_get_post_terms($product_id, 'product_cat', array('fields'=>'ids'));
            if (is_wp_error($term_ids)) continue;
            foreach ((array) $term_ids as $term_id) {
                $term_id = absint($term_id);
                if (!$term_id) continue;
                if (!isset($category_map[$term_id])) {
                    $category_map[$term_id] = array(
                        'state' => 'fresh',
                        'available' => true,
                        'term_id' => $term_id,
                        'products_observed' => 0,
                        'appearances' => 0,
                        'clicks' => 0,
                    );
                }
                $category_map[$term_id]['products_observed']++;
                $category_map[$term_id]['appearances'] += absint($row['appearances'] ?? 0);
                $category_map[$term_id]['clicks'] += absint($row['clicks'] ?? 0);
            }
        }

        $out['state'] = 'fresh';
        $out['available'] = true;
        $out['detail'] = 'Lectura agregada del log persistido de Dependiente; no ejecuta búsquedas.';
        $out['summary'] = array(
            'searches' => absint($summary['searches'] ?? 0),
            'zero_results' => absint($summary['zero_results'] ?? 0),
            'unresolved_searches' => absint($summary['unresolved_searches'] ?? 0),
            'product_clicks' => absint($summary['product_clicks'] ?? 0),
            'negative_feedback' => absint($summary['negative_feedback'] ?? 0),
            'click_rate' => isset($summary['click_rate']) ? (float) $summary['click_rate'] : null,
        );
        $out['_product_map'] = $products;
        $out['_category_map'] = $category_map;
        return $out;
    }

    private static function comparador_snapshot() {
        $out = array(
            'state' => 'unavailable',
            'available' => false,
            'detail' => 'Comparador no está disponible o no tiene perfiles.',
            'profiles' => null,
            'status_counts' => null,
            'latest_updated_at' => null,
            '_category_map' => array(),
        );

        if (!class_exists('SEO_Comparador_DB') || !method_exists('SEO_Comparador_DB', 'list_profiles')) {
            return $out;
        }

        $rows = (array) SEO_Comparador_DB::list_profiles(2000);
        if (!$rows) {
            $out['available'] = true;
            $out['state'] = 'partial';
            $out['profiles'] = 0;
            $out['status_counts'] = array();
            $out['detail'] = 'Comparador está disponible, pero todavía no existen perfiles persistidos.';
            return $out;
        }

        $status_counts = array();
        $latest = '';
        $stale = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $profile_id = absint($row['id'] ?? 0);
            $status = sanitize_key((string) ($row['status'] ?? 'detected'));
            $status_counts[$status] = absint($status_counts[$status] ?? 0) + 1;
            if (in_array($status, array('needs_update','needs_review','blocked'), true)) {
                $stale++;
            }

            $updated = (string) ($row['updated_at'] ?? '');
            if ($updated !== '' && ($latest === '' || strcmp($updated, $latest) > 0)) {
                $latest = $updated;
            }

            $category_ids = self::decode_ids($row['category_ids'] ?? array());
            $primary = absint($row['primary_category_id'] ?? 0);
            if ($primary) {
                $category_ids[] = $primary;
            }
            $category_ids = array_values(array_unique(array_filter(array_map('absint', $category_ids))));

            $profile_context = array(
                'state' => in_array($status, array('needs_update','needs_review','blocked'), true) ? 'stale' : 'fresh',
                'available' => true,
                'profile_id' => $profile_id,
                'status' => $status,
                'recommended_action' => (string) ($row['recommended_action'] ?? ''),
                'decision_reason' => (string) ($row['decision_reason'] ?? ''),
                'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : null,
                'own_products_count' => absint($row['own_products_count'] ?? 0),
                'external_products_seen' => absint($row['external_products_seen'] ?? 0),
                'external_products_comparable' => absint($row['external_products_comparable'] ?? 0),
                'comparison_axes_count' => absint($row['comparison_axes_count'] ?? 0),
                'source_snapshot_at' => !empty($row['source_snapshot_at']) ? (string) $row['source_snapshot_at'] : null,
                'updated_at' => $updated ?: null,
            );
            foreach ($category_ids as $term_id) {
                if (!isset($out['_category_map'][$term_id])) {
                    $out['_category_map'][$term_id] = array();
                }
                $out['_category_map'][$term_id][] = $profile_context;
            }
        }

        $out['available'] = true;
        $out['profiles'] = count($rows);
        $out['status_counts'] = $status_counts;
        $out['latest_updated_at'] = $latest ?: null;
        if ($stale >= count($rows)) {
            $out['state'] = 'stale';
            $out['detail'] = 'Todos los perfiles de Comparador requieren revisión/actualización o están bloqueados.';
        } elseif ($stale > 0) {
            $out['state'] = 'partial';
            $out['detail'] = 'Comparador tiene perfiles persistidos, con algunos pendientes de revisión/actualización.';
        } else {
            $out['state'] = 'fresh';
            $out['detail'] = 'Comparador dispone de perfiles persistidos vigentes.';
        }
        return $out;
    }

    private static function supplier_snapshot() {
        $out = array(
            'state' => 'unavailable',
            'available' => false,
            'detail' => 'No hay snapshot local de proveedores disponible.',
            'total' => null,
            'providers' => null,
            'published_links' => null,
            'issues' => null,
        );

        if (!function_exists('seo_analista_supplier_snapshot')) {
            return $out;
        }

        $snapshot = (array) seo_analista_supplier_snapshot(50);
        if (empty($snapshot['available'])) {
            return $out;
        }

        $issues = (array) ($snapshot['issues'] ?? array());
        $out['available'] = true;
        $out['state'] = $issues ? 'partial' : 'fresh';
        $out['detail'] = $issues
            ? 'Hay incidencias o fuentes de proveedor desactualizadas; revisar antes de usar esos datos como evidencia.'
            : 'Snapshot local de proveedores disponible sin incidencias resumidas.';
        $out['total'] = (int) ($snapshot['total'] ?? 0);
        $out['providers'] = (int) ($snapshot['providers'] ?? 0);
        $out['published_links'] = (int) ($snapshot['published_links'] ?? 0);
        $out['issues'] = array_slice($issues, 0, 15);
        return $out;
    }

    private static function aggregate_category_rows($rows, $source) {
        $rows = array_values(array_filter((array) $rows, 'is_array'));
        if (!$rows) {
            return array(
                'state' => 'unavailable',
                'available' => false,
                'source' => sanitize_key((string) $source),
                'rows' => array(),
            );
        }

        $states = array_map(static function($row) {
            return sanitize_key((string) ($row['state'] ?? 'unavailable'));
        }, $rows);
        $state = 'fresh';
        if (in_array('stale', $states, true)) {
            $state = count(array_unique($states)) > 1 ? 'partial' : 'stale';
        } elseif (in_array('partial', $states, true) || in_array('unavailable', $states, true)) {
            $state = 'partial';
        }

        return array(
            'state' => $state,
            'available' => true,
            'source' => sanitize_key((string) $source),
            'rows' => array_slice($rows, 0, 8),
        );
    }

    private static function date_state($date, $stale_days) {
        $date = trim((string) $date);
        if ($date === '') {
            return 'unavailable';
        }
        $timestamp = strtotime($date . (strlen($date) <= 10 ? ' 23:59:59' : ''));
        if (!$timestamp) {
            return 'unavailable';
        }
        return (time() - $timestamp) > (max(1, absint($stale_days)) * DAY_IN_SECONDS)
            ? 'stale'
            : 'fresh';
    }

    private static function is_past($date) {
        $date = trim((string) $date);
        if ($date === '') return false;
        $ts = strtotime($date);
        return $ts && $ts < time();
    }

    private static function decode_ids($value) {
        if (is_array($value)) {
            return array_values(array_filter(array_map('absint', $value)));
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded)
            ? array_values(array_filter(array_map('absint', $decoded)))
            : array();
    }

    private static function table_exists($table) {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table))
        ) === (string) $table;
    }
}
