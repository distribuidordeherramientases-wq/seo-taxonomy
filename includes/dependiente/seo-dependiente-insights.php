<?php

defined('ABSPATH') || exit;

/**
 * Observabilidad del Dependiente.
 *
 * Las tablas siguen siendo la fuente de verdad. Esta clase genera lecturas
 * agregadas y snapshots JSON para diagnostico, revision y soporte externo.
 * No exporta session_hash, IP, usuario, correo ni otros identificadores.
 */
final class SEO_Dependiente_Insights {
    const JSON_SCHEMA_VERSION = 2;

    public static function allowed_periods() {
        return array(1, 7, 30, 90);
    }

    public static function normalize_days($days) {
        $days = absint($days);
        return in_array($days, self::allowed_periods(), true) ? $days : 7;
    }

    public static function snapshot($days = 7, $recent_limit = 60) {
        $days = self::normalize_days($days);
        $recent_limit = min(200, max(10, absint($recent_limit)));

        $summary = self::summary($days);
        $recent = self::recent_searches($days, $recent_limit);
        $learning = self::learning_rules();

        return array(
            'schema' => array(
                'name'    => 'seo_dependiente_diagnostic',
                'version' => self::JSON_SCHEMA_VERSION,
            ),
            'generated_at' => current_time('c'),
            'site' => array(
                'home_url'           => home_url('/'),
                'dependiente_version'=> defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'db_version'         => (string) get_option('seo_dependiente_db_version', ''),
                'search_log_version' => (string) get_option('seo_dependiente_search_log_version', ''),
                'semantic_seed_version' => (string) get_option('seo_dependiente_semantic_seed_version', ''),
            ),
            'period' => array(
                'days' => $days,
            ),
            'summary' => $summary,
            'top' => array(
                'queries'          => self::top_queries($days, 12),
                'intents'          => self::top_column('detected_intent', $days, 12),
                'objects'          => self::top_column('detected_object', $days, 12),
                'contexts'         => self::top_column('detected_context', $days, 12),
                'strategies'       => self::top_column('search_strategy', $days, 12),
                'offered_products' => self::top_offered_products($days, 30),
                'clicked_products' => self::top_clicked_products($days, 20),
            ),
            'semantics'        => self::semantics_overview(),
            'unresolved_terms' => self::unresolved_terms($days, 30),
            'coverage_watch'   => self::coverage_watch($recent, 20),
            'learning' => array(
                'candidates' => $learning['candidates'],
                'active'     => $learning['active'],
                'rejected'   => $learning['rejected'],
            ),
            'recent_searches' => $recent,
            'notes' => array(
                'coverage_watch_is_heuristic' => true,
                'tables_remain_source_of_truth' => true,
                'privacy' => 'No session hashes or personal identifiers are exported.',
            ),
        );
    }

    public static function summary($days = 7) {
        global $wpdb;

        $days = self::normalize_days($days);
        $empty = array(
            'searches' => 0,
            'primary_searches' => 0,
            'semantic_routes' => 0,
            'object_anchor' => 0,
            'broad_fallback' => 0,
            'strict' => 0,
            'zero_results' => 0,
            'unresolved_searches' => 0,
            'clarifications_shown' => 0,
            'clarifications_answered' => 0,
            'product_clicks' => 0,
            'positive_feedback' => 0,
            'negative_feedback' => 0,
            'average_execution_ms' => 0,
            'semantic_rules_active' => 0,
            'seed_rules_active' => 0,
            'learned_candidates' => 0,
            'learned_active' => 0,
            'learned_rejected' => 0,
            'click_rate' => 0,
            'clarification_answer_rate' => 0,
        );

        if (!class_exists('SEO_Dependiente_Search_Log') || !SEO_Dependiente_Search_Log::table_exists()) {
            return $empty;
        }

        $table = SEO_Dependiente_Search_Log::table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS searches,
                    SUM(request_kind = 'search') AS primary_searches,
                    SUM(search_strategy = 'semantic_routes') AS semantic_routes,
                    SUM(search_strategy = 'object_anchor') AS object_anchor,
                    SUM(search_strategy = 'broad_fallback') AS broad_fallback,
                    SUM(search_strategy = 'strict') AS strict_count,
                    SUM(result_count = 0) AS zero_results,
                    SUM(unresolved_terms IS NOT NULL AND unresolved_terms NOT IN ('', '[]', 'null')) AS unresolved_searches,
                    SUM(clarification_shown_at IS NOT NULL) AS clarifications_shown,
                    SUM(clarified_at IS NOT NULL) AS clarifications_answered,
                    SUM(clicked_product_id IS NOT NULL) AS product_clicks,
                    SUM(feedback > 0) AS positive_feedback,
                    SUM(feedback < 0) AS negative_feedback,
                    AVG(execution_ms) AS average_execution_ms
                 FROM {$table}
                 WHERE created_at >= DATE_SUB(%s, INTERVAL %d DAY)",
                current_time('mysql'),
                $days
            ),
            ARRAY_A
        );

        if ($row) {
            foreach (array(
                'searches','primary_searches','semantic_routes','object_anchor','broad_fallback',
                'zero_results','unresolved_searches','clarifications_shown','clarifications_answered',
                'product_clicks','positive_feedback','negative_feedback'
            ) as $key) {
                $empty[$key] = absint($row[$key] ?? 0);
            }
            $empty['strict'] = absint($row['strict_count'] ?? 0);
            $empty['average_execution_ms'] = round((float) ($row['average_execution_ms'] ?? 0), 2);
            $empty['click_rate'] = $empty['primary_searches'] > 0 ? round($empty['product_clicks'] / $empty['primary_searches'], 4) : 0;
            $empty['clarification_answer_rate'] = $empty['clarifications_shown'] > 0 ? round($empty['clarifications_answered'] / $empty['clarifications_shown'], 4) : 0;
        }

        if (class_exists('SEO_Dependiente_Semantics') && SEO_Dependiente_Semantics::table_exists()) {
            $semantic_table = SEO_Dependiente_Semantics::table();
            $counts = (array) $wpdb->get_results(
                "SELECT source, active, COUNT(*) AS qty FROM {$semantic_table} GROUP BY source, active",
                ARRAY_A
            );
            foreach ($counts as $count) {
                $source = (string) ($count['source'] ?? '');
                $active = absint($count['active'] ?? 0);
                $qty = absint($count['qty'] ?? 0);
                if ($active) {
                    $empty['semantic_rules_active'] += $qty;
                }
                if ('seed' === $source && $active) {
                    $empty['seed_rules_active'] += $qty;
                } elseif ('learned_candidate' === $source) {
                    $empty['learned_candidates'] += $qty;
                } elseif ('learned' === $source && $active) {
                    $empty['learned_active'] += $qty;
                } elseif ('learned_rejected' === $source) {
                    $empty['learned_rejected'] += $qty;
                }
            }
        }

        return $empty;
    }

    public static function top_column($column, $days = 7, $limit = 10) {
        global $wpdb;

        $allowed = array('detected_intent', 'detected_object', 'detected_context', 'search_strategy');
        if (!in_array($column, $allowed, true) || !class_exists('SEO_Dependiente_Search_Log') || !SEO_Dependiente_Search_Log::table_exists()) {
            return array();
        }

        $days = self::normalize_days($days);
        $limit = min(30, max(1, absint($limit)));
        $table = SEO_Dependiente_Search_Log::table();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$column} AS value, COUNT(*) AS qty
                 FROM {$table}
                 WHERE created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                   AND {$column} IS NOT NULL
                   AND {$column} <> ''
                 GROUP BY {$column}
                 ORDER BY qty DESC, value ASC
                 LIMIT %d",
                current_time('mysql'),
                $days,
                $limit
            ),
            ARRAY_A
        );

        return array_values(array_map(static function ($row) {
            return array(
                'value' => (string) ($row['value'] ?? ''),
                'count' => absint($row['qty'] ?? 0),
            );
        }, $rows));
    }

    public static function top_queries($days = 7, $limit = 10) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Search_Log') || !SEO_Dependiente_Search_Log::table_exists()) {
            return array();
        }
        $days = self::normalize_days($days);
        $limit = min(50, max(1, absint($limit)));
        $table = SEO_Dependiente_Search_Log::table();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT query_normalized AS value, MIN(query_original) AS example, COUNT(*) AS qty
                 FROM {$table}
                 WHERE request_kind = 'search'
                   AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                   AND query_normalized <> ''
                 GROUP BY query_normalized
                 ORDER BY qty DESC, value ASC
                 LIMIT %d",
                current_time('mysql'),
                $days,
                $limit
            ),
            ARRAY_A
        );
        return array_values(array_map(static function ($row) {
            return array(
                'value'   => (string) (!empty($row['example']) ? $row['example'] : ($row['value'] ?? '')),
                'normalized' => (string) ($row['value'] ?? ''),
                'count'   => absint($row['qty'] ?? 0),
            );
        }, $rows));
    }

    public static function top_offered_products($days = 7, $limit = 30, $scan_limit = 2500) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Search_Log') || !SEO_Dependiente_Search_Log::table_exists()) {
            return array();
        }
        $days = self::normalize_days($days);
        $limit = min(100, max(1, absint($limit)));
        $scan_limit = min(5000, max(200, absint($scan_limit)));
        $table = SEO_Dependiente_Search_Log::table();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT top_results, clicked_product_id
                 FROM {$table}
                 WHERE request_kind = 'search'
                   AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                   AND top_results IS NOT NULL
                   AND top_results NOT IN ('', '[]', 'null')
                 ORDER BY id DESC
                 LIMIT %d",
                current_time('mysql'),
                $days,
                $scan_limit
            ),
            ARRAY_A
        );
        $products = array();
        foreach ($rows as $row) {
            $clicked = absint($row['clicked_product_id'] ?? 0);
            foreach ((array) self::decode_json($row['top_results'] ?? '') as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $id = absint($product['id'] ?? 0);
                if (!$id) {
                    continue;
                }
                if (!isset($products[$id])) {
                    $products[$id] = array(
                        'id' => $id,
                        'title' => (string) ($product['title'] ?? ''),
                        'appearances' => 0,
                        'position_sum' => 0,
                        'top3' => 0,
                        'top10' => 0,
                        'clicks' => 0,
                    );
                }
                $position = max(1, absint($product['position'] ?? 0));
                $products[$id]['appearances']++;
                $products[$id]['position_sum'] += $position;
                if ($position <= 3) {
                    $products[$id]['top3']++;
                }
                if ($position <= 10) {
                    $products[$id]['top10']++;
                }
                if ($clicked && $clicked === $id) {
                    $products[$id]['clicks']++;
                }
                if (!$products[$id]['title'] && !empty($product['title'])) {
                    $products[$id]['title'] = (string) $product['title'];
                }
            }
        }
        foreach ($products as &$product) {
            $product['average_position'] = $product['appearances'] > 0
                ? round($product['position_sum'] / $product['appearances'], 2)
                : 0;
            unset($product['position_sum']);
        }
        unset($product);
        usort($products, static function ($a, $b) {
            $cmp = (int) $b['appearances'] <=> (int) $a['appearances'];
            if (0 !== $cmp) {
                return $cmp;
            }
            return (int) $b['clicks'] <=> (int) $a['clicks'];
        });
        return array_slice(array_values($products), 0, $limit);
    }

    public static function top_clicked_products($days = 7, $limit = 20) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Search_Log') || !SEO_Dependiente_Search_Log::table_exists()) {
            return array();
        }
        $days = self::normalize_days($days);
        $limit = min(100, max(1, absint($limit)));
        $table = SEO_Dependiente_Search_Log::table();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT clicked_product_id AS id, COUNT(*) AS qty, AVG(clicked_position) AS avg_position
                 FROM {$table}
                 WHERE request_kind = 'search'
                   AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                   AND clicked_product_id IS NOT NULL
                 GROUP BY clicked_product_id
                 ORDER BY qty DESC, id ASC
                 LIMIT %d",
                current_time('mysql'),
                $days,
                $limit
            ),
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id) {
                continue;
            }
            $out[] = array(
                'id' => $id,
                'title' => (string) get_the_title($id),
                'clicks' => absint($row['qty'] ?? 0),
                'average_clicked_position' => round((float) ($row['avg_position'] ?? 0), 2),
            );
        }
        return $out;
    }

    public static function semantics_overview() {
        global $wpdb;
        $out = array(
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'candidate' => 0,
            'by_source' => array(),
            'by_rule_type' => array(),
            'by_role' => array(),
        );
        if (!class_exists('SEO_Dependiente_Semantics') || !SEO_Dependiente_Semantics::table_exists()) {
            return $out;
        }
        $table = SEO_Dependiente_Semantics::table();
        $rows = (array) $wpdb->get_results(
            "SELECT source, active, rule_type, semantic_role, COUNT(*) AS qty
             FROM {$table}
             GROUP BY source, active, rule_type, semantic_role",
            ARRAY_A
        );
        foreach ($rows as $row) {
            $qty = absint($row['qty'] ?? 0);
            $source = (string) ($row['source'] ?? 'unknown');
            $rule_type = (string) ($row['rule_type'] ?? 'unknown');
            $role = (string) ($row['semantic_role'] ?? 'unknown');
            $active = !empty($row['active']);
            $out['total'] += $qty;
            $out[$active ? 'active' : 'inactive'] += $qty;
            if ('learned_candidate' === $source) {
                $out['candidate'] += $qty;
            }
            if (!isset($out['by_source'][$source])) {
                $out['by_source'][$source] = array('active' => 0, 'inactive' => 0, 'total' => 0);
            }
            $out['by_source'][$source][$active ? 'active' : 'inactive'] += $qty;
            $out['by_source'][$source]['total'] += $qty;
            $out['by_rule_type'][$rule_type] = absint($out['by_rule_type'][$rule_type] ?? 0) + $qty;
            $out['by_role'][$role] = absint($out['by_role'][$role] ?? 0) + $qty;
        }
        arsort($out['by_rule_type']);
        arsort($out['by_role']);
        uasort($out['by_source'], static function ($a, $b) {
            return (int) $b['total'] <=> (int) $a['total'];
        });
        return $out;
    }

    public static function unresolved_terms($days = 7, $limit = 30) {
        if (!class_exists('SEO_Dependiente_Search_Log')) {
            return array();
        }
        $data = SEO_Dependiente_Search_Log::learning_candidates(array(
            'days'            => self::normalize_days($days),
            'limit'           => 4000,
            'min_occurrences' => 1,
        ));
        $terms = array_slice((array) ($data['unresolved_terms'] ?? array()), 0, min(100, max(1, absint($limit))));
        return array_values(array_map(static function ($item) {
            return array(
                'term'              => (string) ($item['expression'] ?? ''),
                'occurrences'       => absint($item['occurrences'] ?? 0),
                'zero_results'      => absint($item['zero_results'] ?? 0),
                'negative_feedback' => absint($item['negative_feedback'] ?? 0),
                'contexts'          => (array) ($item['contexts'] ?? array()),
                'examples'          => array_values(array_slice((array) ($item['examples'] ?? array()), 0, 5)),
            );
        }, $terms));
    }

    public static function recent_searches($days = 7, $limit = 60) {
        $days = self::normalize_days($days);
        $limit = min(200, max(10, absint($limit)));
        if (!class_exists('SEO_Dependiente_Search_Log')) {
            return array();
        }

        $rows = SEO_Dependiente_Search_Log::get_searches(array(
            'days'   => $days,
            'limit'  => 500,
            'offset' => 0,
        ));

        $out = array();
        foreach ($rows as $row) {
            if ('search' !== (string) ($row['request_kind'] ?? '')) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $strategy = self::decode_json($row['strategy_detail'] ?? '');
            $events = self::decode_json($row['interaction_events'] ?? '');
            $event_types = array();
            foreach ((array) $events as $event) {
                if (is_array($event) && !empty($event['type'])) {
                    $event_types[] = sanitize_key((string) $event['type']);
                }
            }

            $out[] = array(
                'id'                   => absint($row['id'] ?? 0),
                'search_uuid'          => (string) ($row['search_uuid'] ?? ''),
                'query'                => (string) ($row['query_original'] ?? ''),
                'request_kind'         => (string) ($row['request_kind'] ?? ''),
                'mode'                 => (string) ($row['mode'] ?? ''),
                'intent'               => self::nullable_string($row['detected_intent'] ?? null),
                'object'               => self::nullable_string($row['detected_object'] ?? null),
                'context'              => self::nullable_string($row['detected_context'] ?? null),
                'state'                => self::nullable_string($row['detected_state'] ?? null),
                'ignored_terms'        => array_values((array) self::decode_json($row['ignored_terms'] ?? '')),
                'unresolved_terms'     => array_values((array) self::decode_json($row['unresolved_terms'] ?? '')),
                'strategy'             => (string) ($row['search_strategy'] ?? ''),
                'strategy_detail'      => self::compact_strategy($strategy),
                'candidate_count'      => absint($row['candidate_count'] ?? 0),
                'result_count'         => absint($row['result_count'] ?? 0),
                'top_results'          => array_values(array_slice((array) self::decode_json($row['top_results'] ?? ''), 0, 12)),
                'semantic_analysis'    => self::decode_json($row['semantic_analysis'] ?? ''),
                'execution_ms'         => round((float) ($row['execution_ms'] ?? 0), 3),
                'feedback'             => (int) ($row['feedback'] ?? 0),
                'feedback_reason'      => self::nullable_string($row['feedback_reason'] ?? null),
                'clicked_product_id'   => absint($row['clicked_product_id'] ?? 0) ?: null,
                'clicked_position'     => absint($row['clicked_position'] ?? 0) ?: null,
                'clarification_shown'  => !empty($row['clarification_shown_at']),
                'clarification'        => array(
                    'question'       => self::nullable_string($row['clarification_question'] ?? null),
                    'options'        => self::decode_json($row['clarification_options'] ?? ''),
                    'selected_role'  => self::nullable_string($row['clarified_role'] ?? null),
                    'selected_value' => self::nullable_string($row['clarified_value'] ?? null),
                    'selected_label' => self::nullable_string($row['clarified_label'] ?? null),
                    'source'         => self::nullable_string($row['clarification_source'] ?? null),
                    'shown_at'       => self::nullable_string($row['clarification_shown_at'] ?? null),
                    'clarified_at'   => self::nullable_string($row['clarified_at'] ?? null),
                ),
                'clarified_role'       => self::nullable_string($row['clarified_role'] ?? null),
                'clarified_value'      => self::nullable_string($row['clarified_value'] ?? null),
                'learning_status'      => (string) ($row['learning_status'] ?? 'new'),
                'learning_candidate'   => self::decode_json($row['learning_candidate'] ?? ''),
                'promoted_rule_keys'   => self::decode_json($row['promoted_rule_keys'] ?? ''),
                'interaction_types'    => array_values(array_unique($event_types)),
                'created_at'           => (string) ($row['created_at'] ?? ''),
            );
        }
        return $out;
    }

    public static function learning_rules() {
        global $wpdb;

        $out = array('candidates' => array(), 'active' => array(), 'rejected' => array());
        if (!class_exists('SEO_Dependiente_Semantics') || !SEO_Dependiente_Semantics::table_exists()) {
            return $out;
        }

        $table = SEO_Dependiente_Semantics::table();
        $rows = (array) $wpdb->get_results(
            "SELECT id, rule_key, rule_type, expression, canonical_expression, match_type, semantic_role,
                    source_group, source_slug, context_group, context_slug, target_group, target_slug,
                    relation_type, result_role, weight, priority, confidence, source, active, metadata,
                    created_at, updated_at
             FROM {$table}
             WHERE source IN ('learned_candidate','learned','learned_rejected')
             ORDER BY updated_at DESC, id DESC
             LIMIT 500",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $item = self::compact_learning_rule($row);
            if ('learned_candidate' === $item['source']) {
                $out['candidates'][] = $item;
            } elseif ('learned' === $item['source']) {
                $out['active'][] = $item;
            } elseif ('learned_rejected' === $item['source']) {
                $out['rejected'][] = $item;
            }
        }
        return $out;
    }

    public static function json_filename($days = 7) {
        $days = self::normalize_days($days);
        return 'dependiente-informe-' . gmdate('Y-m-d-His') . '-' . $days . 'd.json';
    }

    private static function coverage_watch($recent, $limit = 20) {
        $groups = array();
        foreach ((array) $recent as $row) {
            if ('search' !== (string) ($row['request_kind'] ?? '')) {
                continue;
            }
            $intent = (string) ($row['intent'] ?? '');
            $object = (string) ($row['object'] ?? '');
            if (!$intent && !$object) {
                continue;
            }
            $detail = (array) ($row['strategy_detail'] ?? array());
            $semantic_products = absint($detail['semantic_product_ids'] ?? 0);
            $semantic_routes = absint($detail['semantic_route_rows'] ?? 0);
            $strategy = (string) ($row['strategy'] ?? '');
            $results = absint($row['result_count'] ?? 0);

            $reason = '';
            if (0 === $results) {
                $reason = 'zero_results';
            } elseif ('broad_fallback' === $strategy) {
                $reason = 'broad_fallback_after_understanding';
            } elseif (0 === $semantic_products && 0 === $semantic_routes && 'object_anchor' === $strategy) {
                $reason = 'object_understood_without_semantic_route';
            }
            if (!$reason) {
                continue;
            }

            $key = ($intent ?: '-') . '|' . ($object ?: '-') . '|' . $reason;
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'intent' => $intent ?: null,
                    'object' => $object ?: null,
                    'reason' => $reason,
                    'occurrences' => 0,
                    'examples' => array(),
                    'heuristic' => true,
                );
            }
            $groups[$key]['occurrences']++;
            if (count($groups[$key]['examples']) < 4) {
                $groups[$key]['examples'][] = (string) ($row['query'] ?? '');
            }
        }
        usort($groups, static function ($a, $b) {
            return (int) $b['occurrences'] <=> (int) $a['occurrences'];
        });
        return array_slice(array_values($groups), 0, min(50, max(1, absint($limit))));
    }

    private static function compact_learning_rule($row) {
        $metadata = self::decode_json($row['metadata'] ?? '');
        $learning = is_array($metadata) ? (array) ($metadata['learning'] ?? array()) : array();

        return array(
            'id'                   => absint($row['id'] ?? 0),
            'rule_key'             => (string) ($row['rule_key'] ?? ''),
            'rule_type'            => (string) ($row['rule_type'] ?? ''),
            'expression'           => (string) ($row['expression'] ?? ''),
            'canonical_expression' => (string) ($row['canonical_expression'] ?? ''),
            'match_type'           => (string) ($row['match_type'] ?? ''),
            'semantic_role'        => (string) ($row['semantic_role'] ?? ''),
            'source_group'         => self::nullable_string($row['source_group'] ?? null),
            'source_slug'          => self::nullable_string($row['source_slug'] ?? null),
            'context_group'        => self::nullable_string($row['context_group'] ?? null),
            'context_slug'         => self::nullable_string($row['context_slug'] ?? null),
            'target_group'         => self::nullable_string($row['target_group'] ?? null),
            'target_slug'          => self::nullable_string($row['target_slug'] ?? null),
            'relation_type'        => (string) ($row['relation_type'] ?? ''),
            'result_role'          => (string) ($row['result_role'] ?? ''),
            'weight'               => absint($row['weight'] ?? 0),
            'priority'             => absint($row['priority'] ?? 0),
            'confidence'           => round((float) ($row['confidence'] ?? 0), 4),
            'source'               => (string) ($row['source'] ?? ''),
            'active'               => (bool) ($row['active'] ?? false),
            'evidence' => array(
                'clicks'          => absint($learning['clicks'] ?? 0),
                'clarifications'  => absint($learning['clarifications'] ?? 0),
                'reformulations'  => absint($learning['reformulations'] ?? 0),
                'sessions'        => count(array_unique((array) ($learning['session_hashes'] ?? array()))),
                'products'        => count(array_unique(array_map('absint', (array) ($learning['product_ids'] ?? array())))),
                'evidence_types'  => array_values((array) ($learning['evidence_types'] ?? array())),
                'examples'        => array_values(array_slice((array) ($learning['examples'] ?? array()), 0, 8)),
                'first_seen'      => self::nullable_string($learning['first_seen'] ?? null),
                'last_seen'       => self::nullable_string($learning['last_seen'] ?? null),
                'target_label'    => self::nullable_string($learning['target_label'] ?? null),
                'catalog_confidence' => isset($learning['catalog_confidence']) ? round((float) $learning['catalog_confidence'], 4) : null,
            ),
            'review' => array(
                'reviewed_at'      => is_array($metadata) ? self::nullable_string($metadata['reviewed_at'] ?? null) : null,
                'review_decision'  => is_array($metadata) ? self::nullable_string($metadata['review_decision'] ?? null) : null,
            ),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        );
    }

    private static function compact_strategy($detail) {
        if (!is_array($detail)) {
            return array();
        }
        $keys = array(
            'strategy','strict_count','semantic_product_ids','semantic_route_rows',
            'object_anchor_rows','broad_fallback_rows','semantic_rules_active'
        );
        $out = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $detail)) {
                $out[$key] = is_numeric($detail[$key]) ? (int) $detail[$key] : $detail[$key];
            }
        }
        return $out;
    }

    private static function nullable_string($value) {
        if (null === $value) {
            return null;
        }
        $value = trim((string) $value);
        return '' === $value ? null : $value;
    }

    private static function decode_json($value) {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string) $value);
        if ('' === $value) {
            return array();
        }
        $decoded = json_decode($value, true);
        return JSON_ERROR_NONE === json_last_error() ? $decoded : array();
    }
}
