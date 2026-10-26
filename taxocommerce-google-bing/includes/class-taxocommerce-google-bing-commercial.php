<?php
/**
 * Customer-facing commercial decisions from locally prepared Insights snapshots.
 *
 * No API requests, price guesses, writes, or checkout operations are performed.
 * All ratios describe event counts, not distinct customers or attributed orders.
 *
 * @package TaxoCommerceInsights
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Commercial {

    /** Build a stable, exportable summary from prepared (cached) data. */
    public static function build( $prepared, $market ) {
        $search = (array) ( $prepared['search'] ?? array() );
        $analytics = (array) ( $prepared['analytics'] ?? array() );
        $search_available = ! empty( $search['snapshot']['available'] );
        $analytics_available = ! empty( $analytics['snapshot']['available'] );
        $s = (array) ( $search['summary'] ?? array() );
        $a = (array) ( $analytics['summary'] ?? array() );
        $sc = (array) ( $s['current'] ?? array() );
        $sp = (array) ( $s['previous'] ?? array() );
        $ac = (array) ( $a['current'] ?? array() );
        $ap = (array) ( $a['previous'] ?? array() );
        $f = (array) ( $analytics['funnel'] ?? array() );
        $fc = (array) ( $f['current'] ?? array() );
        $fp = (array) ( $f['previous'] ?? array() );
        $search_comparable = $search_available && isset( $sp['clicks'], $sp['impressions'] );
        $analytics_comparable = $analytics_available && isset( $ap['sessions'], $ap['ecommercePurchases'] );

        $clicks = $search_available ? max( 0, (int) ( $sc['clicks'] ?? 0 ) ) : null;
        $impressions = $search_available ? max( 0, (int) ( $sc['impressions'] ?? 0 ) ) : null;
        $sessions = $analytics_available ? max( 0, (int) ( $ac['sessions'] ?? 0 ) ) : null;
        $purchases = $analytics_available ? max( 0, (int) ( $fc['purchase'] ?? $ac['ecommercePurchases'] ?? 0 ) ) : null;
        $view_item = $analytics_available && isset( $fc['view_item'] ) ? max( 0, (int) $fc['view_item'] ) : null;
        $add_to_cart = $analytics_available && isset( $fc['add_to_cart'] ) ? max( 0, (int) $fc['add_to_cart'] ) : null;
        $checkout = $analytics_available && isset( $fc['begin_checkout'] ) ? max( 0, (int) $fc['begin_checkout'] ) : null;

        $countries = (array) ( $analytics['countries'] ?? array() );
        $country_labels = (array) ( $countries['labels'] ?? array() );
        $country_counts = (array) ( $countries['current'] ?? array() );
        $spain = self::country_users( $country_labels, $country_counts, array( 'spain', 'espana', 'españa' ) );
        $us = self::country_users( $country_labels, $country_counts, array( 'united states', 'estados unidos' ) );

        $kpis = array(
            'impressions' => self::metric( $impressions, $search_comparable ? (int) $sp['impressions'] : null ),
            'clicks' => self::metric( $clicks, $search_comparable ? (int) $sp['clicks'] : null ),
            'sessions' => self::metric( $sessions, $analytics_comparable ? (int) $ap['sessions'] : null ),
            'purchases' => self::metric( $purchases, $analytics_comparable ? (int) ( $fp['purchase'] ?? $ap['ecommercePurchases'] ) : null ),
            'purchase_per_session_pct' => $sessions > 0 && null !== $purchases ? round( 100 * $purchases / $sessions, 2 ) : null,
            'product_to_cart_event_pct' => $view_item > 0 && null !== $add_to_cart ? round( 100 * $add_to_cart / $view_item, 2 ) : null,
        );
        $funnel = array(
            'view_item' => $view_item,
            'add_to_cart' => $add_to_cart,
            'begin_checkout' => $checkout,
            'purchase' => $purchases,
        );
        $items = array();

        if ( ! $search_available ) {
            self::action( $items, 'SET_UP_SEARCH', 'NOW', 96, 'Update Google Search data',
                'Download a Search Console report so Analyst can evaluate search demand.',
                'Search Console snapshot is missing.', 'google_search', 'Search Console' );
        } elseif ( $impressions >= 50 && $clicks <= max( 3, (int) round( $impressions * 0.01 ) ) ) {
            self::action( $items, 'SEARCH_VISIBILITY_TO_CLICKS', 'NOW', 86,
                'Improve clicks from visible search results',
                'Review queries and their real landing pages, then prioritize relevant titles and content. Start with positions 4-20 where possible.',
                number_format_i18n( $impressions ) . ' impressions and ' . number_format_i18n( $clicks ) . ' clicks. Average position alone is not a conversion metric.',
                'google_search', 'Search Console' );
        } elseif ( $search_comparable && (int) $sc['impressions'] > (int) $sp['impressions'] && (int) $sc['clicks'] <= (int) $sp['clicks'] ) {
            self::action( $items, 'SEARCH_VISIBILITY_TO_CLICKS', 'NEXT', 69,
                'Turn search visibility into visits',
                'Review pages with rising impressions but unchanged clicks; validate query and destination before editing.',
                'Impressions rose, but clicks did not.', 'google_search', 'Search Console' );
        }

        if ( ! $analytics_available ) {
            self::action( $items, 'SET_UP_ANALYTICS', 'NOW', 95, 'Update GA4 data',
                'Download Analytics data to measure product events, purchases, and acquisition channels.',
                'Analytics snapshot is missing.', 'google_analytics', 'Google Analytics' );
        } else {
            if ( null !== $checkout && $checkout > 0 && null !== $purchases && $purchases < $checkout ) {
                self::action( $items, 'CHECKOUT_TEST', 'NOW', 94, 'Test the purchase journey',
                    'Complete a test order and verify cart, shipping, payment, confirmation and GA4 events. Do not infer abandonment from event totals.',
                    number_format_i18n( $checkout ) . ' checkout-start events and ' . number_format_i18n( $purchases ) . ' purchase events. Event totals do not track individual journeys.',
                    'google_analytics', 'Google Analytics' );
            }
            if ( null !== $view_item && $view_item >= 30 && null !== $add_to_cart && $add_to_cart * 100 < $view_item * 5 ) {
                self::action( $items, 'PRODUCT_OFFERS', 'NOW', 80, 'Review product offers',
                    'Check price, availability, shipping and calls to action on your most-viewed product pages before changing SEO copy.',
                    number_format_i18n( $view_item ) . ' product-view events and ' . number_format_i18n( $add_to_cart ) . ' add-to-cart events.',
                    'google_analytics', 'Google Analytics' );
            }
            if ( $sessions >= 100 && null !== $purchases && $purchases / $sessions < 0.01 ) {
                self::action( $items, 'REVIEW_CONVERSION', 'NEXT', 74, 'Review conversion by sales model',
                    'Separate own-store purchases from outbound supplier referrals, and compare channels and countries before judging conversion.',
                    number_format_i18n( $sessions ) . ' sessions and ' . number_format_i18n( $purchases ) . ' measured purchases; affiliate orders are not proved by this metric.',
                    'google_analytics', 'Google Analytics' );
            }
            if ( $spain > 0 && $us > 0 ) {
                self::action( $items, 'SEGMENT_COUNTRIES', 'NEXT', 70, 'Separate local and international results',
                    'Compare sessions and purchase actions by country and channel. Do not combine own-store and off-site conversion rates.',
                    number_format_i18n( $spain ) . ' active users in Spain and ' . number_format_i18n( $us ) . ' in the United States.',
                    'google_analytics', 'Google Analytics' );
            }
        }

        if ( ! empty( $market['available'] ) && $analytics_available && $view_item > 0 ) {
            self::action( $items, 'CHECK_MARKET_PRICES', 'INVESTIGATE', 65,
                'Compare commercial terms for visited products',
                'Check exact product identity, available stock, VAT, total delivery cost and comparable competitor offers before changing prices.',
                'Market evidence is available. It does not establish a comparable price for each viewed product.',
                'mercado', 'Market' );
        }

        usort( $items, static function ( $left, $right ) {
            $rank = array( 'NOW' => 0, 'NEXT' => 1, 'INVESTIGATE' => 2 );
            $a = $rank[ $left['bucket'] ] ?? 9;
            $b = $rank[ $right['bucket'] ] ?? 9;
            return $a === $b ? $right['priority'] <=> $left['priority'] : $a <=> $b;
        } );

        return array(
            'schema' => array( 'name' => 'taxocommerce-insights-commercial', 'version' => 1 ),
            'available' => $search_available || $analytics_available,
            'kpis' => $kpis,
            'funnel' => $funnel,
            'countries' => array( 'spain_users' => $spain, 'us_users' => $us ),
            'directives' => array_slice( $items, 0, 6 ),
            'sources' => array( 'google_search' => $search_available, 'ga4' => $analytics_available, 'market' => ! empty( $market['available'] ) ),
            'notes' => array(
                'Visibility is not a sale; a Google impression is not a site visit.',
                'GA4 event counts are not unique customers or linked funnel journeys.',
                'Off-site supplier orders and commissions cannot be inferred from WooCommerce purchases. Tracking must be verified separately.',
                'No provider APIs are called by this summary. Missing data is shown as unavailable, never as zero.',
            ),
        );
    }

    private static function metric( $current, $previous ) {
        return array( 'current' => $current, 'previous' => $previous );
    }

    private static function country_users( $labels, $values, $names ) {
        foreach ( $labels as $index => $label ) {
            $name = strtolower( remove_accents( (string) $label ) );
            if ( in_array( $name, $names, true ) ) {
                return max( 0, (int) ( $values[ $index ] ?? 0 ) );
            }
        }
        return 0;
    }

    private static function action( &$items, $id, $bucket, $priority, $title, $instruction, $reason, $tab, $source ) {
        $items[] = array(
            'id' => $id,
            'bucket' => $bucket,
            'priority' => $priority,
            'title' => $title,
            'instruction' => $instruction,
            'reason' => $reason,
            'target' => array( 'tab' => $tab, 'label' => $source ),
            'source' => $source,
        );
    }

    /** Render only a concise, accessible top-level summary; details stay below. */
    public static function render( $report ) {
        $kpis = (array) ( $report['kpis'] ?? array() );
        echo '<section class="tgb-analyst-section tgb-analyst-commercial">';
        echo '<div class="tgb-analyst-section-head"><div><h3>What should I do?</h3><p>Clear next steps from saved data. Investigate the cause before making changes.</p></div></div>';
        echo '<div class="tgb-analyst-commercial-kpis">';
        foreach ( array( 'impressions' => 'Google impressions', 'clicks' => 'Google clicks', 'sessions' => 'GA4 sessions', 'purchases' => 'Recorded purchases' ) as $key => $label ) {
            $metric = (array) ( $kpis[ $key ] ?? array() );
            $now = $metric['current'] ?? null;
            $was = $metric['previous'] ?? null;
            echo '<div class="tgb-analyst-commercial-kpi"><span>' . esc_html( $label ) . '</span><strong>' . ( null === $now ? '&mdash;' : esc_html( number_format_i18n( $now ) ) ) . '</strong>';
            if ( null !== $now && null !== $was ) {
                echo '<small>Previous: ' . esc_html( number_format_i18n( $was ) ) . '</small>';
            } else {
                echo '<small>Previous: unavailable</small>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="description">These are separate Google Search and GA4 measurements. Missing data is not zero.</p>';
        $funnel = (array) ( $report['funnel'] ?? array() );
        $maximum = max( 1, (int) ( $funnel['view_item'] ?? 0 ), (int) ( $funnel['add_to_cart'] ?? 0 ), (int) ( $funnel['begin_checkout'] ?? 0 ), (int) ( $funnel['purchase'] ?? 0 ) );
        if ( ! empty( $report['sources']['ga4'] ) ) {
            echo '<div class="tgb-analyst-commercial-funnel"><h4>Purchase events</h4>';
            foreach ( array( 'view_item' => 'Product views', 'add_to_cart' => 'Add to cart', 'begin_checkout' => 'Start checkout', 'purchase' => 'Purchase' ) as $key => $label ) {
                $count = $funnel[ $key ] ?? null;
                $width = null === $count ? 0 : max( 0, min( 100, (int) round( 100 * $count / $maximum ) ) );
                echo '<div class="tgb-analyst-commercial-funnel-row"><span>' . esc_html( $label ) . '</span><div class="tgb-analyst-commercial-track"><span style="width:' . esc_attr( (string) $width ) . '%"></span></div><strong>' . ( null === $count ? '&mdash;' : esc_html( number_format_i18n( $count ) ) ) . '</strong></div>';
            }
            echo '<p class="description">Event counts, not unique users moving through the same funnel.</p></div>';
        }
        echo '<h4>Recommended actions</h4>';
        if ( empty( $report['directives'] ) ) {
            echo '<p>There is not enough reliable evidence for a specific action yet. Update the saved reports and review the detailed diagnosis.</p>';
        }
        foreach ( (array) ( $report['directives'] ?? array() ) as $action ) {
            $target = (array) ( $action['target'] ?? array() );
            $url = add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => sanitize_key( (string) ( $target['tab'] ?? 'analyst' ) ) ), admin_url( 'admin.php' ) );
            echo '<article class="tgb-analyst-commercial-action">';
            echo '<span class="tgb-analyst-commercial-bucket">' . esc_html( (string) ( $action['bucket'] ?? '' ) ) . '</span>';
            echo '<div><h4>' . esc_html( (string) ( $action['title'] ?? '' ) ) . '</h4>';
            echo '<p>' . esc_html( (string) ( $action['instruction'] ?? '' ) ) . '</p>';
            echo '<small><strong>Why:</strong> ' . esc_html( (string) ( $action['reason'] ?? '' ) ) . '</small>';
            echo '<p><a href="' . esc_url( $url ) . '">Open ' . esc_html( (string) ( $target['label'] ?? 'report' ) ) . '</a></p></div></article>';
        }
        echo '<details class="tgb-analyst-commercial-notes"><summary>Data limitations</summary><ul>';
        foreach ( (array) ( $report['notes'] ?? array() ) as $note ) {
            echo '<li>' . esc_html( (string) $note ) . '</li>';
        }
        echo '</ul></details></section>';
    }
}
