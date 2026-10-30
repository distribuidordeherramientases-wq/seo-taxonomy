<?php
/**
 * Evidence-based SEO/GEO synthesis for the restored Analista.
 *
 * Uses validated Analista tasks and saved Auditor, Merchant and public HTML
 * snapshots. It never queries an AI provider, makes remote requests or edits
 * content. Search traffic from AI referrers is NOT a count of AI mentions.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'taxocommerce_google_bing_geo_schema_product' ) ) {
    /**
     * Inspect Product JSON-LD in HTML already collected by the competitive scan.
     * An unparsed/missing script is unknown, not proof that schema is absent.
     */
    function taxocommerce_google_bing_geo_schema_product( $html ) {
        $result = array( 'status' => 'unknown', 'product_found' => false, 'parsed_scripts' => 0, 'missing' => array() );
        if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script\s*>#is', (string) $html, $scripts, PREG_SET_ORDER ) ) {
            return $result;
        }
        $best = null;
        foreach ( array_slice( $scripts, 0, 30 ) as $script ) {
            if ( ! preg_match( '/\btype\s*=\s*["\x27]?application\/ld\+json\b/i', (string) $script[1] ) ) {
                continue;
            }
            $decoded = json_decode( html_entity_decode( trim( (string) $script[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }
            $result['parsed_scripts']++;
            $queue = array( $decoded );
            $visited = 0;
            while ( $queue && ++$visited <= 120 ) {
                $node = array_shift( $queue );
                if ( ! is_array( $node ) ) {
                    continue;
                }
                if ( isset( $node[0] ) ) {
                    foreach ( array_slice( $node, 0, 60 ) as $child ) {
                        $queue[] = $child;
                    }
                    continue;
                }
                foreach ( array( '@graph', 'mainEntity', 'hasVariant' ) as $child_key ) {
                    if ( isset( $node[ $child_key ] ) && is_array( $node[ $child_key ] ) ) {
                        $queue[] = $node[ $child_key ];
                    }
                }
                $types = array_map( 'strtolower', (array) ( $node['@type'] ?? array() ) );
                if ( ! in_array( 'product', $types, true ) ) {
                    continue;
                }
                $result['product_found'] = true;
                $offers = (array) ( $node['offers'] ?? array() );
                if ( isset( $offers[0] ) ) {
                    $offers = (array) $offers[0];
                }
                $price_spec = (array) ( $offers['priceSpecification'] ?? array() );
                $missing = array();
                if ( empty( $node['name'] ) ) {
                    $missing[] = 'name';
                }
                if ( empty( $node['image'] ) ) {
                    $missing[] = 'image';
                }
                if ( empty( $node['sku'] ) && empty( $node['gtin'] ) && empty( $node['gtin13'] ) && empty( $node['mpn'] ) ) {
                    $missing[] = 'sku/gtin/mpn';
                }
                if ( ! $offers ) {
                    $missing[] = 'offers';
                } else {
                    if ( ! isset( $offers['price'] ) && ! isset( $offers['lowPrice'] ) && ! isset( $price_spec['price'] ) ) {
                        $missing[] = 'offers.price';
                    }
                    if ( empty( $offers['priceCurrency'] ) && empty( $price_spec['priceCurrency'] ) ) {
                        $missing[] = 'offers.priceCurrency';
                    }
                    if ( empty( $offers['availability'] ) ) {
                        $missing[] = 'offers.availability';
                    }
                }
                if ( null === $best || count( $missing ) < count( $best ) ) {
                    $best = $missing;
                }
            }
        }
        if ( $result['product_found'] ) {
            $result['status'] = 'checked';
            $result['missing'] = $best;
        } elseif ( $result['parsed_scripts'] ) {
            $result['status'] = 'checked_non_product';
        }
        return $result;
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_recent' ) ) {
    function taxocommerce_google_bing_geo_recent( $date, $max_days = 30 ) {
        $timestamp = strtotime( (string) $date );
        $age = time() - (int) $timestamp;
        return $timestamp && $age >= -DAY_IN_SECONDS && $age <= absint( $max_days ) * DAY_IN_SECONDS;
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_merchant_candidates' ) ) {
    /** Merchant benchmark and offer price originate in the same saved ES report. */
    function taxocommerce_google_bing_geo_merchant_candidates( $limit = 2 ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return array();
        }
        $saved = get_option( 'seo_ojeador_merchant_snapshots_v1', array() );
        $prices = (array) ( $saved['prices'] ?? array() );
        if ( (string) ( $prices['country'] ?? '' ) !== 'ES' ||
            ! taxocommerce_google_bing_geo_recent( $prices['updated_at'] ?? '', 21 ) ) {
            return array();
        }
        $ranked = array();
        foreach ( (array) ( $prices['rows'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) || empty( $row['product_id'] ) || (string) ( $row['currency'] ?? '' ) !== 'EUR' ) {
                continue;
            }
            $offer = $row['price'] ?? null;
            $benchmark = $row['benchmark'] ?? null;
            if ( ! is_numeric( $offer ) || ! is_numeric( $benchmark ) || (float) $benchmark <= 0 ) {
                continue;
            }
            $delta = (float) $offer - (float) $benchmark;
            if ( $delta < 5 || $delta / (float) $benchmark < 0.20 ) {
                continue;
            }
            $ranked[] = array( 'product_id' => absint( $row['product_id'] ), 'price' => (float) $offer,
                'benchmark' => (float) $benchmark, 'delta_pct' => $delta / (float) $benchmark * 100 );
        }
        usort( $ranked, static function ( $a, $b ) {
            return $b['delta_pct'] <=> $a['delta_pct'];
        } );
        $result = array();
        foreach ( array_slice( $ranked, 0, 30 ) as $row ) {
            $product = wc_get_product( $row['product_id'] );
            if ( ! $product || ! method_exists( $product, 'get_status' ) || 'publish' !== $product->get_status() ) {
                continue;
            }
            $result[] = array(
                'title' => (string) $product->get_name(),
                'url' => (string) get_permalink( $row['product_id'] ),
                'action' => 'Revisar el precio y la propuesta comercial con la referencia Merchant antes de modificar la ficha.',
                'evidence' => 'Oferta del informe Merchant: ' . number_format( $row['price'], 2, ',', '.' )
                    . ' EUR; benchmark agregado: ' . number_format( $row['benchmark'], 2, ',', '.' )
                    . ' EUR (+' . number_format( $row['delta_pct'], 1, ',', '.' ) . '%).',
                'source' => 'Merchant Center · ' . (string) $prices['updated_at'],
                'bucket' => 'INVESTIGAR',
                'priority' => 65,
                'origin' => 'merchant',
            );
            if ( count( $result ) >= max( 1, min( 3, absint( $limit ) ) ) ) {
                break;
            }
        }
        return $result;
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_auditor_candidates' ) ) {
    /** Consume Auditor's completed sampled findings; never repeat its scans. */
    function taxocommerce_google_bing_geo_auditor_candidates( $limit = 2 ) {
        if ( ! class_exists( '\TaxoCommerce\Auditor\Runner' ) ) {
            return array();
        }
        $rows = array();
        foreach ( array( 'products', 'categories', 'pages', 'posts' ) as $scope ) {
            $report = \TaxoCommerce\Auditor\Runner::report( $scope );
            if ( (string) ( $report['schema'] ?? '' ) !== 'taxocommerce_auditor_native_v2' ||
                ! taxocommerce_google_bing_geo_recent( $report['completed_gmt'] ?? '', 30 ) ) {
                continue;
            }
            foreach ( (array) ( $report['findings_sample'] ?? array() ) as $finding ) {
                if ( ! is_array( $finding ) || ! in_array( $finding['severity'] ?? '', array( 'high', 'critical' ), true ) ) {
                    continue;
                }
                $id = absint( $finding['entity_id'] ?? 0 );
                if ( ! $id || empty( $finding['recommendation'] ) ) {
                    continue;
                }
                $type = (string) ( $finding['entity_type'] ?? '' );
                if ( 'product_cat' === $type || 'category' === $type ) {
                    $url = get_term_link( $id, 'product_cat' );
                    $url = is_wp_error( $url ) ? '' : (string) $url;
                } else {
                    if ( ! in_array( get_post_status( $id ), array( 'publish', 'private' ), true ) ) {
                        continue;
                    }
                    $url = (string) get_permalink( $id );
                }
                if ( '' === $url ) {
                    continue;
                }
                $rows[] = array(
                    'title' => (string) ( $finding['title'] ?? '' ),
                    'url' => $url,
                    'action' => (string) $finding['recommendation'],
                    'evidence' => 'Incidencia ' . (string) ( $finding['code'] ?? '' ) . ' (' . (string) $finding['severity'] . ').',
                    'source' => 'Auditor · ' . (string) $report['completed_gmt'] . ( 'full' === ( $report['run_mode'] ?? '' ) ? '' : ' · muestra filtrada' ),
                    'bucket' => 'REVISAR',
                    'priority' => min( 100, absint( $finding['priority_score'] ?? 70 ) ),
                    'origin' => 'auditor',
                );
            }
        }
        usort( $rows, static function ( $a, $b ) {
            return $b['priority'] <=> $a['priority'];
        } );
        return array_slice( $rows, 0, max( 1, min( 3, absint( $limit ) ) ) );
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_competitive_candidates' ) ) {
    /** Only report missing JSON-LD fields after a real, recent public scan. */
    function taxocommerce_google_bing_geo_competitive_candidates() {
        if ( ! defined( 'SEO_ANALISTA_COMPETITIVE_REPORT_OPTION' ) ) {
            return array();
        }
        $report = get_option( SEO_ANALISTA_COMPETITIVE_REPORT_OPTION, array() );
        if ( ! is_array( $report ) || ! taxocommerce_google_bing_geo_recent( $report['generated_at'] ?? '', 30 ) ) {
            return array();
        }
        foreach ( (array) ( $report['matrix'] ?? array() ) as $row ) {
            if ( empty( $row['is_own'] ) ) {
                continue;
            }
            $representative = (array) ( $row['public']['representative'] ?? array() );
            $schema = (array) ( $representative['metrics']['schema_product'] ?? array() );
            $url = (string) ( $representative['url'] ?? '' );
            if ( empty( $representative['available'] ) || 'checked' !== ( $schema['status'] ?? '' ) ||
                empty( $schema['product_found'] ) || empty( $schema['missing'] ) || '' === $url ) {
                return array();
            }
            return array( array(
                'title' => 'Datos estructurados de producto',
                'url' => $url,
                'action' => 'Comprobar en el HTML final los campos Product JSON-LD señalados, y contrastarlos con WooCommerce antes de corregir.',
                'evidence' => 'Campos no encontrados en la muestra pública: ' . implode( ', ', array_slice( $schema['missing'], 0, 8 ) ) . '.',
                'source' => 'Informe competitivo · ' . (string) $report['generated_at'],
                'bucket' => 'INVESTIGAR',
                'priority' => 70,
                'origin' => 'schema',
            ) );
        }
        return array();
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_recommendations' ) ) {
    /**
     * At most five unique, traceable actions. Analista owns demand and intent;
     * Auditor and Merchant only contribute evidence from saved snapshots.
     */
    function taxocommerce_google_bing_geo_recommendations( array $plan, $limit = 5 ) {
        $limit = max( 1, min( 5, absint( $limit ) ) );
        $now = array();
        $next = array();
        foreach ( $plan as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $bucket = function_exists( 'seo_analista_task_bucket' ) ? seo_analista_task_bucket( $row )
                : (string) ( $row['work_bucket'] ?? '' );
            if ( ! in_array( $bucket, array( 'HACER_AHORA', 'HACER_DESPUES' ), true ) ) {
                continue;
            }
            $primary = (array) ( $row['primary_action'] ?? array() );
            if ( 'HACER_AHORA' === $bucket && empty( $primary['executable'] ) ) {
                continue;
            }
            $url = (string) ( $row['target_url'] ?? $row['target']['url'] ?? $row['entity']['url'] ?? '' );
            $instruction = trim( (string) ( $primary['instruction'] ?? $row['recommended_changes'][0] ?? '' ) );
            if ( '' === $url || '' === $instruction ) {
                continue;
            }
            $entry = array(
                'title' => (string) ( $row['entity']['title'] ?? $row['topic'] ?? 'Entidad sin título' ),
                'url' => $url,
                'action' => $instruction,
                'evidence' => trim( (string) ( $row['reason'] ?? '' ) ),
                'source' => implode( ', ', array_slice( (array) ( $row['sources'] ?? array() ), 0, 3 ) ),
                'bucket' => $bucket,
                'priority' => absint( $row['priority_score'] ?? $row['priority'] ?? 0 ),
                'origin' => 'analista',
            );
            if ( 'HACER_AHORA' === $bucket ) {
                $now[] = $entry;
            } else {
                $next[] = $entry;
            }
        }
        $selected = array();
        $seen = array();
        $add = static function ( $row ) use ( &$selected, &$seen, $limit ) {
            $url = strtolower( rtrim( (string) ( $row['url'] ?? '' ), '/' ) );
            if ( '' === $url || isset( $seen[ $url ] ) || count( $selected ) >= $limit ) {
                return;
            }
            $seen[ $url ] = true;
            $selected[] = $row;
        };
        foreach ( array_slice( $now, 0, 3 ) as $row ) {
            $add( $row );
        }
        foreach ( taxocommerce_google_bing_geo_auditor_candidates( 2 ) as $row ) {
            $add( $row );
        }
        foreach ( taxocommerce_google_bing_geo_competitive_candidates() as $row ) {
            $add( $row );
        }
        foreach ( taxocommerce_google_bing_geo_merchant_candidates( 2 ) as $row ) {
            $add( $row );
        }
        foreach ( array_merge( $now, $next ) as $row ) {
            $add( $row );
        }
        return $selected;
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_commercial_candidates' ) ) {
    /**
     * Derive commercial investigations strictly from a saved GA4 funnel.
     * Events are aggregate counts, not evidence of individual abandonments.
     *
     * @param array $ga4 GA4 snapshot supplied by Analysis.
     * @return array
     */
    function taxocommerce_google_bing_geo_commercial_candidates( array $ga4 ) {
        if ( empty( $ga4['available'] ) ) {
            return array();
        }
        $funnel = (array) ( $ga4['funnel'] ?? array() );
        $sessions = max( 0, (int) ( $ga4['sessions'] ?? 0 ) );
        $purchases = max( 0, (int) ( $ga4['purchases'] ?? 0 ) );
        $checkouts = max( 0, (int) ( $funnel['begin_checkout'] ?? 0 ) );
        $carts = max( 0, (int) ( $funnel['add_to_cart'] ?? 0 ) );
        $entries = array();

        if ( $sessions > 0 && $checkouts > $purchases ) {
            $entries[] = array(
                'title' => 'Conversión: revisar checkout',
                'url' => function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '',
                'action' => 'Comprobar pagos, transporte y medición en una compra de prueba. No atribuir abandonos sin datos por usuario.',
                'evidence' => $checkouts . ' inicios de compra frente a ' . $purchases . ' compras; recuentos agregados de GA4.',
                'source' => 'Google Analytics 4 · embudo de WooCommerce',
                'bucket' => 'INVESTIGAR',
                'origin' => 'comercial',
            );
        }
        if ( $sessions > 0 && $carts > $checkouts ) {
            $entries[] = array(
                'title' => 'Conversión: revisar carrito',
                'url' => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '',
                'action' => 'Comprobar los costes finales y la transición del carrito al checkout antes de proponer cambios.',
                'evidence' => $carts . ' eventos de añadir al carrito frente a ' . $checkouts . ' inicios de compra; recuentos agregados de GA4.',
                'source' => 'Google Analytics 4 · embudo de WooCommerce',
                'bucket' => 'INVESTIGAR',
                'origin' => 'comercial',
            );
        }
        return $entries;
    }
}

if ( ! function_exists( 'taxocommerce_google_bing_geo_render_summary' ) ) {
    /**
     * One strategic shortlist: commercial investigations plus validated Analista,
     * Auditor, Product JSON-LD and Merchant signals. The complete action plan
     * is rendered below by the historical roadmap function.
     *
     * @param int        $days Analysis window.
     * @param array      $ga4 Saved analytics evidence, or unavailable.
     * @param array|null $plan Shared decision-plan rows, computed by Insights.
     * @return void
     */
    function taxocommerce_google_bing_geo_render_summary( $days, array $ga4 = array(), $plan = null ) {
        if ( ! is_array( $plan ) ) {
            $plan = seo_analista_decision_plan( $days, 40 );
        }
        $seo = taxocommerce_google_bing_geo_recommendations( (array) $plan, 5 );
        $commercial = taxocommerce_google_bing_geo_commercial_candidates( $ga4 );
        $strategy = array();
        $seen = array();
        // Preserve source attribution, prioritise validated executable actions
        // before investigations, and do not present any research as executable.
        foreach ( $seo as $item ) {
            if ( 'HACER_AHORA' === ( $item['bucket'] ?? '' ) ) {
                $strategy[] = $item;
            }
        }
        foreach ( array_merge( $commercial, $seo ) as $item ) {
            if ( count( $strategy ) >= 5 ) {
                break;
            }
            if ( 'HACER_AHORA' === ( $item['bucket'] ?? '' ) ) {
                continue;
            }
            $strategy[] = $item;
        }
        $unique = array();
        foreach ( $strategy as $item ) {
            $key = strtolower( rtrim( (string) ( $item['url'] ?? '' ), '/' ) );
            $key = $key ?: (string) ( $item['origin'] ?? '' ) . ':' . (string) ( $item['title'] ?? '' );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $unique[] = $item;
            if ( count( $unique ) === 5 ) {
                break;
            }
        }

        echo '<section class="seo-analista-section seo-analista-strategy"><h2>Plan estratégico integrado: ventas, SEO y visibilidad en IA</h2>';
        echo '<p>Una única selección de actuaciones basada en Analytics, Analista, Auditor, Merchant y evidencias de datos estructurados, cuando estén disponibles. Se distingue lo ejecutable de lo que requiere investigación.</p>';
        if ( ! $unique ) {
            echo '<p>No hay recomendaciones con evidencia suficiente en las fuentes disponibles. El plan de acción completo conserva las tareas para investigar o vigilar, sin fabricar intervenciones.</p>';
        } else {
            echo '<ol class="seo-analista-geo-priorities">';
            foreach ( $unique as $item ) {
                echo '<li><strong>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</strong> · ';
                echo '<strong>' . esc_html( (string) ( $item['bucket'] ?? 'INVESTIGAR' ) ) . '</strong>';
                echo '<p>' . esc_html( (string) ( $item['action'] ?? '' ) ) . '</p>';
                echo '<p><small>Evidencia: ' . esc_html( (string) ( $item['evidence'] ?? 'No especificada' ) ) . '</small></p>';
                echo '<p><small>Fuente: ' . esc_html( (string) ( $item['source'] ?? 'No especificada' ) ) . '</small>';
                if ( ! empty( $item['url'] ) ) {
                    echo ' · <a href="' . esc_url( (string) $item['url'] ) . '" target="_blank" rel="noopener noreferrer">Ver destino</a>';
                }
                echo '</p></li>';
            }
            echo '</ol>';
        }
        if ( ! empty( $ga4['available'] ) ) {
            echo '<p><strong>Sesiones atribuidas a asistentes IA en GA4:</strong> '
                . esc_html( number_format_i18n( (int) ( $ga4['traffic_quality']['ai_sessions'] ?? 0 ) ) )
                . '. Es tráfico referido, no menciones de marca ni visibilidad en respuestas.</p>';
        } else {
            echo '<p><strong>Sesiones atribuidas a asistentes IA:</strong> N/D (GA4 no disponible).</p>';
        }
        echo '<p class="description">Las buenas prácticas SEO/GEO no implican una integración con GPTCore ni garantizan apariciones en ChatGPT, Gemini o Claude. Las referencias a autoridad, backlinks o menciones reales se omiten si no hay mediciones verificables. No se ejecutan acciones automáticamente.</p></section>';
    }
}
