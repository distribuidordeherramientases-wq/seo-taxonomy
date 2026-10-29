<?php
/**
 * Canonical cross-content Vocabulary bridge for TaxoCommerce Core editors.
 *
 * Reuses the existing seo_vocabulary and seo_object_vocabulary tables. Native
 * WordPress tags and WooCommerce attributes are distinct and never conflated.
 *
 * @package TaxoCommerceCore
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Vocabulary {
    const GROUPS = array( 'rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo' );

    public static function groups() {
        return array(
            'rol'        => 'ROL',
            'tipo'       => 'TIPO',
            'aplicacion' => 'APLICACIÓN',
            'plataforma' => 'PLATAFORMA',
            'subtipo'    => 'SUBTIPO',
        );
    }

    private static function tables() {
        global $wpdb;
        return array(
            'vocabulary' => esc_sql( $wpdb->prefix . 'seo_vocabulary' ),
            'relations'  => esc_sql( $wpdb->prefix . 'seo_object_vocabulary' ),
        );
    }

    public static function available() {
        global $wpdb;
        $tables = self::tables();
        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical vocabulary relations use custom tables; reads must reflect current assignments and writes must persist immediately.
            $exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
            );
            if ( (string) $exists !== $table ) {
                return false;
            }
        }
        return true;
    }

    public static function object_type( $content_type ) {
        $types = array(
            'products'   => 'product',
            'categories' => 'product_cat',
            'posts'      => 'post',
            'pages'      => 'page',
        );
        return $types[ $content_type ] ?? '';
    }

    public static function assignments( $content_type, $object_id ) {
        global $wpdb;
        $type = self::object_type( $content_type );
        $selected = array();
        foreach ( self::groups() as $group => $label ) {
            $selected[ $group ] = array();
        }
        if ( ! $type || ! absint( $object_id ) || ! self::available() ) {
            return $selected;
        }
        $tables = self::tables();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical vocabulary relations use custom tables; reads must reflect current assignments and writes must persist immediately.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.semantic_group, v.slug, v.label, ov.source
                 FROM %i ov
                 INNER JOIN %i v ON v.id=ov.vocabulary_id
                 WHERE ov.object_type=%s AND ov.object_id=%d
                   AND ov.status=1 AND v.active=1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY v.semantic_group ASC,v.label ASC",
                $tables['relations'],
                $tables['vocabulary'],
                $type,
                absint( $object_id )
            ),
            ARRAY_A
        );
        foreach ( (array) $rows as $row ) {
            $group = sanitize_key( (string) $row['semantic_group'] );
            if ( isset( $selected[ $group ] ) ) {
                $selected[ $group ][] = $row;
            }
        }
        return $selected;
    }

    public static function render( $content_type, $object_id ) {
        echo '<section class="tc-editor-card tc-vocabulary-card">';
        echo '<div class="tc-card-heading"><div><span class="tc-eyebrow">TaxoCommerce Vocabulary</span><h2>Etiquetas transversales</h2>';
        echo '<p>Clasificación semántica compartida entre productos, categorías, posts y páginas.</p></div></div>';
        if ( ! self::available() ) {
            echo '<div class="tc-editor-note tc-editor-note--info"><strong>Vocabulary todavía no está disponible.</strong> Instala o activa la extensión Vocabulary desde TaxoCommerce Core. Este formulario no cambiará las asignaciones existentes.</div></section>';
            return;
        }
        $selected = self::assignments( $content_type, $object_id );
        echo '<input type="hidden" name="tc_vocab_present" value="1">';
        echo '<div class="tc-vocab-grid">';
        foreach ( self::groups() as $group => $label ) {
            $current = $selected[ $group ];
            echo '<div class="tc-vocab-group"><label class="tc-field-label" for="tc-vocab-search-' . esc_attr( $group ) . '">' . esc_html( $label ) . '</label>';
            self::render_picker(
                'tc_vocab[' . $group . '][]',
                'vocab',
                $group,
                $current,
                'Buscar ' . strtolower( $label ) . '…',
                false,
                'tc-vocab-search-' . $group
            );
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="tc-field-help">Usa términos existentes del vocabulario canónico. Las etiquetas asignadas por procesos automáticos se muestran protegidas y no se sobrescriben aquí.</p>';
        echo '</section>';
    }

    /**
     * Shared accessible search-and-chips control for all term selectors.
     *
     * @param array $selected Each item: id, label, source (optional).
     */
    public static function render_picker( $field_name, $mode, $group, $selected, $placeholder, $single = false, $id = '' ) {
        $id = $id ? sanitize_html_class( $id ) : sanitize_html_class( 'tc-term-' . wp_unique_id() );
        $field = sanitize_text_field( (string) $field_name );
        echo '<div class="tc-term-picker" data-tc-picker data-mode="' . esc_attr( $mode ) . '" data-group="' . esc_attr( $group ) . '" data-field="' . esc_attr( $field ) . '" data-single="' . ( $single ? '1' : '0' ) . '">';
        echo '<div class="tc-term-chips" data-tc-chips aria-live="polite">';
        foreach ( (array) $selected as $row ) {
            $term_id = absint( $row['id'] ?? 0 );
            $term_label = (string) ( $row['label'] ?? '' );
            if ( ! $term_id ) {
                continue;
            }
            $protected = ! empty( $row['source'] ) && 'manual' !== $row['source'];
            echo '<span class="tc-term-chip' . ( $protected ? ' is-protected' : '' ) . '" data-tc-term-id="' . esc_attr( (string) $term_id ) . '">';
            echo esc_html( $term_label );
            if ( $protected ) {
                echo ' <span title="Asignada automáticamente; no se modifica desde este formulario" aria-label="Asignación protegida">●</span>';
            } else {
                echo '<button type="button" class="tc-chip-remove" data-tc-remove aria-label="' . esc_attr( 'Quitar ' . $term_label ) . '">×</button>';
                echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $term_id ) . '" data-tc-value>';
            }
            echo '</span>';
        }
        echo '</div>';
        echo '<div class="tc-picker-search"><input id="' . esc_attr( $id ) . '" type="search" autocomplete="off" data-tc-search class="tc-input tc-input--search" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr( $placeholder ) . '" aria-autocomplete="list" aria-expanded="false">';
        echo '<div class="tc-picker-results" data-tc-results role="listbox" hidden></div></div></div>';
    }

    public static function search( $group, $needle ) {
        global $wpdb;
        $group = sanitize_key( $group );
        if ( ! isset( self::groups()[ $group ] ) || ! self::available() ) {
            return array();
        }
        $tables = self::tables();
        $search = '%' . $wpdb->esc_like( sanitize_text_field( $needle ) ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical vocabulary relations use custom tables; reads must reflect current assignments and writes must persist immediately.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, slug, label FROM %i
                 WHERE active=1 AND semantic_group=%s
                   AND (label LIKE %s OR slug LIKE %s)
                 ORDER BY label ASC LIMIT 25",
                $tables['vocabulary'],
                $group,
                $search,
                $search
            ),
            ARRAY_A
        );
        return array_map(
            static function ( $row ) {
                return array( 'id' => absint( $row['id'] ), 'label' => (string) $row['label'] );
            },
            (array) $rows
        );
    }

    /**
     * Change only manual relationships, preserving assignments from classifiers.
     */
    public static function save( $content_type, $object_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the mutation nonce before this helper runs.
        if ( empty( $_POST['tc_vocab_present'] ) ) {
            return true;
        }
        $type = self::object_type( $content_type );
        $object_id = absint( $object_id );
        if ( ! $type || ! $object_id || ! self::available() ) {
            return new WP_Error( 'tc_core_vocab_missing', 'No se pueden guardar etiquetas transversales: Vocabulary no está disponible.' );
        }
        if ( 'product_cat' === $type ) {
            $taxonomy = get_taxonomy( 'product_cat' );
            $allowed = $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
        } else {
            $allowed = current_user_can( 'edit_post', $object_id ) && get_post_type( $object_id ) === $type;
        }
        if ( ! $allowed ) {
            return new WP_Error( 'tc_core_vocab_permissions', 'No tienes permisos para editar estas etiquetas.' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request guarded by Core.
        $posted = isset( $_POST['tc_vocab'] ) && is_array( $_POST['tc_vocab'] ) ? map_deep( wp_unslash( $_POST['tc_vocab'] ), 'sanitize_text_field' ) : array();
        global $wpdb;
        $tables = self::tables();

        // Validate every group before making changes to prevent partial results.
        $wanted = array();
        foreach ( self::groups() as $group => $label ) {
            $requested = isset( $posted[ $group ] ) && is_array( $posted[ $group ] ) ? $posted[ $group ] : array();
            $ids = array_values( array_unique( array_filter( array_map( 'absint', $requested ) ) ) );
            if ( count( $ids ) > 100 ) {
                return new WP_Error( 'tc_core_vocab_limit', 'Demasiadas etiquetas para un grupo.' );
            }
            if ( $ids ) {
                $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical vocabulary relations use custom tables; reads must reflect current assignments and writes must persist immediately.
                $valid = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT id FROM %i WHERE active=1 AND semantic_group=%s AND id IN ({$ph})",
                        array_merge( array( $tables['vocabulary'], $group ), $ids )
                    )
                );
                if ( count( $valid ) !== count( $ids ) ) {
                    return new WP_Error( 'tc_core_vocab_invalid', 'Algunas etiquetas de ' . $label . ' no existen en el vocabulario.' );
                }
            }
            $wanted[ $group ] = $ids;
        }

        $existing = self::assignments( $content_type, $object_id );
        foreach ( self::groups() as $group => $label ) {
            $manual_ids = array();
            $protected_ids = array();
            foreach ( $existing[ $group ] as $row ) {
                if ( 'manual' === (string) $row['source'] ) {
                    $manual_ids[] = absint( $row['id'] );
                } else {
                    $protected_ids[] = absint( $row['id'] );
                }
            }
            $to_remove = array_diff( $manual_ids, $wanted[ $group ] );
            if ( $to_remove ) {
                $ids = array_values( $to_remove );
                $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Canonical vocabulary relations use custom tables; reads must reflect current assignments and writes must persist immediately.
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE %i SET status=0, updated_at=%s
                         WHERE object_type=%s AND object_id=%d AND source='manual' AND vocabulary_id IN ({$ph})",
                        array_merge( array( $tables['relations'], current_time( 'mysql', true ), $type, $object_id ), $ids )
                    )
                );
                if ( false === $updated ) {
                    return new WP_Error( 'tc_core_vocab_update', 'No se pudieron retirar etiquetas manuales.' );
                }
            }
            $to_add = array_diff( $wanted[ $group ], $manual_ids, $protected_ids );
            foreach ( $to_add as $term_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Canonical vocabulary relations use custom tables; reads must reflect current assignments and writes must persist immediately.
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO %i
                         (object_type,object_id,vocabulary_id,source,confidence,status,updated_at)
                         VALUES (%s,%d,%d,'manual',1.0000,1,%s)
                         ON DUPLICATE KEY UPDATE source='manual',confidence=1.0000,status=1,updated_at=VALUES(updated_at)",
                        $tables['relations'],
                        $type,
                        $object_id,
                        absint( $term_id ),
                        current_time( 'mysql', true )
                    )
                );
                if ( false === $updated ) {
                    return new WP_Error( 'tc_core_vocab_insert', 'No se pudo guardar una etiqueta transversal.' );
                }
            }
        }
        return true;
    }
}
