<?php
/**
 * Category balance diagnostics based on the original Auditor category process.
 *
 * The original model treats 5-10 products as an operational reference, not an
 * automatic SEO rule. Split/merge proposals require concrete semantic evidence.
 * This reader cannot edit categories, move products or install redirects.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Category_Review {
    const IDEAL_MIN = 5;
    const IDEAL_MAX = 10;
    const SAMPLE_MAX = 200;

    private static $siblings = array();

    private static function finding( $code, $severity, \WP_Term $term, $reason, $recommendation, $context = array() ) {
        return array(
            'code'           => $code,
            'severity'       => $severity,
            'entity_type'    => 'product_cat',
            'entity_id'      => absint( $term->term_id ),
            'title'          => $term->name,
            'evidence'       => $reason,
            'recommendation' => $recommendation,
            'context'        => $context,
        );
    }

    private static function term_concepts( $term_id ) {
        if ( ! taxonomy_exists( 'tc_semantic_tag' ) ) {
            return array();
        }
        $ids = get_term_meta( absint( $term_id ), '_taxocommerce_vocabulary_semantic_terms', true );
        return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
    }

    private static function similarity( $title_a, $title_b ) {
        $a = explode( ' ', Rules::identity_key( $title_a ) );
        $b = explode( ' ', Rules::identity_key( $title_b ) );
        $a = array_values( array_filter( array_unique( $a ) ) );
        $b = array_values( array_filter( array_unique( $b ) ) );
        if ( ! $a || ! $b ) {
            return 0.0;
        }
        return count( array_intersect( $a, $b ) ) / count( array_unique( array_merge( $a, $b ) ) );
    }

    /**
     * Only adjacent categories with clear lexical/semantic support qualify.
     * A small category alone does not justify consolidation.
     */
    private static function merge_candidate( \WP_Term $source ) {
        $parent = absint( $source->parent );
        if ( ! isset( self::$siblings[ $parent ] ) ) {
            $rows = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'parent'     => $parent,
                    'hide_empty' => false,
                )
            );
            self::$siblings[ $parent ] = is_wp_error( $rows ) ? array() : (array) $rows;
        }
        $own_concepts = self::term_concepts( $source->term_id );
        $best = array();
        $score_best = 0.0;
        foreach ( self::$siblings[ $parent ] as $other ) {
            if ( absint( $other->term_id ) === absint( $source->term_id ) || absint( $other->count ) < self::IDEAL_MIN ) {
                continue;
            }
            $title_score = self::similarity( $source->name, $other->name );
            $other_concepts = self::term_concepts( $other->term_id );
            $union = count( array_unique( array_merge( $own_concepts, $other_concepts ) ) );
            $semantic_score = $union ? count( array_intersect( $own_concepts, $other_concepts ) ) / $union : 0;
            // Require strong identity support; do not infer a merge from size.
            if ( $title_score < 0.6 && $semantic_score < 0.7 ) {
                continue;
            }
            $score = max( $title_score, $semantic_score );
            if ( $score > $score_best ) {
                $score_best = $score;
                $best = array(
                    'target_id'        => absint( $other->term_id ),
                    'target_name'      => $other->name,
                    'target_products'  => absint( $other->count ),
                    'title_similarity' => round( $title_score, 3 ),
                    'concept_overlap'  => round( $semantic_score, 3 ),
                );
            }
        }
        return $best;
    }

    /**
     * Matches legacy TIPO/SUBTIPO cohort logic using native tc_tipo terms.
     * The pilot does not invent categories when taxonomy evidence is missing.
     */
    private static function split_candidate( \WP_Term $term ) {
        if ( absint( $term->count ) > self::SAMPLE_MAX || ! taxonomy_exists( 'tc_tipo' ) ) {
            return array();
        }
        $query = new \WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => self::SAMPLE_MAX + 1,
                'no_found_rows'  => true,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Single exact product_cat term; at most 201 IDs for categories with <= 200 products, no_found_rows and no child expansion.
                'tax_query'      => array(
                    array(
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => array( absint( $term->term_id ) ),
                        'include_children' => false,
                    ),
                ),
            )
        );
        $ids = array_values( array_map( 'absint', (array) $query->posts ) );
        if ( count( $ids ) > self::SAMPLE_MAX || count( $ids ) <= self::IDEAL_MAX ) {
            return array();
        }

        $types = wp_get_object_terms( $ids, 'tc_tipo', array( 'fields' => 'all_with_object_id' ) );
        if ( is_wp_error( $types ) || ! $types ) {
            return array();
        }
        $membership = array();
        $names = array();
        foreach ( $types as $type ) {
            $product_id = absint( $type->object_id ?? 0 );
            $concept_id = absint( $type->term_id ?? 0 );
            if ( ! $product_id || ! $concept_id ) {
                continue;
            }
            $membership[ $product_id ][ $concept_id ] = true;
            $names[ $concept_id ] = (string) $type->name;
        }
        $groups = array();
        $ambiguous = 0;
        foreach ( $ids as $product_id ) {
            $concepts = array_keys( (array) ( $membership[ $product_id ] ?? array() ) );
            if ( count( $concepts ) > 1 ) {
                $ambiguous++;
            } elseif ( count( $concepts ) === 1 ) {
                $concept = absint( $concepts[0] );
                $groups[ $concept ] = ( $groups[ $concept ] ?? 0 ) + 1;
            }
        }
        $eligible = array_filter( $groups, static function ( $n ) {
            return $n >= self::IDEAL_MIN;
        } );
        arsort( $eligible, SORT_NUMERIC );
        $covered = array_sum( $eligible );
        if ( count( $eligible ) < 2 || $covered / count( $ids ) < 0.6 || $ambiguous / count( $ids ) > 0.2 ) {
            return array();
        }

        $cohorts = array();
        foreach ( array_slice( $eligible, 0, 8, true ) as $id => $count ) {
            $cohorts[] = array(
                'concept_term_id' => absint( $id ),
                'label'           => $names[ $id ] ?? (string) $id,
                'products'        => absint( $count ),
            );
        }
        return array(
            'sampled_products'     => count( $ids ),
            'cohorts'              => $cohorts,
            'unclassified_products' => count( $ids ) - $covered - $ambiguous,
            'ambiguous_products'   => $ambiguous,
            'coverage_ratio'       => round( $covered / count( $ids ), 3 ),
            'proposal_only'        => true,
        );
    }

    public static function inspect( \WP_Term $term ) {
        $count = absint( $term->count );
        $out = array( 'findings' => array(), 'inventory' => null );
        $review_split = $count > self::IDEAL_MAX;
        $review_merge = $count > 0 && $count < self::IDEAL_MIN;
        $proposal = array();

        if ( $review_split ) {
            $out['findings'][] = self::finding(
                'category_oversized',
                'low',
                $term,
                'La categoría contiene ' . $count . ' productos; referencia operativa antigua: 5-10.',
                'Revisar el tamaño y la coherencia semántica. No dividir únicamente por cantidad.'
            );
            $proposal = self::split_candidate( $term );
            if ( $proposal ) {
                $out['findings'][] = self::finding(
                    'category_split_candidate',
                    'medium',
                    $term,
                    'Se identifican ' . count( $proposal['cohorts'] ) . ' cohortes TIPO de productos con apoyo de Vocabulary nativo.',
                    'Examinar los productos ambiguos y validar manualmente los destinos de cada cohorte antes de dividir.',
                    $proposal
                );
            }
        } elseif ( $review_merge ) {
            $out['findings'][] = self::finding(
                'category_underfilled',
                'low',
                $term,
                'La categoría contiene ' . $count . ' productos; referencia operativa antigua: 5-10.',
                'Revisar si conviene conservarla o agruparla con una categoría hermana coherente.'
            );
            $proposal = self::merge_candidate( $term );
            if ( $proposal ) {
                $out['findings'][] = self::finding(
                    'category_merge_candidate',
                    'medium',
                    $term,
                    'Existe una categoría hermana con identidad semántica próxima: ' . $proposal['target_name'] . ' #' . $proposal['target_id'] . '.',
                    'Verificar landings, estructura y reglas de redirección antes de aprobar cualquier concentración.',
                    $proposal
                );
            }
        }

        $out['inventory'] = array(
            'category_id'       => absint( $term->term_id ),
            'category_name'     => (string) $term->name,
            'products'          => $count,
            'review_split'      => $review_split,
            'review_merge'      => $review_merge,
            'split_proposal'    => $review_split && (bool) $proposal,
            'merge_proposal'    => $review_merge && (bool) $proposal,
            'proposal'          => $proposal,
        );
        return $out;
    }
}
