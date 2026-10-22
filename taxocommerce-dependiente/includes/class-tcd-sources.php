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
            1 => array(
                'key'=>'v2_l1_categories','name'=>'Mapa del catálogo',
                'source'=>'Categorías WooCommerce + Vocabulary',
                'objective'=>'Categorías, jerarquía y lenguaje básico con el que el cliente entiende las familias de producto.',
            ),
            2 => array(
                'key'=>'v2_l2_inventory','name'=>'Inventario y producto',
                'source'=>'Productos publicados',
                'objective'=>'Aprender qué es cada producto, su identidad, marca, categorías y clasificación semántica.',
            ),
            3 => array(
                'key'=>'v2_l3_type_role','name'=>'TIPO y ROL',
                'source'=>'Vocabulary canónico + asignaciones a productos',
                'objective'=>'Aprender TIPO, ROL y las rutas semánticas principales del catálogo.',
            ),
            4 => array(
                'key'=>'v2_l4_features','name'=>'Características y necesidades',
                'source'=>'Productos + atributos + Vocabulary',
                'objective'=>'Aplicaciones, plataformas, subtipos, atributos y etiquetas para resolver necesidades sin depender del nombre exacto.',
            ),
            5 => array(
                'key'=>'v2_l5_editorial','name'=>'Posts y páginas con Vocabulary común',
                'source'=>'Posts/páginas + Vocabulary',
                'objective'=>'Relacionar contenido editorial con catálogo mediante vocabulario canónico compartido.',
            ),
            6 => array(
                'key'=>'v2_l6_faq','name'=>'FAQs contextualizadas',
                'source'=>'TaxoCommerce FAQs (seo_faq)',
                'objective'=>'Aprender cada pregunta y respuesta dentro de su categoría o producto propietario.',
            ),
            7 => array(
                'key'=>'v2_l7_cross','name'=>'Relaciones cruzadas',
                'source'=>'Vocabulary usado por catálogo y contenido',
                'objective'=>'Comprender conceptos que conectan productos, categorías, posts y páginas.',
            ),
            8 => array(
                'key'=>'v2_l8_exam','name'=>'Examen integrado y regresión',
                'source'=>'Conocimiento aprendido en L1-L7',
                'objective'=>'Revisar conjuntamente las siete primeras lecciones y detectar deuda antes de continuar.',
            ),
            9 => array(
                'key'=>'v2_l9_product_language','name'=>'Necesidades y productos',
                'source'=>'Producto + TIPO/ROL + aplicaciones + atributos + texto',
                'objective'=>'Volver desde el lenguaje natural de una necesidad hasta la familia o producto adecuado.',
            ),
            10 => array(
                'key'=>'v2_l10_consolidation_debt','name'=>'Consolidación, deuda y regresión',
                'source'=>'Conocimiento L1-L9',
                'objective'=>'Consolidar lo aprendido, localizar deuda y verificar coherencia global del conocimiento.',
            ),
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
        $limit = max( 1, min( 100, absint( $limit ) ) );

        if ( 1 === $lesson ) {
            return self::category_batch( $cursor, $limit );
        }
        if ( 3 === $lesson ) {
            return self::vocabulary_batch( $cursor, $limit );
        }
        if ( 6 === $lesson ) {
            return self::faq_batch( $cursor, $limit );
        }
        if ( 7 === $lesson ) {
            return self::cross_batch( $cursor, $limit );
        }
        if ( in_array( $lesson, array( 8, 10 ), true ) ) {
            return self::review_batch( $lesson, $cursor, $limit );
        }
        if ( 5 === $lesson ) {
            return self::post_batch( $lesson, $cursor, $limit, array( 'post', 'page' ) );
        }
        return self::post_batch( $lesson, $cursor, $limit, array( 'product' ) );
    }

    private static function category_batch( $cursor, $limit ) {
        $terms = get_terms( array(
            'taxonomy'=>'product_cat','hide_empty'=>false,'number'=>$limit + 1,
            'offset'=>$cursor,'orderby'=>'term_id','order'=>'ASC',
        ) );
        if ( is_wp_error( $terms ) ) return $terms;
        $more = count( $terms ) > $limit;
        $terms = array_slice( $terms, 0, $limit );
        $items = array();
        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) continue;
            $items[] = self::term_item( 1, $term );
        }
        return array( 'items'=>array_values( array_filter( $items ) ), 'cursor'=>$cursor + count( $terms ), 'more'=>$more );
    }

    private static function post_batch( $lesson, $cursor, $limit, $types ) {
        $query = new WP_Query( array(
            'post_type'=>$types,'post_status'=>'publish','fields'=>'ids',
            'posts_per_page'=>$limit + 1,'offset'=>$cursor,'orderby'=>'ID','order'=>'ASC',
            'no_found_rows'=>true,'suppress_filters'=>false,'ignore_sticky_posts'=>true,
        ) );
        $ids = array_map( 'absint', (array) $query->posts );
        $more = count( $ids ) > $limit;
        $page_ids = array_slice( $ids, 0, $limit );
        $items = array();
        foreach ( $page_ids as $id ) {
            $item = self::post_item( $lesson, $id );
            if ( $item ) $items[] = $item;
        }
        return array( 'items'=>$items, 'cursor'=>$cursor + count( $page_ids ), 'more'=>$more );
    }

    private static function faq_batch( $cursor, $limit ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_faq';
        if ( ! self::table_exists( $table ) ) {
            return new WP_Error( 'tcd_faq_plugin_missing', 'TaxoCommerce FAQs no está disponible: falta la tabla seo_faq.' );
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,object_type,object_id,product_cat_id,question,answer,sort_order,active
             FROM {$table} WHERE active=1 ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit + 1, $cursor
        ), ARRAY_A );
        $more = count( (array) $rows ) > $limit;
        $rows = array_slice( (array) $rows, 0, $limit );
        $items = array();
        foreach ( $rows as $row ) {
            $id = absint( $row['id'] ?? 0 );
            $question = trim( wp_strip_all_tags( (string) ( $row['question'] ?? '' ) ) );
            $answer = trim( wp_strip_all_tags( (string) ( $row['answer'] ?? '' ) ) );
            if ( ! $id || '' === $question ) continue;
            $owner_type = 3 === absint( $row['object_type'] ?? 0 ) ? 'product' : 'product_cat';
            $owner_id = 3 === absint( $row['object_type'] ?? 0 )
                ? absint( $row['object_id'] ?? 0 )
                : absint( $row['product_cat_id'] ?? $row['object_id'] ?? 0 );
            $facts = array(
                'owner_type'=>$owner_type,'owner_id'=>$owner_id,
                'product_cat_id'=>absint( $row['product_cat_id'] ?? 0 ),
                'answer'=>mb_substr( $answer, 0, 2500 ),
                'sort_order'=>absint( $row['sort_order'] ?? 0 ),
                'vocabulary'=>self::vocabulary_for_object( 'faq', $id ),
            );
            $items[] = self::item( 6, 'faq', $id, $question, 'Pregunta: '.$question.'. Respuesta: '.$answer, $facts );
        }
        return array( 'items'=>$items, 'cursor'=>$cursor + count( $rows ), 'more'=>$more );
    }

    private static function vocabulary_batch( $cursor, $limit ) {
        global $wpdb;
        $voc = $wpdb->prefix . 'seo_vocabulary';
        $rel = $wpdb->prefix . 'seo_object_vocabulary';
        if ( ! self::table_exists( $voc ) || ! self::table_exists( $rel ) ) {
            return new WP_Error( 'tcd_vocabulary_missing', 'Vocabulary no está disponible para L3.' );
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,semantic_group,slug,label FROM {$voc}
             WHERE active=1 AND semantic_group IN ('tipo','rol')
             ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit + 1, $cursor
        ), ARRAY_A );
        $more = count( (array) $rows ) > $limit;
        $rows = array_slice( (array) $rows, 0, $limit );
        $items = array();
        foreach ( $rows as $row ) {
            $id=absint($row['id']??0); if(!$id)continue;
            $product_ids=$wpdb->get_col($wpdb->prepare(
                "SELECT object_id FROM {$rel} WHERE object_type='product' AND vocabulary_id=%d AND status=1 ORDER BY object_id ASC LIMIT 200",
                $id
            ));
            $facts=array(
                'group'=>sanitize_key((string)$row['semantic_group']),
                'slug'=>sanitize_title((string)$row['slug']),
                'product_ids'=>array_map('absint',(array)$product_ids),
                'product_count'=>count((array)$product_ids),
            );
            $items[]=self::item(3,'vocabulary',$id,(string)$row['label'],'',$facts);
        }
        return array('items'=>$items,'cursor'=>$cursor+count($rows),'more'=>$more);
    }

    private static function cross_batch( $cursor, $limit ) {
        global $wpdb;
        $voc=$wpdb->prefix.'seo_vocabulary';
        $rel=$wpdb->prefix.'seo_object_vocabulary';
        if(!self::table_exists($voc)||!self::table_exists($rel)){
            return new WP_Error('tcd_cross_missing','Vocabulary no está disponible para L7.');
        }
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.semantic_group,v.slug,v.label,
                    SUM(r.object_type='product' AND r.status=1) product_uses,
                    SUM(r.object_type IN ('post','page') AND r.status=1) editorial_uses
             FROM {$voc} v INNER JOIN {$rel} r ON r.vocabulary_id=v.id
             WHERE v.active=1
             GROUP BY v.id,v.semantic_group,v.slug,v.label
             HAVING product_uses>0 AND editorial_uses>0
             ORDER BY v.id ASC LIMIT %d OFFSET %d",
            $limit + 1,$cursor
        ),ARRAY_A);
        $more=count((array)$rows)>$limit;
        $rows=array_slice((array)$rows,0,$limit);
        $items=array();
        foreach($rows as $row){
            $items[]=self::item(7,'cross',absint($row['id']),(string)$row['label'],'',array(
                'group'=>sanitize_key((string)$row['semantic_group']),
                'slug'=>sanitize_title((string)$row['slug']),
                'product_uses'=>absint($row['product_uses']),
                'editorial_uses'=>absint($row['editorial_uses']),
            ));
        }
        return array('items'=>$items,'cursor'=>$cursor+count($rows),'more'=>$more);
    }


    private static function table_exists( $table ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === (string) $table;
    }

    private static function vocabulary_for_object( $object_type, $object_id ) {
        global $wpdb;
        $voc=$wpdb->prefix.'seo_vocabulary';
        $rel=$wpdb->prefix.'seo_object_vocabulary';
        if(!self::table_exists($voc)||!self::table_exists($rel))return array();
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.semantic_group,v.slug,v.label
             FROM {$rel} r INNER JOIN {$voc} v ON v.id=r.vocabulary_id
             WHERE r.object_type=%s AND r.object_id=%d AND r.status=1 AND v.active=1
             ORDER BY v.semantic_group,v.id",
            sanitize_key($object_type),absint($object_id)
        ),ARRAY_A);
        $out=array();
        foreach((array)$rows as $row){
            $group=sanitize_key((string)$row['semantic_group']);
            if(!isset($out[$group]))$out[$group]=array();
            $out[$group][]=array(
                'id'=>absint($row['id']),'slug'=>sanitize_title((string)$row['slug']),'label'=>(string)$row['label']
            );
        }
        return $out;
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
            $facts['vocabulary'] = self::vocabulary_for_object( 'product_cat', $id );
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
        if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) return null;
        $title = wp_strip_all_tags( (string) $post->post_title );
        $body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
        $facts = array(
            'post_type'=>$post->post_type,
            'modified_gmt'=>$post->post_modified_gmt,
            'vocabulary'=>self::vocabulary_for_object( $post->post_type, $id ),
        );

        if ( 5 === $lesson ) {
            $facts['tags'] = self::object_terms( $id, array( 'post_tag' ) );
        } else {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
            if ( ! $product ) return null;
            $facts['sku'] = (string) $product->get_sku();
            $facts['categories'] = self::object_terms( $id, array( 'product_cat' ) );
            $facts['brands'] = self::object_terms( $id, array( 'product_brand', 'pa_marca' ) );
            $facts['short_description'] = wp_strip_all_tags( (string) $product->get_short_description() );
            $facts['vocabulary'] = self::vocabulary_for_object( 'product', $id );
            if ( in_array( $lesson, array( 4, 9 ), true ) ) {
                $facts['features'] = self::product_features( $id, $product );
            }
            if ( 9 === $lesson ) {
                $category_names = wp_get_post_terms( $id, 'product_cat', array( 'fields'=>'names' ) );
                $category_label = ! is_wp_error( $category_names ) && $category_names ? (string) $category_names[0] : 'herramientas';
                $semantic=array();
                foreach(array('tipo','rol','aplicacion','plataforma','subtipo') as $group){
                    if(!empty($facts['vocabulary'][$group][0]['label']))$semantic[]=$facts['vocabulary'][$group][0]['label'];
                }
                $facts['customer_need'] = 'Necesito ' . $category_label . ($semantic ? ' para '.implode(', ',array_slice($semantic,0,3)) : '') . ': ' . $title;
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
            'source_id'=>absint( $id ), 'source_key'=>sanitize_key( $type ) . ':' . absint( $id ), 'title'=>$title, 'body'=>$body,
            'facts'=>$facts,
            'signature'=>hash( 'sha256', wp_json_encode( array( $title, $body, $facts ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
        );
    }

    public static function validate_snapshot( $item ) {
        $lesson=absint($item['lesson']??0);
        $id=absint($item['source_id']??0);
        $title=trim((string)($item['title']??''));
        $facts=(array)($item['facts']??array());
        if(!$id || ''===$title)return 'Fuente congelada sin identificador o título.';
        if(1===$lesson && empty($facts['taxonomy']))return 'Categoría sin taxonomía congelada.';
        if(2===$lesson && empty($facts['categories']['product_cat']) && empty($facts['categories']))return 'Producto sin categoría en la foto fija.';
        if(3===$lesson && (empty($facts['group']) || empty($facts['slug'])))return 'Vocabulary TIPO/ROL incompleto.';
        if(4===$lesson && empty($facts['features']) && empty($facts['vocabulary']))return 'Producto sin características ni Vocabulary.';
        if(5===$lesson && empty($facts['vocabulary']) && empty($facts['tags']))return 'Contenido editorial sin Vocabulary ni etiquetas.';
        if(6===$lesson){
            if(!in_array((string)($facts['owner_type']??''),array('product','product_cat'),true) || !absint($facts['owner_id']??0))return 'FAQ sin propietario canónico.';
            if(''===trim((string)($facts['answer']??'')))return 'FAQ sin respuesta.';
        }
        if(7===$lesson && (!absint($facts['product_uses']??0) || !absint($facts['editorial_uses']??0)))return 'Concepto sin relación cruzada catálogo/editorial.';
        if(in_array($lesson,array(8,10),true) && 'unlearned'===(string)($facts['previous_status']??''))return 'Deuda previa: la fuente no estaba aprendida.';
        if(9===$lesson && ''===trim((string)($facts['customer_need']??'')))return 'Producto sin necesidad natural construida.';
        return '';
    }

    public static function validate( $item ) {
        return self::validate_snapshot( $item );
    }

}
