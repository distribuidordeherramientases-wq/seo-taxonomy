<?php
/**
 * SEO System - Comparador y sincronizacion segura PRO <-> STAGING.
 *
 * Principios:
 * - no consulta las BBDD remotas al cargar la pantalla;
 * - cada entidad se escanea solo al pulsar su boton;
 * - las consultas remotas son SELECT y trabajan por lotes pequenos;
 * - nunca muestra ni transporta contenidos completos durante la comparacion:
 *   descripciones/contenidos se comparan por SHA-256 calculado en MySQL;
 * - no crea ni elimina objetos. Si un ID existe solo en un entorno, se informa;
 * - la sincronizacion escribe UNICAMENTE en el WordPress local. Para escribir
 *   en el otro entorno se abre este mismo panel en ese entorno;
 * - las fechas NO deciden igualdad ni direccion: el comparador trabaja por
 *   contenido y hashes; una diferencia de timestamp por si sola se ignora;
 * - las imagenes se excluyen siempre de comparacion y sincronizacion: pueden
 *   vivir en hosts externos de proveedores y no son identidad de contenido;
 * - contenido general, etiquetas, semantica y atributos se comparan como capas
 *   independientes. La direccion de sincronizacion la elige expresamente el usuario;
 * - antes de escribir se revalidan los hashes del ultimo escaneo en ambos entornos;
 *   si cualquiera cambio desde el escaneo, la escritura se bloquea y exige reescanear;
 * - si una asignacion semantica o atributo necesita vocabulario maestro que no
 *   existe en el destino, se crea por clave canonica antes de asignar el objeto.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 2.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_environment_compare_entities' ) ) {
    function seo_environment_compare_entities() {
        return [
            // Capa 1: contenido/relaciones editoriales generales.
            'products_general'   => [ 'label' => 'Productos · General',    'group' => 'general', 'source' => 'product' ],
            'categories_general' => [ 'label' => 'Categorías · General',   'group' => 'general', 'source' => 'category' ],
            'pages_general'      => [ 'label' => 'Páginas · General',      'group' => 'general', 'source' => 'page' ],
            'posts_general'      => [ 'label' => 'Posts · General',        'group' => 'general', 'source' => 'post' ],
            'faqs'               => [ 'label' => 'FAQs',                   'group' => 'general', 'source' => 'faq' ],

            // Capa 2: clasificación. Cada bloque se sincroniza por separado.
            'product_tags'       => [ 'label' => 'Productos · Etiquetas WC', 'group' => 'classification', 'source' => 'product' ],
            'product_semantic'   => [ 'label' => 'Productos · Semántica',    'group' => 'classification', 'source' => 'product' ],
            'product_attributes' => [ 'label' => 'Productos · Atributos',    'group' => 'classification', 'source' => 'product' ],
            'category_tags'      => [ 'label' => 'Categorías · Etiquetas',   'group' => 'classification', 'source' => 'category' ],
            'category_semantic'  => [ 'label' => 'Categorías · Semántica',   'group' => 'classification', 'source' => 'category' ],
            'page_tags'          => [ 'label' => 'Páginas · Etiquetas',      'group' => 'classification', 'source' => 'page' ],
            'post_tags'          => [ 'label' => 'Posts · Etiquetas',        'group' => 'classification', 'source' => 'post' ],
        ];
    }
}

if ( ! function_exists( 'seo_environment_compare_table' ) ) {
    function seo_environment_compare_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_environment_compare';
    }
}

if ( ! function_exists( 'seo_environment_compare_install' ) ) {
    function seo_environment_compare_install() {
        global $wpdb;
        $table = seo_environment_compare_table();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity VARCHAR(30) NOT NULL,
                object_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL,
                newer_env VARCHAR(20) NOT NULL DEFAULT '',
                name_pro VARCHAR(255) NOT NULL DEFAULT '',
                name_staging VARCHAR(255) NOT NULL DEFAULT '',
                modified_pro DATETIME NULL,
                modified_staging DATETIME NULL,
                hash_pro CHAR(64) NOT NULL DEFAULT '',
                hash_staging CHAR(64) NOT NULL DEFAULT '',
                summary VARCHAR(600) NOT NULL DEFAULT '',
                details_json LONGTEXT NULL,
                scanned_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY entity_object (entity, object_id),
                KEY entity_status (entity, status),
                KEY entity_newer (entity, newer_env)
            ) {$charset};"
        );

        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}

if ( ! function_exists( 'seo_environment_compare_state_option' ) ) {
    function seo_environment_compare_state_option() {
        $state = get_option( 'seo_environment_compare_state', [] );
        return is_array( $state ) ? $state : [];
    }
}

if ( ! function_exists( 'seo_environment_compare_get_state' ) ) {
    function seo_environment_compare_get_state( $entity ) {
        $all = seo_environment_compare_state_option();
        $state = isset( $all[ $entity ] ) && is_array( $all[ $entity ] ) ? $all[ $entity ] : [];
        return wp_parse_args(
            $state,
            [
                'status'        => 'never',
                'cursor'        => 0,
                'processed'     => 0,
                'pro'           => 0,
                'staging'       => 0,
                'same'          => 0,
                'different'     => 0,
                'only_pro'      => 0,
                'only_staging'  => 0,
                'started_at'         => 0,
                'finished_at'        => 0,
                'error'              => '',
                'batch_size'                    => 8,
                'last_batch_target_rows'          => 0,
                'last_batch_rows'                 => 0,
                'last_batch_seconds'              => 0.0,
                'last_batch_memory_ratio'         => 0.0,
                'last_batch_time_budget_reached'  => 0,
                'last_cpu_percent'                => null,
                'adaptive_pressure'               => '',
                'adaptive_reason'                 => '',
                'worker_runs'                     => 0,
                'worker_source'                   => '',
                'next_run_at'                     => 0,
                'last_activity_at'                => 0,
            ]
        );
    }
}

if ( ! function_exists( 'seo_environment_compare_set_state' ) ) {
    function seo_environment_compare_set_state( $entity, array $state ) {
        $all = seo_environment_compare_state_option();
        $all[ $entity ] = $state;
        update_option( 'seo_environment_compare_state', $all, false );
    }
}

if ( ! function_exists( 'seo_environment_compare_current_env' ) ) {
    function seo_environment_compare_current_env() {
        if ( ! function_exists( 'seo_environment_db_settings' ) ) {
            return '';
        }

        $local_db   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
        $local_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $url_matches = [];
        $db_matches  = [];

        foreach ( [ 'pro', 'staging' ] as $env ) {
            $settings = seo_environment_db_settings( $env );
            $remote_host = ! empty( $settings['last_site_url'] ) ? wp_parse_url( (string) $settings['last_site_url'], PHP_URL_HOST ) : '';
            if ( $local_host && $remote_host && strtolower( $local_host ) === strtolower( $remote_host ) ) {
                $url_matches[] = $env;
            }
            if ( $local_db !== '' && ! empty( $settings['database'] ) && hash_equals( $local_db, (string) $settings['database'] ) ) {
                $db_matches[] = $env;
            }
        }

        $url_matches = array_values( array_unique( $url_matches ) );
        if ( 1 === count( $url_matches ) ) {
            return $url_matches[0];
        }
        $db_matches = array_values( array_unique( $db_matches ) );
        return 1 === count( $db_matches ) ? $db_matches[0] : '';
    }
}

if ( ! function_exists( 'seo_environment_compare_db_prefix' ) ) {
    function seo_environment_compare_db_prefix( $env ) {
        $settings = function_exists( 'seo_environment_db_settings' ) ? seo_environment_db_settings( $env ) : [];
        $prefix   = (string) ( $settings['prefix'] ?? 'wp_' );
        return preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ? $prefix : 'wp_';
    }
}

if ( ! function_exists( 'seo_environment_compare_open' ) ) {
    function seo_environment_compare_open( $env ) {
        if ( ! function_exists( 'seo_environment_db_open' ) ) {
            return new WP_Error( 'seo_env_compare_missing_connections', 'No está cargado el módulo de conexiones PRO/STAGING.' );
        }
        $mysqli = seo_environment_db_open( $env );
        if ( $mysqli instanceof mysqli ) {
            // Solo afecta a esta conexion. Evita que GROUP_CONCAT trunque hashes
            // de metadatos/taxonomias en objetos con muchas relaciones.
            @mysqli_query( $mysqli, 'SET SESSION group_concat_max_len = 1048576' );

            // Limite defensivo por sentencia remota. MySQL y MariaDB usan
            // variables distintas; se prueban ambas de forma tolerante. Si el
            // servidor no soporta una de ellas, la conexion sigue siendo valida.
            $config = function_exists( 'seo_environment_compare_adaptive_config' )
                ? seo_environment_compare_adaptive_config()
                : [ 'hard_seconds' => 12.0 ];
            $statement_seconds = max( 5.0, min( 60.0, (float) ( $config['hard_seconds'] ?? 12.0 ) * 1.5 ) );
            @mysqli_query( $mysqli, 'SET SESSION MAX_EXECUTION_TIME = ' . (int) round( $statement_seconds * 1000 ) );
            @mysqli_query( $mysqli, 'SET SESSION max_statement_time = ' . number_format( $statement_seconds, 3, '.', '' ) );
        }
        return $mysqli;
    }
}

if ( ! function_exists( 'seo_environment_compare_sql_ids' ) ) {
    function seo_environment_compare_sql_ids( array $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        return $ids ? implode( ',', $ids ) : '0';
    }
}

if ( ! function_exists( 'seo_environment_compare_query_rows' ) ) {
    function seo_environment_compare_query_rows( $mysqli, $sql ) {
        $result = @mysqli_query( $mysqli, $sql );
        if ( false === $result ) {
            return new WP_Error( 'seo_env_compare_query', sanitize_text_field( mysqli_error( $mysqli ) ) );
        }
        $rows = [];
        while ( $row = mysqli_fetch_assoc( $result ) ) {
            $rows[] = $row;
        }
        mysqli_free_result( $result );
        return $rows;
    }
}

if ( ! function_exists( 'seo_environment_compare_hash' ) ) {
    function seo_environment_compare_hash( $value ) {
        if ( is_array( $value ) ) {
            ksort( $value );
        }
        return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '' );
    }
}

if ( ! function_exists( 'seo_environment_compare_candidate_ids' ) ) {
    function seo_environment_compare_candidate_ids( $mysqli, $prefix, $entity, $cursor, $limit ) {
        $cursor = absint( $cursor );
        $limit  = max( 20, min( 300, absint( $limit ) ) );

        $post_map = [
            'products_general'   => 'product',
            'product_tags'       => 'product',
            'product_semantic'   => 'product',
            'product_attributes' => 'product',
            'pages_general'      => 'page',
            'page_tags'          => 'page',
            'posts_general'      => 'post',
            'post_tags'          => 'post',
        ];

        if ( isset( $post_map[ $entity ] ) ) {
            $post_type = mysqli_real_escape_string( $mysqli, $post_map[ $entity ] );
            $sql = "SELECT ID FROM `{$prefix}posts` WHERE post_type='{$post_type}' AND post_status<>'trash' AND ID>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( in_array( $entity, [ 'categories_general', 'category_tags', 'category_semantic' ], true ) ) {
            $sql = "SELECT term_id AS ID FROM `{$prefix}term_taxonomy` WHERE taxonomy='product_cat' AND term_id>{$cursor} ORDER BY term_id ASC LIMIT {$limit}";
        } else {
            $sql = "SELECT id AS ID FROM `{$prefix}seo_faq` WHERE id>{$cursor} ORDER BY id ASC LIMIT {$limit}";
        }

        $rows = seo_environment_compare_query_rows( $mysqli, $sql );
        if ( is_wp_error( $rows ) ) return $rows;
        return array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'ID' ) ) ) );
    }
}



if ( ! function_exists( 'seo_environment_compare_empty_hash' ) ) {
    function seo_environment_compare_empty_hash() {
        static $hash = null;
        if ( null === $hash ) $hash = hash( 'sha256', '' );
        return $hash;
    }
}



if ( ! function_exists( 'seo_environment_compare_fetch_post_base' ) ) {
    function seo_environment_compare_fetch_post_base( $mysqli, $prefix, $post_type, array $ids ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );
        $type = mysqli_real_escape_string( $mysqli, $post_type );
        $rows = seo_environment_compare_query_rows( $mysqli, "SELECT ID,post_title FROM `{$prefix}posts` WHERE post_type='{$type}' AND post_status<>'trash' AND ID IN ({$id_sql})" );
        if ( is_wp_error( $rows ) ) return $rows;
        $out = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['ID'] );
            $out[$id] = [ 'id'=>$id, 'name'=>(string)$row['post_title'], 'components'=>[] ];
        }
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_post_general_snapshots' ) ) {
    function seo_environment_compare_fetch_post_general_snapshots( $mysqli, $prefix, $post_type, array $ids, $product = false ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );
        $type = mysqli_real_escape_string( $mysqli, $post_type );
        $rows = seo_environment_compare_query_rows( $mysqli, "SELECT ID,post_title,
            SHA2(COALESCE(post_title,''),256) AS base_hash,
            SHA2(COALESCE(post_excerpt,''),256) AS excerpt_hash,
            SHA2(COALESCE(post_content,''),256) AS description_hash
            FROM `{$prefix}posts` WHERE post_type='{$type}' AND post_status<>'trash' AND ID IN ({$id_sql})" );
        if ( is_wp_error( $rows ) ) return $rows;
        $out=[];
        foreach($rows as $row){
            $id=absint($row['ID']);
            $out[$id]=[
                'id'=>$id,
                'name'=>(string)$row['post_title'],
                'components'=>[
                    'base'=>(string)$row['base_hash'],
                    'excerpt'=>(string)$row['excerpt_hash'],
                    'description'=>(string)$row['description_hash'],
                ],
            ];
        }
        if($product && $out){
            $tax=seo_environment_compare_query_rows($mysqli,"SELECT tr.object_id,SHA2(GROUP_CONCAT(t.slug ORDER BY t.slug SEPARATOR '|'),256) AS row_hash
                FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                JOIN `{$prefix}terms` t ON t.term_id=tt.term_id
                WHERE tr.object_id IN ({$id_sql}) AND tt.taxonomy='product_cat' GROUP BY tr.object_id");
            if(!is_wp_error($tax))foreach($tax as $row){$id=absint($row['object_id']);if(isset($out[$id]))$out[$id]['components']['categories']=(string)$row['row_hash'];}
            $empty=seo_environment_compare_empty_hash();
            foreach($out as &$item){if(!isset($item['components']['categories']))$item['components']['categories']=$empty;}unset($item);
        }
        foreach($out as &$item){ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_native_tag_snapshots' ) ) {
    function seo_environment_compare_fetch_native_tag_snapshots( $mysqli, $prefix, $post_type, $taxonomy, array $ids ) {
        $out=seo_environment_compare_fetch_post_base($mysqli,$prefix,$post_type,$ids);if(is_wp_error($out)||!$out)return$out;
        $id_sql=seo_environment_compare_sql_ids($ids);$taxonomy=mysqli_real_escape_string($mysqli,$taxonomy);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT tr.object_id,SHA2(GROUP_CONCAT(t.slug ORDER BY t.slug SEPARATOR '|'),256) AS row_hash
            FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
            JOIN `{$prefix}terms` t ON t.term_id=tt.term_id WHERE tr.object_id IN ({$id_sql}) AND tt.taxonomy='{$taxonomy}' GROUP BY tr.object_id");
        if(!is_wp_error($rows))foreach($rows as $row){$id=absint($row['object_id']);if(isset($out[$id]))$out[$id]['components']['tags']=(string)$row['row_hash'];}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['tags']))$item['components']['tags']=$empty;$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_semantic_snapshots' ) ) {
    function seo_environment_compare_fetch_semantic_snapshots( $mysqli, $prefix, $post_type, $object_type, array $ids ) {
        $out = 'product_cat' === $object_type ? [] : seo_environment_compare_fetch_post_base($mysqli,$prefix,$post_type,$ids);
        $id_sql=seo_environment_compare_sql_ids($ids);
        if('product_cat'===$object_type){
            $rows=seo_environment_compare_query_rows($mysqli,"SELECT t.term_id,t.name FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id IN ({$id_sql})");
            if(is_wp_error($rows))return$rows;
            foreach($rows as $row){$id=absint($row['term_id']);$out[$id]=['id'=>$id,'name'=>(string)$row['name'],'components'=>[]];}
        }elseif(is_wp_error($out)||!$out)return$out;
        $obj=mysqli_real_escape_string($mysqli,$object_type);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT ov.object_id,v.semantic_group,
            SHA2(GROUP_CONCAT(v.slug ORDER BY v.slug SEPARATOR '|'),256) AS row_hash
            FROM `{$prefix}seo_object_vocabulary` ov JOIN `{$prefix}seo_vocabulary` v ON v.id=ov.vocabulary_id
            WHERE ov.object_type='{$obj}' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql})
            GROUP BY ov.object_id,v.semantic_group");
        if(!is_wp_error($rows))foreach($rows as $row){
            $id=absint($row['object_id']);$group=sanitize_key($row['semantic_group']??'');
            if(isset($out[$id])&&$group){$out[$id]['components']['semantic_'.$group]=(string)$row['row_hash'];}
        }
        foreach($out as &$item){ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_product_attribute_snapshots' ) ) {
    function seo_environment_compare_fetch_product_attribute_snapshots( $mysqli, $prefix, array $ids ) {
        $out=seo_environment_compare_fetch_post_base($mysqli,$prefix,'product',$ids);if(is_wp_error($out)||!$out)return$out;
        $id_sql=seo_environment_compare_sql_ids($ids);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT pa.product_id,SHA2(GROUP_CONCAT(CONCAT(a.slug,':',COALESCE(t.slug,''),':',SHA2(CONCAT_WS('|',COALESCE(pa.valor_texto,''),COALESCE(pa.valor_numero,''),COALESCE(pa.valor_numero_max,''),COALESCE(pa.unidad,''),COALESCE(pa.valor_original,'')),256),':',pa.orden) ORDER BY a.slug,pa.orden,pa.id SEPARATOR '|'),256) AS row_hash
            FROM `{$prefix}sql_product_atributos` pa JOIN `{$prefix}sql_atributos` a ON a.id=pa.atributo_id LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=pa.termino_id
            WHERE pa.product_id IN ({$id_sql}) GROUP BY pa.product_id");
        if(!is_wp_error($rows))foreach($rows as $row){$id=absint($row['product_id']);if(isset($out[$id]))$out[$id]['components']['attributes']=(string)$row['row_hash'];}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['attributes']))$item['components']['attributes']=$empty;$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_category_general_snapshots' ) ) {
    function seo_environment_compare_fetch_category_general_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT t.term_id,t.name,SHA2(COALESCE(t.name,''),256) AS base_hash FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id IN ({$id_sql})");
        if(is_wp_error($rows))return$rows;
        $out=[];foreach($rows as $row){$id=absint($row['term_id']);$out[$id]=['id'=>$id,'name'=>(string)$row['name'],'components'=>['base'=>(string)$row['base_hash']]];}
        if(!$out)return$out;
        $nodes=seo_environment_compare_query_rows($mysqli,"SELECT object_id,
            SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='excerpt' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) AS excerpt_hash,
            SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='description' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) AS description_hash
            FROM `{$prefix}seo_nodes` WHERE object_type='category' AND object_id IN ({$id_sql}) AND seo_role IN ('excerpt','description') GROUP BY object_id");
        if(!is_wp_error($nodes))foreach($nodes as $row){$id=absint($row['object_id']);if(isset($out[$id])){$out[$id]['components']['excerpt']=(string)$row['excerpt_hash'];$out[$id]['components']['description']=(string)$row['description_hash'];}}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){foreach(['excerpt','description'] as $c)if(!isset($item['components'][$c]))$item['components'][$c]=$empty;ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_category_tag_snapshots' ) ) {
    function seo_environment_compare_fetch_category_tag_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT t.term_id,t.name FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id IN ({$id_sql})");
        if(is_wp_error($rows))return$rows;
        $out=[];foreach($rows as $row){$id=absint($row['term_id']);$out[$id]=['id'=>$id,'name'=>(string)$row['name'],'components'=>[]];}if(!$out)return$out;
        $nodes=seo_environment_compare_query_rows($mysqli,"SELECT object_id,SHA2(COALESCE(GROUP_CONCAT(CASE WHEN status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) AS row_hash FROM `{$prefix}seo_nodes` WHERE object_type='category' AND seo_role='category' AND object_id IN ({$id_sql}) GROUP BY object_id");
        if(!is_wp_error($nodes))foreach($nodes as $row){$id=absint($row['object_id']);if(isset($out[$id]))$out[$id]['components']['tags']=(string)$row['row_hash'];}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['tags']))$item['components']['tags']=$empty;$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_post_custom_tag_snapshots' ) ) {
    function seo_environment_compare_fetch_post_custom_tag_snapshots( $mysqli, $prefix, $post_type, array $ids ) {
        $out=seo_environment_compare_fetch_native_tag_snapshots($mysqli,$prefix,$post_type,'post_tag',$ids);if(is_wp_error($out)||!$out)return$out;
        $id_sql=seo_environment_compare_sql_ids($ids);$type=mysqli_real_escape_string($mysqli,$post_type);
        $nodes=seo_environment_compare_query_rows($mysqli,"SELECT object_id,SHA2(GROUP_CONCAT(CONCAT(seo_role,':',SHA2(COALESCE(keywords,''),256)) ORDER BY seo_role,id SEPARATOR '|'),256) AS row_hash FROM `{$prefix}seo_nodes` WHERE object_type='{$type}' AND object_id IN ({$id_sql}) AND status=1 AND seo_role NOT IN ('excerpt','description','ambito') GROUP BY object_id");
        if(!is_wp_error($nodes))foreach($nodes as $row){$id=absint($row['object_id']);if(isset($out[$id]))$out[$id]['components']['seo_tags']=(string)$row['row_hash'];}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['seo_tags']))$item['components']['seo_tags']=$empty;ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);
        return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_faq_snapshots' ) ) {
    function seo_environment_compare_fetch_faq_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);
        $sql="SELECT id,object_type,object_id,
                     SHA2(CONCAT_WS(CHAR(31),SHA2(COALESCE(question,''),256),SHA2(COALESCE(answer,''),256)),256) AS content_hash,
                     SHA2(CONCAT_WS(CHAR(31),sort_order,active),256) AS settings_hash,
                     SHA2(CONCAT_WS(CHAR(31),object_type,object_id),256) AS target_hash
              FROM `{$prefix}seo_faq` WHERE id IN ({$id_sql})";
        $rows=seo_environment_compare_query_rows($mysqli,$sql); if(is_wp_error($rows))return $rows;
        $out=[];foreach($rows as $row){$id=absint($row['id']);$components=['content'=>(string)$row['content_hash'],'settings'=>(string)$row['settings_hash'],'target'=>(string)$row['target_hash']];$out[$id]=['id'=>$id,'name'=>'FAQ #'.$id.' · objeto '.absint($row['object_type']).'/'.absint($row['object_id']),'components'=>$components,'hash'=>seo_environment_compare_hash($components)];}
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_snapshots' ) ) {
    function seo_environment_compare_fetch_snapshots( $mysqli, $env, $entity, array $ids ) {
        $prefix=seo_environment_compare_db_prefix($env);
        switch($entity){
            case 'products_general': return seo_environment_compare_fetch_post_general_snapshots($mysqli,$prefix,'product',$ids,true);
            case 'categories_general': return seo_environment_compare_fetch_category_general_snapshots($mysqli,$prefix,$ids);
            case 'pages_general': return seo_environment_compare_fetch_post_general_snapshots($mysqli,$prefix,'page',$ids,false);
            case 'posts_general': return seo_environment_compare_fetch_post_general_snapshots($mysqli,$prefix,'post',$ids,false);
            case 'product_tags': return seo_environment_compare_fetch_native_tag_snapshots($mysqli,$prefix,'product','product_tag',$ids);
            case 'product_semantic': return seo_environment_compare_fetch_semantic_snapshots($mysqli,$prefix,'product','product',$ids);
            case 'product_attributes': return seo_environment_compare_fetch_product_attribute_snapshots($mysqli,$prefix,$ids);
            case 'category_tags': return seo_environment_compare_fetch_category_tag_snapshots($mysqli,$prefix,$ids);
            case 'category_semantic': return seo_environment_compare_fetch_semantic_snapshots($mysqli,$prefix,'','product_cat',$ids);
            case 'page_tags': return seo_environment_compare_fetch_post_custom_tag_snapshots($mysqli,$prefix,'page',$ids);
            case 'post_tags': return seo_environment_compare_fetch_post_custom_tag_snapshots($mysqli,$prefix,'post',$ids);
            default: return seo_environment_compare_fetch_faq_snapshots($mysqli,$prefix,$ids);
        }
    }
}

if ( ! function_exists( 'seo_environment_compare_component_labels' ) ) {
    function seo_environment_compare_component_labels() {
        return [
            'base'        => 'título/nombre',
            'excerpt'     => 'excerpt',
            'description' => 'description',
            'categories'  => 'categoría asociada',
            'tags'        => 'etiquetas',
            'seo_tags'    => 'etiquetas SEO',
            'semantic'    => 'etiquetas semánticas',
            'attributes'  => 'atributos',
            'content'     => 'pregunta/respuesta',
            'settings'    => 'estado/orden',
            'target'      => 'objeto relacionado',
        ];
    }
}

if ( ! function_exists( 'seo_environment_compare_diff_components' ) ) {
    function seo_environment_compare_diff_components( array $a, array $b ) {
        $labels=seo_environment_compare_component_labels();$keys=array_unique(array_merge(array_keys($a),array_keys($b)));$diff=[];
        foreach($keys as $key){if((string)($a[$key]??'')!==(string)($b[$key]??'')){if(0===strpos($key,'semantic_'))$diff[]='semántica '.strtoupper(substr($key,9));else$diff[]=$labels[$key]??$key;}}
        return $diff;
    }
}

if ( ! function_exists( 'seo_environment_compare_store_diff' ) ) {
    function seo_environment_compare_store_diff( $entity, $id, $pro, $staging, $status, array $diffs ) {
        global $wpdb;$table=seo_environment_compare_table();
        // newer_env/modified_* se conservan en la tabla por compatibilidad con
        // instalaciones existentes, pero desde v2.1 no participan en la logica.
        $wpdb->replace($table,[
            'entity'=>$entity,'object_id'=>absint($id),'status'=>$status,'newer_env'=>'',
            'name_pro'=>(string)($pro['name']??''),'name_staging'=>(string)($staging['name']??''),
            'modified_pro'=>null,'modified_staging'=>null,
            'hash_pro'=>(string)($pro['hash']??''),'hash_staging'=>(string)($staging['hash']??''),
            'summary'=>implode(', ',$diffs),'details_json'=>wp_json_encode(['differences'=>$diffs]),'scanned_at'=>current_time('mysql',true),
        ],['%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
    }
}


if ( ! function_exists( 'seo_environment_compare_adaptive_config' ) ) {
    /**
     * Configuracion adaptativa del comparador. El panel central de Procesos
     * puede sobreescribir estos valores mediante el filtro homonimo.
     */
    function seo_environment_compare_adaptive_config() {
        $config = [
            'min_rows'               => 2,
            'initial_rows'           => 8,
            'max_rows'               => 60,
            'target_seconds'         => 4.0,
            'hard_seconds'           => 12.0,
            'growth_factor'          => 1.35,
            'slowdown_factor'        => 0.65,
            'critical_factor'        => 0.40,
            'memory_soft_ratio'      => 0.55,
            'memory_hard_ratio'      => 0.72,
            'cpu_soft_percent'       => 65.0,
            'cpu_hard_percent'       => 80.0,
            'normal_delay'           => 2,
            'heavy_delay'            => 10,
            'critical_delay'         => 30,
        ];

        $filtered = apply_filters( 'seo_environment_compare_adaptive_config', $config );
        if ( is_array( $filtered ) ) $config = array_merge( $config, $filtered );

        $config['min_rows']          = max( 1, min( 100, absint( $config['min_rows'] ) ) );
        $config['initial_rows']      = max( $config['min_rows'], min( 200, absint( $config['initial_rows'] ) ) );
        $config['max_rows']          = max( $config['initial_rows'], min( 300, absint( $config['max_rows'] ) ) );
        $config['target_seconds']    = max( 1.0, min( 60.0, (float) $config['target_seconds'] ) );
        $config['hard_seconds']      = max( $config['target_seconds'] + 1.0, min( 120.0, (float) $config['hard_seconds'] ) );
        $config['growth_factor']     = max( 1.05, min( 2.0, (float) $config['growth_factor'] ) );
        $config['slowdown_factor']   = max( 0.20, min( 0.95, (float) $config['slowdown_factor'] ) );
        $config['critical_factor']   = max( 0.10, min( 0.80, (float) $config['critical_factor'] ) );
        $config['memory_soft_ratio'] = max( 0.20, min( 0.90, (float) $config['memory_soft_ratio'] ) );
        $config['memory_hard_ratio'] = max( $config['memory_soft_ratio'] + 0.05, min( 0.98, (float) $config['memory_hard_ratio'] ) );
        $config['cpu_soft_percent']  = max( 20.0, min( 95.0, (float) $config['cpu_soft_percent'] ) );
        $config['cpu_hard_percent']  = max( $config['cpu_soft_percent'] + 5.0, min( 100.0, (float) $config['cpu_hard_percent'] ) );
        $config['normal_delay']      = max( 0, min( 120, absint( $config['normal_delay'] ) ) );
        $config['heavy_delay']       = max( $config['normal_delay'], min( 300, absint( $config['heavy_delay'] ) ) );
        $config['critical_delay']    = max( $config['heavy_delay'], min( 900, absint( $config['critical_delay'] ) ) );
        return $config;
    }
}

if ( ! function_exists( 'seo_environment_compare_memory_limit_bytes' ) ) {
    function seo_environment_compare_memory_limit_bytes() {
        $raw = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' === $raw || '-1' === $raw ) return 0;
        if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
            $bytes = (int) wp_convert_hr_to_bytes( $raw );
            return $bytes > 0 ? $bytes : 0;
        }
        if ( is_numeric( $raw ) ) return max( 0, (int) $raw );
        $unit = strtolower( substr( $raw, -1 ) );
        $value = (float) $raw;
        if ( 'g' === $unit ) { $value *= 1024; $unit = 'm'; }
        if ( 'm' === $unit ) { $value *= 1024; $unit = 'k'; }
        if ( 'k' === $unit ) $value *= 1024;
        return max( 0, (int) $value );
    }
}

if ( ! function_exists( 'seo_environment_compare_memory_ratio' ) ) {
    function seo_environment_compare_memory_ratio() {
        $limit = seo_environment_compare_memory_limit_bytes();
        if ( $limit < 1 ) return 0.0;
        return max( 0.0, min( 1.5, memory_get_usage( true ) / $limit ) );
    }
}

if ( ! function_exists( 'seo_environment_compare_runtime_cpu_metrics' ) ) {
    /**
     * Usa la misma fuente de CPU del Clasificador/Estado del servidor cuando
     * esta disponible. Si el hosting no expone una metrica fiable, no inventa
     * un porcentaje y el regulador sigue por tiempo y memoria.
     */
    function seo_environment_compare_runtime_cpu_metrics() {
        if ( function_exists( 'seo_classifier_job_runtime_cpu_metrics' ) ) {
            $metrics = seo_classifier_job_runtime_cpu_metrics();
            if ( is_array( $metrics ) ) return $metrics;
        }
        $result = [ 'available'=>false, 'percent'=>null, 'cores'=>0, 'load'=>[] ];
        if ( function_exists( 'seo_server_status_runtime_resource_metrics' ) ) {
            $metrics = (array) seo_server_status_runtime_resource_metrics();
            $cpu = (array) ( $metrics['cpu'] ?? [] );
            if ( ! empty( $cpu['available'] ) && isset( $cpu['percent'] ) && is_numeric( $cpu['percent'] ) ) {
                $result['available'] = true;
                $result['percent'] = max( 0.0, (float) $cpu['percent'] );
                $result['cores'] = absint( $cpu['cores'] ?? 0 );
                $result['load'] = array_values( (array) ( $cpu['load'] ?? [] ) );
            }
        } elseif ( function_exists( 'sys_getloadavg' ) && function_exists( 'seo_server_status_detect_cpu_cores' ) ) {
            $load = @sys_getloadavg();
            $cores = absint( seo_server_status_detect_cpu_cores() );
            if ( is_array( $load ) && isset( $load[0] ) && $cores > 0 ) {
                $result['available'] = true;
                $result['percent'] = max( 0.0, ( (float) $load[0] / max( 1, $cores ) ) * 100.0 );
                $result['cores'] = $cores;
                $result['load'] = array_values( array_slice( $load, 0, 3 ) );
            }
        }
        return apply_filters( 'seo_environment_compare_runtime_cpu_metrics', $result );
    }
}

if ( ! function_exists( 'seo_environment_compare_adaptive_plan' ) ) {
    /**
     * Calcula el siguiente lote usando coste observado + presion global.
     * Tambien respeta la ventana que el Gestor asigna a este target para que el
     * comparador no monopolice el ciclo cuando hay otros procesos activos.
     */
    function seo_environment_compare_adaptive_plan( array $state, $window_seconds = 0 ) {
        $config = seo_environment_compare_adaptive_config();
        $previous_target = max( $config['min_rows'], absint( $state['last_batch_target_rows'] ?? 0 ) );
        if ( empty( $state['last_batch_target_rows'] ) ) $previous_target = $config['initial_rows'];
        $previous_rows = absint( $state['last_batch_rows'] ?? 0 );
        $duration = max( 0.0, (float) ( $state['last_batch_seconds'] ?? 0 ) );
        $previous_memory = max( 0.0, (float) ( $state['last_batch_memory_ratio'] ?? 0 ) );
        $previous_cpu = isset( $state['last_cpu_percent'] ) && is_numeric( $state['last_cpu_percent'] )
            ? max( 0.0, (float) $state['last_cpu_percent'] )
            : null;
        $budget_reached = ! empty( $state['last_batch_time_budget_reached'] );
        $window_seconds = max( 0.0, (float) $window_seconds );
        $window_budget = $window_seconds > 0 ? max( 2.0, $window_seconds - 1.0 ) : (float) $config['hard_seconds'];
        $effective_target = min( (float) $config['target_seconds'], max( 1.0, $window_budget * 0.65 ) );
        $effective_hard = min( (float) $config['hard_seconds'], $window_budget );
        $next = $previous_target;
        $pressure = 'baja';
        $reason = 'arranque conservador';

        if ( $previous_rows > 0 && $duration > 0 ) {
            $seconds_per_row = $duration / max( 1, $previous_rows );
            $ideal = (int) floor( $effective_target / max( 0.01, $seconds_per_row ) );
            $ideal = max( $config['min_rows'], min( $config['max_rows'], $ideal ) );
            if ( $previous_memory >= $config['memory_hard_ratio'] || $budget_reached || $duration >= $effective_hard ) {
                $next = max( $config['min_rows'], min( $ideal, (int) floor( $previous_target * $config['critical_factor'] ) ) );
                $pressure = 'critica';
                $reason = 'corte por tiempo/memoria en el lote anterior';
            } elseif ( $previous_memory >= $config['memory_soft_ratio'] || $duration > ( $effective_target * 1.35 ) ) {
                $next = max( $config['min_rows'], min( $ideal, (int) floor( $previous_target * $config['slowdown_factor'] ) ) );
                $pressure = 'alta';
                $reason = 'lote anterior pesado';
            } elseif ( $ideal > $previous_target ) {
                $growth_cap = max( $previous_target + 1, (int) ceil( $previous_target * $config['growth_factor'] ) );
                $next = min( $config['max_rows'], $ideal, $growth_cap );
                $reason = 'coste estable: puede crecer';
            } elseif ( $ideal < $previous_target ) {
                $next = max( $config['min_rows'], $ideal );
                $pressure = 'media';
                $reason = 'ajuste al coste real';
            } else {
                $reason = 'ritmo estable';
            }
        } elseif ( $window_seconds > 0 && $window_budget < $config['hard_seconds'] ) {
            // El primer lote aun no tiene telemetria. Se escala conservadoramente
            // al presupuesto que el supervisor reparte entre los targets activos.
            $ratio = max( 0.20, min( 1.0, $window_budget / max( 1.0, (float) $config['hard_seconds'] ) ) );
            $next = max( $config['min_rows'], (int) floor( $config['initial_rows'] * $ratio ) );
            $reason = 'primer lote limitado por ventana del gestor';
        }

        // La CPU observada en el lote anterior limita el siguiente lote, pero no
        // lo bloquea indefinidamente: el aplazamiento duro solo depende de la
        // presion ACTUAL medida justo antes de abrir las BBDD remotas.
        if ( null !== $previous_cpu && $previous_cpu >= $config['cpu_hard_percent'] ) {
            $next = $config['min_rows'];
            $pressure = 'cpu_critica';
            $reason = 'CPU crítica observada en el lote anterior';
        } elseif ( null !== $previous_cpu && $previous_cpu >= $config['cpu_soft_percent'] ) {
            $next = min( $next, max( $config['min_rows'], (int) floor( $previous_target * $config['slowdown_factor'] ) ) );
            if ( 'baja' === $pressure ) $pressure = 'cpu_alta';
            $reason = 'CPU elevada observada en el lote anterior';
        }

        $current_memory = seo_environment_compare_memory_ratio();
        $cpu_metrics = seo_environment_compare_runtime_cpu_metrics();
        $current_cpu = ! empty( $cpu_metrics['available'] ) && isset( $cpu_metrics['percent'] ) && is_numeric( $cpu_metrics['percent'] )
            ? max( 0.0, (float) $cpu_metrics['percent'] )
            : null;

        $delay = (int) $config['normal_delay'];
        if ( in_array( $pressure, [ 'alta', 'cpu_alta' ], true ) ) $delay = max( $delay, (int) $config['heavy_delay'] );
        if ( in_array( $pressure, [ 'critica', 'cpu_critica' ], true ) ) $delay = max( $delay, (int) $config['critical_delay'] );

        $defer = false;
        if ( $current_memory >= $config['memory_hard_ratio'] ) {
            $next = $config['min_rows'];
            $delay = (int) $config['critical_delay'];
            $pressure = 'memoria_critica';
            $reason = 'memoria PHP actual por encima del umbral duro';
            $defer = true;
        } elseif ( null !== $current_cpu && $current_cpu >= $config['cpu_hard_percent'] ) {
            $next = $config['min_rows'];
            $delay = (int) $config['critical_delay'];
            $pressure = 'cpu_critica';
            $reason = 'CPU global actual por encima del umbral duro';
            $defer = true;
        } elseif ( $current_memory >= $config['memory_soft_ratio'] ) {
            $next = min( $next, max( $config['min_rows'], (int) floor( $previous_target * $config['slowdown_factor'] ) ) );
            $delay = max( $delay, (int) $config['heavy_delay'] );
            $pressure = 'memoria_alta';
            $reason = 'memoria PHP actual en zona preventiva';
        } elseif ( null !== $current_cpu && $current_cpu >= $config['cpu_soft_percent'] ) {
            $next = min( $next, max( $config['min_rows'], (int) floor( $previous_target * $config['slowdown_factor'] ) ) );
            $delay = max( $delay, (int) $config['heavy_delay'] );
            $pressure = 'cpu_alta';
            $reason = 'CPU global actual elevada';
        }

        return [
            'batch_size'      => max( $config['min_rows'], min( $config['max_rows'], absint( $next ) ) ),
            'delay'           => max( 0, absint( $delay ) ),
            'pressure'        => $pressure,
            'reason'          => $reason,
            'defer'           => $defer,
            'current_memory'  => $current_memory,
            'current_cpu'     => $current_cpu,
            'hard_budget'     => $effective_hard,
            'config'          => $config,
        ];
    }
}

if ( ! function_exists( 'seo_environment_compare_lock_name' ) ) {
    function seo_environment_compare_lock_name( $entity ) {
        return 'seo_env_compare_worker_' . sanitize_key( (string) $entity );
    }
}

if ( ! function_exists( 'seo_environment_compare_manager_key' ) ) {
    function seo_environment_compare_manager_key() {
        // Se conserva el prefijo histórico import-export para mantener
        // compatibilidad con el estado/monitor ya persistido del Gestor.
        return 'import-export-environment-compare';
    }
}

if ( ! function_exists( 'seo_environment_compare_has_pending_scan' ) ) {
    function seo_environment_compare_has_pending_scan() {
        foreach ( array_keys( seo_environment_compare_entities() ) as $entity ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' === (string) ( $state['status'] ?? '' ) ) return true;
        }
        return false;
    }
}


if ( ! function_exists( 'seo_environment_compare_has_due_scan' ) ) {
    function seo_environment_compare_has_due_scan() {
        $now = time();
        foreach ( array_keys( seo_environment_compare_entities() ) as $entity ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;
            if ( absint( $state['next_run_at'] ?? 0 ) <= $now ) return true;
        }
        return false;
    }
}

if ( ! function_exists( 'seo_environment_compare_next_worker_entity' ) ) {
    function seo_environment_compare_next_worker_entity() {
        $entities = array_keys( seo_environment_compare_entities() );
        $count = count( $entities );
        if ( ! $count ) return '';
        $cursor = absint( get_option( 'seo_environment_compare_worker_cursor', 0 ) ) % $count;
        $now = time();
        for ( $i = 0; $i < $count; $i++ ) {
            $index = ( $cursor + $i ) % $count;
            $entity = $entities[ $index ];
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;
            if ( absint( $state['next_run_at'] ?? 0 ) > $now ) continue;
            update_option( 'seo_environment_compare_worker_cursor', ( $index + 1 ) % $count, false );
            return $entity;
        }
        return '';
    }
}

if ( ! function_exists( 'seo_environment_compare_finalize_worker_scan' ) ) {
    function seo_environment_compare_finalize_worker_scan( $entity, array $state ) {
        global $wpdb;
        $table = seo_environment_compare_table();
        $same = (int) ( $state['same'] ?? 0 );
        $different = (int) ( $state['different'] ?? 0 );
        $only_pro = (int) ( $state['only_pro'] ?? 0 );
        $only_staging = (int) ( $state['only_staging'] ?? 0 );
        $processed = (int) ( $state['processed'] ?? 0 );
        $pro = (int) ( $state['pro'] ?? 0 );
        $staging = (int) ( $state['staging'] ?? 0 );
        $stored = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE entity=%s AND status IN ('different','only_pro','only_staging')",
            $entity
        ) );
        $expected_stored = $different + $only_pro + $only_staging;
        $errors = [];
        if ( $processed !== $same + $different + $only_pro + $only_staging ) $errors[] = 'processed no coincide con los estados';
        if ( $pro !== $same + $different + $only_pro ) $errors[] = 'contador PRO inconsistente';
        if ( $staging !== $same + $different + $only_staging ) $errors[] = 'contador STAGING inconsistente';
        if ( $stored !== $expected_stored ) $errors[] = 'filas de diferencias no coinciden con el contador';
        $state['finished_at'] = time();
        $state['last_activity_at'] = time();
        $state['next_run_at'] = 0;
        if ( $errors ) {
            $state['status'] = 'error';
            $state['error'] = 'Validación final fallida: ' . implode( '; ', $errors ) . '. Repite el escaneo.';
        } else {
            $state['status'] = 'complete';
            $state['error'] = '';
        }
        seo_environment_compare_set_state( $entity, $state );
        return $state;
    }
}

if ( ! function_exists( 'seo_environment_compare_process_worker_batch' ) ) {
    /**
     * Ejecuta UN solo lote de comparación. Esta función solo la llama el Gestor
     * de procesos; nunca el navegador. El tamaño se calcula con el regulador
     * central y el lote respeta el presupuesto repartido por el supervisor.
     */
    function seo_environment_compare_process_worker_batch( $entity, $source = 'process_manager', $time_budget = 0 ) {
        $entities = seo_environment_compare_entities();
        if ( ! isset( $entities[ $entity ] ) ) return new WP_Error( 'invalid_entity', 'Entidad de comparación no válida.' );
        $state = seo_environment_compare_get_state( $entity );
        if ( 'running' !== (string) ( $state['status'] ?? '' ) ) return false;
        if ( absint( $state['next_run_at'] ?? 0 ) > time() ) return false;

        global $wpdb;
        $lock_name = seo_environment_compare_lock_name( $entity );
        $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
        if ( '1' !== (string) $locked ) return false;

        $started = microtime( true );
        try {
            $plan = seo_environment_compare_adaptive_plan( $state, $time_budget );
            $state['batch_size'] = absint( $plan['batch_size'] );
            $state['adaptive_pressure'] = sanitize_key( (string) $plan['pressure'] );
            $state['adaptive_reason'] = sanitize_text_field( (string) $plan['reason'] );
            $state['worker_source'] = sanitize_key( (string) $source );

            // Si el servidor ya llega presionado, ni siquiera abrimos las dos
            // conexiones remotas. El Gestor conserva el trabajo y lo reintentará.
            if ( ! empty( $plan['defer'] ) ) {
                $state['last_cpu_percent'] = null === $plan['current_cpu'] ? null : round( (float) $plan['current_cpu'], 2 );
                $state['last_batch_memory_ratio'] = round( (float) $plan['current_memory'], 4 );
                $state['next_run_at'] = time() + absint( $plan['delay'] );
                $state['last_activity_at'] = time();
                seo_environment_compare_set_state( $entity, $state );
                if ( function_exists( 'seo_process_supervisor_nudge' ) ) seo_process_supervisor_nudge( $plan['delay'], 'environment_compare' );
                return false;
            }

            $batch_limit = max( 1, absint( $plan['batch_size'] ) );
            $candidate_limit = min( 300, $batch_limit + max( 4, min( 20, (int) ceil( $batch_limit * 0.5 ) ) ) );
            $pro = seo_environment_compare_open( 'pro' );
            $stg = seo_environment_compare_open( 'staging' );
            if ( is_wp_error( $pro ) || is_wp_error( $stg ) ) {
                if ( $pro instanceof mysqli ) @mysqli_close( $pro );
                if ( $stg instanceof mysqli ) @mysqli_close( $stg );
                $err = is_wp_error( $pro ) ? $pro : $stg;
                $state['status'] = 'error';
                $state['error'] = $err->get_error_message();
                $state['last_activity_at'] = time();
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            $cursor = absint( $state['cursor'] ?? 0 );
            $pro_ids = seo_environment_compare_candidate_ids( $pro, seo_environment_compare_db_prefix( 'pro' ), $entity, $cursor, $candidate_limit );
            $stg_ids = seo_environment_compare_candidate_ids( $stg, seo_environment_compare_db_prefix( 'staging' ), $entity, $cursor, $candidate_limit );
            if ( is_wp_error( $pro_ids ) || is_wp_error( $stg_ids ) ) {
                $err = is_wp_error( $pro_ids ) ? $pro_ids : $stg_ids;
                @mysqli_close( $pro ); @mysqli_close( $stg );
                $state['status'] = 'error'; $state['error'] = $err->get_error_message(); $state['last_activity_at'] = time();
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }
            $ids = array_values( array_unique( array_merge( $pro_ids, $stg_ids ) ) );
            sort( $ids, SORT_NUMERIC );
            $ids = array_slice( $ids, 0, $batch_limit );
            if ( ! $ids ) {
                @mysqli_close( $pro ); @mysqli_close( $stg );
                return seo_environment_compare_finalize_worker_scan( $entity, $state );
            }

            $pro_rows = seo_environment_compare_fetch_snapshots( $pro, 'pro', $entity, $ids );
            $stg_rows = seo_environment_compare_fetch_snapshots( $stg, 'staging', $entity, $ids );
            if ( is_wp_error( $pro_rows ) || is_wp_error( $stg_rows ) ) {
                $err = is_wp_error( $pro_rows ) ? $pro_rows : $stg_rows;
                @mysqli_close( $pro ); @mysqli_close( $stg );
                $state['status'] = 'error'; $state['error'] = $err->get_error_message(); $state['last_activity_at'] = time();
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            $table = seo_environment_compare_table();
            foreach ( $ids as $id ) {
                $p = $pro_rows[ $id ] ?? null;
                $s = $stg_rows[ $id ] ?? null;
                $state['processed']++;
                if ( $p ) $state['pro']++;
                if ( $s ) $state['staging']++;
                if ( ! $p || ! $s ) {
                    $status = $p ? 'only_pro' : 'only_staging';
                    $state[ $status ]++;
                    seo_environment_compare_store_diff( $entity, $id, $p ?: [], $s ?: [], $status, [ $p ? 'solo existe en PRO' : 'solo existe en STAGING' ] );
                    continue;
                }
                if ( hash_equals( (string) $p['hash'], (string) $s['hash'] ) ) {
                    $state['same']++;
                    $wpdb->delete( $table, [ 'entity'=>$entity, 'object_id'=>$id ], [ '%s','%d' ] );
                    continue;
                }
                $state['different']++;
                seo_environment_compare_store_diff( $entity, $id, $p, $s, 'different', seo_environment_compare_diff_components( $p['components'], $s['components'] ) );
            }

            $state['cursor'] = max( $ids );
            $state['worker_runs'] = absint( $state['worker_runs'] ?? 0 ) + 1;
            $state['worker_source'] = sanitize_key( (string) $source );
            $state['last_activity_at'] = time();
            $state['last_batch_target_rows'] = $batch_limit;

            // Detecta el final en este mismo lote: no necesita una petición AJAX
            // adicional del navegador para pasar a COMPLETE.
            $next_pro = seo_environment_compare_candidate_ids( $pro, seo_environment_compare_db_prefix( 'pro' ), $entity, $state['cursor'], 1 );
            $next_stg = seo_environment_compare_candidate_ids( $stg, seo_environment_compare_db_prefix( 'staging' ), $entity, $state['cursor'], 1 );
            @mysqli_close( $pro ); @mysqli_close( $stg );
            if ( is_wp_error( $next_pro ) || is_wp_error( $next_stg ) ) {
                $err = is_wp_error( $next_pro ) ? $next_pro : $next_stg;
                $state['status'] = 'error'; $state['error'] = $err->get_error_message();
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            $elapsed = max( 0.001, microtime( true ) - $started );
            $cpu_metrics = seo_environment_compare_runtime_cpu_metrics();
            $state['last_batch_rows'] = count( $ids );
            $state['last_batch_seconds'] = round( $elapsed, 3 );
            $state['last_batch_memory_ratio'] = round( seo_environment_compare_memory_ratio(), 4 );
            $state['last_cpu_percent'] = ! empty( $cpu_metrics['available'] ) && isset( $cpu_metrics['percent'] ) && is_numeric( $cpu_metrics['percent'] )
                ? round( max( 0.0, (float) $cpu_metrics['percent'] ), 2 )
                : null;
            $effective_budget = max( 0.0, (float) $time_budget );
            $state['last_batch_time_budget_reached'] = $effective_budget > 0 && $elapsed >= max( 1.0, $effective_budget * 0.90 ) ? 1 : 0;

            if ( empty( $next_pro ) && empty( $next_stg ) ) {
                return seo_environment_compare_finalize_worker_scan( $entity, $state );
            }

            $next_plan = seo_environment_compare_adaptive_plan( $state, $time_budget );
            $state['batch_size'] = absint( $next_plan['batch_size'] );
            $state['adaptive_pressure'] = sanitize_key( (string) $next_plan['pressure'] );
            $state['adaptive_reason'] = sanitize_text_field( (string) $next_plan['reason'] );
            $state['status'] = 'running';
            $state['next_run_at'] = time() + absint( $next_plan['delay'] );
            seo_environment_compare_set_state( $entity, $state );
            if ( function_exists( 'seo_process_supervisor_nudge' ) ) seo_process_supervisor_nudge( $next_plan['delay'], 'environment_compare' );
            return $state;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }
}

if ( ! function_exists( 'seo_environment_compare_process_manager_slice' ) ) {
    function seo_environment_compare_process_manager_slice( $seconds = 20, $source = 'process_manager', $target = [] ) {
        $entity = seo_environment_compare_next_worker_entity();
        if ( ! $entity ) return false;
        $labels = seo_environment_compare_entities();
        $state = seo_environment_compare_get_state( $entity );
        $key = seo_environment_compare_manager_key();
        if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
            seo_process_supervisor_managed_update( $key, [
                'name'=>'Comparador PRO/STAGING', 'pending'=>1, 'healthy'=>1, 'last_checked'=>time(),
                'last_attempt_at'=>time(), 'last_result'=>'running', 'last_error'=>'',
                'detail'=>'Comparando '.$labels[$entity]['label'].' desde ID '.absint($state['cursor'] ?? 0).'.'
            ] );
        }
        $result = seo_environment_compare_process_worker_batch( $entity, $source, $seconds );
        $fresh = seo_environment_compare_get_state( $entity );
        $still = seo_environment_compare_has_pending_scan();
        if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
            seo_process_supervisor_managed_update( $key, [
                'name'=>'Comparador PRO/STAGING', 'pending'=>$still?1:0,
                'healthy'=>is_wp_error($result)?0:1, 'last_checked'=>time(),
                'last_result'=>is_wp_error($result)?'error':(false===$result?'waiting':(('complete'===($fresh['status']??''))?'completed':'processed')),
                'last_error'=>is_wp_error($result)?$result->get_error_message():'',
                'detail'=>'Capa '.$labels[$entity]['label'].' · procesados '.absint($fresh['processed']??0).' · lote '.absint($fresh['batch_size']??8).' · '.(float)($fresh['last_batch_seconds']??0).' s · presión '.sanitize_text_field((string)($fresh['adaptive_pressure']??'baja')).'.'
            ] );
        }
        return false !== $result && ! is_wp_error( $result );
    }
}

if ( ! function_exists( 'seo_environment_compare_manager_targets' ) ) {
    function seo_environment_compare_manager_targets( $targets, $settings, $source ) {
        if ( isset( $settings['environment_compare'] ) && empty( $settings['environment_compare'] ) ) return $targets;
        if ( ! seo_environment_compare_has_pending_scan() ) return $targets;
        if ( ! seo_environment_compare_has_due_scan() ) return $targets;
        $targets[] = [
            'type' => 'environment_compare',
            'data' => [],
            'callback' => 'seo_environment_compare_process_manager_slice',
        ];
        return $targets;
    }
}
add_filter( 'seo_process_supervisor_manager_targets', 'seo_environment_compare_manager_targets', 20, 3 );

if ( ! function_exists( 'seo_environment_compare_manager_pending_filter' ) ) {
    function seo_environment_compare_manager_pending_filter( $pending ) {
        if ( function_exists( 'seo_process_supervisor_settings' ) ) {
            $settings = seo_process_supervisor_settings();
            if ( isset( $settings['environment_compare'] ) && empty( $settings['environment_compare'] ) ) return (bool) $pending;
        }
        return $pending || seo_environment_compare_has_pending_scan();
    }
}
add_filter( 'seo_process_supervisor_has_pending_work', 'seo_environment_compare_manager_pending_filter', 20, 1 );

/* -------------------------------------------------------------------------
 * INTEGRACION CON EL MONITOR CENTRAL DE PROCESOS.
 * El Gestor de workers ya ejecuta el comparador mediante los filtros del
 * supervisor. Este collector hace visible ese mismo trabajo en SEO Taxonomy >
 * Procesos, sin modificar el nucleo del monitor.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'seo_environment_compare_process_monitor_item' ) ) {
    function seo_environment_compare_process_monitor_item( $items ) {
        $definitions = seo_environment_compare_entities();
        $states = [];
        $running = [];
        $errors = [];
        $completed = [];
        $latest_entity = '';
        $latest_activity = 0;
        $processed_total = 0;

        foreach ( $definitions as $entity => $definition ) {
            $state = seo_environment_compare_get_state( $entity );
            $states[ $entity ] = $state;
            $status = sanitize_key( (string) ( $state['status'] ?? 'never' ) );
            $processed_total += absint( $state['processed'] ?? 0 );

            if ( 'running' === $status ) $running[] = $entity;
            elseif ( 'error' === $status ) $errors[] = $entity;
            elseif ( 'complete' === $status ) $completed[] = $entity;

            $activity = max(
                absint( $state['last_activity_at'] ?? 0 ),
                absint( $state['finished_at'] ?? 0 ),
                absint( $state['started_at'] ?? 0 )
            );
            if ( $activity >= $latest_activity ) {
                $latest_activity = $activity;
                $latest_entity = $entity;
            }
        }

        $focus = '';
        if ( $running ) {
            // Prioriza la capa activa con actividad mas reciente.
            foreach ( $running as $entity ) {
                $activity = max(
                    absint( $states[$entity]['last_activity_at'] ?? 0 ),
                    absint( $states[$entity]['started_at'] ?? 0 )
                );
                if ( ! $focus || $activity >= max(
                    absint( $states[$focus]['last_activity_at'] ?? 0 ),
                    absint( $states[$focus]['started_at'] ?? 0 )
                ) ) $focus = $entity;
            }
        } elseif ( $latest_entity ) {
            $focus = $latest_entity;
        }

        $focus_state = $focus && isset( $states[$focus] ) ? $states[$focus] : [];
        $now = time();
        $due_in = $running && $focus_state ? max( 0, absint( $focus_state['next_run_at'] ?? 0 ) - $now ) : 0;

        if ( $running ) {
            if ( $due_in > 0 && function_exists( 'seo_processes_state' ) ) {
                $process_state = seo_processes_state( 'waiting', 'En espera controlada', 'waiting' );
            } elseif ( function_exists( 'seo_processes_state' ) ) {
                $process_state = seo_processes_state( 'running', 'En ejecución', 'running' );
            } else {
                $process_state = [ 'code'=>'running', 'label'=>'En ejecución', 'tone'=>'running' ];
            }
        } elseif ( $errors ) {
            $process_state = function_exists( 'seo_processes_state' )
                ? seo_processes_state( 'error', 'Error', 'error' )
                : [ 'code'=>'error', 'label'=>'Error', 'tone'=>'error' ];
        } elseif ( $completed ) {
            $process_state = function_exists( 'seo_processes_state' )
                ? seo_processes_state( 'completed', 'Parado · último escaneo completado', 'completed' )
                : [ 'code'=>'completed', 'label'=>'Parado · último escaneo completado', 'tone'=>'completed' ];
        } else {
            $process_state = function_exists( 'seo_processes_state' )
                ? seo_processes_state( 'stopped', 'Parado', 'stopped' )
                : [ 'code'=>'stopped', 'label'=>'Parado', 'tone'=>'stopped' ];
        }

        $seconds = (float) ( $focus_state['last_batch_seconds'] ?? 0 );
        $rows = absint( $focus_state['last_batch_rows'] ?? 0 );
        $rate = ( $seconds > 0 && $rows > 0 ) ? ( $rows / $seconds ) * 60.0 : 0.0;
        $speed = function_exists( 'seo_processes_format_rate' )
            ? seo_processes_format_rate( $rate, 'objetos' )
            : ( $rate > 0 ? number_format_i18n( $rate, 1 ) . ' objetos/min' : 'Sin ritmo medible' );
        $response = $seconds > 0
            ? number_format_i18n( $seconds, 2 ) . ' s el último lote'
            : 'Sin lote medido';

        $batch = absint( $focus_state['batch_size'] ?? 8 );
        $load = [ 'Gestor de workers', 'lote objetivo ' . number_format_i18n( $batch ) ];
        $pressure = sanitize_key( (string) ( $focus_state['adaptive_pressure'] ?? '' ) );
        if ( $pressure ) $load[] = 'presión ' . str_replace( '_', ' ', $pressure );
        if ( isset( $focus_state['last_cpu_percent'] ) && is_numeric( $focus_state['last_cpu_percent'] ) ) $load[] = 'CPU ' . number_format_i18n( (float) $focus_state['last_cpu_percent'], 1 ) . '%';
        $memory_percent = max( 0.0, (float) ( $focus_state['last_batch_memory_ratio'] ?? 0 ) * 100.0 );
        if ( $memory_percent > 0 ) $load[] = 'mem ' . number_format_i18n( $memory_percent, 1 ) . '%';
        if ( $due_in > 0 ) $load[] = 'pausa ' . number_format_i18n( $due_in ) . ' s';
        if ( $running ) $load[] = count( $running ) . ' capa' . ( 1 === count( $running ) ? ' activa' : 's activas' );
        if ( $errors ) $load[] = count( $errors ) . ' con error';

        if ( $focus && isset( $definitions[$focus] ) ) {
            $detail = $definitions[$focus]['label'] . ' · ' . number_format_i18n( absint( $focus_state['processed'] ?? 0 ) ) . ' procesados';
            $cursor = absint( $focus_state['cursor'] ?? 0 );
            if ( $cursor ) $detail .= ' · cursor ' . number_format_i18n( $cursor );
            if ( count( $running ) > 1 ) $detail .= ' · ' . number_format_i18n( count( $running ) ) . ' capas en cola';
            if ( ! empty( $focus_state['adaptive_reason'] ) ) $detail .= ' · ' . sanitize_text_field( (string) $focus_state['adaptive_reason'] );
            if ( ! empty( $focus_state['error'] ) ) $detail .= ' · ' . sanitize_text_field( (string) $focus_state['error'] );
        } else {
            $detail = 'Sin comparaciones iniciadas.';
        }

        $activity_age = $latest_activity ? max( 0, $now - $latest_activity ) : null;
        $activity = function_exists( 'seo_processes_format_age' )
            ? seo_processes_format_age( $activity_age )
            : ( null === $activity_age ? 'Sin actividad registrada' : 'Hace ' . number_format_i18n( $activity_age ) . ' s' );

        $items[] = [
            'id'            => 'environment-compare',
            'name'          => 'Comparador PRO/STAGING',
            'kind'          => 'Worker PHP · Gestor de procesos',
            'state'         => $process_state,
            'speed'         => $speed,
            'response'      => $response,
            'load'          => implode( ' · ', $load ),
            'activity'      => $activity,
            'activity_age'  => $activity_age,
            'progress'      => null,
            'progress_text' => '—',
            'detail'        => $detail,
            'url'           => add_query_arg(
                [ 'page'=>'seo-import-export', 'seo_ie_tab'=>'comparar-entornos' ],
                admin_url( 'admin.php' )
            ),
        ];

        return $items;
    }
}
add_filter( 'seo_processes_monitor_items', 'seo_environment_compare_process_monitor_item', 20, 1 );

if ( ! function_exists( 'seo_environment_compare_scan_ajax' ) ) {
    /**
     * El AJAX ya NO compara. Solo crea/reinicia el trabajo y devuelve el estado.
     * El trabajo pesado lo recoge el Gestor de procesos en segundo plano.
     */
    function seo_environment_compare_scan_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message'=>'Sin permisos.' ], 403 );
        check_ajax_referer( 'seo_environment_compare', 'nonce' );
        $entity = sanitize_key( $_POST['entity'] ?? '' );
        $entities = seo_environment_compare_entities();
        if ( ! isset( $entities[ $entity ] ) ) wp_send_json_error( [ 'message'=>'Entidad no válida.' ], 400 );
        if ( ! seo_environment_compare_install() ) wp_send_json_error( [ 'message'=>'No se pudo preparar la tabla local de comparación.' ], 500 );

        $reset = ! empty( $_POST['reset'] );
        if ( $reset ) {
            if ( ! function_exists( 'seo_process_supervisor_settings' ) ) {
                wp_send_json_error( [ 'message'=>'No está cargado el Gestor de procesos. No se inicia el escaneo para evitar ejecutarlo fuera del worker.' ], 503 );
            }
            $manager = seo_process_supervisor_settings();
            if ( empty( $manager['enabled'] ) ) {
                wp_send_json_error( [ 'message'=>'El Gestor de procesos está desactivado. Actívalo antes de lanzar la comparación.' ], 409 );
            }
            if ( isset( $manager['environment_compare'] ) && empty( $manager['environment_compare'] ) ) {
                wp_send_json_error( [ 'message'=>'La gestión del Comparador PRO/STAGING está desactivada en Procesos > Gestor de workers.' ], 409 );
            }
            global $wpdb;
            $lock_name = seo_environment_compare_lock_name( $entity );
            $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
            if ( '1' !== (string) $locked ) {
                wp_send_json_error( [ 'message'=>'Hay un lote de esta capa ejecutándose. Espera a que termine antes de reiniciar el escaneo.' ], 409 );
            }
            $config = seo_environment_compare_adaptive_config();
            $now = time();
            try {
                $wpdb->delete( seo_environment_compare_table(), [ 'entity'=>$entity ], [ '%s' ] );
                $state = [
                    'status'=>'running','cursor'=>0,'processed'=>0,'pro'=>0,'staging'=>0,'same'=>0,'different'=>0,
                    'only_pro'=>0,'only_staging'=>0,'started_at'=>$now,'finished_at'=>0,'error'=>'',
                    'batch_size'=>absint($config['initial_rows']),'last_batch_target_rows'=>0,'last_batch_rows'=>0,
                    'last_batch_seconds'=>0.0,'last_batch_memory_ratio'=>seo_environment_compare_memory_ratio(),
                    'last_batch_time_budget_reached'=>0,'last_cpu_percent'=>null,'adaptive_pressure'=>'','adaptive_reason'=>'',
                    'worker_runs'=>0,'worker_source'=>'','next_run_at'=>$now,'last_activity_at'=>$now,
                ];
                seo_environment_compare_set_state( $entity, $state );
            } finally {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            }
            $key = seo_environment_compare_manager_key();
            if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
                seo_process_supervisor_managed_update( $key, [
                    'name'=>'Comparador PRO/STAGING','pending'=>1,'healthy'=>1,'last_checked'=>$now,'last_attempt_at'=>$now,
                    'last_result'=>'queued','last_error'=>'','detail'=>'En cola: '.$entities[$entity]['label'].'. El navegador no ejecuta los lotes.'
                ] );
            }
            if ( function_exists( 'seo_process_supervisor_start' ) ) seo_process_supervisor_start( false, 'environment_compare' );
            if ( function_exists( 'seo_process_supervisor_nudge' ) ) seo_process_supervisor_nudge( 0, 'environment_compare' );
            wp_send_json_success( [ 'done'=>false, 'queued'=>true, 'state'=>$state ] );
        }

        $state = seo_environment_compare_get_state( $entity );
        wp_send_json_success( [
            'done' => in_array( (string)($state['status']??''), [ 'complete','error' ], true ),
            'queued' => 'running' === (string)($state['status']??''),
            'state' => $state,
        ] );
    }
}
add_action( 'wp_ajax_seo_environment_compare_scan', 'seo_environment_compare_scan_ajax' );

/* -------------------------------------------------------------------------
 * EXPORT JSON DEL INFORME.
 * No altera la comparacion ni la sincronizacion: exporta el estado de cada
 * capa y TODAS las filas persistidas del informe (no solo las 100 visibles).
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'seo_environment_compare_export_json_admin_post' ) ) {
    function seo_environment_compare_export_json_admin_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.', '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'seo_environment_compare_export_json' );

        global $wpdb;
        $table    = seo_environment_compare_table();
        $entities = seo_environment_compare_entities();

        $export = [
            'schema'           => 'seo_environment_compare_report',
            'version'          => '2.2.0',
            'execution'        => 'process_manager_worker',
            'generated_at_utc' => current_time( 'mysql', true ),
            'dates_ignored'    => true,
            'images_ignored'   => true,
            'current_env'      => seo_environment_compare_current_env(),
            'layers'           => [],
        ];

        foreach ( $entities as $entity => $definition ) {
            $state = seo_environment_compare_get_state( $entity );
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT object_id,status,name_pro,name_staging,hash_pro,hash_staging,summary,details_json
                     FROM {$table}
                     WHERE entity=%s
                     ORDER BY object_id ASC",
                    $entity
                ),
                ARRAY_A
            );

            $results = [];
            foreach ( (array) $rows as $row ) {
                $details = [];
                if ( ! empty( $row['details_json'] ) ) {
                    $decoded = json_decode( (string) $row['details_json'], true );
                    if ( is_array( $decoded ) ) $details = $decoded;
                }
                $results[] = [
                    'object_id'    => absint( $row['object_id'] ?? 0 ),
                    'status'       => (string) ( $row['status'] ?? '' ),
                    'name_pro'     => (string) ( $row['name_pro'] ?? '' ),
                    'name_staging' => (string) ( $row['name_staging'] ?? '' ),
                    'hash_pro'     => (string) ( $row['hash_pro'] ?? '' ),
                    'hash_staging' => (string) ( $row['hash_staging'] ?? '' ),
                    'summary'      => (string) ( $row['summary'] ?? '' ),
                    'details'      => $details,
                ];
            }

            $export['layers'][ $entity ] = [
                'label' => (string) ( $definition['label'] ?? $entity ),
                'state' => [
                    'status'       => (string) ( $state['status'] ?? 'never' ),
                    'processed'    => (int) ( $state['processed'] ?? 0 ),
                    'pro'          => (int) ( $state['pro'] ?? 0 ),
                    'staging'      => (int) ( $state['staging'] ?? 0 ),
                    'same'         => (int) ( $state['same'] ?? 0 ),
                    'different'    => (int) ( $state['different'] ?? 0 ),
                    'only_pro'     => (int) ( $state['only_pro'] ?? 0 ),
                    'only_staging' => (int) ( $state['only_staging'] ?? 0 ),
                    'error'        => (string) ( $state['error'] ?? '' ),
                ],
                'results' => $results,
            ];
        }

        $json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            wp_die( 'No se pudo generar el JSON.', '', [ 'response' => 500 ] );
        }

        $filename = 'seo-environment-compare-' . gmdate( 'Ymd-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json;
        exit;
    }
}
add_action( 'admin_post_seo_environment_compare_export_json', 'seo_environment_compare_export_json_admin_post' );

/* -------------------------------------------------------------------------
 * SINCRONIZACION: solo se escribe en el WordPress LOCAL.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'seo_environment_sync_fetch_post' ) ) {
    function seo_environment_sync_fetch_post( $mysqli, $prefix, $post_type, $id ) {
        $id=absint($id);$type=mysqli_real_escape_string($mysqli,$post_type);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT * FROM `{$prefix}posts` WHERE ID={$id} AND post_type='{$type}' AND post_status<>'trash' LIMIT 1");
        return is_wp_error($rows)?$rows:($rows[0]??null);
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_terms' ) ) {
    function seo_environment_sync_fetch_terms( $mysqli, $prefix, $object_id, array $taxonomies = [] ) {
        $id = absint( $object_id );
        $where = '';
        if ( $taxonomies ) {
            $quoted = array_map(
                static function ( $v ) use ( $mysqli ) {
                    return "'" . mysqli_real_escape_string( $mysqli, sanitize_key( $v ) ) . "'";
                },
                $taxonomies
            );
            $where = ' AND tt.taxonomy IN (' . implode( ',', $quoted ) . ')';
        }
        return seo_environment_compare_query_rows(
            $mysqli,
            "SELECT tt.taxonomy,t.slug,t.name
             FROM `{$prefix}term_relationships` tr
             JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
             JOIN `{$prefix}terms` t ON t.term_id=tt.term_id
             WHERE tr.object_id={$id}{$where}
             ORDER BY tt.taxonomy,t.slug"
        );
    }
}

if ( ! function_exists( 'seo_environment_sync_resolve_terms_local' ) ) {
    function seo_environment_sync_resolve_terms_local( array $rows ) {
        $resolved=[];$missing=[];
        foreach($rows as $row){$tax=sanitize_key($row['taxonomy']??'');$slug=sanitize_title($row['slug']??'');if(!$tax||!taxonomy_exists($tax))continue;$term=get_term_by('slug',$slug,$tax);if(!$term||is_wp_error($term)){$missing[]=$tax.':'.$slug;continue;}$resolved[$tax][]=(int)$term->term_id;}
        foreach($resolved as &$ids)$ids=array_values(array_unique(array_map('absint',$ids)));unset($ids);
        return ['terms'=>$resolved,'missing'=>$missing];
    }
}

if ( ! function_exists( 'seo_environment_compare_is_image_meta_key' ) ) {
    /**
     * Las imágenes no forman parte de la identidad editorial entre entornos.
     * Incluye miniaturas, galerías y metadatos de imagen de plugins SEO/tema.
     */
    function seo_environment_compare_is_image_meta_key( $meta_key ) {
        $key = strtolower( trim( (string) $meta_key ) );
        if ( '' === $key ) return false;
        foreach ( [ 'thumbnail', 'image', 'gallery', 'imagen', 'galeria', 'picture', 'photo', 'foto' ] as $needle ) {
            if ( false !== strpos( $key, $needle ) ) return true;
        }
        return false;
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_meta' ) ) {
    function seo_environment_sync_fetch_meta( $mysqli, $prefix, $post_id, $product=false ) {
        $id=absint($post_id);
        if($product){$keys=['_regular_price','_sale_price','_price','_manage_stock','_stock','_stock_status','_backorders','_sold_individually','_weight','_length','_width','_height','_virtual','_downloadable','_product_attributes','_seo_marca_proveedor','_seo_fabricante','_seo_proveedor','_seo_proveedor_id_externo','_seo_proveedor_catalogo_id','_seo_categoria_proveedor','_seo_precio_proveedor','_seo_taxonomia_marca'];$quoted=array_map(static function($v)use($mysqli){return"'".mysqli_real_escape_string($mysqli,$v)."'";},$keys);$where='meta_key IN ('.implode(',',$quoted).')';}
        else{$excluded=['_edit_lock','_edit_last','_wp_page_template','_wp_old_slug','_wp_old_date','_wp_desired_post_slug','_pingme','_encloseme'];$quoted=array_map(static function($v)use($mysqli){return"'".mysqli_real_escape_string($mysqli,$v)."'";},$excluded);$where='meta_key NOT IN ('.implode(',',$quoted).") AND meta_key NOT LIKE '_wp_trash_meta_%'";}
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT meta_key,meta_value FROM `{$prefix}postmeta` WHERE post_id={$id} AND {$where} ORDER BY meta_key,meta_id");
        return array_values(array_filter($rows,static function($row){return !seo_environment_compare_is_image_meta_key($row['meta_key']??'');}));
    }
}

if ( ! function_exists( 'seo_environment_sync_apply_meta_local' ) ) {
    function seo_environment_sync_apply_meta_local( $post_id, array $rows, $product=false ) {
        $group=[];foreach($rows as $row){$key=(string)($row['meta_key']??'');if($key===''||seo_environment_compare_is_image_meta_key($key))continue;$group[$key][]=maybe_unserialize($row['meta_value']??'');}
        foreach($group as $key=>$values){delete_post_meta($post_id,$key);foreach($values as $value)add_post_meta($post_id,$key,wp_slash($value),false);}
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_relations' ) ) {
    function seo_environment_sync_fetch_relations( $mysqli, $prefix, $source_type, $source_id ) {
        $type=mysqli_real_escape_string($mysqli,$source_type);$id=absint($source_id);return seo_environment_compare_query_rows($mysqli,"SELECT source_type,source_id,target_type,target_id,relation_type,created_at FROM `{$prefix}seo_relations` WHERE source_type='{$type}' AND source_id={$id} ORDER BY relation_type,target_type,target_id");
    }
}

if ( ! function_exists( 'seo_environment_sync_replace_relations_local' ) ) {
    function seo_environment_sync_replace_relations_local( $source_type, $source_id, array $rows ) {
        global $wpdb;$table=$wpdb->prefix.'seo_relations';$wpdb->delete($table,['source_type'=>$source_type,'source_id'=>absint($source_id)],['%s','%d']);
        foreach($rows as $row){$wpdb->insert($table,['source_type'=>$source_type,'source_id'=>absint($source_id),'target_type'=>sanitize_key($row['target_type']??''),'target_id'=>absint($row['target_id']??0),'relation_type'=>sanitize_key($row['relation_type']??''),'created_at'=>sanitize_text_field($row['created_at']??current_time('mysql'))],['%s','%d','%s','%d','%s','%s']);}
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_nodes' ) ) {
    function seo_environment_sync_fetch_nodes( $mysqli, $prefix, $object_type, $object_id ) {
        $type=mysqli_real_escape_string($mysqli,$object_type);$id=absint($object_id);return seo_environment_compare_query_rows($mysqli,"SELECT seo_role,keywords,title,status,created_at,updated_at FROM `{$prefix}seo_nodes` WHERE object_type='{$type}' AND object_id={$id} ORDER BY seo_role,id");
    }
}

if ( ! function_exists( 'seo_environment_sync_replace_nodes_local' ) ) {
    function seo_environment_sync_replace_nodes_local( $object_type, $object_id, array $rows ) {
        global $wpdb;$table=$wpdb->prefix.'seo_nodes';$roles=[];
        foreach($rows as $row){$role=sanitize_key($row['seo_role']??'');if(!$role)continue;$roles[]=$role;$wpdb->query($wpdb->prepare("INSERT INTO {$table} (object_type,object_id,seo_role,keywords,title,status,created_at,updated_at) VALUES (%s,%d,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE keywords=VALUES(keywords),title=VALUES(title),status=VALUES(status),updated_at=VALUES(updated_at)",$object_type,absint($object_id),$role,(string)($row['keywords']??''),(string)($row['title']??''),absint($row['status']??1),(string)($row['created_at']??current_time('mysql')),(string)($row['updated_at']??current_time('mysql'))));}
        if($roles){$placeholders=implode(',',array_fill(0,count($roles),'%s'));$args=array_merge([$object_type,absint($object_id)],$roles);$sql=$wpdb->prepare("DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND seo_role NOT IN ({$placeholders})",$args);$wpdb->query($sql);}else{$wpdb->delete($table,['object_type'=>$object_type,'object_id'=>absint($object_id)],['%s','%d']);}
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_selected_nodes' ) ) {
    function seo_environment_sync_fetch_selected_nodes( $mysqli, $prefix, $object_type, $object_id, array $roles = [], $labels_only = false ) {
        $type = mysqli_real_escape_string( $mysqli, $object_type );
        $id   = absint( $object_id );
        $where = '';
        if ( $roles ) {
            $quoted = array_map(
                static function ( $v ) use ( $mysqli ) { return "'" . mysqli_real_escape_string( $mysqli, sanitize_key( $v ) ) . "'"; },
                $roles
            );
            $where = ' AND seo_role IN (' . implode( ',', $quoted ) . ')';
        } elseif ( $labels_only ) {
            $where = " AND status=1 AND seo_role NOT IN ('excerpt','description','ambito')";
        }
        return seo_environment_compare_query_rows(
            $mysqli,
            "SELECT seo_role,keywords,title,status,created_at,updated_at
             FROM `{$prefix}seo_nodes`
             WHERE object_type='{$type}' AND object_id={$id}{$where}
             ORDER BY seo_role,id"
        );
    }
}

if ( ! function_exists( 'seo_environment_sync_replace_selected_nodes_local' ) ) {
    function seo_environment_sync_replace_selected_nodes_local( $object_type, $object_id, array $rows, array $roles = [], $labels_only = false ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_nodes';
        $object_type = sanitize_key( $object_type );
        $object_id   = absint( $object_id );

        if ( $roles ) {
            $quoted = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
            $args   = array_merge( [ $object_type, $object_id ], array_map( 'sanitize_key', $roles ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND seo_role IN ({$quoted})", $args ) );
        } elseif ( $labels_only ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND seo_role NOT IN ('excerpt','description','ambito')",
                    $object_type,
                    $object_id
                )
            );
        }

        foreach ( $rows as $row ) {
            $role = sanitize_key( $row['seo_role'] ?? '' );
            if ( ! $role ) continue;
            $wpdb->insert(
                $table,
                [
                    'object_type' => $object_type,
                    'object_id'   => $object_id,
                    'seo_role'    => $role,
                    'keywords'    => (string) ( $row['keywords'] ?? '' ),
                    'title'       => (string) ( $row['title'] ?? '' ),
                    'status'      => absint( $row['status'] ?? 1 ),
                    'created_at'  => (string) ( $row['created_at'] ?? current_time( 'mysql' ) ),
                    'updated_at'  => (string) ( $row['updated_at'] ?? current_time( 'mysql' ) ),
                ],
                [ '%s','%d','%s','%s','%s','%d','%s','%s' ]
            );
        }
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_semantic' ) ) {
    function seo_environment_sync_fetch_semantic( $mysqli, $prefix, $object_type, $object_id ) {
        $type=mysqli_real_escape_string($mysqli,$object_type);$id=absint($object_id);
        return seo_environment_compare_query_rows($mysqli,"SELECT
            v.id AS source_vocabulary_id,v.semantic_group,v.slug,v.label,v.source AS vocabulary_source,v.active,v.created_at AS vocabulary_created_at,v.updated_at AS vocabulary_updated_at,
            pv.semantic_group AS parent_group,pv.slug AS parent_slug,pv.label AS parent_label,pv.source AS parent_source,pv.created_at AS parent_created_at,pv.updated_at AS parent_updated_at,
            ov.source AS assignment_source,ov.confidence,ov.created_at,ov.updated_at,
            rv.semantic_group AS role_group,rv.slug AS role_slug,rv.label AS role_label,rv.source AS role_source,rv.created_at AS role_created_at,rv.updated_at AS role_updated_at,
            m.confidence AS role_confidence,m.source AS role_map_source,m.updated_at AS role_map_updated_at
            FROM `{$prefix}seo_object_vocabulary` ov
            JOIN `{$prefix}seo_vocabulary` v ON v.id=ov.vocabulary_id
            LEFT JOIN `{$prefix}seo_vocabulary` pv ON pv.id=v.parent_id
            LEFT JOIN `{$prefix}seo_type_role_map` m ON m.type_vocabulary_id=v.id AND m.active=1 AND v.semantic_group='tipo'
            LEFT JOIN `{$prefix}seo_vocabulary` rv ON rv.id=m.role_vocabulary_id
            WHERE ov.object_type='{$type}' AND ov.object_id={$id} AND ov.status=1 AND v.active=1
            ORDER BY v.semantic_group,v.slug");
    }
}

if ( ! function_exists( 'seo_environment_sync_upsert_vocabulary_term_local' ) ) {
    function seo_environment_sync_upsert_vocabulary_term_local( array $row, $prefix_key = '' ) {
        global $wpdb;$table=$wpdb->prefix.'seo_vocabulary';
        $p=$prefix_key!==''?$prefix_key.'_':'';$group=sanitize_key($row[$p.'group']??$row[$p.'semantic_group']??'');$slug=sanitize_title($row[$p.'slug']??'');$label=sanitize_text_field($row[$p.'label']??$slug);if(!$group||!$slug)return new WP_Error('invalid_vocabulary','Vocabulario canónico incompleto.');
        if ( '' === $prefix_key ) {
            $src_updated=(string)($row['vocabulary_updated_at']??'');$src_created=(string)($row['vocabulary_created_at']??'');$src_source=sanitize_key($row['vocabulary_source']??'environment_sync')?:'environment_sync';
        } else {
            $src_updated=(string)($row[$p.'updated_at']??'');$src_created=(string)($row[$p.'created_at']??'');$src_source=sanitize_key($row[$p.'source']??'environment_sync')?:'environment_sync';
        }
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,label,active FROM {$table} WHERE semantic_group=%s AND slug=%s LIMIT 1",$group,$slug),ARRAY_A);
        if($existing){
            $id=absint($existing['id']);
            if(!(int)$existing['active'])return new WP_Error('vocabulary_inactive_local','El vocabulario '.$group.':'.$slug.' ya existe pero está inactivo en el destino. Revísalo manualmente; las fechas no se usan para reactivarlo.');
            return $id;
        }
        $data=['semantic_group'=>$group,'slug'=>$slug,'label'=>$label,'source'=>$src_source,'active'=>1];$formats=['%s','%s','%s','%s','%d'];
        if($src_created){$data['created_at']=$src_created;$formats[]='%s';}if($src_updated){$data['updated_at']=$src_updated;$formats[]='%s';}
        $ok=$wpdb->insert($table,$data,$formats);if(!$ok)return new WP_Error('vocabulary_insert',$wpdb->last_error?:'No se pudo crear vocabulario en el destino.');return absint($wpdb->insert_id);
    }
}

if ( ! function_exists( 'seo_environment_sync_ensure_semantic_local' ) ) {
    function seo_environment_sync_ensure_semantic_local( array $rows ) {
        global $wpdb;$groups=[];$missing=[];
        foreach($rows as $row){
            $parent_id=0;if(!empty($row['parent_group'])&&!empty($row['parent_slug'])){$parent=['parent_group'=>$row['parent_group'],'parent_slug'=>$row['parent_slug'],'parent_label'=>$row['parent_label']??$row['parent_slug'],'parent_source'=>$row['parent_source']??'environment_sync','parent_created_at'=>$row['parent_created_at']??'','parent_updated_at'=>$row['parent_updated_at']??''];$parent_id=seo_environment_sync_upsert_vocabulary_term_local($parent,'parent');if(is_wp_error($parent_id))return$parent_id;}
            $id=seo_environment_sync_upsert_vocabulary_term_local($row);if(is_wp_error($id))return$id;
            if($parent_id)$wpdb->update($wpdb->prefix.'seo_vocabulary',['parent_id'=>$parent_id],['id'=>$id],['%d'],['%d']);
            $group=sanitize_key($row['semantic_group']??'');if($group)$groups[$group][]=absint($id);
            if('tipo'===$group&&!empty($row['role_slug'])){
                $role=['role_group'=>$row['role_group']?:'rol','role_slug'=>$row['role_slug'],'role_label'=>$row['role_label']??$row['role_slug'],'role_source'=>$row['role_source']??'environment_sync','role_created_at'=>$row['role_created_at']??'','role_updated_at'=>$row['role_updated_at']??''];
                $role_id=seo_environment_sync_upsert_vocabulary_term_local($role,'role');if(is_wp_error($role_id))return$role_id;
                $map=$wpdb->prefix.'seo_type_role_map';$existing=$wpdb->get_row($wpdb->prepare("SELECT id,role_vocabulary_id,active FROM {$map} WHERE type_vocabulary_id=%d LIMIT 1",$id),ARRAY_A);
                if(!$existing){$wpdb->insert($map,['type_vocabulary_id'=>$id,'role_vocabulary_id'=>$role_id,'confidence'=>(float)($row['role_confidence']??1),'source'=>sanitize_key($row['role_map_source']??'environment_sync')?:'environment_sync','active'=>1],['%d','%d','%f','%s','%d']);}
                elseif(!(int)$existing['active']||absint($existing['role_vocabulary_id'])!==absint($role_id)){return new WP_Error('role_map_conflict','El mapeo maestro TIPO → ROL ya existe con otro estado o valor en el destino. Revísalo manualmente; no se decide por fecha.');}
            }
        }
        foreach($groups as &$ids)$ids=array_values(array_unique(array_map('absint',$ids)));unset($ids);return['groups'=>$groups,'missing'=>$missing];
    }
}

if ( ! function_exists( 'seo_environment_sync_restore_semantic_dates_local' ) ) {
    function seo_environment_sync_restore_semantic_dates_local( $object_type, $object_id, array $rows ) {
        global $wpdb;
        $object_type = sanitize_key( $object_type );
        $object_id   = absint( $object_id );
        foreach ( $rows as $row ) {
            $group = sanitize_key( $row['semantic_group'] ?? '' );
            $slug  = sanitize_title( $row['slug'] ?? '' );
            $date  = trim( (string) ( $row['updated_at'] ?? '' ) );
            if ( ! $group || ! $slug || ! $date ) continue;
            $vocabulary_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}seo_vocabulary WHERE semantic_group=%s AND slug=%s AND active=1 LIMIT 1",
                    $group,
                    $slug
                )
            );
            if ( ! $vocabulary_id ) continue;
            $wpdb->update(
                $wpdb->prefix . 'seo_object_vocabulary',
                [ 'updated_at' => $date ],
                [ 'object_type' => $object_type, 'object_id' => $object_id, 'vocabulary_id' => absint( $vocabulary_id ) ],
                [ '%s' ],
                [ '%s', '%d', '%d' ]
            );
        }
    }
}

if ( ! function_exists( 'seo_environment_sync_fetch_product_attributes' ) ) {
    function seo_environment_sync_fetch_product_attributes( $mysqli, $prefix, $product_id ) {
        $id=absint($product_id);return seo_environment_compare_query_rows($mysqli,"SELECT
            a.slug AS attribute_type,a.nombre AS attribute_name,a.grupo AS attribute_group,a.tipo AS attribute_data_type,a.unidad_tipo,a.unidad_base,a.multiple,a.filtrable,a.visible,a.seo,a.orden AS attribute_order,a.activo AS attribute_active,a.created_at AS attribute_created_at,a.updated_at AS attribute_updated_at,
            t.slug AS term_slug,t.nombre AS term_name,t.orden AS term_order,t.activo AS term_active,
            pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original,pa.orden
            FROM `{$prefix}sql_product_atributos` pa JOIN `{$prefix}sql_atributos` a ON a.id=pa.atributo_id LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=pa.termino_id WHERE pa.product_id={$id} ORDER BY a.slug,pa.orden,pa.id");
    }
}

if ( ! function_exists( 'seo_environment_sync_ensure_attribute_masters_local' ) ) {
    function seo_environment_sync_ensure_attribute_masters_local( array $rows ) {
        global $wpdb;$defs=$wpdb->prefix.'sql_atributos';$terms=$wpdb->prefix.'sql_atributos_terminos';$cache=[];
        foreach($rows as $row){
            $slug=sanitize_key($row['attribute_type']??'');if(!$slug)continue;
            if(!isset($cache[$slug])){
                $existing=$wpdb->get_row($wpdb->prepare("SELECT id,activo FROM {$defs} WHERE slug=%s LIMIT 1",$slug),ARRAY_A);
                $src_updated=(string)($row['attribute_updated_at']??'');
                $definition=['nombre'=>sanitize_text_field($row['attribute_name']??$slug),'grupo'=>sanitize_text_field($row['attribute_group']??''),'tipo'=>sanitize_key($row['attribute_data_type']??'texto')?:'texto','unidad_tipo'=>sanitize_text_field($row['unidad_tipo']??''),'unidad_base'=>sanitize_text_field($row['unidad_base']??''),'multiple'=>absint($row['multiple']??0),'filtrable'=>absint($row['filtrable']??0),'visible'=>absint($row['visible']??1),'seo'=>absint($row['seo']??1),'orden'=>(int)($row['attribute_order']??0),'activo'=>absint($row['attribute_active']??1)];
                if(!$existing){
                    $data=array_merge(['slug'=>$slug],$definition);$formats=['%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%d','%d'];
                    if(!empty($row['attribute_created_at'])){$data['created_at']=(string)$row['attribute_created_at'];$formats[]='%s';}if($src_updated){$data['updated_at']=$src_updated;$formats[]='%s';}
                    $ok=$wpdb->insert($defs,$data,$formats);if(!$ok)return new WP_Error('attribute_definition_insert',$wpdb->last_error?:'No se pudo crear la definición de atributo '.$slug.'.');$id=$wpdb->insert_id;
                } else {
                    $id=absint($existing['id']);
                    if(!(int)$existing['activo'] && absint($row['attribute_active']??1))return new WP_Error('attribute_definition_inactive_local','El atributo maestro '.$slug.' ya existe pero está inactivo en el destino. Revísalo manualmente; las fechas no se usan para reactivarlo.');
                    // Si el maestro ya existe y está activo se reutiliza. Esta capa
                    // sincroniza valores de producto, no decide cambios globales del maestro.
                }
                $cache[$slug]=absint($id);
            }
            if(!empty($row['term_slug'])){
                $term_slug=sanitize_title($row['term_slug']);$attribute_id=$cache[$slug];$existing_term=$wpdb->get_row($wpdb->prepare("SELECT id,activo FROM {$terms} WHERE atributo_id=%d AND slug=%s LIMIT 1",$attribute_id,$term_slug),ARRAY_A);
                if(!$existing_term){$ok=$wpdb->insert($terms,['atributo_id'=>$attribute_id,'slug'=>$term_slug,'nombre'=>sanitize_text_field($row['term_name']??$term_slug),'orden'=>(int)($row['term_order']??0),'activo'=>absint($row['term_active']??1)],['%d','%s','%s','%d','%d']);if(!$ok)return new WP_Error('attribute_term_insert',$wpdb->last_error?:'No se pudo crear el término '.$slug.':'.$term_slug.'.');}
                elseif(!(int)$existing_term['activo'] && absint($row['term_active']??1)){return new WP_Error('attribute_term_inactive','El término maestro '.$slug.':'.$term_slug.' está inactivo en el destino; no se reactiva automáticamente porque esa tabla no dispone de fecha de modificación fiable.');}
            }
        }return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_attribute_rows_local' ) ) {
    function seo_environment_sync_attribute_rows_local( array $rows ) {
        $out=[];foreach($rows as $row){$value='';if(!empty($row['term_name']))$value=(string)$row['term_name'];elseif(trim((string)($row['valor_original']??''))!=='')$value=(string)$row['valor_original'];elseif(''!==(string)($row['valor_numero']??'')){$value=(string)$row['valor_numero'];if(''!==(string)($row['valor_numero_max']??''))$value.=' - '.(string)$row['valor_numero_max'];if(!empty($row['unidad']))$value.=' '.(string)$row['unidad'];}else$value=(string)($row['valor_texto']??'');if(trim($value)!=='')$out[]=['attribute_type'=>(string)$row['attribute_type'],'attribute_value'=>$value];}return$out;
    }
}

if ( ! function_exists( 'seo_environment_sync_resolve_or_create_terms_local' ) ) {
    function seo_environment_sync_resolve_or_create_terms_local( array $rows, array $creatable_taxonomies = [] ) {
        $resolved=seo_environment_sync_resolve_terms_local($rows);if(!$resolved['missing'])return$resolved;
        $creatable_taxonomies=array_map('sanitize_key',$creatable_taxonomies);$missing=[];
        foreach($rows as $row){$tax=sanitize_key($row['taxonomy']??'');$slug=sanitize_title($row['slug']??'');$name=sanitize_text_field($row['name']??$slug);if(!$tax||!$slug||!in_array($tax,$creatable_taxonomies,true))continue;$term=get_term_by('slug',$slug,$tax);if(!$term){$created=wp_insert_term($name,$tax,['slug'=>$slug]);if(is_wp_error($created))return['terms'=>$resolved['terms'],'missing'=>[$tax.':'.$slug.' ('.$created->get_error_message().')']];}}
        return seo_environment_sync_resolve_terms_local($rows);
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_general_post' ) ) {
    function seo_environment_sync_pull_general_post( $mysqli, $source_env, $entity, $id ) {
        $prefix=seo_environment_compare_db_prefix($source_env);$post_type=['products_general'=>'product','pages_general'=>'page','posts_general'=>'post'][$entity];$src=seo_environment_sync_fetch_post($mysqli,$prefix,$post_type,$id);if(is_wp_error($src)||!$src)return is_wp_error($src)?$src:new WP_Error('missing_source','El objeto origen ya no existe.');$local=get_post($id);if(!$local||$local->post_type!==$post_type)return new WP_Error('missing_destination','El objeto no existe en el destino. No se crean objetos desde este comparador.');
        $postarr=['ID'=>$id,'post_title'=>(string)$src['post_title'],'post_excerpt'=>(string)$src['post_excerpt'],'post_content'=>(string)$src['post_content']];$updated=wp_update_post(wp_slash($postarr),true);if(is_wp_error($updated))return$updated;
        if('products_general'===$entity){$terms=seo_environment_sync_fetch_terms($mysqli,$prefix,$id,['product_cat']);if(is_wp_error($terms))return$terms;$resolved=seo_environment_sync_resolve_terms_local($terms);if($resolved['missing'])return new WP_Error('missing_categories','Faltan categorías en el destino: '.implode(', ',array_slice($resolved['missing'],0,8)).'. Sincroniza primero las categorías.');$r=wp_set_object_terms($id,$resolved['terms']['product_cat']??[],'product_cat',false);if(is_wp_error($r))return$r;}
        if(function_exists('seo_ie_sync_restore_post_modified'))seo_ie_sync_restore_post_modified($id,['fecha_modificada'=>(string)$src['post_modified'],'fecha_modificada_gmt'=>(string)$src['post_modified_gmt']],'fecha_modificada','fecha_modificada_gmt');clean_post_cache($id);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_native_tags' ) ) {
    function seo_environment_sync_pull_native_tags( $mysqli, $source_env, $entity, $id ) {
        $prefix=seo_environment_compare_db_prefix($source_env);$map=['product_tags'=>['product','product_tag'],'page_tags'=>['page','post_tag'],'post_tags'=>['post','post_tag']];[$post_type,$taxonomy]=$map[$entity];$local=get_post($id);if(!$local||$local->post_type!==$post_type)return new WP_Error('missing_destination','El objeto no existe en el destino.');$terms=seo_environment_sync_fetch_terms($mysqli,$prefix,$id,[$taxonomy]);if(is_wp_error($terms))return$terms;$resolved=seo_environment_sync_resolve_or_create_terms_local($terms,[$taxonomy]);if($resolved['missing'])return new WP_Error('missing_terms','No se pudieron resolver términos: '.implode(', ',array_slice($resolved['missing'],0,8)));$r=wp_set_object_terms($id,$resolved['terms'][$taxonomy]??[],$taxonomy,false);if(is_wp_error($r))return$r;
        if(in_array($entity,['page_tags','post_tags'],true)){$nodes=seo_environment_sync_fetch_selected_nodes($mysqli,$prefix,$post_type,$id,[],true);if(is_wp_error($nodes))return$nodes;seo_environment_sync_replace_selected_nodes_local($post_type,$id,$nodes,[],true);}clean_post_cache($id);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_semantic' ) ) {
    function seo_environment_sync_pull_semantic( $mysqli, $source_env, $entity, $id ) {
        $prefix=seo_environment_compare_db_prefix($source_env);$object_type='product_semantic'===$entity?'product':'product_cat';if('product'===$object_type){$local=get_post($id);if(!$local||$local->post_type!=='product')return new WP_Error('missing_destination','El producto no existe en el destino.');}else{$local=get_term($id,'product_cat');if(!$local||is_wp_error($local))return new WP_Error('missing_destination','La categoría no existe en el destino.');}
        $rows=seo_environment_sync_fetch_semantic($mysqli,$prefix,$object_type,$id);if(is_wp_error($rows))return$rows;$sem=seo_environment_sync_ensure_semantic_local($rows);if(is_wp_error($sem))return$sem;
        $all=['rol'=>[],'tipo'=>[],'aplicacion'=>[],'plataforma'=>[],'subtipo'=>[]];foreach($sem['groups'] as $g=>$ids)$all[$g]=$ids;
        if('product'===$object_type){if(!function_exists('seo_catalog_apply_product_vocabulary_changes'))return new WP_Error('semantic_writer_missing','No está disponible el escritor canónico de producto.');$r=seo_catalog_apply_product_vocabulary_changes($id,$all,'environment_sync');if(empty($r['ok']))return new WP_Error('semantic_sync',(string)($r['message']??'No se pudo sincronizar la semántica.'));}
        else{if(!function_exists('seo_category_vocabulary_replace'))return new WP_Error('semantic_writer_missing','No está disponible el escritor canónico de categorías.');$r=seo_category_vocabulary_replace($id,$all,'environment_sync');if(is_wp_error($r))return$r;}
        seo_environment_sync_restore_semantic_dates_local($object_type,$id,$rows);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_product_attributes' ) ) {
    function seo_environment_sync_pull_product_attributes( $mysqli, $source_env, $id ) {
        $local=get_post($id);if(!$local||$local->post_type!=='product')return new WP_Error('missing_destination','El producto no existe en el destino.');$prefix=seo_environment_compare_db_prefix($source_env);$rows=seo_environment_sync_fetch_product_attributes($mysqli,$prefix,$id);if(is_wp_error($rows))return$rows;$masters=seo_environment_sync_ensure_attribute_masters_local($rows);if(is_wp_error($masters))return$masters;if(!function_exists('seo_attributes_replace_product'))return new WP_Error('attribute_writer_missing','No está disponible el escritor canónico de atributos.');seo_attributes_replace_product($id,seo_environment_sync_attribute_rows_local($rows),'environment_sync');if(function_exists('wc_delete_product_transients'))wc_delete_product_transients($id);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_category_general' ) ) {
    function seo_environment_sync_pull_category_general( $mysqli, $source_env, $id ) {
        $prefix=seo_environment_compare_db_prefix($source_env);$id=absint($id);$rows=seo_environment_compare_query_rows($mysqli,"SELECT t.term_id,t.name FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id={$id} LIMIT 1");if(is_wp_error($rows))return$rows;$src=$rows[0]??null;if(!$src)return new WP_Error('missing_source','La categoría origen ya no existe.');$local=get_term($id,'product_cat');if(!$local||is_wp_error($local))return new WP_Error('missing_destination','La categoría no existe en el destino. No se crean categorías desde este comparador.');$nodes=seo_environment_sync_fetch_selected_nodes($mysqli,$prefix,'category',$id,['excerpt','description'],false);if(is_wp_error($nodes))return$nodes;$r=wp_update_term($id,'product_cat',['name'=>(string)$src['name']]);if(is_wp_error($r))return$r;seo_environment_sync_replace_selected_nodes_local('category',$id,$nodes,['excerpt','description'],false);$date_rows=seo_environment_compare_query_rows($mysqli,"SELECT meta_value FROM `{$prefix}termmeta` WHERE term_id={$id} AND meta_key='_seo_sync_modified_gmt' ORDER BY meta_id DESC LIMIT 1");$date=!is_wp_error($date_rows)&&!empty($date_rows[0]['meta_value'])?(string)$date_rows[0]['meta_value']:'';if(function_exists('seo_ie_sync_restore_category_modified')&&$date)seo_ie_sync_restore_category_modified($id,['fecha_modificada_gmt'=>$date]);clean_term_cache($id,'product_cat');return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_category_tags' ) ) {
    function seo_environment_sync_pull_category_tags( $mysqli, $source_env, $id ) {
        $local=get_term($id,'product_cat');if(!$local||is_wp_error($local))return new WP_Error('missing_destination','La categoría no existe en el destino.');$prefix=seo_environment_compare_db_prefix($source_env);$nodes=seo_environment_sync_fetch_selected_nodes($mysqli,$prefix,'category',$id,['category'],false);if(is_wp_error($nodes))return$nodes;seo_environment_sync_replace_selected_nodes_local('category',$id,$nodes,['category'],false);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_faq' ) ) {
    function seo_environment_sync_pull_faq( $mysqli, $source_env, $id ) {
        global $wpdb;$prefix=seo_environment_compare_db_prefix($source_env);$id=absint($id);$rows=seo_environment_compare_query_rows($mysqli,"SELECT id,object_type,object_id,question,answer,sort_order,active,updated_at FROM `{$prefix}seo_faq` WHERE id={$id} LIMIT 1");if(is_wp_error($rows))return$rows;$src=$rows[0]??null;if(!$src)return new WP_Error('missing_source','La FAQ origen ya no existe.');$exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}seo_faq WHERE id=%d",$id));if(!$exists)return new WP_Error('missing_destination','La FAQ no existe en el destino. No se crean FAQs desde este comparador.');$ok=$wpdb->update($wpdb->prefix.'seo_faq',['object_type'=>absint($src['object_type']),'object_id'=>absint($src['object_id']),'question'=>(string)$src['question'],'answer'=>(string)$src['answer'],'sort_order'=>absint($src['sort_order']),'active'=>absint($src['active']),'updated_at'=>(string)$src['updated_at']],['id'=>$id],['%d','%d','%s','%s','%d','%d','%s'],['%d']);return false===$ok?new WP_Error('faq_update',$wpdb->last_error):true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_item' ) ) {
    function seo_environment_sync_pull_item( $source_env, $entity, $id, $mysqli=null ) {
        $own=false;if(!$mysqli){$mysqli=seo_environment_compare_open($source_env);$own=true;}if(is_wp_error($mysqli))return$mysqli;
        switch($entity){
            case 'products_general': case 'pages_general': case 'posts_general': $result=seo_environment_sync_pull_general_post($mysqli,$source_env,$entity,$id);break;
            case 'categories_general': $result=seo_environment_sync_pull_category_general($mysqli,$source_env,$id);break;
            case 'product_tags': case 'page_tags': case 'post_tags': $result=seo_environment_sync_pull_native_tags($mysqli,$source_env,$entity,$id);break;
            case 'product_semantic': case 'category_semantic': $result=seo_environment_sync_pull_semantic($mysqli,$source_env,$entity,$id);break;
            case 'product_attributes': $result=seo_environment_sync_pull_product_attributes($mysqli,$source_env,$id);break;
            case 'category_tags': $result=seo_environment_sync_pull_category_tags($mysqli,$source_env,$id);break;
            default: $result=seo_environment_sync_pull_faq($mysqli,$source_env,$id);
        }
        if($own&&$mysqli instanceof mysqli)@mysqli_close($mysqli);return$result;
    }
}

if ( ! function_exists( 'seo_environment_sync_validate_direction' ) ) {
    function seo_environment_sync_validate_direction( $source, $destination ) {
        $current=seo_environment_compare_current_env();if(!$current)return new WP_Error('unknown_local_env','No se ha podido identificar si este WordPress es PRO o STAGING.');
        if($destination!==$current)return new WP_Error('remote_write_blocked','Por seguridad solo se escribe en la BBDD local. Ejecuta esta dirección desde '.$destination.'.');
        if($source===$destination||!in_array($source,['pro','staging'],true))return new WP_Error('invalid_direction','Dirección no válida.');return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_revalidate_item' ) ) {
    function seo_environment_sync_revalidate_item( $entity, $id, $source = '' ) {
        global $wpdb;$table=seo_environment_compare_table();
        $scan=$wpdb->get_row($wpdb->prepare("SELECT status,hash_pro,hash_staging FROM {$table} WHERE entity=%s AND object_id=%d LIMIT 1",$entity,absint($id)),ARRAY_A);
        if(!$scan||'different'!==($scan['status']??''))return new WP_Error('scan_required','La fila no pertenece a un escaneo vigente de diferencias. Vuelve a escanear.');
        $pro=seo_environment_compare_open('pro');$stg=seo_environment_compare_open('staging');if(is_wp_error($pro)||is_wp_error($stg)){if($pro instanceof mysqli)@mysqli_close($pro);if($stg instanceof mysqli)@mysqli_close($stg);return is_wp_error($pro)?$pro:$stg;}
        $p=seo_environment_compare_fetch_snapshots($pro,'pro',$entity,[$id]);$s=seo_environment_compare_fetch_snapshots($stg,'staging',$entity,[$id]);@mysqli_close($pro);@mysqli_close($stg);
        if(is_wp_error($p)||is_wp_error($s))return is_wp_error($p)?$p:$s;
        if(empty($p[$id])||empty($s[$id]))return new WP_Error('missing_object','El objeto ya no existe en ambos entornos. Solo se informa; no se crea ni elimina.');
        $fresh_pro=(string)$p[$id]['hash'];$fresh_staging=(string)$s[$id]['hash'];
        if(!hash_equals((string)$scan['hash_pro'],$fresh_pro)||!hash_equals((string)$scan['hash_staging'],$fresh_staging))return new WP_Error('stale_scan','PRO o STAGING cambió después del último escaneo. No se sobrescribe nada: vuelve a escanear antes de elegir dirección.');
        if(hash_equals($fresh_pro,$fresh_staging))return new WP_Error('already_equal','El objeto ya está igual en ambos entornos.');
        return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_item_ajax' ) ) {
    function seo_environment_sync_item_ajax() {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Sin permisos.'],403);check_ajax_referer('seo_environment_compare','nonce');
        $entity=sanitize_key($_POST['entity']??'');$id=absint($_POST['object_id']??0);$source=sanitize_key($_POST['source']??'');$destination=sanitize_key($_POST['destination']??'');if(!isset(seo_environment_compare_entities()[$entity])||!$id)wp_send_json_error(['message'=>'Objeto no válido.'],400);
        $dir=seo_environment_sync_validate_direction($source,$destination);if(is_wp_error($dir))wp_send_json_error(['message'=>$dir->get_error_message()],400);$valid=seo_environment_sync_revalidate_item($entity,$id,$source);if(is_wp_error($valid))wp_send_json_error(['message'=>$valid->get_error_message()],409);
        $result=seo_environment_sync_pull_item($source,$entity,$id);if(is_wp_error($result))wp_send_json_error(['message'=>$result->get_error_message()],500);global$wpdb;$wpdb->delete(seo_environment_compare_table(),['entity'=>$entity,'object_id'=>$id],['%s','%d']);wp_send_json_success(['message'=>'Actualizado #'.$id.'. Vuelve a escanear para confirmar.']);
    }
}
add_action('wp_ajax_seo_environment_sync_item','seo_environment_sync_item_ajax');

if ( ! function_exists( 'seo_environment_sync_bulk_ajax' ) ) {
    function seo_environment_sync_bulk_ajax() {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Sin permisos.'],403);check_ajax_referer('seo_environment_compare','nonce');$entity=sanitize_key($_POST['entity']??'');$source=sanitize_key($_POST['source']??'');$destination=sanitize_key($_POST['destination']??'');if(!isset(seo_environment_compare_entities()[$entity]))wp_send_json_error(['message'=>'Entidad no válida.'],400);$dir=seo_environment_sync_validate_direction($source,$destination);if(is_wp_error($dir))wp_send_json_error(['message'=>$dir->get_error_message()],400);
        global$wpdb;$table=seo_environment_compare_table();$ids=$wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$table} WHERE entity=%s AND status='different' ORDER BY object_id ASC LIMIT 5",$entity));if(!$ids)wp_send_json_success(['done'=>true,'updated'=>0,'errors'=>[]]);$mysqli=seo_environment_compare_open($source);if(is_wp_error($mysqli))wp_send_json_error(['message'=>$mysqli->get_error_message()],500);$updated=0;$errors=[];
        foreach($ids as$id){$id=absint($id);$valid=seo_environment_sync_revalidate_item($entity,$id,$source);if(is_wp_error($valid)){$message=$valid->get_error_message();$errors[]='#'.$id.': '.$message;$wpdb->update($table,['status'=>'blocked','summary'=>'Bloqueado: '.$message],['entity'=>$entity,'object_id'=>$id],['%s','%s'],['%s','%d']);continue;}$r=seo_environment_sync_pull_item($source,$entity,$id,$mysqli);if(is_wp_error($r)){$message=$r->get_error_message();$errors[]='#'.$id.': '.$message;$wpdb->update($table,['status'=>'blocked','summary'=>'Bloqueado: '.$message],['entity'=>$entity,'object_id'=>$id],['%s','%s'],['%s','%d']);continue;}$wpdb->delete($table,['entity'=>$entity,'object_id'=>$id],['%s','%d']);$updated++;}
        @mysqli_close($mysqli);$remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s AND status='different'",$entity));$blocked=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s AND status='blocked'",$entity));wp_send_json_success(['done'=>0===$remaining,'updated'=>$updated,'remaining'=>$remaining,'blocked'=>$blocked,'errors'=>$errors]);
    }
}
add_action('wp_ajax_seo_environment_sync_bulk','seo_environment_sync_bulk_ajax');

if ( ! function_exists( 'seo_environment_compare_render' ) ) {
    function seo_environment_compare_render() {
        if(!current_user_can('manage_options'))return;seo_environment_compare_install();global$wpdb;$table=seo_environment_compare_table();$entities=seo_environment_compare_entities();$current=seo_environment_compare_current_env();$nonce=wp_create_nonce('seo_environment_compare');
        echo '<div class="seo-env-compare">';
        $json_url = wp_nonce_url( add_query_arg( [ 'action' => 'seo_environment_compare_export_json' ], admin_url( 'admin-post.php' ) ), 'seo_environment_compare_export_json' );
        echo '<div style="margin:0 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><a class="button button-primary" href="'.esc_url($json_url).'">Descargar JSON del informe</a><strong style="color:#2271b1;">Escaneo por Gestor de procesos / worker</strong></div>';
        echo '<div class="card" style="max-width:none;padding:18px;margin-bottom:18px;"><h2 style="margin-top:0;">Comparar PRO ↔ STAGING por capas</h2><p>El comparador separa los datos para que una edición nueva de clasificación nunca se pierda al actualizar contenido general.</p><p><strong>Información general:</strong> productos = título, excerpt, description y categorías asociadas; categorías = nombre, excerpt y description; páginas/posts = título, excerpt y description.</p><p><strong>Clasificación:</strong> etiquetas WordPress/WooCommerce, etiquetas SEO de categoría/página/post, vocabulario semántico canónico y atributos de producto se escanean y sincronizan en bloques independientes.</p><p><strong>Vocabulario dependiente:</strong> si una asignación semántica o atributo del origen necesita una definición que no existe en el destino, el comparador crea primero la definición necesaria por su clave canónica y después aplica la asignación. Nunca copia IDs maestros a ciegas.</p><p><strong>Imágenes excluidas:</strong> imágenes, attachment IDs, miniaturas y galerías no participan en ninguna capa.</p><p><strong>Fechas ignoradas:</strong> las fechas de modificación no participan en el resultado ni eligen dirección. Igual/Diferente depende exclusivamente del contenido relevante de la capa.</p><p><strong>Seguridad:</strong> la dirección la eliges tú. Antes de escribir se comparan de nuevo los hashes actuales con los del último escaneo; si cualquiera de los dos entornos cambió desde entonces, esa escritura se bloquea y exige reescanear.</p><p><strong>Altas nuevas:</strong> los productos/categorías/páginas/posts que existen solo en un entorno se informan, pero su creación completa se deja al importador canónico del plugin. El comparador no clona <code>postmeta</code> ni IDs internos a ciegas; una vez existe el objeto en ambos entornos, sí reconcilia sus capas y crea el vocabulario maestro necesario.</p><p>Entorno actual detectado: <strong>'.esc_html($current?strtoupper($current):'NO IDENTIFICADO').'</strong>.</p></div>';
        echo '<style>.seo-env-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:16px 0 24px}.seo-env-kpi{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px}.seo-env-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}.seo-env-green{background:#00a32a}.seo-env-yellow{background:#dba617}.seo-env-red{background:#d63638}.seo-env-gray{background:#8c8f94}.seo-env-count{font-size:24px;font-weight:650}.seo-env-section{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:16px;margin:0 0 18px}.seo-env-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.seo-env-table{width:100%;border-collapse:collapse}.seo-env-table th,.seo-env-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.seo-env-muted{color:#646970;font-size:12px}.seo-env-progress{margin-left:8px}.seo-env-group-title{grid-column:1/-1;margin:16px 0 0}</style>';
        echo '<div class="seo-env-kpis">';
        $last_group='';foreach($entities as$key=>$def){if(($def['group']??'')!==$last_group){$last_group=(string)($def['group']??'');echo '<h2 class="seo-env-group-title">'.esc_html('classification'===$last_group?'Clasificación / enriquecimiento':'Información general').'</h2>';}$s=seo_environment_compare_get_state($key);$color='gray';if('complete'===$s['status']){$color=($s['only_pro']||$s['only_staging'])?'red':($s['different']?'yellow':'green');}elseif('error'===$s['status'])$color='red';$diff=(int)$s['different']+(int)$s['only_pro']+(int)$s['only_staging'];echo '<div class="seo-env-kpi" data-kpi="'.esc_attr($key).'"><div><span class="seo-env-dot seo-env-'.esc_attr($color).'"></span><strong>'.esc_html($def['label']).'</strong></div><div class="seo-env-count">'.esc_html('complete'===$s['status']?number_format_i18n($diff):'—').'</div><div class="seo-env-muted">PRO '.number_format_i18n((int)$s['pro']).' · STAGING '.number_format_i18n((int)$s['staging']).'</div><p><button class="button seo-env-scan" data-entity="'.esc_attr($key).'">Escanear '.esc_html(strtolower($def['label'])).'</button><span class="seo-env-progress" data-progress="'.esc_attr($key).'"></span></p></div>';}
        echo '</div>';
        foreach($entities as$key=>$def){$s=seo_environment_compare_get_state($key);$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE entity=%s ORDER BY CASE status WHEN 'different' THEN 0 ELSE 1 END, object_id ASC LIMIT 100",$key),ARRAY_A);$total_diff=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s",$key));
            echo '<section class="seo-env-section"><h2 style="margin-top:0;">Diferencias de '.esc_html(strtolower($def['label'])).'</h2><div class="seo-env-actions"><button class="button button-primary seo-env-scan" data-entity="'.esc_attr($key).'">Escanear diferencias</button>';
            foreach([['pro','staging'],['staging','pro']] as$direction){[$source,$dest]=$direction;$enabled=$current===$dest&&'complete'===$s['status']&&((int)$s['different']>0);$label='Copiar diferencias '.strtoupper($source).' → '.strtoupper($dest);echo '<button class="button seo-env-bulk" data-entity="'.esc_attr($key).'" data-source="'.esc_attr($source).'" data-destination="'.esc_attr($dest).'" '.disabled(!$enabled,true,false).' title="'.esc_attr($current!==$dest?'Ejecuta esta dirección desde '.strtoupper($dest):'Copia todas las diferencias de esta capa en la dirección elegida; las fechas se ignoran').'">'.esc_html($label).'</button>';}
            echo '<span class="seo-env-progress" data-progress="'.esc_attr($key).'"></span></div>';
            if('complete'===$s['status'])echo '<p class="seo-env-muted">Iguales: '.number_format_i18n((int)$s['same']).' · Diferentes: '.number_format_i18n((int)$s['different']).' · Solo PRO: '.number_format_i18n((int)$s['only_pro']).' · Solo STAGING: '.number_format_i18n((int)$s['only_staging']).' · <strong>Fechas ignoradas</strong></p>';
            elseif('never'===$s['status'])echo '<p class="seo-env-muted">Todavía no se ha escaneado esta entidad.</p>';elseif('error'===$s['status'])echo '<p style="color:#b32d2e;">'.esc_html($s['error']).'</p>';
            if($rows){echo '<details><summary><strong>Ver diferencias detectadas ('.number_format_i18n($total_diff).')</strong></summary><div style="overflow:auto;margin-top:10px;"><table class="seo-env-table"><thead><tr><th>ID</th><th>Nombre</th><th>Resumen</th><th>Acciones</th></tr></thead><tbody>';
                foreach($rows as$row){$id=absint($row['object_id']);$name=$row['name_pro']?:$row['name_staging'];$missing='only_pro'===$row['status']||'only_staging'===$row['status'];echo '<tr><td><code>'.$id.'</code></td><td>'.esc_html($name).'</td><td>'.esc_html($row['summary']).($missing?'<br><span class="seo-env-muted">Solo se informa; no se crea ni elimina.</span>':'').'</td><td>';
                    foreach([['pro','staging'],['staging','pro']] as$direction){[$source,$dest]=$direction;$can=!$missing&&'different'===$row['status']&&$current===$dest;echo '<button class="button button-small seo-env-sync-one" data-entity="'.esc_attr($key).'" data-id="'.$id.'" data-source="'.esc_attr($source).'" data-destination="'.esc_attr($dest).'" '.disabled(!$can,true,false).'>'.esc_html(strtoupper($source).' → '.strtoupper($dest)).'</button> ';}
                    echo '</td></tr>';}
                echo '</tbody></table></div>';if($total_diff>100)echo '<p class="seo-env-muted">Se muestran las primeras 100 diferencias para mantener ligera la pantalla. El escaneo conserva el inventario completo.</p>';echo '</details>';}
            echo '</section>';}
        echo '<script>(function(){const ajax='.wp_json_encode(admin_url('admin-ajax.php')).',nonce='.wp_json_encode($nonce).';function post(data){data.nonce=nonce;return fetch(ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(data)}).then(r=>r.json());}function progress(entity,text){document.querySelectorAll("[data-progress=\""+entity+"\"]").forEach(n=>n.textContent=text||"");}async function scan(entity,reset){document.querySelectorAll(".seo-env-scan[data-entity=\""+entity+"\"]").forEach(b=>b.disabled=true);try{let r=await post({action:"seo_environment_compare_scan",entity:entity,reset:reset?1:0});if(!r.success)throw new Error((r.data&&r.data.message)||"Error al encolar el escaneo");progress(entity,"En cola del Gestor de procesos…");while(true){await new Promise(x=>setTimeout(x,3000));r=await post({action:"seo_environment_compare_scan",entity:entity,reset:0});if(!r.success)throw new Error((r.data&&r.data.message)||"Error consultando estado");const s=r.data.state||{};progress(entity,"Worker: "+(s.processed||0)+" procesados · lote "+(s.batch_size||8)+" · "+(s.last_batch_seconds||0)+" s");if(r.data.done){if(s.status==="error")throw new Error(s.error||"El worker terminó con error");break;}}progress(entity,"Escaneo completo");setTimeout(()=>location.reload(),700);}catch(e){progress(entity,e.message);document.querySelectorAll(".seo-env-scan[data-entity=\""+entity+"\"]").forEach(b=>b.disabled=false);}}document.querySelectorAll(".seo-env-scan").forEach(b=>b.addEventListener("click",e=>{e.preventDefault();scan(b.dataset.entity,true);}));document.querySelectorAll(".seo-env-sync-one").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(b.disabled)return;b.disabled=true;progress(b.dataset.entity,"Actualizando #"+b.dataset.id+"…");const r=await post({action:"seo_environment_sync_item",entity:b.dataset.entity,object_id:b.dataset.id,source:b.dataset.source,destination:b.dataset.destination});if(!r.success){progress(b.dataset.entity,(r.data&&r.data.message)||"Error");b.disabled=false;return;}progress(b.dataset.entity,"Actualizado. Verificando…");scan(b.dataset.entity,true);}));document.querySelectorAll(".seo-env-bulk").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(b.disabled)return;const msg="Vas a copiar TODAS las diferencias de esta capa "+b.dataset.source.toUpperCase()+" → "+b.dataset.destination.toUpperCase()+". Las fechas se ignoran y el contenido del origen sustituirá esta capa en el destino. ¿Continuar?";if(!window.confirm(msg))return;b.disabled=true;let total=0;progress(b.dataset.entity,"Sincronizando por lotes…");while(true){const r=await post({action:"seo_environment_sync_bulk",entity:b.dataset.entity,source:b.dataset.source,destination:b.dataset.destination});if(!r.success){progress(b.dataset.entity,(r.data&&r.data.message)||"Error");b.disabled=false;return;}total+=Number(r.data.updated||0);progress(b.dataset.entity,"Actualizados "+total+" · pendientes "+Number(r.data.remaining||0)+" · bloqueados "+Number(r.data.blocked||0));if(r.data.done)break;if(Number(r.data.updated||0)===0&&Number(r.data.remaining||0)>0){progress(b.dataset.entity,"Hay filas bloqueadas porque cambiaron después del escaneo o requieren revisión. Reescanea.");b.disabled=false;return;}await new Promise(x=>setTimeout(x,400));}scan(b.dataset.entity,true);}));})();</script>';
        echo '</div>';
    }
}
