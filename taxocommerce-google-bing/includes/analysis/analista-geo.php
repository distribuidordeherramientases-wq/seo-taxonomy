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

if ( ! function_exists( 'taxocommerce_google_bing_geo_render_summary' ) ) {
    function taxocommerce_google_bing_geo_render_summary( $days, array $ga4 = array(), $plan = null ) {
        // Reuse the unified Analysis decision portfolio when provided.
        if ( ! is_array( $plan ) ) {
            $plan = seo_analista_decision_plan( $days, 25 );
        }
        $actions = taxocommerce_google_bing_geo_recommendations( (array) $plan, 5 );
        echo '<section class="seo-analista-section"><h3>Hasta cinco mejoras prioritarias verificables</h3>';
        echo '<p>SEO y preparación para que los asistentes encuentren información fiable. No son posiciones en ChatGPT, Gemini o Claude ni garantías de recomendación.</p>';
        if ( ! $actions ) {
            echo '<p>No hay acciones con evidencia y destino suficientes en las fuentes actuales. Revisar Plan de acción y Fuentes; no se proponen cambios inventados.</p>';
        } else {
            echo '<ol class="seo-analista-geo-priorities">';
            foreach ( $actions as $action ) {
                echo '<li><strong>' . esc_html( (string) $action['title'] ) . '</strong> · ' . esc_html( (string) $action['bucket'] );
                echo '<p>' . esc_html( (string) $action['action'] ) . '</p>';
                if ( '' !== (string) $action['evidence'] ) {
                    echo '<p><small>Evidencia: ' . esc_html( (string) $action['evidence'] ) . '</small></p>';
                }
                echo '<p><small>Fuente: ' . esc_html( (string) ( $action['source'] ?: 'No especificada' ) ) . '</small> · ';
                echo '<a href="' . esc_url( (string) $action['url'] ) . '" target="_blank" rel="noopener noreferrer">Ver URL</a></p></li>';
            }
            echo '</ol>';
        }
        if ( ! empty( $ga4['available'] ) ) {
            echo '<p><strong>Visitas atribuidas a asistentes IA en GA4:</strong> '
                . esc_html( number_format_i18n( (int) ( $ga4['traffic_quality']['ai_sessions'] ?? 0 ) ) )
                . '. No mide respuestas, citas ni menciones de marca.</p>';
        } else {
            echo '<p><strong>Visitas atribuidas a asistentes IA:</strong> N/D (GA4 no disponible).</p>';
        }
        echo '<p class="description">Los informes de Auditor, Merchant y competencia solo se aprovechan si existen snapshots recientes. Este bloque no consume APIs, no publica cambios y no calcula autoridad de backlinks sin fuente.</p></section>';
    }
}
