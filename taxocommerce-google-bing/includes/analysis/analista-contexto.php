<?php
/**
 * Contexto local de Auditor y Merchant para Analista.
 *
 * Adaptador SOLO LECTURA: no inicia escaneos, sincronizaciones, llamadas
 * externas ni modifica las decisiones/gates del plan de accion.
 */
defined('ABSPATH') || exit;

if (!function_exists('taxocommerce_google_bing_analyst_context_fresh')) {
    function taxocommerce_google_bing_analyst_context_fresh($date, $mysql_gmt = false) {
        $date = trim((string) $date);
        if ($date === '') return false;
        $timestamp = strtotime($date . ($mysql_gmt ? ' UTC' : ''));
        return $timestamp !== false && $timestamp <= time() + 300
            && $timestamp >= time() - 30 * DAY_IN_SECONDS;
    }
}

if (!function_exists('taxocommerce_google_bing_analyst_context_entity_key')) {
    function taxocommerce_google_bing_analyst_context_entity_key($type, $id) {
        $type = sanitize_key((string) $type);
        if ($type === 'product_cat') $type = 'category';
        $id = absint($id);
        return $type !== '' && $id > 0 ? $type . ':' . $id : '';
    }
}

if (!function_exists('taxocommerce_google_bing_analyst_local_context')) {
    function taxocommerce_google_bing_analyst_local_context() {
        $auditor = array(
            'state'=>'unavailable', 'scopes'=>0, 'stale_scopes'=>0,
            'findings'=>0, 'rows'=>array(), 'by_entity'=>array(),
        );
        if (class_exists('TaxoCommerce\\Auditor\\Runner')) {
            $auditor['state'] = 'pending';
            $scopes = array('products','categories','posts','pages','faqs');
            foreach ($scopes as $scope) {
                $report = (array) \TaxoCommerce\Auditor\Runner::report($scope);
                if (empty($report['completed_gmt'])) continue;
                if (!taxocommerce_google_bing_analyst_context_fresh($report['completed_gmt'], true)) {
                    $auditor['stale_scopes']++;
                    continue;
                }
                $auditor['scopes']++;
                $auditor['findings'] += absint($report['finding_count'] ?? 0);
                foreach ((array) ($report['findings_sample'] ?? array()) as $finding) {
                    if (!is_array($finding)) continue;
                    $priority = (string) ($finding['priority'] ?? '');
                    if (!in_array($priority, array('P1','P2'), true)) continue;
                    $key = taxocommerce_google_bing_analyst_context_entity_key($finding['entity_type'] ?? '', $finding['entity_id'] ?? 0);
                    if ($key === '') continue;
                    $item = array(
                        'key'=>$key, 'scope'=>$scope,
                        'title'=>(string) ($finding['title'] ?? ''),
                        'priority'=>$priority,
                        'code'=>(string) ($finding['code'] ?? ''),
                        'recommendation'=>(string) ($finding['recommendation'] ?? ''),
                        'status'=>(string) ($finding['status'] ?? ''),
                        'score'=>absint($finding['priority_score'] ?? 0),
                    );
                    $auditor['rows'][] = $item;
                    if (!isset($auditor['by_entity'][$key])) $auditor['by_entity'][$key] = array();
                    if (count($auditor['by_entity'][$key]) < 3) {
                        $auditor['by_entity'][$key][] = $item;
                    }
                }
            }
            if ($auditor['scopes'] > 0) {
                $auditor['state'] = $auditor['scopes'] === count($scopes) ? 'ok' : 'partial';
            } elseif ($auditor['stale_scopes'] > 0) {
                $auditor['state'] = 'stale';
            }
            usort($auditor['rows'], static function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            $auditor['rows'] = array_slice($auditor['rows'], 0, 8);
        }

        $merchant = array(
            'state'=>'unavailable', 'updated_at'=>'',
            'prices'=>null, 'popular'=>null, 'rows'=>array(), 'by_product'=>array(),
        );
        if (class_exists('TaxoCommerce_Google_Bing_Merchant')) {
            $merchant['state'] = 'pending';
            $snapshots = (array) TaxoCommerce_Google_Bing_Merchant::snapshots();
            $prices = (array) ($snapshots['prices'] ?? array());
            $popular = (array) ($snapshots['popular'] ?? array());
            $prices_fresh = ($prices['country'] ?? '') === 'ES'
                && taxocommerce_google_bing_analyst_context_fresh($prices['updated_at'] ?? '');
            $popular_fresh = ($popular['country'] ?? '') === 'ES'
                && taxocommerce_google_bing_analyst_context_fresh($popular['updated_at'] ?? '');
            if ($prices_fresh) {
                $merchant['prices'] = count((array) ($prices['rows'] ?? array()));
                $merchant['updated_at'] = (string) ($prices['updated_at'] ?? '');
                foreach ((array) ($prices['rows'] ?? array()) as $row) {
                    if (!is_array($row)) continue;
                    $id = absint($row['product_id'] ?? 0);
                    $benchmark = (float) ($row['benchmark'] ?? 0);
                    $price = (float) ($row['price'] ?? 0);
                    if (!$id || $benchmark <= 0 || ($row['currency'] ?? '') !== 'EUR') continue;
                    // Solo precios del informe Merchant ES: no son precios vivos del WooCommerce.
                    $item = array(
                        'id'=>$id, 'title'=>(string) ($row['title'] ?? ''),
                        'price'=>$price > 0 ? $price : null,
                        'benchmark'=>$benchmark,
                    );
                    $merchant['by_product'][$id] = $item;
                    if ($price > $benchmark * 1.05) {
                        $item['gap_pct'] = round(100 * ($price / $benchmark - 1), 1);
                        $merchant['rows'][] = $item;
                    }
                }
                usort($merchant['rows'], static function ($a, $b) {
                    return $b['gap_pct'] <=> $a['gap_pct'];
                });
                $merchant['rows'] = array_slice($merchant['rows'], 0, 5);
            }
            if ($popular_fresh) {
                $merchant['popular'] = count((array) ($popular['rows'] ?? array()));
                if (!$merchant['updated_at']) $merchant['updated_at'] = (string) ($popular['updated_at'] ?? '');
            }
            if ($prices_fresh || $popular_fresh) {
                $all_complete = (!$prices_fresh || !empty($prices['complete']))
                    && (!$popular_fresh || !empty($popular['complete']));
                $merchant['state'] = $all_complete ? 'ok' : 'partial';
            } elseif (!empty($prices['updated_at']) || !empty($popular['updated_at'])) {
                $merchant['state'] = 'stale';
            }
        }

        return array('auditor'=>$auditor, 'merchant'=>$merchant);
    }
}

if (!function_exists('taxocommerce_google_bing_analyst_context_attach_plan')) {
    function taxocommerce_google_bing_analyst_context_attach_plan(array $plan, array $context) {
        $auditor = (array) ($context['auditor']['by_entity'] ?? array());
        $merchant = (array) ($context['merchant']['by_product'] ?? array());
        foreach ($plan as &$row) {
            $entity = (array) ($row['entity'] ?? array());
            $type = sanitize_key((string) ($entity['type'] ?? ''));
            $id = absint($entity['id'] ?? 0);
            $key = taxocommerce_google_bing_analyst_context_entity_key($type, $id);
            $notes = array();
            foreach (array_slice((array) ($auditor[$key] ?? array()), 0, 2) as $finding) {
                $notes[] = 'Auditor ' . $finding['priority'] . ' (' . $finding['code'] . '): '
                    . ($finding['recommendation'] !== '' ? $finding['recommendation'] : 'Revisar hallazgo en Auditor');
            }
            if ($type === 'product' && isset($merchant[$id])) {
                $price = $merchant[$id];
                $notes[] = 'Merchant ES: benchmark agregado ' . number_format_i18n($price['benchmark'], 2)
                    . ' EUR; precio declarado en informe '
                    . ($price['price'] !== null ? number_format_i18n($price['price'], 2) . ' EUR' : 'no disponible')
                    . '. Verificar fecha, moneda, margen y precio actual antes de actuar.';
            }
            if ($notes) $row['local_context_evidence'] = array_slice($notes, 0, 3);
            // No cambiar prioridad, bucket, action, primary_action ni task_decision.
        }
        unset($row);
        return $plan;
    }
}

if (!function_exists('taxocommerce_google_bing_analyst_render_local_context')) {
    function taxocommerce_google_bing_analyst_render_local_context(array $context) {
        $auditor = (array) ($context['auditor'] ?? array());
        $merchant = (array) ($context['merchant'] ?? array());
        echo '<section class="seo-analista-section"><h2>Comprobaciones complementarias · Auditor y Merchant</h2>';
        echo '<p>Se consultan únicamente resultados guardados. Estas fuentes no crean tareas ejecutables ni alteran los controles del Plan de acción.</p>';
        if (in_array($auditor['state'] ?? '', array('ok','partial'), true)) {
            echo '<p><strong>Auditor:</strong> ' . esc_html(number_format_i18n($auditor['scopes'])) . '/5 auditorías recientes · '
                . esc_html(number_format_i18n($auditor['findings'])) . ' hallazgos totales. La lista es una muestra priorizada.</p>';
            if (!empty($auditor['rows'])) {
                echo '<ul>';
                foreach (array_slice($auditor['rows'], 0, 5) as $item) {
                    $url = add_query_arg(array('page'=>'taxocommerce-auditor', 'auditor_scope'=>$item['scope']), admin_url('admin.php'));
                    echo '<li><strong>' . esc_html($item['priority'] . ' · ' . $item['title']) . '</strong>: '
                        . esc_html($item['recommendation'] !== '' ? $item['recommendation'] : $item['code'])
                        . ' <a href="' . esc_url($url) . '">Ver hallazgo en Auditor</a></li>';
                }
                echo '</ul>';
            }
        } else {
            echo '<p><strong>Auditor:</strong> ' . esc_html(($auditor['state'] ?? '') === 'stale' ? 'informes caducados; actualizar auditorías.' : 'no hay informes recientes disponibles.') . '</p>';
        }
        if (in_array($merchant['state'] ?? '', array('ok','partial'), true)) {
            echo '<p><strong>Merchant ES:</strong> benchmarks de precio '
                . esc_html($merchant['prices'] !== null ? number_format_i18n($merchant['prices']) : 'no disponibles')
                . ' · productos populares ' . esc_html($merchant['popular'] !== null ? number_format_i18n($merchant['popular']) : 'no disponibles')
                . ' · última descarga ' . esc_html((string) ($merchant['updated_at'] ?? '')) . '.</p>';
            echo '<p>Los benchmarks son referencias agregadas de Google, no ofertas individuales ni precios actuales de la tienda. Revisar diferencias antes de modificar precios.</p>';
            if (!empty($merchant['rows'])) {
                echo '<ul>';
                foreach (array_slice($merchant['rows'], 0, 3) as $item) {
                    echo '<li>' . esc_html($item['title'] ?: ('Producto #' . $item['id']))
                        . ': precio informado ' . esc_html(number_format_i18n($item['price'], 2))
                        . ' EUR · benchmark agregado ' . esc_html(number_format_i18n($item['benchmark'], 2))
                        . ' EUR. Verificar margen y precio actual.</li>';
                }
                echo '</ul>';
            }
        } else {
            echo '<p><strong>Merchant:</strong> ' . esc_html(($merchant['state'] ?? '') === 'stale' ? 'instantáneas caducadas; sincronizar de nuevo.' : 'sin datos recientes válidos de Merchant ES.') . '</p>';
        }
        echo '<p class="description">Menciones o recomendaciones directas en ChatGPT, Gemini y Claude: no medidas. La calidad editorial y los datos estructurados no demuestran por sí solos visibilidad en asistentes.</p></section>';
    }
}
