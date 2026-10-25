<?php
/**
 * Editorial specialist ported from the former Auditor's editorial diagnosis.
 *
 * Uses WordPress posts, product terms, Core role postmeta, and Vocabulary terms.
 * The specialist never publishes, edits, removes, merges or redirects content.
 *
 * @package TaxoCommerceAuditor
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Editorial_Specialist {
    const MAP_LIMIT = 2500;

    /**
     * Preserve legacy treatment of functional/corporate pages so they never
     * generate irrelevant length, SEO or Vocabulary audit findings.
     */
    public static function page_kind( $post ) {
        $role = sanitize_key( (string) get_post_meta( $post->ID, '_taxocommerce_core_seo_role', true ) );
        $slug = sanitize_title( (string) $post->post_name );

        $functional = array(
            'carrito', 'cart', 'finalizar-compra', 'checkout', 'mi-cuenta',
            'my-account', 'tienda', 'shop', 'blog', 'dependiente',
        );
        $corporate = array(
            'inicio', 'nosotros', 'nuestro-servicio', 'quienes-somos',
            'contacto', 'privacidad-de-datos', 'politica-de-privacidad',
            'politica-privacidad', 'terminos-y-condiciones',
            'terminos-condiciones', 'devoluciones-y-reembolsos',
            'condiciones-de-venta', 'politica-de-cookies', 'cookies',
            'aviso-legal', 'proveedores-de-distribuidor-de-herramientas-es',
        );

        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( array( 'cart', 'checkout', 'myaccount', 'shop' ) as $woo_page ) {
                $woo_id = (int) wc_get_page_id( $woo_page );
                if ( $woo_id > 0 && $woo_id === (int) $post->ID ) {
                    return 'functional';
                }
            }
        }
        if ( in_array( $slug, $functional, true ) ) {
            return 'functional';
        }
        if ( 'corporate_page' === $role || in_array( $slug, $corporate, true ) ) {
            return 'corporate';
        }
        if ( in_array( $role, array( 'cluster', 'hub_primary', 'hub_secondary' ), true ) ) {
            return 'canonical';
        }
        return 'landing' === $role ? 'landing' : 'editorial';
    }

    private static function entity( $type, $id, $title, $slug, $kind, $role = '' ) {
        return array(
            'type'  => $type,
            'id'    => absint( $id ),
            'title' => (string) $title,
            'slug'  => sanitize_title( (string) $slug ),
            'kind'  => $kind,
            'role'  => sanitize_key( $role ),
        );
    }

    /**
     * Build a bounded snapshot of published editorial owners.
     *
     * The limit is declared as partial in reports: a sampled source must never
     * masquerade as a complete content map.
     */
    public static function build_map() {
        $objects = get_posts(
            array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => self::MAP_LIMIT + 1,
                'orderby'       => 'ID',
                'order'         => 'ASC',
                'no_found_rows' => true,
                'suppress_filters' => false,
            )
        );

        $partial = count( (array) $objects ) > self::MAP_LIMIT;
        $map = array(
            'posts'      => array(),
            'canonical'  => array(),
            'landings'   => array(),
            'pages'      => array(),
            'categories' => array(),
            'partial'    => $partial,
            'indexed_posts_pages' => 0,
        );

        foreach ( array_slice( (array) $objects, 0, self::MAP_LIMIT ) as $id ) {
            $post = get_post( absint( $id ) );
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }
            $kind = 'post' === $post->post_type ? 'post' : self::page_kind( $post );
            if ( in_array( $kind, array( 'functional', 'corporate' ), true ) ) {
                continue;
            }
            $role = 'page' === $post->post_type
                ? (string) get_post_meta( $post->ID, '_taxocommerce_core_seo_role', true )
                : '';
            $entity = self::entity( $post->post_type, $post->ID, $post->post_title, $post->post_name, $kind, $role );
            if ( 'post' === $kind ) {
                $map['posts'][] = $entity;
            } else {
                $map['pages'][] = $entity;
                if ( 'canonical' === $kind ) {
                    $map['canonical'][] = $entity;
                } elseif ( 'landing' === $kind ) {
                    $map['landings'][] = $entity;
                }
            }
            $map['indexed_posts_pages']++;
        }

        if ( taxonomy_exists( 'product_cat' ) ) {
            $terms = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'fields'     => 'all',
                )
            );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( (array) $terms as $term ) {
                    $map['categories'][] = self::entity(
                        'product_cat',
                        $term->term_id,
                        $term->name,
                        $term->slug,
                        'canonical',
                        'category'
                    );
                }
            }
        }
        return $map;
    }

    private static function tokens( $title ) {
        $stop = array(
            'para','como','este','esta','estos','estas','sobre','guia','guias',
            'todo','todos','todas','mejor','mejores','del','las','los','una',
            'uno','unos','unas','con','sin','por','que','debe','debo',
            'uso','usar','producto','productos',
        );
        $tokens = array();
        foreach ( explode( ' ', Rules::identity_key( $title ) ) as $word ) {
            if ( mb_strlen( $word, 'UTF-8' ) >= 3 && ! in_array( $word, $stop, true ) ) {
                $tokens[ $word ] = true;
            }
        }
        return array_keys( $tokens );
    }

    public static function title_similarity( $left, $right ) {
        $a = self::tokens( (string) $left );
        $b = self::tokens( (string) $right );
        if ( ! $a || ! $b ) {
            return 0.0;
        }
        return count( array_intersect( $a, $b ) ) / count( array_unique( array_merge( $a, $b ) ) );
    }

    private static function exact_match( $entity, $candidates ) {
        $best = array();
        foreach ( (array) $candidates as $candidate ) {
            if ( $entity['type'] === $candidate['type'] && $entity['id'] === $candidate['id'] ) {
                continue;
            }
            $exact_slug  = $entity['slug'] !== '' && $entity['slug'] === $candidate['slug'];
            $exact_title = Rules::identity_key( $entity['title'] ) !== ''
                && Rules::identity_key( $entity['title'] ) === Rules::identity_key( $candidate['title'] );
            if ( ! $exact_slug && ! $exact_title ) {
                continue;
            }
            $score = ( $exact_slug ? 2 : 0 ) + ( $exact_title ? 1 : 0 );
            if ( ! $best || $score > $best['score'] ) {
                $best = array(
                    'entity'      => $candidate,
                    'score'       => $score,
                    'exact_title' => $exact_title,
                    'exact_slug'  => $exact_slug,
                );
            }
        }
        return $best;
    }

    private static function vocabulary( $entity ) {
        if ( ! taxonomy_exists( 'tc_semantic_tag' ) ) {
            return array();
        }
        if ( 'product_cat' === $entity['type'] ) {
            $ids = get_term_meta( $entity['id'], '_taxocommerce_vocabulary_semantic_terms', true );
            return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
        }
        $ids = wp_get_object_terms(
            $entity['id'],
            'tc_semantic_tag',
            array( 'fields' => 'ids' )
        );
        return is_wp_error( $ids ) ? array() : array_values( array_map( 'absint', (array) $ids ) );
    }

    private static function supported_overlap( $entity, $candidates ) {
        $best = array();
        $tokens = self::tokens( $entity['title'] );
        if ( count( $tokens ) < 2 || ! taxonomy_exists( 'tc_semantic_tag' ) ) {
            return $best;
        }
        $own_vocabulary = self::vocabulary( $entity );
        if ( ! $own_vocabulary ) {
            return $best;
        }
        foreach ( (array) $candidates as $candidate ) {
            if ( $entity['type'] === $candidate['type'] && $entity['id'] === $candidate['id'] ) {
                continue;
            }
            $similarity = self::title_similarity( $entity['title'], $candidate['title'] );
            if ( $similarity < 0.55 ) {
                continue;
            }
            $other_vocabulary = self::vocabulary( $candidate );
            $shared = count( array_intersect( $own_vocabulary, $other_vocabulary ) );
            $union = count( array_unique( array_merge( $own_vocabulary, $other_vocabulary ) ) );
            $ratio = $union ? $shared / $union : 0;
            // Same support criterion as legacy: title >= .55 plus vocabulary >= .55.
            if ( ! $shared || $ratio < 0.55 ) {
                continue;
            }
            $score = $similarity + $ratio;
            if ( ! $best || $score > $best['score'] ) {
                $best = array(
                    'entity'              => $candidate,
                    'score'               => $score,
                    'title_similarity'    => round( $similarity, 3 ),
                    'vocabulary_overlap' => round( $ratio, 3 ),
                );
            }
        }
        return $best;
    }

    private static function finding( $code, $severity, $entity, $reason, $recommendation, $other = array() ) {
        return array(
            'code'           => $code,
            'severity'       => $severity,
            'entity_type'    => $entity['type'],
            'entity_id'      => $entity['id'],
            'title'          => $entity['title'],
            'evidence'       => $reason,
            'recommendation' => $recommendation,
            'context'        => $other,
        );
    }

    private static function reference( $match ) {
        if ( ! $match ) {
            return '';
        }
        $other = $match['entity'];
        return $other['type'] . ' #' . absint( $other['id'] ) . ': ' . (string) $other['title'];
    }

    /**
     * The same intent-ownership precedence as the original editorial Auditor:
     * structural owner > landing > informational post.
     */
    public static function inspect( \WP_Post $post, $scope, $map ) {
        if ( ! in_array( $scope, array( 'posts', 'pages' ), true ) ) {
            return array();
        }
        $kind = 'posts' === $scope ? 'post' : self::page_kind( $post );
        if ( in_array( $kind, array( 'corporate', 'functional' ), true ) ) {
            return array();
        }

        $entity = self::entity(
            $post->post_type,
            $post->ID,
            $post->post_title,
            $post->post_name,
            $kind
        );
        $findings = array();
        $canonical = (array) ( $map['canonical'] ?? array() );
        $landings  = (array) ( $map['landings'] ?? array() );
        $posts     = (array) ( $map['posts'] ?? array() );
        $categories = (array) ( $map['categories'] ?? array() );

        if ( 'posts' === $scope ) {
            $exact = self::exact_match( $entity, $canonical );
            if ( $exact ) {
                $findings[] = self::finding(
                    'editorial_post_canonical_cannibalization',
                    ! empty( $exact['exact_slug'] ) ? 'high' : 'medium',
                    $entity,
                    'El post comparte identidad de título o slug con ' . self::reference( $exact ),
                    'La página estructural debe conservar la intención amplia. Revisar si el post aporta una intención informativa distinta.',
                    $exact
                );
                return $findings;
            }

            $exact = self::exact_match( $entity, $landings );
            if ( $exact ) {
                $findings[] = self::finding(
                    'editorial_post_landing_cannibalization',
                    ! empty( $exact['exact_slug'] ) ? 'high' : 'medium',
                    $entity,
                    'El post comparte identidad con ' . self::reference( $exact ),
                    'Revisar qué URL debe cubrir la necesidad transversal y diferenciar el post informativo.',
                    $exact
                );
                return $findings;
            }

            foreach ( $categories as $category ) {
                $sim = self::title_similarity( $entity['title'], $category['title'] );
                if ( $sim >= 0.95 ) {
                    $findings[] = self::finding(
                        'editorial_post_category_cannibalization',
                        'medium',
                        $entity,
                        'Título prácticamente idéntico al de la categoría #' . $category['id'],
                        'La categoría concentra la intención comercial; verificar que el post sea realmente informativo.'
                    );
                    return $findings;
                }
            }

            $near = self::supported_overlap( $entity, $canonical );
            if ( $near ) {
                $findings[] = self::finding(
                    'editorial_post_structural_overlap',
                    'low',
                    $entity,
                    'Solapamiento semántico apoyado por términos nativos con ' . self::reference( $near ),
                    'Diferenciar intención informativa y cobertura estructural antes de enriquecer.',
                    $near
                );
            }
            return $findings;
        }

        if ( 'editorial' === $kind ) {
            $findings[] = self::finding(
                'editorial_page_role_unclassified',
                'low',
                $entity,
                'Página editorial sin función SEO definida en Core.',
                'Clasificar la página existente antes de crear nuevas URLs.'
            );
            return $findings;
        }

        if ( 'canonical' === $kind ) {
            $exact = self::exact_match( $entity, $posts );
            if ( $exact ) {
                $findings[] = self::finding(
                    'editorial_canonical_post_cannibalization',
                    ! empty( $exact['exact_slug'] ) ? 'high' : 'medium',
                    $entity,
                    'La página estructural coincide con ' . self::reference( $exact ),
                    'Conservar la intención amplia en el cluster/hub y revisar el post duplicado.',
                    $exact
                );
            }
            return $findings;
        }

        $exact = self::exact_match( $entity, $canonical );
        if ( $exact ) {
            $findings[] = self::finding(
                'editorial_landing_canonical_cannibalization',
                ! empty( $exact['exact_slug'] ) ? 'high' : 'medium',
                $entity,
                'La landing duplica identidad con ' . self::reference( $exact ),
                'La landing solo se justifica para una necesidad transversal diferente de la jerarquía.',
                $exact
            );
        } else {
            $near = self::supported_overlap( $entity, $canonical );
            if ( $near ) {
                $findings[] = self::finding(
                    'editorial_landing_structural_overlap',
                    'low',
                    $entity,
                    'Posible solapamiento con ' . self::reference( $near ),
                    'Revisar la intención transversal de esta landing.',
                    $near
                );
            }
        }

        $exact = self::exact_match( $entity, $landings );
        if ( $exact ) {
            $findings[] = self::finding(
                'editorial_landing_duplicate',
                ! empty( $exact['exact_slug'] ) ? 'high' : 'medium',
                $entity,
                'Landing coincidente con ' . self::reference( $exact ),
                'Valorar consolidar contenido y mantener una sola URL propietaria.',
                $exact
            );
        } else {
            $near = self::supported_overlap( $entity, $landings );
            if ( $near ) {
                $findings[] = self::finding(
                    'editorial_landing_merge_candidate',
                    'low',
                    $entity,
                    'Dos landings comparten intención y Vocabulary: ' . self::reference( $near ),
                    'Candidatas a revisar para posible fusión; no aplicar automáticamente.',
                    $near
                );
            }
        }

        $exact = self::exact_match( $entity, $posts );
        if ( $exact ) {
            $findings[] = self::finding(
                'editorial_landing_post_cannibalization',
                ! empty( $exact['exact_slug'] ) ? 'high' : 'medium',
                $entity,
                'La landing coincide con ' . self::reference( $exact ),
                'Conservar la landing transversal y revisar el alcance del post informativo.',
                $exact
            );
        }

        foreach ( $categories as $category ) {
            $sim = self::title_similarity( $entity['title'], $category['title'] );
            if ( $sim >= 0.95 ) {
                $findings[] = self::finding(
                    'editorial_landing_category_overlap',
                    'medium',
                    $entity,
                    'Landing prácticamente equivalente al nombre de categoría #' . $category['id'],
                    'Revisar si la landing duplica innecesariamente la categoría comercial.'
                );
                break;
            }
        }
        return $findings;
    }
}
