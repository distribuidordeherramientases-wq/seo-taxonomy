<?php
/**
 * Native WordPress/WooCommerce source adapters for the ten fixed lessons.
 *
 * No reads from seo_faq, seo_vocabulary, seo_object_vocabulary, seo_nodes,
 * seo_relations or the former Dependiente tables. No writes to WP content.
 */
defined( 'ABSPATH' ) || exit;

final class TCD_Sources {
    const GROUP_TAXONOMIES = array( 'tc_tipo', 'tc_rol', 'tc_semantic_tag' );
    const FAQ_TYPES = array( 'tc_faq', 'taxocommerce_faq', 'faq' );

    public static function syllabus() {
        return array(
            1 => array( 'name'=>'Mapa del catálogo', 'source'=>'product_cat', 'objective'=>'Jerarquía y categorías WooCommerce' ),
            2 => array( 'name'=>'Inventario representativo', 'source'=>'product', 'objective'=>'Identidad de productos, marca y categoría' ),
            3 => array( 'name'=>'TIPO y ROL', 'source'=>'tc_tipo / tc_rol', 'objective'=>'Roles y tipos de taxonomías nativas' ),
            4 => array( 'name'=>'Características y necesidades', 'source'=>'product', 'objective'=>'Atributos WooCommerce, aplicaciones y etiquetas' ),
            5 => array( 'name'=>'Posts y páginas', 'source'=>'post / page', 'objective'=>'Contenido y Vocabulary compartido' ),
            6 => array( 'name'=>'FAQs contextualizadas', 'source'=>'CPT FAQ', 'objective'=>'Pregunta y respuesta vinculadas a producto o categoría' ),
            7 => array( 'name'=>'Relaciones cruzadas', 'source'=>'tc_semantic_tag', 'objective'=>'Conceptos compartidos entre catálogo y editorial' ),
            8 => array( 'name'=>'Examen integrado', 'source'=>'Conocimiento L1-L7', 'objective'=>'Verificación de fuentes ya estudiadas' ),
            9 => array( 'name'=>'Necesidades y productos', 'source'=>'product', 'objective'=>'Consulta natural y correspondencia con producto' ),
            10 => array( 'name'=>'Consolidación y deuda', 'source'=>'Conocimiento L1-L9', 'objective'=>'Auditar lo no aprendido y la coherencia del conocimiento' ),
        );
    }

    public static function faq_type() {
        $types = apply_filters( 'taxocommerce_dependiente_faq_post_types', self::FAQ_TYPES );
        foreach ( (array) $types as $type ) {
            $type = sanitize_key( (string) $type );
            if ( $type && post_type_exists( $type ) ) {
                return $type;
            }
        }
        return '';
    }

    public static function semantic_taxonomies() {
        return array_values( array_filter( self::GROUP_TAXONOMIES, 'taxonomy_exists' ) );
    }

    public static function batch( $lesson, $cursor, $limit ) {
        $lesson = absint( $lesson );
        $cursor = max( 0, absint( $cursor ) );
        $limit = max( 1, min( 25, absint( $limit ) ) );
        if ( in_array( $lesson, array( 1, 3, 7 ), true ) ) {
            $taxonomies = 1 === $lesson ? array( 'product_cat' ) : ( 3 === $lesson ? self::semantic_taxonomies() : array( 'tc_semantic_tag' ) );
            $taxonomies = array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );
            if ( ! $taxonomies ) {
                return new WP_Error( 'tcd_taxonomy_missing', 'Falta la taxonomía nativa necesaria para la lección ' . $lesson . '.' );
            }
            $terms = get_terms( array(
                'taxonomy'=>$taxonomies, 'hide_empty'=>false, 'number'=>$limit + 1,
                'offset'=>$cursor, 'orderby'=>'term_id', 'order'=>'ASC',
            ) );
            if ( is_wp_error( $terms ) ) {
                return $terms;
            }
            $more = count( $terms ) > $limit;
            $terms = array_slice( $terms, 0, $limit );
            $items = array();
            foreach ( $terms as $term ) {
                if ( ! $term instanceof WP_Term ) {
                    continue;
                }
                $item = self::term_item( $lesson, $term );
                if ( $item ) {
                    $items[] = $item;
                }
            }
            return array( 'items'=>$items, 'cursor'=>$cursor + count( $terms ), 'more'=>$more );
        }
        if ( in_array( $lesson, array( 8, 10 ), true ) ) {
            return self::review_batch( $lesson, $cursor, $limit );
        }
        if ( 6 === $lesson ) {
            $faq_type = self::faq_type();
            if ( ! $faq_type ) {
                return new WP_Error( 'tcd_faq_plugin_missing', 'No existe un CPT de FAQs nativo. Activa TaxoCommerce FAQs antes de ejecutar L6.' );
            }
            $types = array( $faq_type );
        } elseif ( 5 === $lesson ) {
            $types = array( 'post', 'page' );
        } else {
            $types = array( 'product' );
        }
        $query = new WP_Query( array(
            'post_type'=>$types, 'post_status'=>'publish', 'fields'=>'ids',
            'posts_per_page'=>$limit + 1, 'offset'=>$cursor, 'orderby'=>'ID',
            'order'=>'ASC', 'no_found_rows'=>true, 'suppress_filters'=>false,
            'ignore_sticky_posts'=>true,
        ) );
        $ids = array_map( 'absint', (array) $query->posts );
        $more = count( $ids ) > $limit;
        $page_ids = array_slice( $ids, 0, $limit );
        $items = array();
        foreach ( $page_ids as $id ) {
            $item = self::post_item( $lesson, $id );
            if ( $item ) {
                $items[] = $item;
            }
        }
        return array( 'items'=>$items, 'cursor'=>$cursor + count( $page_ids ), 'more'=>$more );
    }

    private static function term_item( $lesson, $term ) {
        $id = absint( $term->term_id );
        $title = wp_strip_all_tags( (string) $term->name );
        $facts = array( 'taxonomy'=>$term->taxonomy, 'slug'=>$term->slug );
        $content = wp_strip_all_tags( (string) $term->description );
        if ( 1 === $lesson ) {
            $facts['parent'] = absint( $term->parent );
            $facts['ancestors'] = array_map( 'absint', (array) get_ancestors( $id, 'product_cat', 'taxonomy' ) );
            $facts['products'] = absint( $term->count );
            $facts['semantic_ids'] = self::category_semantic_ids( $id );
            $excerpt = get_term_meta( $id, 'excerpt', true );
            if ( is_string( $excerpt ) && '' !== trim( $excerpt ) ) {
                $content .= ' ' . wp_strip_all_tags( $excerpt );
            }
        }
        if ( 3 === $lesson ) {
            $facts['group'] = in_array( $term->taxonomy, array( 'tc_tipo', 'tc_rol' ), true )
                ? substr( $term->taxonomy, 3 ) : sanitize_key( (string) get_term_meta( $id, 'tc_semantic_group', true ) );
        }
        if ( 7 === $lesson ) {
            $objects = get_objects_in_term( $id, $term->taxonomy );
            $product_id = 0;
            $editorial_id = 0;
            if ( ! is_wp_error( $objects ) ) {
                foreach ( (array) $objects as $oid ) {
                    $post = get_post( absint( $oid ) );
                    if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
                        continue;
                    }
                    if ( 'product' === $post->post_type ) {
                        $product_id = absint( $oid );
                    } elseif ( in_array( $post->post_type, array( 'post','page' ), true ) ) {
                        $editorial_id = absint( $oid );
                    }
                    if ( $product_id && $editorial_id ) {
                        break;
                    }
                }
            }
            $facts['product_id'] = $product_id;
            $facts['editorial_id'] = $editorial_id;
        }
        return self::item( $lesson, 'term:' . $term->taxonomy, $id, $title, $content, $facts );
    }

    private static function category_semantic_ids( $category_id ) {
        $ids = get_term_meta( absint( $category_id ), '_taxocommerce_vocabulary_semantic_terms', true );
        return is_array( $ids ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 50 ) : array();
    }

    private static function post_item( $lesson, $id ) {
        $post = get_post( $id );
        if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
            return null;
        }
        $title = wp_strip_all_tags( (string) $post->post_title );
        $body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
        $facts = array( 'post_type'=>$post->post_type, 'modified_gmt'=>$post->post_modified_gmt );
        if ( 6 === $lesson ) {
            $owner = self::faq_owner( $post );
            $facts['owner_type'] = $owner['type'];
            $facts['owner_id'] = $owner['id'];
            $facts['answer'] = mb_substr( trim( $body ), 0, 1000 );
            $body = 'Pregunta: ' . $title . '. Respuesta: ' . $body;
        } elseif ( 5 === $lesson ) {
            $facts['seo_role'] = sanitize_key( (string) get_post_meta( $id, '_taxocommerce_core_seo_role', true ) );
            $facts['tags'] = self::object_terms( $id, array( 'post_tag', 'tc_semantic_tag' ) );
        } else {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
            if ( ! $product ) {
                return null;
            }
            $facts['sku'] = (string) $product->get_sku();
            $facts['categories'] = self::object_terms( $id, array( 'product_cat' ) );
            $facts['brands'] = self::object_terms( $id, array( 'product_brand', 'pa_marca' ) );
            if ( in_array( $lesson, array( 4, 9 ), true ) ) {
                $facts['features'] = self::product_features( $id, $product );
            }
            if ( 9 === $lesson ) {
                $category_names = wp_get_post_terms( $id, 'product_cat', array( 'fields'=>'names' ) );
                $category_label = ! is_wp_error( $category_names ) && $category_names ? (string) $category_names[0] : 'herramientas';
                $facts['customer_need'] = 'Necesito ' . $category_label . ': ' . $title;
            }
            $body = (string) $product->get_short_description() . ' ' . $body;
        }
        return self::item( $lesson, $post->post_type, $id, $title, $body, $facts );
    }

    public static function faq_owner( $post ) {
        $filtered = apply_filters( 'taxocommerce_dependiente_faq_owner', null, $post );
        if ( is_array( $filtered ) && isset( $filtered['type'], $filtered['id'] ) ) {
            return array( 'type'=>sanitize_key( (string) $filtered['type'] ), 'id'=>absint( $filtered['id'] ) );
        }
        foreach ( array( '_tc_faq_owner_', '_taxocommerce_faq_owner_', 'tc_faq_owner_', 'faq_owner_' ) as $prefix ) {
            $type = sanitize_key( (string) get_post_meta( $post->ID, $prefix . 'type', true ) );
            $id = absint( get_post_meta( $post->ID, $prefix . 'id', true ) );
            if ( $type && $id ) {
                return array( 'type'=>$type, 'id'=>$id );
            }
        }
        return array( 'type'=>'', 'id'=>0 );
    }

    private static function object_terms( $id, $taxonomies ) {
        $terms = array();
        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $ids = wp_get_object_terms( $id, $taxonomy, array( 'fields'=>'ids' ) );
            if ( ! is_wp_error( $ids ) ) {
                $terms[ $taxonomy ] = array_slice( array_map( 'absint', (array) $ids ), 0, 50 );
            }
        }
        return $terms;
    }

    private static function product_features( $id, $product ) {
        $features = self::object_terms( $id, array( 'product_tag', 'tc_tipo', 'tc_rol', 'tc_semantic_tag' ) );
        foreach ( (array) $product->get_attributes() as $attribute ) {
            if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
                continue;
            }
            $name = sanitize_key( (string) $attribute->get_name() );
            if ( ! $name ) {
                continue;
            }
            $features['attributes'][ $name ] = method_exists( $attribute, 'get_options' )
                ? array_slice( (array) $attribute->get_options(), 0, 30 ) : array();
        }
        return $features;
    }

    private static function review_batch( $lesson, $cursor, $limit ) {
        global $wpdb;
        $table = TCD_Academy::knowledge_table();
        $source_lessons = 8 === $lesson ? '1,2,3,4,5,6,7' : '1,2,3,4,5,6,7,8,9';
        // Table name and allowed source lesson numbers are internal constants.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,lesson,source_type,source_id,title,context_json,status FROM {$table}
             WHERE id > %d AND lesson IN ({$source_lessons}) ORDER BY id ASC LIMIT %d",
            $cursor, $limit + 1
        ), ARRAY_A );
        $more = count( (array) $rows ) > $limit;
        $rows = array_slice( (array) $rows, 0, $limit );
        $items = array();
        foreach ( $rows as $row ) {
            $snapshot = json_decode( (string) $row['context_json'], true );
            $items[] = self::item( $lesson, 'review', absint( $row['id'] ), (string) $row['title'], '', array(
                'original_lesson'=>absint( $row['lesson'] ),
                'original_type'=>sanitize_key( (string) $row['source_type'] ),
                'original_id'=>absint( $row['source_id'] ),
                'previous_status'=>sanitize_key( (string) $row['status'] ),
                'original_context'=>is_array( $snapshot ) ? $snapshot : array(),
            ) );
        }
        $last = $rows ? absint( $rows[ count( $rows ) - 1 ]['id'] ) : $cursor;
        return array( 'items'=>$items, 'cursor'=>$last, 'more'=>$more );
    }

    private static function item( $lesson, $type, $id, $title, $body, $facts ) {
        $title = trim( sanitize_text_field( $title ) );
        $body = trim( mb_substr( sanitize_textarea_field( $body ), 0, 1700 ) );
        return array(
            'lesson'=>absint( $lesson ), 'source_type'=>sanitize_key( $type ),
            'source_id'=>absint( $id ), 'title'=>$title, 'body'=>$body,
            'facts'=>$facts,
            'signature'=>hash( 'sha256', wp_json_encode( array( $title, $body, $facts ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
        );
    }

    public static function validate( $item ) {
        $lesson = absint( $item['lesson'] ?? 0 );
        $type = (string) ( $item['source_type'] ?? '' );
        $id = absint( $item['source_id'] ?? 0 );
        $facts = (array) ( $item['facts'] ?? array() );
        if ( ! $id || '' === trim( (string) ( $item['title'] ?? '' ) ) ) {
            return 'Fuente sin identificador o título válido.';
        }
        if ( 6 === $lesson ) {
            $owner_type = (string) ( $facts['owner_type'] ?? '' );
            $owner_id = absint( $facts['owner_id'] ?? 0 );
            if ( 'product' === $owner_type ) {
                $owner = get_post( $owner_id );
                if ( ! $owner || 'product' !== $owner->post_type || 'publish' !== $owner->post_status ) {
                    return 'FAQ sin propietario producto publicado.';
                }
            } elseif ( 'product_cat' === $owner_type ) {
                $owner = get_term( $owner_id, 'product_cat' );
                if ( ! $owner || is_wp_error( $owner ) ) {
                    return 'FAQ sin categoría propietaria válida.';
                }
            } else {
                return 'FAQ sin propietario canónico producto/categoría.';
            }
            if ( '' === trim( (string) ( $facts['answer'] ?? '' ) ) ) {
                return 'FAQ sin respuesta.';
            }
        }
        if ( 7 === $lesson && ( ! absint( $facts['product_id'] ?? 0 ) || ! absint( $facts['editorial_id'] ?? 0 ) ) ) {
            return 'No hay relación nativa entre producto y contenido editorial para este concepto.';
        }
        if ( in_array( $lesson, array( 8, 10 ), true ) ) {
            if ( 'unlearned' === (string) ( $facts['previous_status'] ?? '' ) ) {
                return 'Deuda previa: la fuente aún no estaba aprendida.';
            }
            $source_type = (string) ( $facts['original_type'] ?? '' );
            $source_id = absint( $facts['original_id'] ?? 0 );
            if ( 0 === strpos( $source_type, 'term:' ) ) {
                $taxonomy = substr( $source_type, 5 );
                if ( ! taxonomy_exists( $taxonomy ) || ! term_exists( $source_id, $taxonomy ) ) {
                    return 'Fuente de conocimiento anterior ya no existe.';
                }
            } elseif ( 'review' !== $source_type ) {
                $post = get_post( $source_id );
                if ( ! $post || 'publish' !== $post->post_status ) {
                    return 'Fuente editorial o producto anterior ya no está publicado.';
                }
            }
        }
        return '';
    }
}
