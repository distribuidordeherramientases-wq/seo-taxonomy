<?php
/**
 * SEO System - Comparador y sincronizacion segura PRO <-> STAGING.
 *
 * Principios:
 * - no consulta las BBDD remotas al cargar la pantalla;
 * - cada entidad puede escanearse individualmente o mediante un chequeo general
 *   estrictamente secuencial, de arriba abajo;
 * - las consultas remotas son SELECT y trabajan por lotes pequenos;
 * - nunca muestra ni transporta contenidos completos durante la comparacion:
 *   descripciones/contenidos se comparan por SHA-256 calculado en MySQL;
 * - los maestros canónicos (vocabulario, etiquetas nativas y atributos) sí pueden
 *   crearse/actualizarse de forma explícita entre entornos por su clave canónica;
 * - el Comparador no implementa escrituras destructivas: SCAN/DIFF/PLAN y la
 *   verificacion posterior viven aqui; el servicio separado MirrorEngine ejecuta
 *   DRY RUN/APPLY para la copia integral;
 * - las acciones individuales quedan limitadas a maestros canonicos. Para objetos
 *   y asignaciones, MirrorEngine puede lanzarse desde PRO o STAGING y escribe
 *   exclusivamente mediante la conexion configurada de STAGING; PRO nunca se escribe;
 * - las fechas NO deciden igualdad ni direccion: el comparador trabaja por
 *   contenido y hashes; una diferencia de timestamp por si sola se ignora;
 * - las imagenes se excluyen siempre de comparacion y sincronizacion: pueden
 *   vivir en hosts externos de proveedores y no son identidad de contenido;
 * - contenido general, etiquetas, semantica y atributos se comparan como capas
 *   independientes. La interfaz operativa de sincronizacion queda fijada en PRO -> STAGING; STAGING -> PRO se muestra solo como informacion;
 * - antes de escribir se revalidan los hashes del ultimo escaneo en ambos entornos;
 *   si cualquiera cambio desde el escaneo, la escritura se bloquea y exige reescanear;
 * - las asignaciones nunca crean maestros implícitamente: si falta vocabulario,
 *   etiqueta o atributo, se bloquean y se exige sincronizar primero Maestros.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 2.4.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SEO_ENVIRONMENT_COMPARE_VERSION' ) ) {
    define( 'SEO_ENVIRONMENT_COMPARE_VERSION', '2.4.1' );
}
if ( ! defined( 'SEO_ENVIRONMENT_COMPARE_SOURCE_FILE' ) ) {
    define( 'SEO_ENVIRONMENT_COMPARE_SOURCE_FILE', 'includes/import-export/comparador/comparador.php' );
}

if ( ! function_exists( 'seo_environment_compare_entities' ) ) {
    function seo_environment_compare_entities() {
        return [
            // Capa 0: maestros/diccionarios. Se sincronizan antes de cualquier asignación.
            'vocabulary_master'    => [ 'label' => 'Maestros · Vocabulario semántico', 'group' => 'masters', 'source' => 'master' ],
            'product_tag_master'   => [ 'label' => 'Maestros · Etiquetas de producto', 'group' => 'masters', 'source' => 'master' ],
            'post_tag_master'      => [ 'label' => 'Maestros · Etiquetas de posts/páginas', 'group' => 'masters', 'source' => 'master' ],
            'attribute_master'     => [ 'label' => 'Maestros · Atributos', 'group' => 'masters', 'source' => 'master' ],
            'attribute_term_master'=> [ 'label' => 'Maestros · Términos de atributos', 'group' => 'masters', 'source' => 'master' ],
            'attribute_alias_master'=>[ 'label' => 'Maestros · Alias de atributos', 'group' => 'masters', 'source' => 'master' ],

            // Capa 1: contenido/relaciones editoriales generales.
            'products_general'   => [ 'label' => 'Productos · General',    'group' => 'general', 'source' => 'product' ],
            'categories_general' => [ 'label' => 'Categorías · General',   'group' => 'general', 'source' => 'category' ],
            'pages_general'      => [ 'label' => 'Páginas · General',      'group' => 'general', 'source' => 'page' ],
            'posts_general'      => [ 'label' => 'Posts · General',        'group' => 'general', 'source' => 'post' ],
            'faqs'               => [ 'label' => 'FAQs',                   'group' => 'general', 'source' => 'faq' ],

            // Capa 2: asignaciones. Nunca crean maestros implícitamente.
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


if ( ! function_exists( 'seo_environment_compare_is_master_entity' ) ) {
    function seo_environment_compare_is_master_entity( $entity ) {
        return in_array(
            sanitize_key( (string) $entity ),
            [ 'vocabulary_master', 'product_tag_master', 'post_tag_master', 'attribute_master', 'attribute_term_master', 'attribute_alias_master' ],
            true
        );
    }
}

if ( ! function_exists( 'seo_environment_compare_missing_status_for_source' ) ) {
    function seo_environment_compare_missing_status_for_source( $source ) {
        return 'pro' === sanitize_key( (string) $source ) ? 'only_pro' : 'only_staging';
    }
}

if ( ! function_exists( 'seo_environment_compare_syncable_statuses' ) ) {
    function seo_environment_compare_syncable_statuses( $entity, $source ) {
        $statuses = [ 'different' ];
        if ( seo_environment_compare_is_master_entity( $entity ) ) {
            if ( 'pro' === sanitize_key( (string) $source ) ) {
                // PRO es la autoridad: crear lo que falte en STAGING y borrar
                // del maestro local lo que exista solo en STAGING.
                $statuses[] = 'only_pro';
                $statuses[] = 'only_staging';
            } else {
                $statuses[] = seo_environment_compare_missing_status_for_source( $source );
            }
        }
        return array_values( array_unique( $statuses ) );
    }
}

if ( ! function_exists( 'seo_environment_compare_stable_sql_id' ) ) {
    /**
     * Devuelve un BIGINT determinista a partir de una expresión SQL canónica.
     * Se usan 60 bits del SHA-256: permite reutilizar el worker numérico actual
     * sin depender de IDs autoincrementales distintos entre PRO y STAGING.
     */
    function seo_environment_compare_stable_sql_id( $canonical_expression ) {
        return "CAST(CONV(SUBSTRING(SHA2({$canonical_expression},256),1,15),16,10) AS UNSIGNED)";
    }
}


if ( ! function_exists( 'seo_environment_compare_post_identity_parts' ) ) {
    /**
     * Identidad portable de objetos WordPress. Devuelve expresion stable_id y
     * JOIN opcional. Los IDs fisicos nunca forman parte de la identidad.
     */
    function seo_environment_compare_post_identity_parts( $prefix, $post_type, $post_alias = 'p', $meta_alias = 'ident' ) {
        $post_type = sanitize_key( (string) $post_type );
        $p = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $post_alias ) ?: 'p';
        $m = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $meta_alias ) ?: 'ident';
        if ( 'product' === $post_type ) {
            $join = " LEFT JOIN (\n"
                . " SELECT post_id,\n"
                . " MAX(CASE WHEN meta_key='_seo_catalog_uid' THEN meta_value END) catalog_uid,\n"
                . " MAX(CASE WHEN meta_key='_seo_proveedor' THEN meta_value END) provider,\n"
                . " MAX(CASE WHEN meta_key='_seo_proveedor_id_externo' THEN meta_value END) external_id,\n"
                . " MAX(CASE WHEN meta_key='_sku' THEN meta_value END) sku\n"
                . " FROM `{$prefix}postmeta`\n"
                . " WHERE meta_key IN ('_seo_catalog_uid','_seo_proveedor','_seo_proveedor_id_externo','_sku')\n"
                . " GROUP BY post_id\n"
                . " ) {$m} ON {$m}.post_id={$p}.ID ";
            $canonical = "CONCAT('product|',COALESCE("
                . "NULLIF(CONVERT({$m}.catalog_uid USING utf8mb4),_utf8mb4''),"
                . "CASE WHEN COALESCE({$m}.provider,'')<>'' AND COALESCE({$m}.external_id,'')<>'' THEN CONCAT('provider_external|',CONVERT({$m}.provider USING utf8mb4),0x1F,CONVERT({$m}.external_id USING utf8mb4)) END,"
                . "CASE WHEN COALESCE({$m}.sku,'')<>'' THEN CONCAT('sku|',CONVERT({$m}.sku USING utf8mb4)) END,"
                . "CONCAT('slug|',CONVERT({$p}.post_name USING utf8mb4))"
                . "))";
        } else {
            $join = '';
            $canonical = "CONCAT('{$post_type}|slug|',CONVERT({$p}.post_name USING utf8mb4))";
        }
        return [
            'join' => $join,
            'canonical' => $canonical,
            'stable_id' => seo_environment_compare_stable_sql_id( $canonical ),
        ];
    }
}

if ( ! function_exists( 'seo_environment_compare_category_stable_sql_id' ) ) {
    function seo_environment_compare_category_stable_sql_id( $term_alias = 't' ) {
        $t = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $term_alias ) ?: 't';
        return seo_environment_compare_stable_sql_id( "CONCAT('product_cat|',CONVERT({$t}.slug USING utf8mb4))" );
    }
}

if ( ! function_exists( 'seo_environment_compare_post_identity_map' ) ) {
    function seo_environment_compare_post_identity_map( $mysqli, $prefix, $post_type, array $stable_ids ) {
        $ids = seo_environment_compare_sql_ids( $stable_ids );
        $type = mysqli_real_escape_string( $mysqli, sanitize_key( $post_type ) );
        $parts = seo_environment_compare_post_identity_parts( $prefix, $post_type, 'p', 'ident' );
        $sid = $parts['stable_id'];
        $rows = seo_environment_compare_query_rows( $mysqli, "SELECT {$sid} AS stable_id,p.ID,p.post_title,p.post_name FROM `{$prefix}posts` p {$parts['join']} WHERE p.post_type='{$type}' AND p.post_status<>'trash' AND {$sid} IN ({$ids}) ORDER BY p.ID" );
        if ( is_wp_error( $rows ) ) return $rows;
        $by_stable = [];
        $native_to_stable = [];
        foreach ( $rows as $row ) {
            $stable = absint( $row['stable_id'] ?? 0 );
            $native = absint( $row['ID'] ?? 0 );
            if ( ! $stable || ! $native ) continue;
            if ( isset( $by_stable[ $stable ] ) && absint( $by_stable[ $stable ]['native_id'] ) !== $native ) {
                return new WP_Error( 'portable_identity_duplicate', 'Identidad portable duplicada para ' . $post_type . ' stable_id=' . $stable . '.' );
            }
            $by_stable[ $stable ] = [ 'native_id'=>$native, 'name'=>(string)($row['post_title']??''), 'slug'=>(string)($row['post_name']??'') ];
            $native_to_stable[ $native ] = $stable;
        }
        return [ 'by_stable'=>$by_stable, 'native_to_stable'=>$native_to_stable ];
    }
}

if ( ! function_exists( 'seo_environment_compare_category_identity_map' ) ) {
    function seo_environment_compare_category_identity_map( $mysqli, $prefix, array $stable_ids ) {
        $ids = seo_environment_compare_sql_ids( $stable_ids );
        $sid = seo_environment_compare_category_stable_sql_id( 't' );
        $rows = seo_environment_compare_query_rows( $mysqli, "SELECT {$sid} AS stable_id,t.term_id,t.name,t.slug,tt.term_taxonomy_id FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND {$sid} IN ({$ids}) ORDER BY t.term_id" );
        if ( is_wp_error( $rows ) ) return $rows;
        $by_stable=[];$native_to_stable=[];
        foreach($rows as $row){$stable=absint($row['stable_id']??0);$native=absint($row['term_id']??0);if(!$stable||!$native)continue;if(isset($by_stable[$stable])&&absint($by_stable[$stable]['native_id'])!==$native)return new WP_Error('portable_category_duplicate','Slug product_cat duplicado para stable_id='.$stable.'.');$by_stable[$stable]=['native_id'=>$native,'name'=>(string)($row['name']??''),'slug'=>(string)($row['slug']??''),'term_taxonomy_id'=>absint($row['term_taxonomy_id']??0)];$native_to_stable[$native]=$stable;}
        return ['by_stable'=>$by_stable,'native_to_stable'=>$native_to_stable];
    }
}

if ( ! function_exists( 'seo_environment_compare_faq_identity_union_sql' ) ) {
    /**
     * SELECT portable de FAQs. La identidad usa destino portable + pregunta;
     * el id autoincremental de seo_faq nunca decide equivalencia entre BBDD.
     */
    function seo_environment_compare_faq_identity_union_sql( $prefix ) {
        $product = seo_environment_compare_post_identity_parts( $prefix, 'product', 'p3', 'ident3' );
        $product_canonical = $product['canonical'];
        $hub_canonical = "CONCAT(CONVERT(p1.post_type USING utf8mb4),'|slug|',CONVERT(p1.post_name USING utf8mb4))";
        $cat_canonical = "CONCAT('product_cat|slug|',CONVERT(t2.slug USING utf8mb4))";

        $stable1 = seo_environment_compare_stable_sql_id( "CONCAT('faq|1|target|',{$hub_canonical},'|question|',CONVERT(f1.question USING utf8mb4))" );
        $stable2 = seo_environment_compare_stable_sql_id( "CONCAT('faq|2|target|',{$cat_canonical},'|question|',CONVERT(f2.question USING utf8mb4))" );
        $stable3 = seo_environment_compare_stable_sql_id( "CONCAT('faq|3|target|',{$product_canonical},'|question|',CONVERT(f3.question USING utf8mb4))" );

        return "SELECT {$stable1} stable_id,f1.id native_id,f1.object_type,f1.object_id,f1.question,f1.answer,f1.sort_order,f1.active,p1.post_title target_name,{$hub_canonical} target_key
                FROM `{$prefix}seo_faq` f1 JOIN `{$prefix}posts` p1 ON p1.ID=f1.object_id
                WHERE f1.object_type=1 AND p1.post_status<>'trash'
                UNION ALL
                SELECT {$stable2} stable_id,f2.id native_id,f2.object_type,f2.object_id,f2.question,f2.answer,f2.sort_order,f2.active,t2.name target_name,{$cat_canonical} target_key
                FROM `{$prefix}seo_faq` f2 JOIN `{$prefix}terms` t2 ON t2.term_id=f2.object_id JOIN `{$prefix}term_taxonomy` tt2 ON tt2.term_id=t2.term_id AND tt2.taxonomy='product_cat'
                WHERE f2.object_type=2
                UNION ALL
                SELECT {$stable3} stable_id,f3.id native_id,f3.object_type,f3.object_id,f3.question,f3.answer,f3.sort_order,f3.active,p3.post_title target_name,{$product_canonical} target_key
                FROM `{$prefix}seo_faq` f3 JOIN `{$prefix}posts` p3 ON p3.ID=f3.object_id {$product['join']}
                WHERE f3.object_type=3 AND p3.post_type='product' AND p3.post_status<>'trash'";
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
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity VARCHAR(30) NOT NULL,
                object_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL,
                newer_env VARCHAR(20) NOT NULL DEFAULT '',
                name_pro TEXT NULL,
                name_staging TEXT NULL,
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

if ( ! function_exists( 'seo_environment_compare_fresh_option' ) ) {
    /**
     * Lee una option saltándose la caché local de WordPress.
     *
     * El Gestor puede vivir horas en un proceso CLI/FPM persistente mientras el
     * administrador encola trabajo desde otra petición PHP. Sin invalidar esta
     * caché, el worker puede seguir viendo para siempre el estado anterior.
     */
    function seo_environment_compare_fresh_option( $name, $default = false ) {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( (string) $name, 'options' );
        }
        return get_option( (string) $name, $default );
    }
}

if ( ! function_exists( 'seo_environment_compare_entity_state_option' ) ) {
    function seo_environment_compare_entity_state_option( $entity ) {
        return 'seo_environment_compare_state_' . sanitize_key( (string) $entity );
    }
}

if ( ! function_exists( 'seo_environment_compare_state_option' ) ) {
    /**
     * Estado agregado legado. Solo se conserva como fuente de migración.
     * Las escrituras nuevas se realizan por entidad para evitar que dos workers
     * de capas distintas se pisen al reescribir un único array completo.
     */
    function seo_environment_compare_state_option() {
        $state = seo_environment_compare_fresh_option( 'seo_environment_compare_state', [] );
        return is_array( $state ) ? $state : [];
    }
}

if ( ! function_exists( 'seo_environment_compare_get_state' ) ) {
    function seo_environment_compare_get_state( $entity ) {
        $entity = sanitize_key( (string) $entity );
        $missing = '__seo_environment_compare_missing__';
        $option = seo_environment_compare_entity_state_option( $entity );
        $state = seo_environment_compare_fresh_option( $option, $missing );

        // Migración transparente desde la option agregada de versiones previas.
        if ( $missing === $state || ! is_array( $state ) ) {
            $legacy = seo_environment_compare_state_option();
            $state = isset( $legacy[ $entity ] ) && is_array( $legacy[ $entity ] ) ? $legacy[ $entity ] : [];
            if ( $state ) {
                if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( $option, 'options' );
                update_option( $option, $state, false );
            }
        }

        return wp_parse_args(
            is_array( $state ) ? $state : [],
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
                'batch_size'         => 1000,
                'last_batch_seconds' => 0.0,
                'worker_runs'        => 0,
                'worker_source'      => '',
                'next_run_at'        => 0,
                'last_activity_at'      => 0,
                'worker_attempts'       => 0,
                'worker_lock_misses'    => 0,
                'last_worker_attempt_at'=> 0,
                'last_worker_error'     => '',
                'last_worker_phase'     => '',
                'last_candidate_pro'    => 0,
                'last_candidate_staging'=> 0,
                'source_total_pro'      => null,
                'source_total_staging'  => null,
                'empty_verified'        => 0,
            ]
        );
    }
}

if ( ! function_exists( 'seo_environment_compare_stop_option' ) ) {
    function seo_environment_compare_stop_option( $entity ) {
        return 'seo_environment_compare_stop_' . sanitize_key( (string) $entity );
    }
}

if ( ! function_exists( 'seo_environment_compare_stop_requested' ) ) {
    /**
     * Lee la marca de parada directamente de wp_options para que un worker PHP
     * ya iniciado vea una orden enviada desde otra petición sin depender de la
     * caché de opciones de su propio proceso.
     */
    function seo_environment_compare_stop_requested( $entity ) {
        global $wpdb;
        $option = seo_environment_compare_stop_option( $entity );
        if ( '' === $option ) return false;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1",
            $option
        ) );
        return ! empty( $value );
    }
}

if ( ! function_exists( 'seo_environment_compare_apply_stopped_state' ) ) {
    function seo_environment_compare_apply_stopped_state( array $state ) {
        $now = time();
        $state['status'] = 'stopped';
        $state['error'] = '';
        $state['last_worker_error'] = '';
        $state['last_worker_phase'] = 'stopped_by_user';
        $state['last_activity_at'] = $now;
        $state['finished_at'] = $now;
        $state['next_run_at'] = 0;
        return $state;
    }
}

if ( ! function_exists( 'seo_environment_compare_set_state' ) ) {
    function seo_environment_compare_set_state( $entity, array $state ) {
        $entity = sanitize_key( (string) $entity );
        if ( '' === $entity ) return false;
        if ( seo_environment_compare_stop_requested( $entity ) ) {
            $state = seo_environment_compare_apply_stopped_state( $state );
        }
        $option = seo_environment_compare_entity_state_option( $entity );
        // update_option() consulta también la caché; la invalidamos antes para
        // no comparar contra una copia vieja retenida por un worker persistente.
        if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( $option, 'options' );
        return update_option( $option, $state, false );
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
            // de metadatos/taxonomias en objetos con muchas relaciones. Si no se
            // puede aplicar, fallamos cerrado: comparar con GROUP_CONCAT truncado
            // podria producir hashes falsos.
            if ( false === @mysqli_query( $mysqli, 'SET SESSION group_concat_max_len = 1048576' ) ) {
                $message = sanitize_text_field( mysqli_error( $mysqli ) );
                @mysqli_close( $mysqli );
                return new WP_Error(
                    'seo_env_compare_group_concat',
                    'No se pudo configurar group_concat_max_len en ' . strtoupper( (string) $env ) . ( $message ? ': ' . $message : '.' )
                );
            }
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
        $limit  = max( 1, min( 2200, absint( $limit ) ) );

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
            $post_type = sanitize_key( $post_map[ $entity ] );
            $type_sql  = mysqli_real_escape_string( $mysqli, $post_type );
            $parts     = seo_environment_compare_post_identity_parts( $prefix, $post_type, 'p', 'ident' );
            $sid       = $parts['stable_id'];
            $sql = "SELECT DISTINCT {$sid} AS ID FROM `{$prefix}posts` p {$parts['join']} WHERE p.post_type='{$type_sql}' AND p.post_status<>'trash' AND {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( in_array( $entity, [ 'categories_general', 'category_tags', 'category_semantic' ], true ) ) {
            $sid = seo_environment_compare_category_stable_sql_id( 't' );
            $sql = "SELECT DISTINCT {$sid} AS ID FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( 'vocabulary_master' === $entity ) {
            $sid = seo_environment_compare_stable_sql_id( "CONCAT(SHA2(CONVERT(semantic_group USING utf8mb4),256),SHA2(CONVERT(slug USING utf8mb4),256))" );
            $sql = "SELECT {$sid} AS ID FROM `{$prefix}seo_vocabulary` WHERE {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( in_array( $entity, [ 'product_tag_master', 'post_tag_master' ], true ) ) {
            $taxonomy = 'product_tag_master' === $entity ? 'product_tag' : 'post_tag';
            $tax = mysqli_real_escape_string( $mysqli, $taxonomy );
            $sid = seo_environment_compare_stable_sql_id( "CONVERT(t.slug USING utf8mb4)" );
            $sql = "SELECT {$sid} AS ID FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='{$tax}' AND {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( 'attribute_master' === $entity ) {
            $sid = seo_environment_compare_stable_sql_id( "CONVERT(slug USING utf8mb4)" );
            $sql = "SELECT {$sid} AS ID FROM `{$prefix}sql_atributos` WHERE {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( 'attribute_term_master' === $entity ) {
            $sid = seo_environment_compare_stable_sql_id( "CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(t.slug USING utf8mb4),256))" );
            $sql = "SELECT {$sid} AS ID FROM `{$prefix}sql_atributos_terminos` t JOIN `{$prefix}sql_atributos` a ON a.id=t.atributo_id WHERE {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( 'attribute_alias_master' === $entity ) {
            $sid = seo_environment_compare_stable_sql_id( "CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(aa.alias USING utf8mb4),256))" );
            $sql = "SELECT DISTINCT {$sid} AS ID FROM `{$prefix}sql_atributos_aliases` aa JOIN `{$prefix}sql_atributos` a ON a.id=aa.atributo_id WHERE {$sid}>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( 'faqs' === $entity ) {
            $faq_union = seo_environment_compare_faq_identity_union_sql( $prefix );
            $sql = "SELECT stable_id AS ID FROM ({$faq_union}) faq_portable WHERE stable_id>{$cursor} ORDER BY stable_id ASC LIMIT {$limit}";
        } else {
            return new WP_Error( 'seo_env_compare_unknown_entity', 'Entidad de comparación desconocida: ' . sanitize_key( $entity ) );
        }

        $rows = seo_environment_compare_query_rows( $mysqli, $sql );
        if ( is_wp_error( $rows ) ) return $rows;
        return array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'ID' ) ) ) ) );
    }
}


if ( ! function_exists( 'seo_environment_compare_entity_total' ) ) {
    function seo_environment_compare_entity_total( $mysqli, $prefix, $entity ) {
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
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}posts` WHERE post_type='{$post_type}' AND post_status<>'trash'";
        } elseif ( in_array( $entity, [ 'categories_general', 'category_tags', 'category_semantic' ], true ) ) {
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}term_taxonomy` WHERE taxonomy='product_cat'";
        } elseif ( 'vocabulary_master' === $entity ) {
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}seo_vocabulary`";
        } elseif ( in_array( $entity, [ 'product_tag_master', 'post_tag_master' ], true ) ) {
            $taxonomy = 'product_tag_master' === $entity ? 'product_tag' : 'post_tag';
            $tax = mysqli_real_escape_string( $mysqli, $taxonomy );
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}term_taxonomy` WHERE taxonomy='{$tax}'";
        } elseif ( 'attribute_master' === $entity ) {
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}sql_atributos`";
        } elseif ( 'attribute_term_master' === $entity ) {
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}sql_atributos_terminos`";
        } elseif ( 'attribute_alias_master' === $entity ) {
            $sql = "SELECT COUNT(DISTINCT CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(aa.alias USING utf8mb4),256))) AS total FROM `{$prefix}sql_atributos_aliases` aa JOIN `{$prefix}sql_atributos` a ON a.id=aa.atributo_id";
        } elseif ( 'faqs' === $entity ) {
            $sql = "SELECT COUNT(*) AS total FROM `{$prefix}seo_faq` WHERE object_type IN (1,2,3)";
        } else {
            return new WP_Error( 'seo_env_compare_unknown_entity', 'Entidad de comparación desconocida: ' . sanitize_key( $entity ) );
        }

        $rows = seo_environment_compare_query_rows( $mysqli, $sql );
        if ( is_wp_error( $rows ) ) return $rows;
        return isset( $rows[0]['total'] ) ? absint( $rows[0]['total'] ) : 0;
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
        $map = seo_environment_compare_post_identity_map( $mysqli, $prefix, $post_type, $ids );
        if ( is_wp_error( $map ) ) return $map;
        $out=[];
        foreach((array)$map['by_stable'] as $stable=>$row){$out[absint($stable)]=['id'=>absint($stable),'native_id'=>absint($row['native_id']),'name'=>(string)$row['name'],'components'=>[]];}
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_post_general_snapshots' ) ) {
    function seo_environment_compare_fetch_post_general_snapshots( $mysqli, $prefix, $post_type, array $ids, $product = false ) {
        $map=seo_environment_compare_post_identity_map($mysqli,$prefix,$post_type,$ids);if(is_wp_error($map))return$map;
        $native_ids=array_keys((array)$map['native_to_stable']);if(!$native_ids)return[];$native_sql=seo_environment_compare_sql_ids($native_ids);$type=mysqli_real_escape_string($mysqli,$post_type);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT ID,post_title,SHA2(COALESCE(post_title,''),256) base_hash,SHA2(COALESCE(post_excerpt,''),256) excerpt_hash,SHA2(COALESCE(post_content,''),256) description_hash FROM `{$prefix}posts` WHERE post_type='{$type}' AND post_status<>'trash' AND ID IN ({$native_sql})");if(is_wp_error($rows))return$rows;$out=[];
        foreach($rows as $row){$native=absint($row['ID']);$stable=absint($map['native_to_stable'][$native]??0);if(!$stable)continue;$out[$stable]=['id'=>$stable,'native_id'=>$native,'name'=>(string)$row['post_title'],'components'=>['base'=>(string)$row['base_hash'],'excerpt'=>(string)$row['excerpt_hash'],'description'=>(string)$row['description_hash']]];}
        if($product&&$out){$tax=seo_environment_compare_query_rows($mysqli,"SELECT tr.object_id,SHA2(GROUP_CONCAT(t.slug ORDER BY t.slug SEPARATOR '|'),256) row_hash FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$prefix}terms` t ON t.term_id=tt.term_id WHERE tr.object_id IN ({$native_sql}) AND tt.taxonomy='product_cat' GROUP BY tr.object_id");if(is_wp_error($tax))return$tax;foreach($tax as $row){$stable=absint($map['native_to_stable'][absint($row['object_id'])]??0);if($stable&&isset($out[$stable]))$out[$stable]['components']['categories']=(string)$row['row_hash'];}$empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['categories']))$item['components']['categories']=$empty;}unset($item);}
        foreach($out as &$item){ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_native_tag_snapshots' ) ) {
    function seo_environment_compare_fetch_native_tag_snapshots( $mysqli, $prefix, $post_type, $taxonomy, array $ids ) {
        $out=seo_environment_compare_fetch_post_base($mysqli,$prefix,$post_type,$ids);if(is_wp_error($out)||!$out)return$out;
        $native_to_stable=[];$native=[];foreach($out as $stable=>$item){$nid=absint($item['native_id']??0);if($nid){$native[]=$nid;$native_to_stable[$nid]=absint($stable);}}
        $id_sql=seo_environment_compare_sql_ids($native);$taxonomy=mysqli_real_escape_string($mysqli,$taxonomy);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT tr.object_id,SHA2(GROUP_CONCAT(t.slug ORDER BY t.slug SEPARATOR '|'),256) row_hash FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$prefix}terms` t ON t.term_id=tt.term_id WHERE tr.object_id IN ({$id_sql}) AND tt.taxonomy='{$taxonomy}' GROUP BY tr.object_id");if(is_wp_error($rows))return$rows;
        foreach($rows as $row){$stable=absint($native_to_stable[absint($row['object_id'])]??0);if($stable&&isset($out[$stable]))$out[$stable]['components']['tags']=(string)$row['row_hash'];}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['tags']))$item['components']['tags']=$empty;$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_semantic_snapshots' ) ) {
    function seo_environment_compare_fetch_semantic_snapshots( $mysqli, $prefix, $post_type, $object_type, array $ids ) {
        if('product_cat'===$object_type){$map=seo_environment_compare_category_identity_map($mysqli,$prefix,$ids);if(is_wp_error($map))return$map;$out=[];$native_to_stable=(array)$map['native_to_stable'];foreach((array)$map['by_stable'] as $stable=>$row)$out[absint($stable)]=['id'=>absint($stable),'native_id'=>absint($row['native_id']),'name'=>(string)$row['name'],'components'=>[]];}
        else{$out=seo_environment_compare_fetch_post_base($mysqli,$prefix,$post_type,$ids);if(is_wp_error($out)||!$out)return$out;$native_to_stable=[];foreach($out as $stable=>$item){$nid=absint($item['native_id']??0);if($nid)$native_to_stable[$nid]=absint($stable);}}
        if(!$out)return$out;$native_ids=array_keys($native_to_stable);$id_sql=seo_environment_compare_sql_ids($native_ids);$obj=mysqli_real_escape_string($mysqli,$object_type);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT ov.object_id,v.semantic_group,SHA2(GROUP_CONCAT(v.slug ORDER BY v.slug SEPARATOR '|'),256) row_hash FROM `{$prefix}seo_object_vocabulary` ov JOIN `{$prefix}seo_vocabulary` v ON v.id=ov.vocabulary_id WHERE ov.object_type='{$obj}' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql}) GROUP BY ov.object_id,v.semantic_group");if(is_wp_error($rows))return$rows;
        foreach($rows as $row){$stable=absint($native_to_stable[absint($row['object_id'])]??0);$group=sanitize_key($row['semantic_group']??'');if($stable&&isset($out[$stable])&&$group)$out[$stable]['components']['semantic_'.$group]=(string)$row['row_hash'];}
        foreach($out as &$item){ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_product_attribute_snapshots' ) ) {
    function seo_environment_compare_fetch_product_attribute_snapshots( $mysqli, $prefix, array $ids ) {
        $out=seo_environment_compare_fetch_post_base($mysqli,$prefix,'product',$ids);if(is_wp_error($out)||!$out)return$out;$native_to_stable=[];foreach($out as $stable=>$item){$nid=absint($item['native_id']??0);if($nid)$native_to_stable[$nid]=absint($stable);}$id_sql=seo_environment_compare_sql_ids(array_keys($native_to_stable));
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT pa.product_id,SHA2(GROUP_CONCAT(SHA2(CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(COALESCE(CONVERT(t.slug USING utf8mb4),_utf8mb4''),256),SHA2(COALESCE(CONVERT(pa.valor_texto USING utf8mb4),_utf8mb4''),256),SHA2(COALESCE(CONVERT(CAST(pa.valor_numero AS CHAR) USING utf8mb4),_utf8mb4''),256),SHA2(COALESCE(CONVERT(CAST(pa.valor_numero_max AS CHAR) USING utf8mb4),_utf8mb4''),256),SHA2(COALESCE(CONVERT(pa.unidad USING utf8mb4),_utf8mb4''),256),SHA2(COALESCE(CONVERT(pa.valor_original USING utf8mb4),_utf8mb4''),256),SHA2(CONVERT(CAST(pa.orden AS CHAR) USING utf8mb4),256)),256) ORDER BY a.slug,pa.orden,pa.id SEPARATOR ''),256) row_hash FROM `{$prefix}sql_product_atributos` pa JOIN `{$prefix}sql_atributos` a ON a.id=pa.atributo_id LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=pa.termino_id WHERE pa.product_id IN ({$id_sql}) GROUP BY pa.product_id");if(is_wp_error($rows))return$rows;
        foreach($rows as $row){$stable=absint($native_to_stable[absint($row['product_id'])]??0);if($stable&&isset($out[$stable]))$out[$stable]['components']['attributes']=(string)$row['row_hash'];}$empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['attributes']))$item['components']['attributes']=$empty;$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_category_general_snapshots' ) ) {
    function seo_environment_compare_fetch_category_general_snapshots( $mysqli, $prefix, array $ids ) {
        $map=seo_environment_compare_category_identity_map($mysqli,$prefix,$ids);if(is_wp_error($map))return$map;$out=[];$native_to_stable=(array)$map['native_to_stable'];$native_ids=array_keys($native_to_stable);if(!$native_ids)return[];$id_sql=seo_environment_compare_sql_ids($native_ids);
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT t.term_id,t.name,SHA2(COALESCE(t.name,''),256) base_hash FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id IN ({$id_sql})");if(is_wp_error($rows))return$rows;
        foreach($rows as $row){$stable=absint($native_to_stable[absint($row['term_id'])]??0);if($stable)$out[$stable]=['id'=>$stable,'native_id'=>absint($row['term_id']),'name'=>(string)$row['name'],'components'=>['base'=>(string)$row['base_hash']]];}
        $nodes=seo_environment_compare_query_rows($mysqli,"SELECT object_id,SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='excerpt' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) excerpt_hash,SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='description' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) description_hash FROM `{$prefix}seo_nodes` WHERE object_type='category' AND object_id IN ({$id_sql}) AND seo_role IN ('excerpt','description') GROUP BY object_id");if(is_wp_error($nodes))return$nodes;
        foreach($nodes as $row){$stable=absint($native_to_stable[absint($row['object_id'])]??0);if($stable&&isset($out[$stable])){$out[$stable]['components']['excerpt']=(string)$row['excerpt_hash'];$out[$stable]['components']['description']=(string)$row['description_hash'];}}
        $empty=seo_environment_compare_empty_hash();foreach($out as &$item){foreach(['excerpt','description'] as $c)if(!isset($item['components'][$c]))$item['components'][$c]=$empty;ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_category_tag_snapshots' ) ) {
    function seo_environment_compare_fetch_category_tag_snapshots( $mysqli, $prefix, array $ids ) {
        $map=seo_environment_compare_category_identity_map($mysqli,$prefix,$ids);if(is_wp_error($map))return$map;$out=[];$native_to_stable=(array)$map['native_to_stable'];foreach((array)$map['by_stable'] as $stable=>$row)$out[absint($stable)]=['id'=>absint($stable),'native_id'=>absint($row['native_id']),'name'=>(string)$row['name'],'components'=>[]];if(!$out)return$out;$id_sql=seo_environment_compare_sql_ids(array_keys($native_to_stable));
        $nodes=seo_environment_compare_query_rows($mysqli,"SELECT object_id,SHA2(COALESCE(GROUP_CONCAT(CASE WHEN status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) row_hash FROM `{$prefix}seo_nodes` WHERE object_type='category' AND seo_role='category' AND object_id IN ({$id_sql}) GROUP BY object_id");if(is_wp_error($nodes))return$nodes;
        foreach($nodes as $row){$stable=absint($native_to_stable[absint($row['object_id'])]??0);if($stable&&isset($out[$stable]))$out[$stable]['components']['tags']=(string)$row['row_hash'];}$empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['tags']))$item['components']['tags']=$empty;$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_post_custom_tag_snapshots' ) ) {
    function seo_environment_compare_fetch_post_custom_tag_snapshots( $mysqli, $prefix, $post_type, array $ids ) {
        $out=seo_environment_compare_fetch_native_tag_snapshots($mysqli,$prefix,$post_type,'post_tag',$ids);if(is_wp_error($out)||!$out)return$out;$native_to_stable=[];foreach($out as $stable=>$item){$nid=absint($item['native_id']??0);if($nid)$native_to_stable[$nid]=absint($stable);}$id_sql=seo_environment_compare_sql_ids(array_keys($native_to_stable));$type=mysqli_real_escape_string($mysqli,$post_type);
        $nodes=seo_environment_compare_query_rows($mysqli,"SELECT object_id,SHA2(GROUP_CONCAT(SHA2(CONCAT(CAST(CONVERT(seo_role USING utf8mb4) AS BINARY),0x1F,CAST(COALESCE(CONVERT(keywords USING utf8mb4),_utf8mb4'') AS BINARY)),256) ORDER BY CONVERT(seo_role USING utf8mb4),id SEPARATOR ''),256) row_hash FROM `{$prefix}seo_nodes` WHERE object_type='{$type}' AND object_id IN ({$id_sql}) AND status=1 AND seo_role NOT IN ('excerpt','description','ambito') GROUP BY object_id");if(is_wp_error($nodes))return$nodes;
        foreach($nodes as $row){$stable=absint($native_to_stable[absint($row['object_id'])]??0);if($stable&&isset($out[$stable]))$out[$stable]['components']['seo_tags']=(string)$row['row_hash'];}$empty=seo_environment_compare_empty_hash();foreach($out as &$item){if(!isset($item['components']['seo_tags']))$item['components']['seo_tags']=$empty;ksort($item['components']);$item['hash']=seo_environment_compare_hash($item['components']);}unset($item);return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_faq_snapshots' ) ) {
    function seo_environment_compare_fetch_faq_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );
        $union  = seo_environment_compare_faq_identity_union_sql( $prefix );
        $rows   = seo_environment_compare_query_rows( $mysqli, "SELECT * FROM ({$union}) faq_portable WHERE stable_id IN ({$id_sql}) ORDER BY stable_id,native_id" );
        if ( is_wp_error( $rows ) ) return $rows;
        $out = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['stable_id'] ?? 0 );
            if ( ! $id ) continue;
            if ( isset( $out[ $id ] ) ) {
                return new WP_Error( 'portable_faq_duplicate', 'FAQ portable duplicada para stable_id=' . $id . '. Revisa preguntas duplicadas sobre el mismo objeto.' );
            }
            $components = [
                'content'  => hash( 'sha256', (string)($row['question']??'') . chr(31) . (string)($row['answer']??'') ),
                'settings' => hash( 'sha256', (string)($row['sort_order']??'') . chr(31) . (string)($row['active']??'') ),
                'target'   => hash( 'sha256', (string)($row['target_key']??'') ),
            ];
            $question = trim( (string) ( $row['question'] ?? '' ) );
            $out[ $id ] = [
                'id'        => $id,
                'native_id' => absint( $row['native_id'] ?? 0 ),
                'name'      => 'FAQ · ' . (string)($row['target_name']??'objeto') . ' · ' . wp_trim_words( $question, 10, '…' ),
                'components'=> $components,
                'hash'      => seo_environment_compare_hash( $components ),
            ];
        }
        return $out;
    }
}


if ( ! function_exists( 'seo_environment_compare_fetch_vocabulary_master_snapshots' ) ) {
    function seo_environment_compare_fetch_vocabulary_master_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );
        $sid = seo_environment_compare_stable_sql_id( "CONCAT(SHA2(CONVERT(v.semantic_group USING utf8mb4),256),SHA2(CONVERT(v.slug USING utf8mb4),256))" );
        $rows = seo_environment_compare_query_rows( $mysqli, "SELECT {$sid} AS stable_id,v.id AS native_id,v.semantic_group,v.slug,v.label,v.source,v.active,pv.semantic_group AS parent_group,pv.slug AS parent_slug,rv.semantic_group AS role_group,rv.slug AS role_slug,m.active AS map_active,m.confidence AS map_confidence,m.source AS map_source FROM `{$prefix}seo_vocabulary` v LEFT JOIN `{$prefix}seo_vocabulary` pv ON pv.id=v.parent_id LEFT JOIN `{$prefix}seo_type_role_map` m ON m.type_vocabulary_id=v.id LEFT JOIN `{$prefix}seo_vocabulary` rv ON rv.id=m.role_vocabulary_id WHERE {$sid} IN ({$id_sql})" );
        if ( is_wp_error( $rows ) ) return $rows;
        $out = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['stable_id'] );
            $components = [
                'label' => hash( 'sha256', (string) $row['label'] ),
                'source' => hash( 'sha256', (string) $row['source'] ),
                'active' => hash( 'sha256', (string) absint( $row['active'] ) ),
                'parent' => hash( 'sha256', (string) ( $row['parent_group'] ?? '' ) . '|' . (string) ( $row['parent_slug'] ?? '' ) ),
                'role_map' => hash( 'sha256', implode( '|', [ (string) ( $row['role_group'] ?? '' ), (string) ( $row['role_slug'] ?? '' ), (string) ( $row['map_active'] ?? '' ), (string) ( $row['map_confidence'] ?? '' ), (string) ( $row['map_source'] ?? '' ) ] ) ),
            ];
            $out[ $id ] = [ 'id'=>$id, 'native_id'=>absint($row['native_id']), 'name'=>(string)$row['semantic_group'].' · '.(string)$row['label'].' ['.(string)$row['slug'].']', 'components'=>$components, 'hash'=>seo_environment_compare_hash($components) ];
        }
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_taxonomy_master_snapshots' ) ) {
    function seo_environment_compare_fetch_taxonomy_master_snapshots( $mysqli, $prefix, $taxonomy, array $ids ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );
        $tax = mysqli_real_escape_string( $mysqli, sanitize_key( $taxonomy ) );
        $sid = seo_environment_compare_stable_sql_id( "CONVERT(t.slug USING utf8mb4)" );
        $rows = seo_environment_compare_query_rows( $mysqli, "SELECT {$sid} AS stable_id,t.term_id AS native_id,t.name,t.slug,tt.description FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='{$tax}' AND {$sid} IN ({$id_sql})" );
        if ( is_wp_error( $rows ) ) return $rows;
        $out=[];
        foreach($rows as $row){$id=absint($row['stable_id']);$components=['name'=>hash('sha256',(string)$row['name']),'description'=>hash('sha256',(string)$row['description'])];$out[$id]=['id'=>$id,'native_id'=>absint($row['native_id']),'name'=>(string)$row['name'].' ['.(string)$row['slug'].']','components'=>$components,'hash'=>seo_environment_compare_hash($components)];}
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_attribute_master_snapshots' ) ) {
    function seo_environment_compare_fetch_attribute_master_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);$sid=seo_environment_compare_stable_sql_id("CONVERT(a.slug USING utf8mb4)");
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,a.* FROM `{$prefix}sql_atributos` a WHERE {$sid} IN ({$id_sql})");if(is_wp_error($rows))return$rows;$out=[];
        foreach($rows as $row){$id=absint($row['stable_id']);$parts=[];foreach(['nombre','grupo','tipo','unidad_tipo','unidad_base','multiple','filtrable','visible','seo','orden','activo'] as $k)$parts[$k]=hash('sha256',(string)($row[$k]??''));$out[$id]=['id'=>$id,'native_id'=>absint($row['id']??0),'name'=>(string)($row['nombre']??$row['slug']).' ['.(string)$row['slug'].']','components'=>$parts,'hash'=>seo_environment_compare_hash($parts)];}return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_attribute_term_master_snapshots' ) ) {
    function seo_environment_compare_fetch_attribute_term_master_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);$sid=seo_environment_compare_stable_sql_id("CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(t.slug USING utf8mb4),256))");
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,t.id AS native_id,a.slug AS attribute_slug,t.slug,t.nombre,t.orden,t.activo FROM `{$prefix}sql_atributos_terminos` t JOIN `{$prefix}sql_atributos` a ON a.id=t.atributo_id WHERE {$sid} IN ({$id_sql})");if(is_wp_error($rows))return$rows;$out=[];
        foreach($rows as $row){$id=absint($row['stable_id']);$parts=['name'=>hash('sha256',(string)$row['nombre']),'order'=>hash('sha256',(string)$row['orden']),'active'=>hash('sha256',(string)$row['activo'])];$out[$id]=['id'=>$id,'native_id'=>absint($row['native_id']),'name'=>(string)$row['attribute_slug'].' → '.(string)$row['nombre'].' ['.(string)$row['slug'].']','components'=>$parts,'hash'=>seo_environment_compare_hash($parts)];}return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_attribute_alias_master_snapshots' ) ) {
    function seo_environment_compare_fetch_attribute_alias_master_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);$sid=seo_environment_compare_stable_sql_id("CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(aa.alias USING utf8mb4),256))");
        $rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,MIN(aa.id) AS native_id,a.slug AS attribute_slug,aa.alias,GROUP_CONCAT(DISTINCT COALESCE(CONVERT(t.slug USING utf8mb4),_utf8mb4'') ORDER BY t.slug SEPARATOR '|') AS term_slugs FROM `{$prefix}sql_atributos_aliases` aa JOIN `{$prefix}sql_atributos` a ON a.id=aa.atributo_id LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=aa.termino_id WHERE {$sid} IN ({$id_sql}) GROUP BY stable_id,a.slug,aa.alias");if(is_wp_error($rows))return$rows;$out=[];
        foreach($rows as $row){$id=absint($row['stable_id']);$parts=['mapping'=>hash('sha256',(string)$row['term_slugs'])];$out[$id]=['id'=>$id,'native_id'=>absint($row['native_id']),'name'=>(string)$row['attribute_slug'].' · alias «'.(string)$row['alias'].'»','components'=>$parts,'hash'=>seo_environment_compare_hash($parts)];}return$out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_snapshots' ) ) {
    function seo_environment_compare_fetch_snapshots( $mysqli, $env, $entity, array $ids ) {
        $prefix=seo_environment_compare_db_prefix($env);
        switch($entity){
            case 'vocabulary_master': return seo_environment_compare_fetch_vocabulary_master_snapshots($mysqli,$prefix,$ids);
            case 'product_tag_master': return seo_environment_compare_fetch_taxonomy_master_snapshots($mysqli,$prefix,'product_tag',$ids);
            case 'post_tag_master': return seo_environment_compare_fetch_taxonomy_master_snapshots($mysqli,$prefix,'post_tag',$ids);
            case 'attribute_master': return seo_environment_compare_fetch_attribute_master_snapshots($mysqli,$prefix,$ids);
            case 'attribute_term_master': return seo_environment_compare_fetch_attribute_term_master_snapshots($mysqli,$prefix,$ids);
            case 'attribute_alias_master': return seo_environment_compare_fetch_attribute_alias_master_snapshots($mysqli,$prefix,$ids);
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
        global $wpdb;
        $table = seo_environment_compare_table();
        $result = $wpdb->replace(
            $table,
            [
                'entity'=>$entity,'object_id'=>absint($id),'status'=>$status,'newer_env'=>'',
                'name_pro'=>(string)($pro['name']??''),'name_staging'=>(string)($staging['name']??''),
                'modified_pro'=>null,'modified_staging'=>null,
                'hash_pro'=>(string)($pro['hash']??''),'hash_staging'=>(string)($staging['hash']??''),
                'summary'=>implode(', ',$diffs),'details_json'=>wp_json_encode(['differences'=>$diffs]),'scanned_at'=>current_time('mysql',true),
            ],
            ['%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
        );
        if ( false === $result ) {
            return new WP_Error( 'seo_env_compare_store_diff', 'No se pudo guardar la diferencia ' . sanitize_key( $entity ) . ' #' . absint( $id ) . ': ' . ( $wpdb->last_error ?: 'error SQL desconocido' ) );
        }
        return true;
    }
}

if ( ! function_exists( 'seo_environment_compare_manager_key' ) ) {
    function seo_environment_compare_manager_key() {
        // El prefijo import-export hace que el proceso aparezca en el monitor
        // central sin modificar el supervisor ni pisar Academia/Clasificador.
        return 'import-export-environment-compare';
    }
}

if ( ! function_exists( 'seo_environment_compare_manager_enabled' ) ) {
    function seo_environment_compare_manager_enabled( $settings = null ) {
        if ( null === $settings ) {
            if ( ! function_exists( 'seo_process_supervisor_settings' ) ) return false;
            $settings = seo_process_supervisor_settings();
        }
        return ! empty( $settings['enabled'] ) && ! empty( $settings['environment_compare'] );
    }
}

if ( ! function_exists( 'seo_environment_compare_has_pending_scan' ) ) {
    function seo_environment_compare_has_pending_scan() {
        if ( function_exists( 'seo_process_supervisor_settings' ) && ! seo_environment_compare_manager_enabled() ) return false;
        foreach ( array_keys( seo_environment_compare_entities() ) as $entity ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' === (string) ( $state['status'] ?? '' ) ) return true;
        }
        return false;
    }
}


if ( ! function_exists( 'seo_environment_compare_has_due_scan' ) ) {
    function seo_environment_compare_has_due_scan() {
        if ( function_exists( 'seo_process_supervisor_settings' ) && ! seo_environment_compare_manager_enabled() ) return false;
        $now = time();
        foreach ( array_keys( seo_environment_compare_entities() ) as $entity ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;
            if ( absint( $state['next_run_at'] ?? 0 ) <= $now ) return true;
        }
        return false;
    }
}

if ( ! function_exists( 'seo_environment_compare_next_due_at' ) ) {
    function seo_environment_compare_next_due_at() {
        $next = 0;
        foreach ( array_keys( seo_environment_compare_entities() ) as $entity ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;
            $due = absint( $state['next_run_at'] ?? 0 );
            if ( ! $due ) return time();
            if ( ! $next || $due < $next ) $next = $due;
        }
        return $next;
    }
}

if ( ! function_exists( 'seo_environment_compare_next_worker_entity' ) ) {
    function seo_environment_compare_next_worker_entity() {
        $entities = array_keys( seo_environment_compare_entities() );
        $count = count( $entities );
        if ( ! $count ) return '';
        $now = time();

        // Una capa recién encolada debe recibir al menos su primer intento antes
        // de seguir consumiendo capas antiguas. Esto evita que un escaneo nuevo
        // aparezca indefinidamente como "queued · intentos 0".
        $first_attempt = [];
        foreach ( $entities as $index => $entity ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;
            if ( absint( $state['next_run_at'] ?? 0 ) > $now ) continue;
            if ( 0 === absint( $state['worker_attempts'] ?? 0 ) ) {
                $first_attempt[] = [
                    'entity' => $entity,
                    'index' => (int) $index,
                    'started_at' => absint( $state['started_at'] ?? 0 ),
                ];
            }
        }
        if ( $first_attempt ) {
            usort( $first_attempt, static function ( $a, $b ) {
                return (int) $b['started_at'] <=> (int) $a['started_at'];
            } );
            $chosen = $first_attempt[0];
            if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( 'seo_environment_compare_worker_cursor', 'options' );
            update_option( 'seo_environment_compare_worker_cursor', ( (int) $chosen['index'] + 1 ) % $count, false );
            return (string) $chosen['entity'];
        }

        $cursor = absint( seo_environment_compare_fresh_option( 'seo_environment_compare_worker_cursor', 0 ) ) % $count;
        for ( $i = 0; $i < $count; $i++ ) {
            $index = ( $cursor + $i ) % $count;
            $entity = $entities[ $index ];
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;
            if ( absint( $state['next_run_at'] ?? 0 ) > $now ) continue;
            if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( 'seo_environment_compare_worker_cursor', 'options' );
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

if ( ! function_exists( 'seo_environment_compare_kick_manager' ) ) {
    /**
     * Solicita un pulso real del Gestor incluso cuando el trabajo se encola
     * despues de wp_loaded (por ejemplo desde admin-ajax.php). El supervisor
     * conserva el lock global y decide el round-robin; el comparador no ejecuta
     * aqui ningun lote directamente.
     *
     * @return bool True si se ha podido solicitar/asegurar continuidad.
     */
    function seo_environment_compare_kick_manager( $source = 'environment_compare' ) {
        static $shutdown_registered = false;

        if ( function_exists( 'seo_process_supervisor_schedule_backup' ) ) {
            seo_process_supervisor_schedule_backup( false );
        }
        if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
            seo_process_supervisor_nudge( 0, $source );
        }

        if ( 'cli' === PHP_SAPI || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return true;
        }

        // El hook general del supervisor se evalua en wp_loaded. Si la cola se
        // crea mas tarde en AJAX, registramos el mismo pulso de shutdown aqui.
        if (
            ! $shutdown_registered
            && function_exists( 'seo_process_supervisor_request_pulse_claim' )
            && function_exists( 'seo_process_supervisor_request_pulse_shutdown' )
        ) {
            if ( seo_process_supervisor_request_pulse_claim() ) {
                $shutdown_registered = true;
                add_action( 'shutdown', 'seo_process_supervisor_request_pulse_shutdown', 0 );
            }
            // Si no se obtiene el claim normalmente ya existe otro pulso en
            // curso o reservado; el supervisor limpiara claims obsoletos.
            return true;
        }

        return function_exists( 'seo_process_supervisor_nudge' );
    }
}

if ( ! function_exists( 'seo_environment_compare_worker_stop_checkpoint' ) ) {
    /**
     * Parada cooperativa: no intenta matar una consulta SQL a mitad de ejecución.
     * En cuanto el worker alcanza un punto seguro, persiste estado PARADO y sale
     * sin encolar otro lote.
     */
    function seo_environment_compare_worker_stop_checkpoint( $entity, array &$state ) {
        if ( ! seo_environment_compare_stop_requested( $entity ) ) return false;
        $state = seo_environment_compare_apply_stopped_state( $state );
        seo_environment_compare_set_state( $entity, $state );
        return true;
    }
}

if ( ! function_exists( 'seo_environment_compare_process_worker_batch' ) ) {
    /**
     * Ejecuta UN lote pequeno de comparación. Solo puede ser invocado por el
     * Gestor de procesos; el AJAX de administración se limita a encolar/leer
     * estado. Cada intento persiste su fase antes de tocar las BBDD remotas.
     */
    function seo_environment_compare_process_worker_batch( $entity, $source = 'process_manager' ) {
        $entities = seo_environment_compare_entities();
        if ( ! isset( $entities[ $entity ] ) ) {
            return new WP_Error( 'invalid_entity', 'Entidad de comparación no válida.' );
        }
        if ( function_exists( 'seo_process_supervisor_settings' ) && ! seo_environment_compare_manager_enabled() ) {
            return new WP_Error( 'environment_compare_manager_disabled', 'El Comparador PRO/STAGING está desactivado en el Gestor de workers.' );
        }

        $state = seo_environment_compare_get_state( $entity );
        if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
        if ( 'running' !== (string) ( $state['status'] ?? '' ) ) return false;
        if ( absint( $state['next_run_at'] ?? 0 ) > time() ) return false;

        global $wpdb;
        $state['worker_attempts'] = absint( $state['worker_attempts'] ?? 0 ) + 1;
        $state['last_worker_attempt_at'] = time();
        $state['last_activity_at'] = time();
        $state['last_worker_phase'] = 'lock';
        $state['last_worker_error'] = '';
        seo_environment_compare_set_state( $entity, $state );

        $lock_name = 'seo_env_compare_worker_' . $entity;
        $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
        if ( '1' !== (string) $locked ) {
            $state['worker_lock_misses'] = absint( $state['worker_lock_misses'] ?? 0 ) + 1;
            $state['last_worker_error'] = 'El worker no pudo adquirir el lock MySQL del comparador.';
            $state['last_worker_phase'] = 'lock_wait';
            $state['last_activity_at'] = time();
            $state['next_run_at'] = time() + 2;
            seo_environment_compare_set_state( $entity, $state );
            if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
                seo_process_supervisor_nudge( 2, 'environment_compare_lock_wait' );
            }
            return false;
        }

        $started = microtime( true );
        $pro = null;
        $stg = null;
        try {
            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            $batch_limit = max( 1000, min( 2000, absint( $state['batch_size'] ?? 1000 ) ) );
            // Pedimos un pequeño colchón en cada entorno para que la unión PRO/STAGING
            // pueda llenar el lote sin disparar consultas gigantes.
            $candidate_limit = min( 2200, $batch_limit + 200 );

            $state['last_worker_phase'] = 'connect';
            seo_environment_compare_set_state( $entity, $state );
            $pro = seo_environment_compare_open( 'pro' );
            $stg = seo_environment_compare_open( 'staging' );
            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            if ( is_wp_error( $pro ) || is_wp_error( $stg ) ) {
                $err = is_wp_error( $pro ) ? $pro : $stg;
                $state['status'] = 'error';
                $state['error'] = 'Conexión remota: ' . $err->get_error_message();
                $state['last_worker_error'] = $state['error'];
                $state['last_worker_phase'] = 'connect_error';
                $state['last_activity_at'] = time();
                $state['next_run_at'] = 0;
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            $cursor = absint( $state['cursor'] ?? 0 );
            $state['last_worker_phase'] = 'candidate_ids';
            seo_environment_compare_set_state( $entity, $state );

            $pro_ids = seo_environment_compare_candidate_ids(
                $pro,
                seo_environment_compare_db_prefix( 'pro' ),
                $entity,
                $cursor,
                $candidate_limit
            );
            $stg_ids = seo_environment_compare_candidate_ids(
                $stg,
                seo_environment_compare_db_prefix( 'staging' ),
                $entity,
                $cursor,
                $candidate_limit
            );
            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            if ( is_wp_error( $pro_ids ) || is_wp_error( $stg_ids ) ) {
                $err = is_wp_error( $pro_ids ) ? $pro_ids : $stg_ids;
                $state['status'] = 'error';
                $state['error'] = 'Selección de IDs: ' . $err->get_error_message();
                $state['last_worker_error'] = $state['error'];
                $state['last_worker_phase'] = 'candidate_error';
                $state['last_activity_at'] = time();
                $state['next_run_at'] = 0;
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            $state['last_candidate_pro'] = count( $pro_ids );
            $state['last_candidate_staging'] = count( $stg_ids );
            $state['last_activity_at'] = time();
            seo_environment_compare_set_state( $entity, $state );

            $ids = array_values( array_unique( array_merge( $pro_ids, $stg_ids ) ) );
            sort( $ids, SORT_NUMERIC );
            $ids = array_slice( $ids, 0, $batch_limit );

            if ( ! $ids ) {
                // No declaramos un escaneo 0/0 como completado a ciegas. En la
                // primera vuelta verificamos cuántos objetos ve realmente cada
                // conexión; así un prefijo/BD equivocado queda visible.
                if ( 0 === $cursor && 0 === absint( $state['processed'] ?? 0 ) ) {
                    $state['last_worker_phase'] = 'verify_empty';
                    seo_environment_compare_set_state( $entity, $state );
                    $pro_total = seo_environment_compare_entity_total( $pro, seo_environment_compare_db_prefix( 'pro' ), $entity );
                    $stg_total = seo_environment_compare_entity_total( $stg, seo_environment_compare_db_prefix( 'staging' ), $entity );
                    if ( is_wp_error( $pro_total ) || is_wp_error( $stg_total ) ) {
                        $err = is_wp_error( $pro_total ) ? $pro_total : $stg_total;
                        $state['status'] = 'error';
                        $state['error'] = 'Verificación inicial: ' . $err->get_error_message();
                        $state['last_worker_error'] = $state['error'];
                        $state['last_worker_phase'] = 'verify_empty_error';
                        $state['next_run_at'] = 0;
                        seo_environment_compare_set_state( $entity, $state );
                        return $err;
                    }
                    $state['source_total_pro'] = absint( $pro_total );
                    $state['source_total_staging'] = absint( $stg_total );
                    $state['empty_verified'] = ( 0 === absint( $pro_total ) && 0 === absint( $stg_total ) ) ? 1 : 0;

                    if ( ! $state['empty_verified'] ) {
                        $state['status'] = 'error';
                        $state['error'] = sprintf(
                            'El Gestor no obtuvo IDs aunque las conexiones contienen objetos (PRO %d · STAGING %d). Revisa prefijos/consultas antes de continuar.',
                            absint( $pro_total ),
                            absint( $stg_total )
                        );
                        $state['last_worker_error'] = $state['error'];
                        $state['last_worker_phase'] = 'candidate_inconsistent';
                        $state['next_run_at'] = 0;
                        seo_environment_compare_set_state( $entity, $state );
                        return new WP_Error( 'environment_compare_candidate_inconsistent', $state['error'] );
                    }
                }

                if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
                $state['last_worker_phase'] = 'finalize';
                seo_environment_compare_set_state( $entity, $state );
                return seo_environment_compare_finalize_worker_scan( $entity, $state );
            }

            $state['last_worker_phase'] = 'snapshots';
            seo_environment_compare_set_state( $entity, $state );
            $pro_rows = seo_environment_compare_fetch_snapshots( $pro, 'pro', $entity, $ids );
            $stg_rows = seo_environment_compare_fetch_snapshots( $stg, 'staging', $entity, $ids );
            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            if ( is_wp_error( $pro_rows ) || is_wp_error( $stg_rows ) ) {
                $err = is_wp_error( $pro_rows ) ? $pro_rows : $stg_rows;
                $state['status'] = 'error';
                $state['error'] = 'Comparación del lote: ' . $err->get_error_message();
                $state['last_worker_error'] = $state['error'];
                $state['last_worker_phase'] = 'snapshot_error';
                $state['last_activity_at'] = time();
                $state['next_run_at'] = 0;
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            $table = seo_environment_compare_table();
            foreach ( $ids as $id ) {
                $p = $pro_rows[ $id ] ?? null;
                $s = $stg_rows[ $id ] ?? null;
                $state['processed']++;
                if ( $p ) $state['pro']++;
                if ( $s ) $state['staging']++;
                if ( ! $p || ! $s ) {
                    $status = $p ? 'only_pro' : 'only_staging';
                    $stored = seo_environment_compare_store_diff( $entity, $id, $p ?: [], $s ?: [], $status, [ $p ? 'solo existe en PRO' : 'solo existe en STAGING' ] );
                    if ( is_wp_error( $stored ) ) {
                        $state['status'] = 'error';
                        $state['error'] = $stored->get_error_message();
                        $state['last_worker_error'] = $state['error'];
                        $state['last_worker_phase'] = 'persist_difference_error';
                        $state['next_run_at'] = 0;
                        seo_environment_compare_set_state( $entity, $state );
                        return $stored;
                    }
                    $state[ $status ]++;
                    continue;
                }
                if ( hash_equals( (string) $p['hash'], (string) $s['hash'] ) ) {
                    $deleted = $wpdb->delete( $table, [ 'entity'=>$entity, 'object_id'=>$id ], [ '%s','%d' ] );
                    if ( false === $deleted ) {
                        $err = new WP_Error( 'seo_env_compare_delete_diff', 'No se pudo limpiar una diferencia obsoleta: ' . ( $wpdb->last_error ?: 'error SQL desconocido' ) );
                        $state['status'] = 'error';
                        $state['error'] = $err->get_error_message();
                        $state['last_worker_error'] = $state['error'];
                        $state['last_worker_phase'] = 'persist_same_error';
                        $state['next_run_at'] = 0;
                        seo_environment_compare_set_state( $entity, $state );
                        return $err;
                    }
                    $state['same']++;
                    continue;
                }
                $stored = seo_environment_compare_store_diff( $entity, $id, $p, $s, 'different', seo_environment_compare_diff_components( $p['components'], $s['components'] ) );
                if ( is_wp_error( $stored ) ) {
                    $state['status'] = 'error';
                    $state['error'] = $stored->get_error_message();
                    $state['last_worker_error'] = $state['error'];
                    $state['last_worker_phase'] = 'persist_difference_error';
                    $state['next_run_at'] = 0;
                    seo_environment_compare_set_state( $entity, $state );
                    return $stored;
                }
                $state['different']++;
            }

            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            $state['cursor'] = max( $ids );
            $state['worker_runs'] = absint( $state['worker_runs'] ?? 0 ) + 1;
            $state['worker_source'] = sanitize_key( (string) $source );
            $state['last_activity_at'] = time();
            $state['last_worker_phase'] = 'next_probe';

            $next_pro = seo_environment_compare_candidate_ids( $pro, seo_environment_compare_db_prefix( 'pro' ), $entity, $state['cursor'], 1 );
            $next_stg = seo_environment_compare_candidate_ids( $stg, seo_environment_compare_db_prefix( 'staging' ), $entity, $state['cursor'], 1 );
            if ( is_wp_error( $next_pro ) || is_wp_error( $next_stg ) ) {
                $err = is_wp_error( $next_pro ) ? $next_pro : $next_stg;
                $state['status'] = 'error';
                $state['error'] = 'Comprobación de continuidad: ' . $err->get_error_message();
                $state['last_worker_error'] = $state['error'];
                $state['last_worker_phase'] = 'next_probe_error';
                $state['next_run_at'] = 0;
                seo_environment_compare_set_state( $entity, $state );
                return $err;
            }

            $elapsed = max( 0.001, microtime( true ) - $started );
            $state['last_batch_rows'] = count( $ids );
            $state['last_batch_seconds'] = round( $elapsed, 3 );

            // Regulador para comparaciones masivas. El lote nunca baja de 1.000
            // ni supera 2.000 objetos. Con el rendimiento observado (~2.000 obj/s),
            // un lote rápido escala 1.000 -> 1.500 -> 2.000; si la BBDD se frena,
            // retrocede sin saltarse el presupuesto temporal del Gestor.
            if ( $elapsed > 12.0 ) {
                $state['batch_size'] = 1000;
                $delay = 12;
            } elseif ( $elapsed > 6.0 ) {
                $state['batch_size'] = max( 1000, $batch_limit - 500 );
                $delay = 6;
            } elseif ( $elapsed > 3.0 ) {
                $state['batch_size'] = max( 1000, $batch_limit - 250 );
                $delay = 3;
            } elseif ( $elapsed < 0.75 ) {
                $state['batch_size'] = min( 2000, $batch_limit + 500 );
                $delay = 1;
            } elseif ( $elapsed < 1.5 ) {
                $state['batch_size'] = min( 2000, $batch_limit + 250 );
                $delay = 1;
            } else {
                $state['batch_size'] = $batch_limit;
                $delay = 2;
            }

            if ( seo_environment_compare_worker_stop_checkpoint( $entity, $state ) ) return false;
            if ( empty( $next_pro ) && empty( $next_stg ) ) {
                $state['last_worker_phase'] = 'finalize';
                return seo_environment_compare_finalize_worker_scan( $entity, $state );
            }

            $state['status'] = 'running';
            $state['error'] = '';
            $state['last_worker_error'] = '';
            $state['last_worker_phase'] = 'waiting_manager';
            $state['next_run_at'] = time() + $delay;
            seo_environment_compare_set_state( $entity, $state );
            if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
                seo_process_supervisor_nudge( $delay, 'environment_compare' );
            }
            return $state;
        } finally {
            if ( $pro instanceof mysqli ) @mysqli_close( $pro );
            if ( $stg instanceof mysqli ) @mysqli_close( $stg );
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }
}

if ( ! function_exists( 'seo_environment_compare_process_manager_slice' ) ) {
    function seo_environment_compare_process_manager_slice( $seconds = 20, $source = 'process_manager', $target = [] ) {
        if ( ! seo_environment_compare_manager_enabled() ) return false;

        // El Gestor entrega un presupuesto temporal. Aprovechamos esa ventana
        // para dar servicio a varias capas pendientes, pero cada llamada al
        // worker sigue siendo un lote pequeño con su propio lock y regulador.
        $budget = max( 5, min( 55, absint( $seconds ) ) );
        $deadline = microtime( true ) + $budget;
        $labels = seo_environment_compare_entities();
        $key = seo_environment_compare_manager_key();
        $did_work = false;
        $had_error = false;
        $batches = 0;
        $max_batches = max( 1, min( 4, count( $labels ) ) );
        $last_entity = '';

        while ( $batches < $max_batches && microtime( true ) < ( $deadline - 1.0 ) ) {
            $entity = seo_environment_compare_next_worker_entity();
            if ( ! $entity ) break;
            $last_entity = $entity;
            $state = seo_environment_compare_get_state( $entity );

            if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
                seo_process_supervisor_managed_update( $key, [
                    'name'=>'Comparador PRO/STAGING', 'pending'=>1, 'healthy'=>1, 'last_checked'=>time(),
                    'last_attempt_at'=>time(), 'last_result'=>'running', 'last_error'=>'',
                    'detail'=>'Comparando '.$labels[$entity]['label'].' desde ID '.absint($state['cursor'] ?? 0).' · ventana '.$budget.' s.'
                ] );
            }

            try {
                $result = seo_environment_compare_process_worker_batch( $entity, $source );
            } catch ( Throwable $e ) {
                $fresh = seo_environment_compare_get_state( $entity );
                $fresh['status'] = 'error';
                $fresh['error'] = 'Excepción del worker: ' . sanitize_text_field( $e->getMessage() );
                $fresh['last_worker_error'] = $fresh['error'];
                $fresh['last_activity_at'] = time();
                $fresh['next_run_at'] = 0;
                seo_environment_compare_set_state( $entity, $fresh );
                $result = new WP_Error( 'environment_compare_worker_exception', $fresh['error'] );
            }

            $batches++;
            if ( is_wp_error( $result ) ) {
                $had_error = true;
            } elseif ( false !== $result ) {
                $did_work = true;
            }

            // No monopolizamos la ventana. Si el lote anterior consumió casi
            // todo el presupuesto, devolvemos el control al supervisor.
            if ( microtime( true ) >= ( $deadline - 2.0 ) ) break;
        }

        $still = seo_environment_compare_has_pending_scan();
        if ( ! $last_entity ) {
            $due_at = seo_environment_compare_next_due_at();
            $wait = $due_at ? max( 0, $due_at - time() ) : 0;
            if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
                seo_process_supervisor_managed_update( $key, [
                    'name'=>'Comparador PRO/STAGING', 'pending'=>$still?1:0,
                    'healthy'=>1, 'last_checked'=>time(), 'last_result'=>'waiting', 'last_error'=>'',
                    'detail'=>$wait > 0 ? 'Pausa adaptativa · siguiente lote en '.$wait.' s.' : 'En cola del Gestor; esperando siguiente ventana.'
                ] );
            }
            if ( $wait > 0 && function_exists( 'seo_process_supervisor_nudge' ) ) {
                seo_process_supervisor_nudge( $wait, 'environment_compare_wait' );
            }
            return false;
        }

        $fresh = seo_environment_compare_get_state( $last_entity );
        $managed_result = $had_error && ! $did_work
            ? 'error'
            : ( $did_work ? 'processed' : ( $still ? 'waiting' : 'completed' ) );
        if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
            seo_process_supervisor_managed_update( $key, [
                'name'=>'Comparador PRO/STAGING', 'pending'=>$still?1:0,
                'healthy'=>$had_error && ! $did_work ? 0 : 1, 'last_checked'=>time(),
                'last_result'=>$managed_result,
                'last_error'=>$had_error && ! $did_work ? (string)($fresh['last_worker_error']??'') : '',
                'detail'=>'Ventana del comparador: '.$batches.' lote(s) atendidos · última capa '.$labels[$last_entity]['label'].' · procesados '.absint($fresh['processed']??0).'.'
            ] );
        }
        return $did_work;
    }
}

if ( ! function_exists( 'seo_environment_compare_manager_targets' ) ) {
    function seo_environment_compare_manager_targets( $targets, $settings, $source ) {
        if ( ! seo_environment_compare_manager_enabled( $settings ) ) return $targets;
        if ( ! seo_environment_compare_has_pending_scan() ) return $targets;

        // El supervisor nuevo registra environment_compare como target nativo.
        // Conservamos este filtro para compatibilidad con instalaciones cuyo
        // supervisor aún no tenga esa integración, evitando duplicados.
        foreach ( (array) $targets as $existing_target ) {
            if ( 'environment_compare' === (string) ( $existing_target['type'] ?? '' ) ) {
                return $targets;
            }
        }
        $targets[] = [
            'type' => 'environment_compare',
            'data' => [ 'due' => seo_environment_compare_has_due_scan() ? 1 : 0 ],
            'callback' => 'seo_environment_compare_process_manager_slice',
        ];
        return $targets;
    }
}
add_filter( 'seo_process_supervisor_manager_targets', 'seo_environment_compare_manager_targets', 20, 3 );

if ( ! function_exists( 'seo_environment_compare_manager_pending_filter' ) ) {
    function seo_environment_compare_manager_pending_filter( $pending ) {
        if ( ! seo_environment_compare_manager_enabled() ) return $pending;
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
        $latest_status = $latest_entity && isset( $states[$latest_entity] )
            ? sanitize_key( (string) ( $states[$latest_entity]['status'] ?? '' ) )
            : '';
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
        } elseif ( 'stopped' === $latest_status ) {
            $process_state = function_exists( 'seo_processes_state' )
                ? seo_processes_state( 'stopped', 'Parado por el usuario', 'stopped' )
                : [ 'code'=>'stopped', 'label'=>'Parado por el usuario', 'tone'=>'stopped' ];
        } elseif ( 'error' === $latest_status || $errors ) {
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

        $batch = max( 1000, min( 2000, absint( $focus_state['batch_size'] ?? 1000 ) ) );
        $load = [ 'Gestor de workers', 'lote objetivo ' . number_format_i18n( $batch ) ];
        if ( $due_in > 0 ) $load[] = 'pausa ' . number_format_i18n( $due_in ) . ' s';
        if ( $running ) $load[] = count( $running ) . ' capa' . ( 1 === count( $running ) ? ' activa' : 's activas' );
        if ( $errors ) $load[] = count( $errors ) . ' con error';

        if ( $focus && isset( $definitions[$focus] ) ) {
            $detail = $definitions[$focus]['label'] . ' · ' . number_format_i18n( absint( $focus_state['processed'] ?? 0 ) ) . ' procesados';
            $cursor = absint( $focus_state['cursor'] ?? 0 );
            if ( $cursor ) $detail .= ' · cursor ' . number_format_i18n( $cursor );
            if ( count( $running ) > 1 ) $detail .= ' · ' . number_format_i18n( count( $running ) ) . ' capas en cola';
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

if ( ! function_exists( 'seo_environment_compare_running_entity' ) ) {
    /**
     * Devuelve la primera capa que sigue en estado running.
     * Se usa como cerrojo funcional para impedir que el usuario lance varios
     * chequeos simultaneos desde distintas tarjetas o pestanas.
     */
    function seo_environment_compare_running_entity() {
        foreach ( seo_environment_compare_entities() as $key => $definition ) {
            $state = seo_environment_compare_get_state( $key );
            if ( 'running' === (string) ( $state['status'] ?? '' ) ) {
                return [
                    'key'   => (string) $key,
                    'label' => (string) ( $definition['label'] ?? $key ),
                    'state' => $state,
                ];
            }
        }
        return null;
    }
}

if ( ! function_exists( 'seo_environment_compare_stop_ajax' ) ) {
    /**
     * Detiene únicamente el Comparador. No apaga el Gestor global ni afecta a
     * Import/Export, Academia, Clasificador u otros workers compartidos.
     */
    function seo_environment_compare_stop_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message'=>'Sin permisos.' ], 403 );
        check_ajax_referer( 'seo_environment_compare', 'nonce' );

        $stopped = [];
        $now = time();
        foreach ( seo_environment_compare_entities() as $entity => $definition ) {
            $state = seo_environment_compare_get_state( $entity );
            if ( 'running' !== (string) ( $state['status'] ?? '' ) ) continue;

            update_option( seo_environment_compare_stop_option( $entity ), $now, false );
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( seo_environment_compare_stop_option( $entity ), 'options' );
            }
            $state = seo_environment_compare_apply_stopped_state( $state );
            seo_environment_compare_set_state( $entity, $state );
            $stopped[] = (string) ( $definition['label'] ?? $entity );
        }

        $still = seo_environment_compare_has_pending_scan();
        $key = seo_environment_compare_manager_key();
        if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
            seo_process_supervisor_managed_update( $key, [
                'name'=>'Comparador PRO/STAGING',
                'pending'=>$still?1:0,
                'healthy'=>1,
                'last_checked'=>$now,
                'last_result'=>'stopped',
                'last_error'=>'',
                'detail'=>$stopped ? 'Parado por el usuario: '.implode( ', ', $stopped ).'.' : 'Orden de parada recibida; no había una capa activa.'
            ] );
        }

        wp_send_json_success( [
            'stopped' => count( $stopped ),
            'labels' => $stopped,
            'message' => $stopped
                ? 'Proceso parado. El worker no iniciará otro lote.'
                : 'No había ningún chequeo activo en el servidor.'
        ] );
    }
}
add_action( 'wp_ajax_seo_environment_compare_stop', 'seo_environment_compare_stop_ajax' );

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
            $running = seo_environment_compare_running_entity();
            if ( $running ) {
                wp_send_json_error( [
                    'message' => 'Ya hay un chequeo en curso: ' . $running['label'] . '. Espera a que termine antes de iniciar otra capa.'
                ], 409 );
            }
            if ( ! function_exists( 'seo_process_supervisor_settings' ) ) {
                wp_send_json_error( [ 'message'=>'No está cargado el Gestor de procesos. No se inicia el escaneo para evitar ejecutarlo fuera del worker.' ], 503 );
            }
            $manager = seo_process_supervisor_settings();
            if ( empty( $manager['enabled'] ) ) {
                wp_send_json_error( [ 'message'=>'El Gestor de procesos está desactivado. Actívalo antes de lanzar la comparación.' ], 409 );
            }
            if ( empty( $manager['environment_compare'] ) ) {
                wp_send_json_error( [ 'message'=>'El Comparador PRO/STAGING está desactivado dentro del Gestor de workers.' ], 409 );
            }
            delete_option( seo_environment_compare_stop_option( $entity ) );
            if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( seo_environment_compare_stop_option( $entity ), 'options' );
            global $wpdb;
            $wpdb->delete( seo_environment_compare_table(), [ 'entity'=>$entity ], [ '%s' ] );
            $now = time();
            $state = [
                'status'=>'running','cursor'=>0,'processed'=>0,'pro'=>0,'staging'=>0,'same'=>0,'different'=>0,
                'only_pro'=>0,'only_staging'=>0,'started_at'=>$now,'finished_at'=>0,'error'=>'',
                'batch_size'=>1000,'last_batch_seconds'=>0.0,'worker_runs'=>0,'worker_source'=>'','next_run_at'=>$now,'last_activity_at'=>$now,
                'worker_attempts'=>0,'worker_lock_misses'=>0,'last_worker_attempt_at'=>0,'last_worker_error'=>'',
                'last_worker_phase'=>'queued','last_candidate_pro'=>0,'last_candidate_staging'=>0,
                'source_total_pro'=>null,'source_total_staging'=>null,'empty_verified'=>0,
            ];
            seo_environment_compare_set_state( $entity, $state );

            // La capa que el usuario acaba de lanzar obtiene la siguiente
            // posición del round-robin. Las capas antiguas que sigan pendientes
            // no pueden retrasar varios ciclos el primer intento de esta capa.
            $entity_keys = array_keys( $entities );
            $entity_index = array_search( $entity, $entity_keys, true );
            if ( false !== $entity_index ) {
                update_option( 'seo_environment_compare_worker_cursor', (int) $entity_index, false );
            }

            $key = seo_environment_compare_manager_key();
            if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
                seo_process_supervisor_managed_update( $key, [
                    'name'=>'Comparador PRO/STAGING','pending'=>1,'healthy'=>1,'last_checked'=>$now,'last_attempt_at'=>$now,
                    'last_result'=>'queued','last_error'=>'','detail'=>'En cola: '.$entities[$entity]['label'].'. El navegador no ejecuta los lotes.'
                ] );
            }
            // No reiniciamos el estado global del supervisor: el gestor puede
            // estar atendiendo Import/Export, Academia o Clasificador. Solo le
            // notificamos que existe trabajo nuevo y aseguramos un primer pulso.
            seo_environment_compare_kick_manager( 'environment_compare' );
            wp_send_json_success( [ 'done'=>false, 'queued'=>true, 'state'=>$state ] );
        }

        $state = seo_environment_compare_get_state( $entity );
        if ( 'running' === (string) ( $state['status'] ?? '' ) ) {
            $now = time();
            $due = absint( $state['next_run_at'] ?? 0 ) <= $now;
            $last_attempt = absint( $state['last_worker_attempt_at'] ?? 0 );

            // El polling es tambien un watchdog de cola. No ejecuta el lote:
            // simplemente vuelve a despertar el Gestor si el trabajo esta vencido.
            if ( $due && ( ! $last_attempt || ( $now - $last_attempt ) >= 5 ) ) {
                seo_environment_compare_kick_manager( 'environment_compare_poll' );
            }

            // Evita el estado "En cola" infinito: si durante tres minutos no
            // hubo ni un intento de worker, la UI devuelve una causa accionable.
            if (
                0 === absint( $state['worker_attempts'] ?? 0 )
                && absint( $state['started_at'] ?? 0 )
                && ( $now - absint( $state['started_at'] ) ) >= 180
            ) {
                $state['status'] = 'error';
                $state['error'] = 'El Gestor de procesos no ha ejecutado ningún lote en 180 s. Revisa el cron/pulsos del Gestor de workers.';
                $state['last_worker_error'] = $state['error'];
                $state['last_activity_at'] = $now;
                $state['next_run_at'] = 0;
                seo_environment_compare_set_state( $entity, $state );
            }
        }

        $manager_state = function_exists( 'seo_process_supervisor_state' ) ? seo_process_supervisor_state() : [];
        wp_send_json_success( [
            'done' => in_array( (string)($state['status']??''), [ 'complete','error','stopped' ], true ),
            'queued' => 'running' === (string)($state['status']??''),
            'state' => $state,
            'manager' => [
                'status' => sanitize_key( (string) ( $manager_state['status'] ?? '' ) ),
                'backend' => sanitize_key( (string) ( $manager_state['backend'] ?? '' ) ),
                'next_cycle_at' => absint( $manager_state['next_cycle_at'] ?? 0 ),
                'last_error' => sanitize_text_field( (string) ( $manager_state['last_error'] ?? '' ) ),
            ],
        ] );
    }
}
add_action( 'wp_ajax_seo_environment_compare_scan', 'seo_environment_compare_scan_ajax' );

/* -------------------------------------------------------------------------
 * EXPORT JSON DEL INFORME.
 * No altera la comparacion ni la sincronizacion: exporta el estado de cada
 * capa y TODAS las filas persistidas del informe (no solo las 100 visibles).
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'seo_environment_compare_export_json_response' ) ) {
    function seo_environment_compare_export_json_response( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.', '', [ 'response' => 403 ] );
        }
        check_admin_referer( $nonce_action );

        global $wpdb;
        $table    = seo_environment_compare_table();
        $entities = seo_environment_compare_entities();

        $export = [
            'schema'           => 'seo_environment_compare_report',
            'version'          => SEO_ENVIRONMENT_COMPARE_VERSION,
            'execution'        => 'process_manager_worker',
            'exporter_action'  => $nonce_action,
            'source_file'      => SEO_ENVIRONMENT_COMPARE_SOURCE_FILE,
            'policy'           => 'pro_authoritative',
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

/* Compatibilidad con enlaces antiguos. */
if ( ! function_exists( 'seo_environment_compare_export_json_admin_post' ) ) {
    function seo_environment_compare_export_json_admin_post() {
        seo_environment_compare_export_json_response( 'seo_environment_compare_export_json' );
    }
}
add_action( 'admin_post_seo_environment_compare_export_json', 'seo_environment_compare_export_json_admin_post' );

/*
 * Endpoint propio del comparador actual. La interfaz usa este action para que
 * un exportador legado registrado por otro archivo no pueda interceptar el JSON.
 */
if ( ! function_exists( 'seo_environment_compare_export_json_current_admin_post' ) ) {
    function seo_environment_compare_export_json_current_admin_post() {
        seo_environment_compare_export_json_response( 'seo_environment_compare_export_json_current' );
    }
}
add_action( 'admin_post_seo_environment_compare_export_json_current', 'seo_environment_compare_export_json_current_admin_post' );

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
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND seo_role IN ({$quoted})", $args ) );
            if ( false === $deleted ) {
                return new WP_Error( 'seo_nodes_delete', $wpdb->last_error ?: 'No se pudieron reemplazar los nodos SEO del destino.' );
            }
        } elseif ( $labels_only ) {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND seo_role NOT IN ('excerpt','description','ambito')",
                    $object_type,
                    $object_id
                )
            );
            if ( false === $deleted ) {
                return new WP_Error( 'seo_nodes_delete', $wpdb->last_error ?: 'No se pudieron reemplazar las etiquetas SEO del destino.' );
            }
        }

        foreach ( $rows as $row ) {
            $role = sanitize_key( $row['seo_role'] ?? '' );
            if ( ! $role ) continue;
            $inserted = $wpdb->insert(
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
            if ( false === $inserted ) {
                return new WP_Error( 'seo_nodes_insert', $wpdb->last_error ?: 'No se pudieron guardar los nodos SEO del destino.' );
            }
        }

        return true;
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
            if($parent_id){
                $updated_parent=$wpdb->update($wpdb->prefix.'seo_vocabulary',['parent_id'=>$parent_id],['id'=>$id],['%d'],['%d']);
                if(false===$updated_parent)return new WP_Error('vocabulary_parent_update',$wpdb->last_error?:'No se pudo asignar el vocabulario padre en el destino.');
            }
            $group=sanitize_key($row['semantic_group']??'');if($group)$groups[$group][]=absint($id);
            if('tipo'===$group&&!empty($row['role_slug'])){
                $role=['role_group'=>$row['role_group']?:'rol','role_slug'=>$row['role_slug'],'role_label'=>$row['role_label']??$row['role_slug'],'role_source'=>$row['role_source']??'environment_sync','role_created_at'=>$row['role_created_at']??'','role_updated_at'=>$row['role_updated_at']??''];
                $role_id=seo_environment_sync_upsert_vocabulary_term_local($role,'role');if(is_wp_error($role_id))return$role_id;
                $map=$wpdb->prefix.'seo_type_role_map';$existing=$wpdb->get_row($wpdb->prepare("SELECT id,role_vocabulary_id,active FROM {$map} WHERE type_vocabulary_id=%d LIMIT 1",$id),ARRAY_A);
                if(!$existing){
                    $inserted_map=$wpdb->insert($map,['type_vocabulary_id'=>$id,'role_vocabulary_id'=>$role_id,'confidence'=>(float)($row['role_confidence']??1),'source'=>sanitize_key($row['role_map_source']??'environment_sync')?:'environment_sync','active'=>1],['%d','%d','%f','%s','%d']);
                    if(false===$inserted_map)return new WP_Error('role_map_insert',$wpdb->last_error?:'No se pudo crear el mapeo maestro TIPO → ROL en el destino.');
                }
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


if ( ! function_exists( 'seo_environment_sync_fetch_master_row' ) ) {
    function seo_environment_sync_fetch_master_row( $mysqli, $prefix, $entity, $stable_id ) {
        $stable_id=absint($stable_id);
        if('vocabulary_master'===$entity){$sid=seo_environment_compare_stable_sql_id("CONCAT(SHA2(CONVERT(v.semantic_group USING utf8mb4),256),SHA2(CONVERT(v.slug USING utf8mb4),256))");$rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,v.*,pv.semantic_group AS parent_group,pv.slug AS parent_slug,pv.label AS parent_label,pv.source AS parent_source,pv.active AS parent_active,rv.semantic_group AS role_group,rv.slug AS role_slug,rv.label AS role_label,rv.source AS role_source,rv.active AS role_active,m.active AS map_active,m.confidence AS map_confidence,m.source AS map_source FROM `{$prefix}seo_vocabulary` v LEFT JOIN `{$prefix}seo_vocabulary` pv ON pv.id=v.parent_id LEFT JOIN `{$prefix}seo_type_role_map` m ON m.type_vocabulary_id=v.id LEFT JOIN `{$prefix}seo_vocabulary` rv ON rv.id=m.role_vocabulary_id WHERE {$sid}={$stable_id} LIMIT 1");}
        elseif(in_array($entity,['product_tag_master','post_tag_master'],true)){$taxonomy='product_tag_master'===$entity?'product_tag':'post_tag';$tax=mysqli_real_escape_string($mysqli,$taxonomy);$sid=seo_environment_compare_stable_sql_id("CONVERT(t.slug USING utf8mb4)");$rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,t.term_id,t.name,t.slug,tt.description,tt.taxonomy FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='{$tax}' AND {$sid}={$stable_id} LIMIT 1");}
        elseif('attribute_master'===$entity){$sid=seo_environment_compare_stable_sql_id("CONVERT(a.slug USING utf8mb4)");$rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,a.* FROM `{$prefix}sql_atributos` a WHERE {$sid}={$stable_id} LIMIT 1");}
        elseif('attribute_term_master'===$entity){$sid=seo_environment_compare_stable_sql_id("CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(t.slug USING utf8mb4),256))");$rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,t.*,a.slug AS attribute_slug FROM `{$prefix}sql_atributos_terminos` t JOIN `{$prefix}sql_atributos` a ON a.id=t.atributo_id WHERE {$sid}={$stable_id} LIMIT 1");}
        elseif('attribute_alias_master'===$entity){$sid=seo_environment_compare_stable_sql_id("CONCAT(SHA2(CONVERT(a.slug USING utf8mb4),256),SHA2(CONVERT(aa.alias USING utf8mb4),256))");$rows=seo_environment_compare_query_rows($mysqli,"SELECT {$sid} AS stable_id,MIN(aa.id) AS id,a.slug AS attribute_slug,aa.alias,GROUP_CONCAT(DISTINCT COALESCE(CONVERT(t.slug USING utf8mb4),_utf8mb4'') ORDER BY t.slug SEPARATOR '|') AS term_slugs FROM `{$prefix}sql_atributos_aliases` aa JOIN `{$prefix}sql_atributos` a ON a.id=aa.atributo_id LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=aa.termino_id WHERE {$sid}={$stable_id} GROUP BY stable_id,a.slug,aa.alias LIMIT 1");}
        else return new WP_Error('invalid_master','Capa maestra no válida.');
        return is_wp_error($rows)?$rows:($rows[0]??null);
    }
}

if ( ! function_exists( 'seo_environment_sync_upsert_vocab_master_local' ) ) {
    function seo_environment_sync_upsert_vocab_master_local( array $row, $prefix_key='' ) {
        global $wpdb;$table=$wpdb->prefix.'seo_vocabulary';$p=$prefix_key!==''?$prefix_key.'_':'';$group=sanitize_key($row[$p.'semantic_group']??$row[$p.'group']??'');$slug=sanitize_title($row[$p.'slug']??'');if(!$group||!$slug)return new WP_Error('invalid_vocabulary','Vocabulario maestro incompleto.');$label=sanitize_text_field($row[$p.'label']??$slug);$source=sanitize_key($row[$p.'source']??'environment_sync')?:'environment_sync';$active=absint($row[$p.'active']??1);$existing=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE semantic_group=%s AND slug=%s LIMIT 1",$group,$slug),ARRAY_A);$data=['label'=>$label,'source'=>$source,'active'=>$active];
        if($existing){$id=absint($existing['id']);$ok=$wpdb->update($table,$data,['id'=>$id],['%s','%s','%d'],['%d']);if(false===$ok)return new WP_Error('vocabulary_update',$wpdb->last_error?:'No se pudo actualizar vocabulario maestro.');return$id;}
        $data=array_merge(['semantic_group'=>$group,'slug'=>$slug],$data);$ok=$wpdb->insert($table,$data,['%s','%s','%s','%s','%d']);if(false===$ok)return new WP_Error('vocabulary_insert',$wpdb->last_error?:'No se pudo crear vocabulario maestro.');return absint($wpdb->insert_id);
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_master' ) ) {
    function seo_environment_sync_pull_master( $mysqli, $source_env, $entity, $id ) {
        global $wpdb;$prefix=seo_environment_compare_db_prefix($source_env);$row=seo_environment_sync_fetch_master_row($mysqli,$prefix,$entity,$id);if(is_wp_error($row))return$row;if(!$row)return new WP_Error('missing_source_master','El maestro origen ya no existe. Reescanea.');
        if('vocabulary_master'===$entity){$parent_id=0;if(!empty($row['parent_group'])&&!empty($row['parent_slug'])){$parent=['parent_semantic_group'=>$row['parent_group'],'parent_slug'=>$row['parent_slug'],'parent_label'=>$row['parent_label']??$row['parent_slug'],'parent_source'=>$row['parent_source']??'environment_sync','parent_active'=>$row['parent_active']??1];$parent_id=seo_environment_sync_upsert_vocab_master_local($parent,'parent');if(is_wp_error($parent_id))return$parent_id;}$local_id=seo_environment_sync_upsert_vocab_master_local($row);if(is_wp_error($local_id))return$local_id;if($parent_id){$ok=$wpdb->update($wpdb->prefix.'seo_vocabulary',['parent_id'=>$parent_id],['id'=>$local_id],['%d'],['%d']);if(false===$ok)return new WP_Error('vocabulary_parent',$wpdb->last_error?:'No se pudo actualizar el padre canónico.');}
            if('tipo'===sanitize_key($row['semantic_group']??'')&&!empty($row['role_slug'])){$role=['role_semantic_group'=>$row['role_group']?:'rol','role_slug'=>$row['role_slug'],'role_label'=>$row['role_label']??$row['role_slug'],'role_source'=>$row['role_source']??'environment_sync','role_active'=>$row['role_active']??1];$role_id=seo_environment_sync_upsert_vocab_master_local($role,'role');if(is_wp_error($role_id))return$role_id;$map=$wpdb->prefix.'seo_type_role_map';$existing=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$map} WHERE type_vocabulary_id=%d LIMIT 1",$local_id),ARRAY_A);$data=['role_vocabulary_id'=>$role_id,'confidence'=>(float)($row['map_confidence']??1),'source'=>sanitize_key($row['map_source']??'environment_sync')?:'environment_sync','active'=>absint($row['map_active']??1)];if($existing){$ok=$wpdb->update($map,$data,['id'=>absint($existing['id'])],['%d','%f','%s','%d'],['%d']);}else{$data=['type_vocabulary_id'=>$local_id]+$data;$ok=$wpdb->insert($map,$data,['%d','%d','%f','%s','%d']);}if(false===$ok)return new WP_Error('role_map_sync',$wpdb->last_error?:'No se pudo sincronizar TIPO → ROL.');}return true;}
        if(in_array($entity,['product_tag_master','post_tag_master'],true)){$tax=sanitize_key($row['taxonomy']??'');$slug=sanitize_title($row['slug']??'');$term=get_term_by('slug',$slug,$tax);if(!$term||is_wp_error($term)){$created=wp_insert_term(sanitize_text_field($row['name']??$slug),$tax,['slug'=>$slug,'description'=>(string)($row['description']??'')]);if(is_wp_error($created))return$created;}else{$updated=wp_update_term(absint($term->term_id),$tax,['name'=>sanitize_text_field($row['name']??$slug),'description'=>(string)($row['description']??'')]);if(is_wp_error($updated))return$updated;}return true;}
        if('attribute_master'===$entity){$table=$wpdb->prefix.'sql_atributos';$slug=sanitize_key($row['slug']??'');$existing=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE slug=%s LIMIT 1",$slug),ARRAY_A);$data=['nombre'=>sanitize_text_field($row['nombre']??$slug),'grupo'=>sanitize_text_field($row['grupo']??''),'tipo'=>sanitize_key($row['tipo']??'texto')?:'texto','unidad_tipo'=>sanitize_text_field($row['unidad_tipo']??''),'unidad_base'=>sanitize_text_field($row['unidad_base']??''),'multiple'=>absint($row['multiple']??0),'filtrable'=>absint($row['filtrable']??0),'visible'=>absint($row['visible']??1),'seo'=>absint($row['seo']??1),'orden'=>(int)($row['orden']??0),'activo'=>absint($row['activo']??1)];if($existing){$ok=$wpdb->update($table,$data,['id'=>absint($existing['id'])]);}else{$ok=$wpdb->insert($table,['slug'=>$slug]+$data);}return false===$ok?new WP_Error('attribute_master_sync',$wpdb->last_error?:'No se pudo sincronizar el atributo maestro.'):true;}
        if('attribute_term_master'===$entity){$defs=$wpdb->prefix.'sql_atributos';$terms=$wpdb->prefix.'sql_atributos_terminos';$attr=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$defs} WHERE slug=%s LIMIT 1",sanitize_key($row['attribute_slug']??'')),ARRAY_A);if(!$attr)return new WP_Error('missing_attribute_master','Falta el atributo maestro '.sanitize_key($row['attribute_slug']??'').'. Sincroniza primero Maestros · Atributos.');$slug=sanitize_title($row['slug']??'');$existing=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$terms} WHERE atributo_id=%d AND slug=%s LIMIT 1",absint($attr['id']),$slug),ARRAY_A);$data=['nombre'=>sanitize_text_field($row['nombre']??$slug),'orden'=>(int)($row['orden']??0),'activo'=>absint($row['activo']??1)];if($existing){$ok=$wpdb->update($terms,$data,['id'=>absint($existing['id'])]);}else{$ok=$wpdb->insert($terms,['atributo_id'=>absint($attr['id']),'slug'=>$slug]+$data);}return false===$ok?new WP_Error('attribute_term_sync',$wpdb->last_error?:'No se pudo sincronizar el término de atributo.'):true;}
        if('attribute_alias_master'===$entity){$defs=$wpdb->prefix.'sql_atributos';$terms=$wpdb->prefix.'sql_atributos_terminos';$aliases=$wpdb->prefix.'sql_atributos_aliases';$attr=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$defs} WHERE slug=%s LIMIT 1",sanitize_key($row['attribute_slug']??'')),ARRAY_A);if(!$attr)return new WP_Error('missing_attribute_master','Falta el atributo maestro. Sincroniza primero Maestros · Atributos.');$term_id=null;$slugs=array_values(array_filter(explode('|',(string)($row['term_slugs']??''))));if(count($slugs)>1)return new WP_Error('alias_ambiguous_source','El alias apunta a varios términos en origen; requiere revisión manual.');if($slugs){$term=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$terms} WHERE atributo_id=%d AND slug=%s LIMIT 1",absint($attr['id']),sanitize_title($slugs[0])),ARRAY_A);if(!$term)return new WP_Error('missing_attribute_term','Falta el término maestro del alias. Sincroniza primero Maestros · Términos de atributos.');$term_id=absint($term['id']);}$alias=(string)($row['alias']??'');$existing=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$aliases} WHERE atributo_id=%d AND alias=%s ORDER BY id ASC LIMIT 1",absint($attr['id']),$alias),ARRAY_A);$data=['termino_id'=>$term_id];if($existing){$ok=$wpdb->update($aliases,$data,['id'=>absint($existing['id'])]);}else{$ok=$wpdb->insert($aliases,['atributo_id'=>absint($attr['id']),'alias'=>$alias,'termino_id'=>$term_id]);}return false===$ok?new WP_Error('attribute_alias_sync',$wpdb->last_error?:'No se pudo sincronizar el alias.'):true;}
        return new WP_Error('invalid_master','Capa maestra no soportada.');
    }
}

if ( ! function_exists( 'seo_environment_sync_resolve_semantic_local_strict' ) ) {
    function seo_environment_sync_resolve_semantic_local_strict( array $rows ) {
        global $wpdb;$groups=[];$missing=[];$vtable=$wpdb->prefix.'seo_vocabulary';$map=$wpdb->prefix.'seo_type_role_map';
        foreach($rows as $row){$group=sanitize_key($row['semantic_group']??'');$slug=sanitize_title($row['slug']??'');if(!$group||!$slug)continue;$local=$wpdb->get_row($wpdb->prepare("SELECT id,active FROM {$vtable} WHERE semantic_group=%s AND slug=%s LIMIT 1",$group,$slug),ARRAY_A);if(!$local||!(int)$local['active']){$missing[]=$group.':'.$slug;continue;}$id=absint($local['id']);$groups[$group][]=$id;if('tipo'===$group&&!empty($row['role_slug'])){$rg=sanitize_key($row['role_group']??'rol')?:'rol';$rs=sanitize_title($row['role_slug']);$role=$wpdb->get_row($wpdb->prepare("SELECT id,active FROM {$vtable} WHERE semantic_group=%s AND slug=%s LIMIT 1",$rg,$rs),ARRAY_A);if(!$role||!(int)$role['active']){$missing[]=$rg.':'.$rs.' (ROL de '.$slug.')';continue;}$m=$wpdb->get_row($wpdb->prepare("SELECT role_vocabulary_id,active FROM {$map} WHERE type_vocabulary_id=%d LIMIT 1",$id),ARRAY_A);if(!$m||!(int)$m['active']||absint($m['role_vocabulary_id'])!==absint($role['id']))$missing[]='mapeo TIPO→ROL '.$slug.'→'.$rs;}}
        if($missing)return new WP_Error('missing_vocabulary_master','Falta vocabulario maestro en el destino: '.implode(', ',array_slice(array_values(array_unique($missing)),0,12)).'. Sincroniza primero Maestros · Vocabulario semántico.');foreach($groups as &$ids)$ids=array_values(array_unique(array_map('absint',$ids)));unset($ids);return['groups'=>$groups,'missing'=>[]];
    }
}

if ( ! function_exists( 'seo_environment_sync_validate_attribute_masters_local' ) ) {
    function seo_environment_sync_validate_attribute_masters_local( array $rows ) {
        global $wpdb;$defs=$wpdb->prefix.'sql_atributos';$terms=$wpdb->prefix.'sql_atributos_terminos';$missing=[];$cache=[];
        foreach($rows as $row){$slug=sanitize_key($row['attribute_type']??'');if(!$slug)continue;if(!array_key_exists($slug,$cache)){$a=$wpdb->get_row($wpdb->prepare("SELECT id,activo FROM {$defs} WHERE slug=%s LIMIT 1",$slug),ARRAY_A);$cache[$slug]=$a&&((int)$a['activo'])?absint($a['id']):0;}if(!$cache[$slug]){$missing[]='atributo '.$slug;continue;}if(!empty($row['term_slug'])){$ts=sanitize_title($row['term_slug']);$t=$wpdb->get_row($wpdb->prepare("SELECT id,activo FROM {$terms} WHERE atributo_id=%d AND slug=%s LIMIT 1",$cache[$slug],$ts),ARRAY_A);if(!$t||!(int)$t['activo'])$missing[]='término '.$slug.':'.$ts;}}
        return $missing?new WP_Error('missing_attribute_master','Faltan maestros de atributos en el destino: '.implode(', ',array_slice(array_values(array_unique($missing)),0,12)).'. Sincroniza primero Maestros · Atributos y Maestros · Términos de atributos.'):true;
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
        $prefix=seo_environment_compare_db_prefix($source_env);$map=['product_tags'=>['product','product_tag'],'page_tags'=>['page','post_tag'],'post_tags'=>['post','post_tag']];[$post_type,$taxonomy]=$map[$entity];$local=get_post($id);if(!$local||$local->post_type!==$post_type)return new WP_Error('missing_destination','El objeto no existe en el destino.');$terms=seo_environment_sync_fetch_terms($mysqli,$prefix,$id,[$taxonomy]);if(is_wp_error($terms))return$terms;$resolved=seo_environment_sync_resolve_terms_local($terms);if($resolved['missing'])return new WP_Error('missing_terms','Faltan etiquetas maestras en el destino: '.implode(', ',array_slice($resolved['missing'],0,8)).'. Sincroniza primero la capa Maestros correspondiente.');$r=wp_set_object_terms($id,$resolved['terms'][$taxonomy]??[],$taxonomy,false);if(is_wp_error($r))return$r;
        if(in_array($entity,['page_tags','post_tags'],true)){$nodes=seo_environment_sync_fetch_selected_nodes($mysqli,$prefix,$post_type,$id,[],true);if(is_wp_error($nodes))return$nodes;$replaced=seo_environment_sync_replace_selected_nodes_local($post_type,$id,$nodes,[],true);if(is_wp_error($replaced))return$replaced;}clean_post_cache($id);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_semantic' ) ) {
    function seo_environment_sync_pull_semantic( $mysqli, $source_env, $entity, $id ) {
        $prefix=seo_environment_compare_db_prefix($source_env);$object_type='product_semantic'===$entity?'product':'product_cat';if('product'===$object_type){$local=get_post($id);if(!$local||$local->post_type!=='product')return new WP_Error('missing_destination','El producto no existe en el destino.');}else{$local=get_term($id,'product_cat');if(!$local||is_wp_error($local))return new WP_Error('missing_destination','La categoría no existe en el destino.');}
        $rows=seo_environment_sync_fetch_semantic($mysqli,$prefix,$object_type,$id);if(is_wp_error($rows))return$rows;$sem=seo_environment_sync_resolve_semantic_local_strict($rows);if(is_wp_error($sem))return$sem;
        $all=['rol'=>[],'tipo'=>[],'aplicacion'=>[],'plataforma'=>[],'subtipo'=>[]];foreach($sem['groups'] as $g=>$ids)$all[$g]=$ids;
        if('product'===$object_type){if(!function_exists('seo_catalog_apply_product_vocabulary_changes'))return new WP_Error('semantic_writer_missing','No está disponible el escritor canónico de producto.');$r=seo_catalog_apply_product_vocabulary_changes($id,$all,'environment_sync');if(empty($r['ok']))return new WP_Error('semantic_sync',(string)($r['message']??'No se pudo sincronizar la semántica.'));}
        else{if(!function_exists('seo_category_vocabulary_replace'))return new WP_Error('semantic_writer_missing','No está disponible el escritor canónico de categorías.');$r=seo_category_vocabulary_replace($id,$all,'environment_sync');if(is_wp_error($r))return$r;}
        seo_environment_sync_restore_semantic_dates_local($object_type,$id,$rows);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_product_attributes' ) ) {
    function seo_environment_sync_pull_product_attributes( $mysqli, $source_env, $id ) {
        $local=get_post($id);if(!$local||$local->post_type!=='product')return new WP_Error('missing_destination','El producto no existe en el destino.');$prefix=seo_environment_compare_db_prefix($source_env);$rows=seo_environment_sync_fetch_product_attributes($mysqli,$prefix,$id);if(is_wp_error($rows))return$rows;$masters=seo_environment_sync_validate_attribute_masters_local($rows);if(is_wp_error($masters))return$masters;if(!function_exists('seo_attributes_replace_product'))return new WP_Error('attribute_writer_missing','No está disponible el escritor canónico de atributos.');$written=seo_attributes_replace_product($id,seo_environment_sync_attribute_rows_local($rows),'environment_sync');if(is_wp_error($written))return$written;if(false===$written)return new WP_Error('attribute_sync','El escritor canónico de atributos devolvió un fallo.');if(is_array($written)&&array_key_exists('ok',$written)&&empty($written['ok']))return new WP_Error('attribute_sync',(string)($written['message']??'No se pudieron sincronizar los atributos.'));if(function_exists('wc_delete_product_transients'))wc_delete_product_transients($id);return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_category_general' ) ) {
    function seo_environment_sync_pull_category_general( $mysqli, $source_env, $id ) {
        $prefix=seo_environment_compare_db_prefix($source_env);$id=absint($id);$rows=seo_environment_compare_query_rows($mysqli,"SELECT t.term_id,t.name FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id={$id} LIMIT 1");if(is_wp_error($rows))return$rows;$src=$rows[0]??null;if(!$src)return new WP_Error('missing_source','La categoría origen ya no existe.');$local=get_term($id,'product_cat');if(!$local||is_wp_error($local))return new WP_Error('missing_destination','La categoría no existe en el destino. No se crean categorías desde este comparador.');$nodes=seo_environment_sync_fetch_selected_nodes($mysqli,$prefix,'category',$id,['excerpt','description'],false);if(is_wp_error($nodes))return$nodes;$r=wp_update_term($id,'product_cat',['name'=>(string)$src['name']]);if(is_wp_error($r))return$r;$replaced=seo_environment_sync_replace_selected_nodes_local('category',$id,$nodes,['excerpt','description'],false);if(is_wp_error($replaced))return$replaced;$date_rows=seo_environment_compare_query_rows($mysqli,"SELECT meta_value FROM `{$prefix}termmeta` WHERE term_id={$id} AND meta_key='_seo_sync_modified_gmt' ORDER BY meta_id DESC LIMIT 1");$date=!is_wp_error($date_rows)&&!empty($date_rows[0]['meta_value'])?(string)$date_rows[0]['meta_value']:'';if(function_exists('seo_ie_sync_restore_category_modified')&&$date)seo_ie_sync_restore_category_modified($id,['fecha_modificada_gmt'=>$date]);clean_term_cache($id,'product_cat');return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_category_tags' ) ) {
    function seo_environment_sync_pull_category_tags( $mysqli, $source_env, $id ) {
        $local=get_term($id,'product_cat');if(!$local||is_wp_error($local))return new WP_Error('missing_destination','La categoría no existe en el destino.');$prefix=seo_environment_compare_db_prefix($source_env);$nodes=seo_environment_sync_fetch_selected_nodes($mysqli,$prefix,'category',$id,['category'],false);if(is_wp_error($nodes))return$nodes;$replaced=seo_environment_sync_replace_selected_nodes_local('category',$id,$nodes,['category'],false);if(is_wp_error($replaced))return$replaced;return true;
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
            case 'vocabulary_master': case 'product_tag_master': case 'post_tag_master': case 'attribute_master': case 'attribute_term_master': case 'attribute_alias_master': $result=seo_environment_sync_pull_master($mysqli,$source_env,$entity,$id);break;
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
        $current = seo_environment_compare_current_env();
        if ( ! $current ) {
            return new WP_Error( 'unknown_local_env', 'No se ha podido identificar si este WordPress es PRO o STAGING.' );
        }
        if ( 'pro' !== $source || 'staging' !== $destination ) {
            return new WP_Error( 'reverse_sync_disabled', 'La sincronización operativa está fijada en PRO → STAGING. STAGING → PRO es solo informativo.' );
        }
        if ( 'staging' !== $current ) {
            return new WP_Error( 'remote_write_blocked', 'Por seguridad PRO → STAGING solo se ejecuta desde el WordPress STAGING.' );
        }
        return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_delete_master_local' ) ) {
    /**
     * Elimina de STAGING un maestro que el ultimo escaneo confirma como
     * inexistente en PRO. La clave recibida es el stable_id canonico, nunca un
     * ID autoincremental compartido entre entornos.
     */
    function seo_environment_sync_delete_master_local( $entity, $stable_id ) {
        global $wpdb;

        $entity    = sanitize_key( (string) $entity );
        $stable_id = absint( $stable_id );
        if ( ! seo_environment_compare_is_master_entity( $entity ) || ! $stable_id ) {
            return new WP_Error( 'invalid_master_delete', 'Maestro no valido para eliminar.' );
        }
        if ( 'staging' !== seo_environment_compare_current_env() ) {
            return new WP_Error( 'delete_outside_staging', 'La limpieza de maestros exclusivos solo se permite desde STAGING.' );
        }

        $stg = seo_environment_compare_open( 'staging' );
        if ( is_wp_error( $stg ) ) return $stg;
        $row = seo_environment_sync_fetch_master_row(
            $stg,
            seo_environment_compare_db_prefix( 'staging' ),
            $entity,
            $stable_id
        );
        @mysqli_close( $stg );
        if ( is_wp_error( $row ) ) return $row;
        if ( ! $row ) return new WP_Error( 'stale_master_delete', 'El maestro ya no existe en STAGING. Reescanea.' );

        if ( 'vocabulary_master' === $entity ) {
            $id = absint( $row['id'] ?? 0 );
            if ( ! $id ) return new WP_Error( 'invalid_vocabulary_delete', 'No se pudo resolver el vocabulario local.' );
            $v  = $wpdb->prefix . 'seo_vocabulary';
            $ov = $wpdb->prefix . 'seo_object_vocabulary';
            $rm = $wpdb->prefix . 'seo_type_role_map';

            if ( false === $wpdb->delete( $ov, [ 'vocabulary_id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'vocabulary_delete_relations', $wpdb->last_error ?: 'No se pudieron eliminar las asignaciones del vocabulario.' );
            if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$rm} WHERE type_vocabulary_id=%d OR role_vocabulary_id=%d", $id, $id ) ) ) return new WP_Error( 'vocabulary_delete_role_map', $wpdb->last_error ?: 'No se pudo limpiar el mapa TIPO/ROL.' );
            if ( false === $wpdb->update( $v, [ 'parent_id'=>null ], [ 'parent_id'=>$id ], [ '%d' ], [ '%d' ] ) ) return new WP_Error( 'vocabulary_delete_children', $wpdb->last_error ?: 'No se pudieron desacoplar los hijos del vocabulario.' );
            if ( false === $wpdb->delete( $v, [ 'id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'vocabulary_delete', $wpdb->last_error ?: 'No se pudo eliminar el vocabulario exclusivo de STAGING.' );
            return true;
        }

        if ( in_array( $entity, [ 'product_tag_master', 'post_tag_master' ], true ) ) {
            $taxonomy = 'product_tag_master' === $entity ? 'product_tag' : 'post_tag';
            $slug     = sanitize_title( $row['slug'] ?? '' );
            $term     = $slug ? get_term_by( 'slug', $slug, $taxonomy ) : false;
            if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'stale_tag_delete', 'La etiqueta ya no existe en STAGING. Reescanea.' );
            $deleted = wp_delete_term( absint( $term->term_id ), $taxonomy );
            if ( is_wp_error( $deleted ) ) return $deleted;
            if ( false === $deleted ) return new WP_Error( 'tag_delete', 'WordPress no pudo eliminar la etiqueta exclusiva de STAGING.' );
            return true;
        }

        if ( 'attribute_master' === $entity ) {
            $id      = absint( $row['id'] ?? 0 );
            $defs    = $wpdb->prefix . 'sql_atributos';
            $terms   = $wpdb->prefix . 'sql_atributos_terminos';
            $aliases = $wpdb->prefix . 'sql_atributos_aliases';
            $product = $wpdb->prefix . 'sql_product_atributos';
            if ( ! $id ) return new WP_Error( 'invalid_attribute_delete', 'No se pudo resolver el atributo local.' );
            if ( false === $wpdb->delete( $product, [ 'atributo_id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_delete_product', $wpdb->last_error ?: 'No se pudieron limpiar las asignaciones del atributo.' );
            if ( false === $wpdb->delete( $aliases, [ 'atributo_id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_delete_aliases', $wpdb->last_error ?: 'No se pudieron limpiar los alias del atributo.' );
            if ( false === $wpdb->delete( $terms, [ 'atributo_id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_delete_terms', $wpdb->last_error ?: 'No se pudieron limpiar los terminos del atributo.' );
            if ( false === $wpdb->delete( $defs, [ 'id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_delete', $wpdb->last_error ?: 'No se pudo eliminar el atributo exclusivo de STAGING.' );
            return true;
        }

        if ( 'attribute_term_master' === $entity ) {
            $id      = absint( $row['id'] ?? 0 );
            $terms   = $wpdb->prefix . 'sql_atributos_terminos';
            $aliases = $wpdb->prefix . 'sql_atributos_aliases';
            $product = $wpdb->prefix . 'sql_product_atributos';
            if ( ! $id ) return new WP_Error( 'invalid_attribute_term_delete', 'No se pudo resolver el termino de atributo local.' );
            if ( false === $wpdb->delete( $product, [ 'termino_id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_term_delete_product', $wpdb->last_error ?: 'No se pudieron limpiar las asignaciones del termino.' );
            if ( false === $wpdb->delete( $aliases, [ 'termino_id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_term_delete_aliases', $wpdb->last_error ?: 'No se pudieron limpiar los alias del termino.' );
            if ( false === $wpdb->delete( $terms, [ 'id'=>$id ], [ '%d' ] ) ) return new WP_Error( 'attribute_term_delete', $wpdb->last_error ?: 'No se pudo eliminar el termino exclusivo de STAGING.' );
            return true;
        }

        if ( 'attribute_alias_master' === $entity ) {
            $defs      = $wpdb->prefix . 'sql_atributos';
            $aliases   = $wpdb->prefix . 'sql_atributos_aliases';
            $attr_slug = sanitize_key( $row['attribute_slug'] ?? '' );
            $alias     = (string) ( $row['alias'] ?? '' );
            $attr      = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$defs} WHERE slug=%s LIMIT 1", $attr_slug ), ARRAY_A );
            if ( ! $attr ) return new WP_Error( 'stale_alias_delete', 'El atributo del alias ya no existe en STAGING. Reescanea.' );
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$aliases} WHERE atributo_id=%d AND alias=%s", absint( $attr['id'] ), $alias ) );
            if ( false === $deleted ) return new WP_Error( 'attribute_alias_delete', $wpdb->last_error ?: 'No se pudo eliminar el alias exclusivo de STAGING.' );
            return true;
        }

        return new WP_Error( 'unsupported_master_delete', 'Esta capa maestra no admite limpieza automatica.' );
    }
}

if ( ! function_exists( 'seo_environment_sync_apply_authoritative_item' ) ) {
    /** Aplica la politica PRO = verdad: upsert desde PRO o purge de only_staging. */
    function seo_environment_sync_apply_authoritative_item( $source_env, $entity, $id, $mysqli = null ) {
        global $wpdb;
        $status = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM " . seo_environment_compare_table() . " WHERE entity=%s AND object_id=%d LIMIT 1",
                sanitize_key( $entity ),
                absint( $id )
            )
        );
        if ( 'pro' === sanitize_key( $source_env ) && 'only_staging' === $status && seo_environment_compare_is_master_entity( $entity ) ) {
            return seo_environment_sync_delete_master_local( $entity, $id );
        }
        return seo_environment_sync_pull_item( $source_env, $entity, $id, $mysqli );
    }
}

if ( ! function_exists( 'seo_environment_sync_revalidate_item' ) ) {
    function seo_environment_sync_revalidate_item( $entity, $id, $source = '' ) {
        global $wpdb;$table=seo_environment_compare_table();$source=sanitize_key($source);$allowed=seo_environment_compare_syncable_statuses($entity,$source);
        if ( ! seo_environment_compare_is_master_entity( $entity ) ) {
            return new WP_Error( 'use_portable_mirror', 'Esta capa usa identidad portable. Para escribir usa «Copiar PRO → STAGING», que remapea los IDs locales mediante MirrorEngine.' );
        }
        $scan=$wpdb->get_row($wpdb->prepare("SELECT status,hash_pro,hash_staging FROM {$table} WHERE entity=%s AND object_id=%d LIMIT 1",$entity,absint($id)),ARRAY_A);
        if(!$scan||!in_array((string)($scan['status']??''),$allowed,true))return new WP_Error('scan_required','La fila no pertenece a un escaneo vigente sincronizable en esta dirección. Vuelve a escanear.');
        $pro=seo_environment_compare_open('pro');$stg=seo_environment_compare_open('staging');if(is_wp_error($pro)||is_wp_error($stg)){if($pro instanceof mysqli)@mysqli_close($pro);if($stg instanceof mysqli)@mysqli_close($stg);return is_wp_error($pro)?$pro:$stg;}
        $p=seo_environment_compare_fetch_snapshots($pro,'pro',$entity,[$id]);$s=seo_environment_compare_fetch_snapshots($stg,'staging',$entity,[$id]);@mysqli_close($pro);@mysqli_close($stg);if(is_wp_error($p)||is_wp_error($s))return is_wp_error($p)?$p:$s;$pr=$p[$id]??null;$sr=$s[$id]??null;
        if('different'===($scan['status']??'')){if(!$pr||!$sr)return new WP_Error('stale_scan','El objeto cambió de existencia después del escaneo. Reescanea.');$fresh_pro=(string)$pr['hash'];$fresh_staging=(string)$sr['hash'];if(!hash_equals((string)$scan['hash_pro'],$fresh_pro)||!hash_equals((string)$scan['hash_staging'],$fresh_staging))return new WP_Error('stale_scan','PRO o STAGING cambió después del último escaneo. Reescanea.');if(hash_equals($fresh_pro,$fresh_staging))return new WP_Error('already_equal','El maestro/objeto ya está igual en ambos entornos.');return true;}
        if(!seo_environment_compare_is_master_entity($entity))return new WP_Error('missing_object','Las altas/bajas de productos, categorías, páginas y posts siguen bloqueadas: esas capas todavía dependen de IDs locales. Los maestros sí se alinean de forma estricta por clave canónica.');
        $status=(string)($scan['status']??'');
        if('pro'===$source&&'only_staging'===$status){if($pr)return new WP_Error('stale_scan','El maestro ya apareció en PRO después del escaneo. Reescanea.');if(!$sr)return new WP_Error('stale_scan','El maestro ya desapareció de STAGING después del escaneo. Reescanea.');if(!hash_equals((string)$scan['hash_staging'],(string)$sr['hash']))return new WP_Error('stale_scan','El maestro exclusivo de STAGING cambió después del escaneo. Reescanea.');return true;}
        $source_row='pro'===$source?$pr:$sr;$dest_row='pro'===$source?$sr:$pr;if(!$source_row)return new WP_Error('missing_source_master','El maestro ya no existe en el origen. Reescanea.');if($dest_row)return new WP_Error('stale_scan','El maestro ya apareció en el destino después del escaneo. Reescanea.');$expected='pro'===$source?(string)$scan['hash_pro']:(string)$scan['hash_staging'];if(!hash_equals($expected,(string)$source_row['hash']))return new WP_Error('stale_scan','El maestro origen cambió después del escaneo. Reescanea.');return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_item_ajax' ) ) {
    function seo_environment_sync_item_ajax() {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Sin permisos.'],403);check_ajax_referer('seo_environment_compare','nonce');
        $entity=sanitize_key($_POST['entity']??'');$id=absint($_POST['object_id']??0);$source=sanitize_key($_POST['source']??'');$destination=sanitize_key($_POST['destination']??'');if(!isset(seo_environment_compare_entities()[$entity])||!$id)wp_send_json_error(['message'=>'Objeto no válido.'],400);
        $dir=seo_environment_sync_validate_direction($source,$destination);if(is_wp_error($dir))wp_send_json_error(['message'=>$dir->get_error_message()],400);$valid=seo_environment_sync_revalidate_item($entity,$id,$source);if(is_wp_error($valid))wp_send_json_error(['message'=>$valid->get_error_message()],409);
        $result=seo_environment_sync_apply_authoritative_item($source,$entity,$id);if(is_wp_error($result))wp_send_json_error(['message'=>$result->get_error_message()],500);global$wpdb;$wpdb->delete(seo_environment_compare_table(),['entity'=>$entity,'object_id'=>$id],['%s','%d']);wp_send_json_success(['message'=>'Alineado #'.$id.' con PRO. Vuelve a escanear para confirmar.']);
    }
}
add_action('wp_ajax_seo_environment_sync_item','seo_environment_sync_item_ajax');

if ( ! function_exists( 'seo_environment_sync_bulk_ajax' ) ) {
    function seo_environment_sync_bulk_ajax() {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Sin permisos.'],403);check_ajax_referer('seo_environment_compare','nonce');$entity=sanitize_key($_POST['entity']??'');$source=sanitize_key($_POST['source']??'');$destination=sanitize_key($_POST['destination']??'');if(!isset(seo_environment_compare_entities()[$entity]))wp_send_json_error(['message'=>'Entidad no válida.'],400);$dir=seo_environment_sync_validate_direction($source,$destination);if(is_wp_error($dir))wp_send_json_error(['message'=>$dir->get_error_message()],400);
        global$wpdb;$table=seo_environment_compare_table();$state=seo_environment_compare_get_state($entity);if('complete'!==($state['status']??''))wp_send_json_error(['message'=>'La capa no tiene un escaneo completo y valido. Reescanea antes de alinear.'],409);$statuses=seo_environment_compare_syncable_statuses($entity,$source);$placeholders=implode(',',array_fill(0,count($statuses),'%s'));$args=array_merge([$entity],$statuses);$sql=$wpdb->prepare("SELECT object_id FROM {$table} WHERE entity=%s AND status IN ({$placeholders}) ORDER BY object_id ASC LIMIT 5",$args);$ids=$wpdb->get_col($sql);if(!$ids)wp_send_json_success(['done'=>true,'updated'=>0,'remaining'=>0,'blocked'=>0,'errors'=>[]]);$mysqli=seo_environment_compare_open($source);if(is_wp_error($mysqli))wp_send_json_error(['message'=>$mysqli->get_error_message()],500);$updated=0;$errors=[];
        foreach($ids as$id){$id=absint($id);$valid=seo_environment_sync_revalidate_item($entity,$id,$source);if(is_wp_error($valid)){$message=$valid->get_error_message();$errors[]='#'.$id.': '.$message;$wpdb->update($table,['status'=>'blocked','summary'=>'Bloqueado: '.$message],['entity'=>$entity,'object_id'=>$id],['%s','%s'],['%s','%d']);continue;}$r=seo_environment_sync_apply_authoritative_item($source,$entity,$id,$mysqli);if(is_wp_error($r)){$message=$r->get_error_message();$errors[]='#'.$id.': '.$message;$wpdb->update($table,['status'=>'blocked','summary'=>'Bloqueado: '.$message],['entity'=>$entity,'object_id'=>$id],['%s','%s'],['%s','%d']);continue;}$wpdb->delete($table,['entity'=>$entity,'object_id'=>$id],['%s','%d']);$updated++;}
        @mysqli_close($mysqli);$args=array_merge([$entity],$statuses);$remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s AND status IN ({$placeholders})",$args));$blocked=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s AND status='blocked'",$entity));wp_send_json_success(['done'=>0===$remaining,'updated'=>$updated,'remaining'=>$remaining,'blocked'=>$blocked,'errors'=>$errors]);
    }
}
add_action('wp_ajax_seo_environment_sync_bulk','seo_environment_sync_bulk_ajax');

if ( ! function_exists( 'seo_environment_compare_render' ) ) {
    function seo_environment_compare_render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        seo_environment_compare_install();

        global $wpdb;
        $table    = seo_environment_compare_table();
        $entities = seo_environment_compare_entities();
        $current  = seo_environment_compare_current_env();
        $nonce    = wp_create_nonce( 'seo_environment_compare' );
        $semantic_direct_nonce = wp_create_nonce( 'seo_semantic_catalog_direct' );
        $full_mirror_nonce = wp_create_nonce( 'seo_environment_full_mirror' );
        $json_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'seo_environment_compare_export_json_current' ], admin_url( 'admin-post.php' ) ),
            'seo_environment_compare_export_json_current'
        );

        $group_labels = [
            'masters'        => 'Maestros / diccionarios',
            'general'        => 'Información general',
            'classification' => 'Clasificación / asignaciones',
        ];
        $scan_order  = array_keys( $entities );
        $scan_labels = [];
        foreach ( $entities as $scan_key => $scan_definition ) {
            $scan_labels[ $scan_key ] = (string) ( $scan_definition['label'] ?? $scan_key );
        }
        $running_scan = seo_environment_compare_running_entity();

        echo '<div class="seo-env-compare">';
        $align_all_disabled = ! empty( $running_scan ) || 'staging' !== $current;
        $align_all_title = 'staging' === $current
            ? 'Escanea, alinea y verifica cada capa de arriba abajo antes de continuar.'
            : 'La alineacion automatica PRO → STAGING solo se ejecuta desde STAGING.';
        $semantic_direct_available = class_exists( 'SEO_Semantic_Catalog_Direct_Sync' );
        $semantic_direct_disabled = ! empty( $running_scan ) || 'staging' !== $current || ! $semantic_direct_available;
        $semantic_direct_title = ! $semantic_direct_available
            ? 'No esta cargado el motor directo de Catalogo semantico.'
            : ( 'staging' === $current
                ? 'Simula y, tras confirmacion, deja maestros y asignaciones semanticas de STAGING como PRO usando claves portables.'
                : 'La alineacion directa de solo catalogo semantico se mantiene como herramienta local de STAGING.' );

        $full_mirror_available = class_exists( 'SEO_Environment_Mirror_Engine' );
        $full_mirror_configured = false;
        $full_mirror_policy = false;
        if ( $full_mirror_available && function_exists( 'seo_environment_db_settings' ) && function_exists( 'seo_environment_db_is_configured' ) ) {
            $pro_full_settings = (array) seo_environment_db_settings( 'pro' );
            $stg_full_settings = (array) seo_environment_db_settings( 'staging' );
            $full_mirror_configured = ! empty( $pro_full_settings['enabled'] )
                && ! empty( $stg_full_settings['enabled'] )
                && seo_environment_db_is_configured( $pro_full_settings )
                && seo_environment_db_is_configured( $stg_full_settings );
            $full_mirror_policy = 'disposable_mirror' === sanitize_key( (string) ( $stg_full_settings['staging_mode'] ?? '' ) ) || ! empty( $stg_full_settings['disposable_mirror'] );
        }
        $full_mirror_disabled = ! empty( $running_scan ) || ! $full_mirror_available || ! $full_mirror_configured || ! $full_mirror_policy;
        $full_mirror_title = ! $full_mirror_available
            ? 'No esta cargado MirrorEngine.'
            : ( ! $full_mirror_configured
                ? 'Configura y activa las conexiones PRO y STAGING. STAGING necesita permisos de escritura.'
                : ( ! $full_mirror_policy
                    ? 'Activa en la conexión STAGING la política «laboratorio desechable» antes de permitir una copia destructiva.'
                    : 'Puede lanzarse desde PRO o STAGING. MirrorEngine siempre lee PRO y escribe exclusivamente STAGING.' ) );

        echo '<div class="seo-env-toolbar"><button class="button button-primary seo-env-scan-all" '.disabled( ! empty( $running_scan ), true, false ).'>Hacer todos los chequeos</button><button class="button button-primary seo-env-full-mirror" '.disabled( $full_mirror_disabled, true, false ).' title="'.esc_attr( $full_mirror_title ).'">Copiar PRO → STAGING</button><button class="button seo-env-stop" '.disabled( empty( $running_scan ), true, false ).'>Parar proceso</button><span class="seo-env-process-status '.( $running_scan ? 'is-running' : 'is-stopped' ).'" data-process-status><strong>Estado:</strong> '.( $running_scan ? 'EN CURSO · '.esc_html( $running_scan['label'] ) : 'PARADO' ).'</span><a class="button" href="'.esc_url( $json_url ).'">Descargar JSON del informe</a><strong>PRO es la fuente de verdad</strong><span>“Copiar PRO → STAGING” reemplaza únicamente el perímetro gestionado por el Comparador y nunca modifica PRO.</span><span class="seo-env-global-progress" data-global-progress>'.( $running_scan ? 'En curso: '.esc_html( $running_scan['label'] ).'. Puedes detenerlo con “Parar proceso”.' : 'El chequeo general recorre todas las capas una a una, de arriba abajo.' ).'</span></div>';
        echo '<div class="seo-env-mirror-plan" data-mirror-plan hidden><h3>DRY RUN · Copiar PRO → STAGING</h3><pre data-mirror-plan-text></pre><div class="seo-env-actions"><button class="button button-primary seo-env-full-mirror-apply" disabled>Aplicar plan simulado</button><button class="button seo-env-full-mirror-download" disabled>Descargar JSON del DRY RUN</button></div></div>';
        echo '<div class="card seo-env-intro"><h2>Comparar PRO ↔ STAGING por capas <small>v'.esc_html( SEO_ENVIRONMENT_COMPARE_VERSION ).'</small></h2><p class="seo-env-route"><code>'.esc_html( SEO_ENVIRONMENT_COMPARE_SOURCE_FILE ).'</code></p><p><strong>Control del proceso:</strong> el estado superior indica claramente EN CURSO o PARADO. “Parar proceso” detiene el Comparador sin apagar el Gestor de workers global. Si ya hay una consulta/lote ejecutándose, se deja llegar al siguiente punto seguro y no se inicia otro lote.</p><p><strong>Fuente de verdad:</strong> PRO es siempre el origen y STAGING el único destino de escritura.</p><p><strong>Hacer todos los chequeos:</strong> compara todas las capas una a una sin modificar datos.</p><p><strong>Separación de responsabilidades:</strong> el Comparador solo detecta, explica y verifica (SCAN → DIFF → PLAN). El botón «Copiar PRO → STAGING» delega toda escritura destructiva en <strong>MirrorEngine</strong> (DRY RUN → APPLY → VERIFY).</p><p><strong>Copiar PRO → STAGING:</strong> puede lanzarse desde el WordPress de PRO o de STAGING. El primer clic ejecuta únicamente DRY RUN y deja el plan visible/descargable; APPLY requiere un segundo botón y confirmación explícita. Después MirrorEngine reemplaza exclusivamente el perímetro gestionado: productos, páginas, posts, categorías, FAQs, maestros, etiquetas, semántica, atributos y tablas propias relacionadas. Los IDs de PRO nunca identifican filas destino: se resuelven claves portables y se escriben IDs locales de STAGING. Crea lo que falte, actualiza diferencias y elimina extras de STAGING. Requiere que STAGING esté marcado explícitamente como laboratorio desechable. No copia toda la BBDD, no toca usuarios, pedidos ni configuración general, no copia imágenes y no modifica el conocimiento aprendido de Academia/Dependiente.</p><p><strong>Botones por capa:</strong> las escrituras puntuales quedan reservadas a maestros; para objetos y asignaciones se usa MirrorEngine para no depender de IDs locales. Las antiguas acciones globales de catálogo semántico y alineación por orden se han retirado de la barra principal para evitar duplicidad.</p><p><strong>Orden de comparación:</strong> 1) Maestros/diccionarios; 2) objetos generales; 3) clasificación/asignaciones. Las fechas e imágenes siguen excluidas de la decisión de igualdad.</p><p>Entorno actual detectado: <strong>'.esc_html( $current ? strtoupper( $current ) : 'NO IDENTIFICADO' ).'</strong>.</p></div>';

        echo '<style>
            .seo-env-toolbar{margin:0 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.seo-env-toolbar>strong{color:#2271b1}.seo-env-toolbar>span{color:#646970}.seo-env-stop{border-color:#d63638!important;color:#b32d2e!important}.seo-env-stop:disabled{border-color:#dcdcde!important;color:#a7aaad!important}.seo-env-process-status{display:inline-block;padding:5px 9px;border-radius:999px;font-weight:700}.seo-env-process-status strong{color:inherit}.seo-env-process-status.is-running{background:#fff8e5;color:#664d03}.seo-env-process-status.is-stopped{background:#f0f0f1;color:#50575e}.seo-env-global-progress{flex-basis:100%;padding:8px 10px;background:#f6f7f7;border-left:3px solid #2271b1;border-radius:4px;font-weight:600}
            .seo-env-mirror-plan{background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:6px;padding:14px;margin:0 0 18px}.seo-env-mirror-plan h3{margin:0 0 10px}.seo-env-mirror-plan pre{max-height:520px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:12px;border-radius:4px;font:12px/1.45 monospace}.seo-env-mirror-plan.is-blocked{border-left-color:#d63638}.seo-env-mirror-plan.is-warning{border-left-color:#dba617}
            .seo-env-intro{max-width:none;padding:18px;margin-bottom:18px}.seo-env-intro h2{margin-top:0}.seo-env-intro h2 small{font-weight:400;color:#646970}.seo-env-route{margin-top:-6px;color:#646970}
            .seo-env-group{margin:18px 0 26px}.seo-env-group>h2{margin:0 0 12px}.seo-env-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}
            .seo-env-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;min-width:0}.seo-env-kpi-head{display:flex;align-items:flex-start;gap:7px}.seo-env-kpi-head strong{line-height:1.3}.seo-env-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-top:4px;flex:0 0 auto}.seo-env-green{background:#00a32a}.seo-env-yellow{background:#dba617}.seo-env-red{background:#d63638}.seo-env-gray{background:#8c8f94}
            .seo-env-count{font-size:24px;font-weight:650;line-height:1.15;margin-top:4px}.seo-env-muted{color:#646970;font-size:12px}.seo-env-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 10px;margin:11px 0;padding:10px;background:#f6f7f7;border-radius:6px;font-size:12px}.seo-env-stats span{white-space:nowrap}.seo-env-staging-info{margin:8px 0 0;padding:7px 9px;border-left:3px solid #dba617;background:#fff8e5;color:#664d03;font-size:12px}
            .seo-env-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 8px}.seo-env-progress{display:block;min-height:17px;margin-top:5px;color:#646970;font-size:12px;overflow-wrap:anywhere}.seo-env-error{color:#b32d2e;margin:8px 0 0}.seo-env-details{margin-top:10px;border-top:1px solid #eee;padding-top:9px}.seo-env-details>summary{cursor:pointer}.seo-env-table-wrap{overflow:auto;margin-top:10px}.seo-env-table{width:100%;border-collapse:collapse;min-width:650px}.seo-env-table th,.seo-env-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.seo-env-readonly{display:inline-block;color:#646970;font-size:12px}.seo-env-status-pill{display:inline-block;padding:2px 6px;border-radius:999px;background:#f0f0f1;font-size:11px;color:#50575e}
            @media (max-width:782px){.seo-env-grid{grid-template-columns:1fr}.seo-env-actions .button{width:100%;text-align:center}.seo-env-stats{grid-template-columns:1fr 1fr}}
        </style>';

        foreach ( $group_labels as $group_key => $group_label ) {
            $group_entities = array_filter(
                $entities,
                static function ( $def ) use ( $group_key ) {
                    return ( $def['group'] ?? '' ) === $group_key;
                }
            );
            if ( ! $group_entities ) {
                continue;
            }

            echo '<section class="seo-env-group"><h2>'.esc_html( $group_label ).'</h2><div class="seo-env-grid">';

            foreach ( $group_entities as $key => $def ) {
                $s = seo_environment_compare_get_state( $key );
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE entity=%s ORDER BY CASE status WHEN 'different' THEN 0 WHEN 'only_pro' THEN 1 WHEN 'only_staging' THEN 2 ELSE 3 END, object_id ASC LIMIT 100",
                        $key
                    ),
                    ARRAY_A
                );
                $total_diff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE entity=%s", $key ) );
                $diff_total = (int) $s['different'] + (int) $s['only_pro'] + (int) $s['only_staging'];
                $master = seo_environment_compare_is_master_entity( $key );
                $syncable_count = (int) $s['different'] + ( $master ? ( (int) $s['only_pro'] + (int) $s['only_staging'] ) : 0 );

                $color = 'gray';
                if ( 'complete' === $s['status'] ) {
                    $color = $diff_total > 0 ? ( (int) $s['only_pro'] > 0 || (int) $s['only_staging'] > 0 ? 'red' : 'yellow' ) : 'green';
                } elseif ( 'error' === $s['status'] ) {
                    $color = 'red';
                }

                $scan_label = 'never' === $s['status'] ? 'Escanear' : 'Actualizar escaneo';
                $bulk_enabled = $master && empty( $running_scan ) && 'staging' === $current && 'complete' === $s['status'] && $syncable_count > 0;
                $bulk_title = 'staging' !== $current
                    ? 'La alineación con PRO se ejecuta desde STAGING.'
                    : ( $syncable_count > 0
                        ? ( $master ? 'Alinea este maestro con PRO: actualiza, crea faltantes y elimina exclusivos de STAGING.' : 'Esta capa se escribe con MirrorEngine mediante el botón global Copiar PRO → STAGING.' )
                        : 'No hay diferencias sincronizables desde PRO.' );

                echo '<article class="seo-env-kpi" data-kpi="'.esc_attr( $key ).'">';
                echo '<div class="seo-env-kpi-head"><span class="seo-env-dot seo-env-'.esc_attr( $color ).'"></span><strong>'.esc_html( $def['label'] ).'</strong></div>';
                echo '<div class="seo-env-count">'.esc_html( 'complete' === $s['status'] ? number_format_i18n( $diff_total ) : '—' ).'</div>';
                echo '<div class="seo-env-muted">PRO '.number_format_i18n( (int) $s['pro'] ).' · STAGING '.number_format_i18n( (int) $s['staging'] ).'</div>';

                if ( 'complete' === $s['status'] ) {
                    echo '<div class="seo-env-stats">';
                    echo '<span>Iguales <strong>'.number_format_i18n( (int) $s['same'] ).'</strong></span>';
                    echo '<span>Diferentes <strong>'.number_format_i18n( (int) $s['different'] ).'</strong></span>';
                    echo '<span>Solo PRO <strong>'.number_format_i18n( (int) $s['only_pro'] ).'</strong></span>';
                    echo '<span>Solo STAGING <strong>'.number_format_i18n( (int) $s['only_staging'] ).'</strong></span>';
                    echo '</div>';
                    if ( (int) $s['only_staging'] > 0 ) {
                        if ( $master ) {
                            echo '<div class="seo-env-staging-info"><strong>STAGING exclusivo: '.number_format_i18n( (int) $s['only_staging'] ).'.</strong> PRO es la referencia: estos maestros se eliminarán de STAGING al pulsar “Alinear con PRO”.</div>';
                        } else {
                            echo '<div class="seo-env-staging-info"><strong>STAGING exclusivo: '.number_format_i18n( (int) $s['only_staging'] ).'.</strong> MirrorEngine lo retirará al usar “Copiar PRO → STAGING” si no existe en PRO; nunca se decide por el ID local.</div>';
                        }
                    }
                } elseif ( 'never' === $s['status'] ) {
                    echo '<p class="seo-env-muted">Todavía no se ha escaneado esta capa.</p>';
                } elseif ( 'running' === $s['status'] ) {
                    echo '<p class="seo-env-muted">Escaneo en curso o pendiente del worker.</p>';
                } elseif ( 'stopped' === $s['status'] ) {
                    echo '<p class="seo-env-muted">Escaneo parado por el usuario. Puedes iniciarlo de nuevo cuando quieras.</p>';
                } elseif ( 'error' === $s['status'] ) {
                    echo '<p class="seo-env-error">'.esc_html( $s['error'] ).'</p>';
                }

                echo '<div class="seo-env-actions">';
                echo '<button class="button button-primary seo-env-scan" data-entity="'.esc_attr( $key ).'" '.disabled( ! empty( $running_scan ), true, false ).'>'.esc_html( $scan_label ).'</button>';
                $bulk_label = $master ? 'Alinear con PRO' : 'Usar Copiar PRO → STAGING';
                echo '<button class="button seo-env-bulk" data-entity="'.esc_attr( $key ).'" data-master="'.( $master ? '1' : '0' ).'" data-source="pro" data-destination="staging" '.disabled( ! $bulk_enabled, true, false ).' title="'.esc_attr( $bulk_title ).'">'.esc_html( $bulk_label ).( $syncable_count > 0 ? ' ('.number_format_i18n( $syncable_count ).')' : '' ).'</button>';
                echo '</div>';
                echo '<span class="seo-env-progress" data-progress="'.esc_attr( $key ).'"></span>';

                if ( $rows ) {
                    echo '<details class="seo-env-details"><summary><strong>Ver diferencias detectadas ('.number_format_i18n( $total_diff ).')</strong></summary><div class="seo-env-table-wrap"><table class="seo-env-table"><thead><tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Resumen</th><th>Acción</th></tr></thead><tbody>';

                    foreach ( $rows as $row ) {
                        $id = absint( $row['object_id'] );
                        $name = $row['name_pro'] ?: $row['name_staging'];
                        $status = (string) $row['status'];
                        $syncable_statuses = seo_environment_compare_syncable_statuses( $key, 'pro' );
                        $can_sync_from_pro = $master && empty( $running_scan ) && in_array( $status, $syncable_statuses, true ) && 'staging' === $current;

                        $note = '';
                        if ( 'only_staging' === $status && $master ) {
                            $note = 'Existe solo en STAGING. PRO es la referencia; puede eliminarse de STAGING.';
                        } elseif ( 'only_staging' === $status ) {
                            $note = 'Existe solo en STAGING. MirrorEngine lo retirará en la copia global si no existe en PRO, resolviendo identidad portable.';
                        } elseif ( 'only_pro' === $status && $master ) {
                            $note = 'Existe solo en PRO y puede crearse en STAGING.';
                        } elseif ( 'only_pro' === $status || 'only_staging' === $status ) {
                            $note = 'La escritura puntual queda bloqueada; usa Copiar PRO → STAGING para crear/eliminar con identidad portable.';
                        }

                        echo '<tr><td><code>'.( $master ? '—' : $id ).'</code></td><td>'.esc_html( $name ).'</td><td><span class="seo-env-status-pill">'.esc_html( $status ).'</span></td><td>'.esc_html( $row['summary'] ).( $note ? '<br><span class="seo-env-muted">'.esc_html( $note ).'</span>' : '' ).'</td><td>';

                        if ( $can_sync_from_pro ) {
                            $one_label = ( $master && 'only_staging' === $status ) ? 'Eliminar de STAGING' : 'PRO → STAGING';
                            echo '<button class="button button-small seo-env-sync-one" data-entity="'.esc_attr( $key ).'" data-id="'.$id.'" data-source="pro" data-destination="staging">'.esc_html( $one_label ).'</button>';
                        } elseif ( 'only_staging' === $status ) {
                            echo '<span class="seo-env-readonly">Usar copia global</span>';
                        } elseif ( 'staging' !== $current && in_array( $status, $syncable_statuses, true ) ) {
                            echo '<span class="seo-env-readonly">Usar copia global</span>';
                        } else {
                            echo '<span class="seo-env-readonly">Sin acción</span>';
                        }

                        echo '</td></tr>';
                    }

                    echo '</tbody></table></div>';
                    if ( $total_diff > 100 ) {
                        echo '<p class="seo-env-muted">Se muestran las primeras 100 diferencias. El escaneo conserva el inventario completo.</p>';
                    }
                    echo '</details>';
                }

                echo '</article>';
            }

            echo '</div></section>';
        }

        echo '<script>(function(){
const ajax='.wp_json_encode( admin_url( 'admin-ajax.php' ) ).',nonce='.wp_json_encode( $nonce ).',directNonce='.wp_json_encode( $semantic_direct_nonce ).',fullMirrorNonce='.wp_json_encode( $full_mirror_nonce ).',scanOrder='.wp_json_encode( $scan_order ).',scanLabels='.wp_json_encode( $scan_labels ).',masterEntities='.wp_json_encode( array_fill_keys( array_filter( $scan_order, 'seo_environment_compare_is_master_entity' ), true ) ).';
let uiBusy=false,stopRequested=false;
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
function post(data){data.nonce=nonce;return fetch(ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(data)}).then(async r=>{const t=await r.text();if(!t.trim())throw new Error("Respuesta vacía del servidor (HTTP "+r.status+").");try{return JSON.parse(t);}catch(e){throw new Error("Respuesta no JSON (HTTP "+r.status+"): "+t.slice(0,180));}});}
function postDirect(data){data.nonce=directNonce;return fetch(ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(data)}).then(async r=>{const t=await r.text();if(!t.trim())throw new Error("Respuesta vacía del servidor (HTTP "+r.status+").");try{return JSON.parse(t);}catch(e){throw new Error("Respuesta no JSON (HTTP "+r.status+"): "+t.slice(0,180));}});}
function postFullMirror(data){data.nonce=fullMirrorNonce;return fetch(ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(data)}).then(async r=>{const t=await r.text();if(!t.trim())throw new Error("Respuesta vacía del servidor (HTTP "+r.status+").");try{return JSON.parse(t);}catch(e){throw new Error("Respuesta no JSON (HTTP "+r.status+"): "+t.slice(0,180));}});}
function progress(entity,text){document.querySelectorAll("[data-progress=\""+entity+"\"]").forEach(n=>n.textContent=text||"");}
function globalProgress(text){document.querySelectorAll("[data-global-progress]").forEach(n=>n.textContent=text||"");}
const stopButton=document.querySelector(".seo-env-stop"),processStatus=document.querySelector("[data-process-status]");
function setProcessState(running,label){if(stopButton)stopButton.disabled=!running;if(processStatus){processStatus.classList.toggle("is-running",!!running);processStatus.classList.toggle("is-stopped",!running);processStatus.innerHTML="<strong>Estado:</strong> "+(running?("EN CURSO"+(label?" · "+label:"")):"PARADO");}}
function setDirectState(label){if(stopButton)stopButton.disabled=true;if(processStatus){processStatus.classList.add("is-running");processStatus.classList.remove("is-stopped");processStatus.innerHTML="<strong>Estado:</strong> EN CURSO · "+(label||"alineación directa");}}
function setBusy(on){uiBusy=!!on;document.querySelectorAll(".seo-env-scan,.seo-env-scan-all,.seo-env-align-all,.seo-env-semantic-direct,.seo-env-full-mirror,.seo-env-full-mirror-apply,.seo-env-bulk,.seo-env-sync-one").forEach(b=>{if(on){if(!b.hasAttribute("data-prebusy-disabled"))b.dataset.prebusyDisabled=b.disabled?"1":"0";b.disabled=true;}else{b.disabled=b.dataset.prebusyDisabled==="1";b.removeAttribute("data-prebusy-disabled");}});}
async function scanOne(entity,reset){if(stopRequested)throw new Error("Proceso parado por el usuario.");setProcessState(true,scanLabels[entity]||entity);let r=await post({action:"seo_environment_compare_scan",entity:entity,reset:reset?1:0});if(!r.success)throw new Error((r.data&&r.data.message)||"Error al encolar el escaneo");progress(entity,"En cola del Gestor de procesos…");while(true){if(stopRequested)throw new Error("Proceso parado por el usuario.");await sleep(3000);r=await post({action:"seo_environment_compare_scan",entity:entity,reset:0});if(!r.success)throw new Error((r.data&&r.data.message)||"Error consultando estado");const s=r.data.state||{},m=r.data.manager||{};progress(entity,"Worker: "+(s.processed||0)+" procesados · intentos "+(s.worker_attempts||0)+" · fase "+(s.last_worker_phase||"—")+" · candidatos PRO/STG "+(s.last_candidate_pro||0)+"/"+(s.last_candidate_staging||0)+" · lote "+(s.batch_size||1000)+" · "+(s.last_batch_seconds||0)+" s"+(m.status?" · gestor "+m.status:""));if(r.data.done){if(s.status==="error")throw new Error(s.error||"El worker terminó con error");if(s.status==="stopped")throw new Error("Proceso parado por el usuario.");if(s.status!=="complete")throw new Error("El escaneo no termino en estado completo ("+(s.status||"desconocido")+").");progress(entity,"Escaneo completo ✓");setProcessState(false,"");return s;}}}
function countsFor(entity,s){const different=Number(s&&s.different||0),onlyPro=Number(s&&s.only_pro||0),onlyStaging=Number(s&&s.only_staging||0),master=!!masterEntities[entity];return{different,onlyPro,onlyStaging,master,syncable:different+(master?onlyPro+onlyStaging:0),manual:master?0:onlyPro+onlyStaging,total:different+onlyPro+onlyStaging};}
async function alignLayer(entity){let total=0,lastBlocked=0;setProcessState(true,"alineando · "+(scanLabels[entity]||entity));while(true){if(stopRequested)throw new Error("Proceso parado por el usuario.");const r=await post({action:"seo_environment_sync_bulk",entity:entity,source:"pro",destination:"staging"});if(!r.success)throw new Error((r.data&&r.data.message)||"Error alineando la capa");total+=Number(r.data.updated||0);lastBlocked=Number(r.data.blocked||0);progress(entity,"Alineados "+total+" · pendientes "+Number(r.data.remaining||0)+" · bloqueados "+lastBlocked);if(lastBlocked>0)throw new Error("La capa tiene "+lastBlocked+" fila(s) bloqueada(s). Reescanea y revisa antes de continuar.");if(r.data.done)return total;if(Number(r.data.updated||0)===0&&Number(r.data.remaining||0)>0)throw new Error("No se pudo avanzar en la alineacion. Hay filas obsoletas o no validables.");await sleep(400);}}
document.querySelectorAll(".seo-env-scan").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||b.disabled)return;stopRequested=false;setBusy(true);setProcessState(true,scanLabels[b.dataset.entity]||b.dataset.entity);globalProgress("Chequeo individual: "+(scanLabels[b.dataset.entity]||b.dataset.entity)+". Puedes detenerlo con ‘Parar proceso’. ");try{await scanOne(b.dataset.entity,true);globalProgress("Chequeo completado. Actualizando pantalla…");setTimeout(()=>location.reload(),700);}catch(err){progress(b.dataset.entity,err.message);globalProgress(err.message);if(stopRequested){setProcessState(false,"");setTimeout(()=>location.reload(),500);}else{setProcessState(true,"estado por confirmar");setBusy(false);if(stopButton)stopButton.disabled=false;}}}));
const allButton=document.querySelector(".seo-env-scan-all");if(allButton)allButton.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||allButton.disabled)return;if(!window.confirm("Se ejecutarán todos los chequeos de arriba abajo, uno cada vez. Si una capa falla o se para, el proceso se detendrá en esa capa. ¿Continuar?"))return;stopRequested=false;setBusy(true);setProcessState(true,"chequeo general");try{for(let i=0;i<scanOrder.length;i++){if(stopRequested)throw new Error("Proceso parado por el usuario.");const entity=scanOrder[i],label=scanLabels[entity]||entity;globalProgress("Chequeo "+(i+1)+"/"+scanOrder.length+": "+label+". Esperando a que termine antes de pasar al siguiente…");await scanOne(entity,true);if(stopRequested)throw new Error("Proceso parado por el usuario.");if(i<scanOrder.length-1)await sleep(800);}setProcessState(false,"");globalProgress("Todos los chequeos completados. Actualizando pantalla…");setTimeout(()=>location.reload(),900);}catch(err){globalProgress("Chequeo general detenido: "+err.message);if(stopRequested){setProcessState(false,"");setTimeout(()=>location.reload(),500);}else{setProcessState(true,"estado por confirmar");setBusy(false);if(stopButton)stopButton.disabled=false;}}});
const fullMirrorButton=document.querySelector(".seo-env-full-mirror"),mirrorPlanBox=document.querySelector("[data-mirror-plan]"),mirrorPlanText=document.querySelector("[data-mirror-plan-text]"),mirrorApplyButton=document.querySelector(".seo-env-full-mirror-apply"),mirrorDownloadButton=document.querySelector(".seo-env-full-mirror-download");
let lastMirrorPreview=null;
function mirrorActionLine(label,a){a=a||{};return label+": PRO "+Number(a.source||a.pro||0)+" · STAGING "+Number(a.target||a.staging||0)+" · crear "+Number(a.create||0)+" · existentes resueltos "+Number(a.matched_existing||a.keep||0)+" · eliminar "+Number(a.remove||0);}
function renderMirrorPlan(d){lastMirrorPreview=d||{};if(!mirrorPlanBox||!mirrorPlanText)return;const a=d.actions||{},o=a.objects||{},ir=d.identity_resolution||{},counts=ir.counts||{},refs=d.reference_audit||{},warnings=d.warnings||[],conflicts=d.conflicts||[],lines=[];lines.push("DRY RUN REALIZADO · ESCRITURAS DE FILAS: "+Number(d.writes_performed||0));lines.push("");lines.push("ACCIONES PREVISTAS");["product","page","post"].forEach(t=>lines.push(mirrorActionLine(t.toUpperCase(),o[t]||{})));lines.push(mirrorActionLine("CATEGORIAS",a.categories||{}));lines.push(mirrorActionLine("PRODUCT_TAG",a.product_tag||{}));lines.push(mirrorActionLine("POST_TAG",a.post_tag||{}));const masters=a.masters||{};Object.keys(masters).forEach(k=>lines.push(mirrorActionLine("MAESTRO "+k,masters[k]||{})));lines.push("");lines.push("OBJETOS SIN CORRESPONDENCIA EN STAGING (SE CREARIAN)");(ir.unmatched_creates||[]).slice(0,50).forEach(x=>lines.push("  "+String(x.post_type||"")+" PRO#"+Number(x.source_id_audit||0)+" · "+String(x.preferred_identity||"")+"="+String(x.preferred_value||"")+" · "+String(x.title||"")));if((ir.unmatched_creates||[]).length>50)lines.push("  ... ver JSON para los "+(ir.unmatched_creates||[]).length+" objetos completos.");lines.push("");lines.push("EXTRAS DE STAGING (SE ELIMINARIAN)");(ir.staging_removals||[]).slice(0,50).forEach(x=>lines.push("  "+String(x.post_type||"")+" STAGING#"+Number(x.target_id_audit||0)+" · "+String(x.slug||"")+" · "+String(x.title||"")));if((ir.staging_removals||[]).length>50)lines.push("  ... ver JSON para los "+(ir.staging_removals||[]).length+" extras completos.");lines.push("");lines.push("RESOLUCION DE IDENTIDAD");Object.keys(counts).forEach(t=>{const c=counts[t]||{};lines.push(t+": catalog_uid="+Number(c.catalog_uid||0)+", proveedor+external="+Number(c.provider_external||0)+", SKU="+Number(c.sku||0)+", slug="+Number(c.slug||0)+", crear="+Number(c.create||0));});lines.push("Fallbacks: "+Number(ir.fallback_count||0));(ir.fallbacks||[]).slice(0,50).forEach(f=>lines.push("  "+String(f.post_type||"")+" PRO#"+Number(f.source_id_audit||0)+" -> STAGING#"+Number(f.target_id_audit||0)+" · preferida "+String(f.preferred_identity||"")+"="+String(f.preferred_value||"")+" · fallback "+String(f.matched_identity||"")+"="+String(f.matched_value||"")+" · "+String(f.title||"")));if(Number(ir.fallback_count||0)>50)lines.push("  ... ver JSON del DRY RUN para los "+Number(ir.fallback_count||0)+" fallbacks completos.");lines.push("");lines.push("ALTAS/BAJAS DE CATEGORIAS Y TAGS");[["CATEGORIA",a.categories],["PRODUCT_TAG",a.product_tag],["POST_TAG",a.post_tag]].forEach(pair=>{const label=pair[0],x=pair[1]||{};(x.create_items||[]).slice(0,30).forEach(v=>lines.push("  CREAR "+label+": "+String(v.path||v.slug||"")+" · "+String(v.name||"")));(x.remove_items||[]).slice(0,30).forEach(v=>lines.push("  ELIMINAR "+label+": "+String(v.path||v.slug||"")+" · "+String(v.name||"")));if((x.create_items||[]).length>30||(x.remove_items||[]).length>30)lines.push("  ... ver JSON para el detalle completo de "+label+"." );});lines.push("");lines.push("REFERENCIAS");(refs.remapped||[]).forEach(x=>lines.push("  REMAP: "+x));(refs.excluded||[]).forEach(x=>lines.push("  EXCLUIDO: "+x));const known=refs.known_postmeta_reference_rows||{};Object.keys(known).forEach(k=>lines.push("  postmeta "+k+": "+Number(known[k]||0)+" fila(s) con remapeo conocido"));lines.push("  Referencias opacas sospechosas: "+Number(refs.opaque_reference_count||0));(refs.opaque_reference_samples||[]).slice(0,30).forEach(x=>lines.push("    PRO post#"+Number(x.post_id||0)+" "+String(x.meta_key||"")+" = "+String(x.sample||"")));if(warnings.length){lines.push("");lines.push("WARNINGS ("+warnings.length+")");warnings.slice(0,100).forEach(x=>lines.push("  - "+x));if(warnings.length>100)lines.push("  ... ver JSON para todos los warnings.");}if(conflicts.length){lines.push("");lines.push("CONFLICTOS ("+Number(d.conflict_count||conflicts.length)+")");conflicts.slice(0,100).forEach(x=>lines.push("  - "+x));if(conflicts.length>100)lines.push("  ... ver JSON para todos los conflictos.");}lines.push("");lines.push(Number(d.conflict_count||0)>0?"NO-GO: APPLY BLOQUEADO.":"DRY RUN COMPLETO. Revisa el plan antes de autorizar APPLY.");mirrorPlanText.textContent=lines.join("\n");mirrorPlanBox.hidden=false;mirrorPlanBox.classList.toggle("is-blocked",Number(d.conflict_count||0)>0);mirrorPlanBox.classList.toggle("is-warning",Number(d.conflict_count||0)===0&&warnings.length>0);if(mirrorApplyButton)mirrorApplyButton.disabled=Number(d.conflict_count||0)>0;if(mirrorDownloadButton)mirrorDownloadButton.disabled=false;}
if(mirrorDownloadButton)mirrorDownloadButton.addEventListener("click",e=>{e.preventDefault();if(!lastMirrorPreview)return;const blob=new Blob([JSON.stringify(lastMirrorPreview,null,2)],{type:"application/json"}),url=URL.createObjectURL(blob),a=document.createElement("a");a.href=url;a.download="seo-mirror-dry-run-"+new Date().toISOString().replace(/[:.]/g,"-")+".json";document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);});
if(fullMirrorButton)fullMirrorButton.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||fullMirrorButton.disabled)return;const warning="COPIAR PRO → STAGING\n\nPrimero se ejecutara SOLO DRY RUN. No se escribira ninguna fila. El plan mostrara colisiones, fallback de identidad, altas/bajas y referencias remapeadas.\n\n¿Ejecutar simulacion?";if(!window.confirm(warning))return;stopRequested=false;lastMirrorPreview=null;if(mirrorPlanBox)mirrorPlanBox.hidden=true;if(mirrorApplyButton)mirrorApplyButton.disabled=true;if(mirrorDownloadButton)mirrorDownloadButton.disabled=true;setBusy(true);setDirectState("DRY RUN PRO → STAGING");globalProgress("Leyendo PRO y STAGING. DRY RUN sin escrituras…");try{const r=await postFullMirror({action:"seo_environment_full_mirror_preview"});if(!r.success)throw new Error((r.data&&r.data.message)||"Error en el DRY RUN");setBusy(false);setProcessState(false,"");renderMirrorPlan(r.data||{});globalProgress("DRY RUN terminado. No se ha escrito nada. Revisa el plan y descarga el JSON antes de APPLY.");}catch(err){globalProgress("DRY RUN detenido: "+err.message);setProcessState(false,"");setBusy(false);}});
if(mirrorApplyButton)mirrorApplyButton.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||mirrorApplyButton.disabled||!lastMirrorPreview)return;if(Number(lastMirrorPreview.conflict_count||0)>0)return;const warnings=(lastMirrorPreview.warnings||[]).length;const msg="APPLY · COPIAR PRO → STAGING\n\nEl DRY RUN tiene 0 conflictos"+(warnings?(" y "+warnings+" warning(s) que debes haber revisado"):"")+".\n\nEl servidor volvera a calcular el plan y bloqueara si PRO o STAGING han cambiado. STAGING es desechable dentro del perimetro gestionado.\n\n¿AUTORIZAS APPLY?";if(!window.confirm(msg))return;setBusy(true);setDirectState("APPLY PRO → STAGING");globalProgress("Revalidando ambas BBDD y aplicando el plan…");try{const r=await postFullMirror({action:"seo_environment_full_mirror_apply",confirm:"1"});if(!r.success)throw new Error((r.data&&r.data.message)||"Error en APPLY");const a=r.data||{};globalProgress((a.message||"MIRROR terminado.")+" Iniciando VERIFY completo del Comparador…");setProcessState(true,"VERIFY posterior");let verifyError=null;for(let i=0;i<scanOrder.length;i++){if(stopRequested){verifyError=new Error("VERIFY detenido por el usuario; APPLY ya estaba completado.");break;}const entity=scanOrder[i],label=scanLabels[entity]||entity;globalProgress("VERIFY "+(i+1)+"/"+scanOrder.length+": "+label+"…");try{await scanOne(entity,true);}catch(err){verifyError=err;break;}if(i<scanOrder.length-1)await sleep(500);}if(verifyError){window.alert("APPLY se completo, pero VERIFY se detuvo: "+verifyError.message+"\n\nLos datos no se revierten. Ejecuta de nuevo los chequeos.");globalProgress("APPLY completado; VERIFY incompleto: "+verifyError.message);setProcessState(false,"");setTimeout(()=>location.reload(),1200);return;}setProcessState(false,"");globalProgress("APPLY + VERIFY completos. Actualizando pantalla…");setTimeout(()=>location.reload(),1200);}catch(err){globalProgress("APPLY bloqueado/detenido: "+err.message);setProcessState(false,"");setBusy(false);}});
const semanticDirectButton=document.querySelector(".seo-env-semantic-direct");if(semanticDirectButton)semanticDirectButton.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||semanticDirectButton.disabled)return;const warning="Esta operación considera PRO la fuente de verdad para el CATÁLOGO SEMÁNTICO. Puede crear/actualizar/eliminar maestros exclusivos de STAGING y añadir/retirar asignaciones de etiquetas WC, semántica y atributos de productos, además de etiquetas/semántica de categorías. NO modifica Productos · General, Posts, Páginas, FAQs ni Academia/Dependiente aprendido. Los IDs de PRO son solo auditoría: el destino se resuelve por claves portables. Primero se hará una simulación sin escribir. ¿Simular ahora?";if(!window.confirm(warning))return;setBusy(true);setDirectState("simulando catálogo semántico");globalProgress("Leyendo PRO y simulando MIRROR contra STAGING…");try{let r=await postDirect({action:"seo_semantic_catalog_direct_preview"});if(!r.success)throw new Error((r.data&&r.data.message)||"Error en la simulación directa");const d=r.data||{},s=d.summary||{},conf=Number(d.conflict_count||0);let text="SIMULACIÓN PRO → STAGING\n\n"+"Maestros: crear "+Number(s.masters_create||0)+", actualizar "+Number(s.masters_update||0)+", retirar "+Number(s.masters_remove||0)+".\n"+"Relaciones: añadir "+Number(s.relationships_add||0)+", retirar "+Number(s.relationships_remove||0)+".\n"+"Objetos encontrados: "+Number(s.objects_found||0)+".\n"+"Productos afectados: "+Number(s.products_changed||0)+". Categorías afectadas: "+Number(s.categories_changed||0)+".\n"+"Conflictos: "+conf+".";if(conf>0){const list=(d.conflicts||[]).slice(0,12);text+="\n\nNO SE PUEDE APLICAR hasta resolver los conflictos."+(list.length?"\n\n"+list.join("\n"):"");window.alert(text);globalProgress("Simulación detenida: "+conf+" conflicto(s). No se ha escrito nada.");setProcessState(false,"");setBusy(false);return;}text+="\n\nSi confirmas, se volverá a leer PRO y se revalidará STAGING. Si cualquiera cambió desde esta simulación, se bloqueará sin escribir.\n\n¿Aplicar MIRROR ahora?";if(!window.confirm(text)){globalProgress("Simulación correcta; aplicación cancelada por el usuario. No se ha escrito nada.");setProcessState(false,"");setBusy(false);return;}setDirectState("aplicando catálogo semántico");globalProgress("Revalidando PRO/STAGING y aplicando MIRROR transaccional…");r=await postDirect({action:"seo_semantic_catalog_direct_apply",confirm:"1"});if(!r.success)throw new Error((r.data&&r.data.message)||"Error aplicando el catálogo semántico");const a=r.data||{},as=a.summary||{};globalProgress((a.message||"Alineación terminada.")+" Cambios: maestros "+(Number(as.masters_create||0)+Number(as.masters_update||0)+Number(as.masters_remove||0))+", relaciones +"+Number(as.relationships_add||0)+" / -"+Number(as.relationships_remove||0)+". Actualizando pantalla…");setProcessState(false,"");setTimeout(()=>location.reload(),1400);}catch(err){globalProgress("Alineación directa detenida: "+err.message);setProcessState(false,"");setBusy(false);}});
const alignAllButton=document.querySelector(".seo-env-align-all");if(alignAllButton)alignAllButton.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||alignAllButton.disabled)return;const warning="Se alineará STAGING con PRO de arriba abajo. Antes de cada capa se hará un escaneo fresco; después de escribir se volverá a escanear para verificar. En MAESTROS esto puede CREAR y ELIMINAR vocabulario, etiquetas, atributos, términos y alias exclusivos de STAGING, junto con relaciones dependientes. Estas bajas son una excepción operativa de STAGING y no pasan por DataLayer. Si aparece cualquier error, hash obsoleto, fila bloqueada o alta/baja editorial no automatizable, el proceso se detendrá. ¿Continuar?";if(!window.confirm(warning))return;stopRequested=false;setBusy(true);setProcessState(true,"alineacion ordenada");try{for(let i=0;i<scanOrder.length;i++){if(stopRequested)throw new Error("Proceso parado por el usuario.");const entity=scanOrder[i],label=scanLabels[entity]||entity;globalProgress("Alineacion "+(i+1)+"/"+scanOrder.length+": "+label+" · escaneo previo…");const before=await scanOne(entity,true),bc=countsFor(entity,before);if(bc.syncable>0){globalProgress("Alineacion "+(i+1)+"/"+scanOrder.length+": "+label+" · aplicando "+bc.syncable+" diferencia(s) permitida(s)…");await alignLayer(entity);if(stopRequested)throw new Error("Proceso parado por el usuario.");globalProgress("Alineacion "+(i+1)+"/"+scanOrder.length+": "+label+" · verificando resultado…");const after=await scanOne(entity,true),ac=countsFor(entity,after);if(ac.syncable>0)throw new Error(label+" sigue teniendo "+ac.syncable+" diferencia(s) sincronizable(s) despues de verificar.");if(ac.manual>0)throw new Error(label+" queda con "+ac.manual+" alta(s)/baja(s) editoriales no automatizables por ID local. Revision manual requerida.");progress(entity,"Alineado y verificado ✓");}else{if(bc.manual>0)throw new Error(label+" tiene "+bc.manual+" alta(s)/baja(s) editoriales no automatizables por ID local. Revision manual requerida.");progress(entity,"Sin diferencias · verificado ✓");}if(i<scanOrder.length-1)await sleep(600);}setProcessState(false,"");globalProgress("Alineacion ordenada completada: todas las capas han quedado verificadas. Actualizando pantalla…");setTimeout(()=>location.reload(),1000);}catch(err){globalProgress("Alineacion ordenada detenida: "+err.message);if(stopRequested){setProcessState(false,"");setTimeout(()=>location.reload(),500);}else{setProcessState(false,"");setBusy(false);}}});
if(stopButton)stopButton.addEventListener("click",async e=>{e.preventDefault();if(stopButton.disabled)return;stopRequested=true;stopButton.disabled=true;globalProgress("Solicitando parada segura del Comparador…");try{const r=await post({action:"seo_environment_compare_stop"});if(!r.success)throw new Error((r.data&&r.data.message)||"No se pudo parar el proceso");setProcessState(false,"");globalProgress((r.data&&r.data.message)||"Proceso parado.");setTimeout(()=>location.reload(),650);}catch(err){stopRequested=false;stopButton.disabled=false;globalProgress("No se pudo confirmar la parada: "+err.message);}});
document.querySelectorAll(".seo-env-sync-one").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||b.disabled)return;b.disabled=true;progress(b.dataset.entity,"Alineando #"+b.dataset.id+" con PRO…");const r=await post({action:"seo_environment_sync_item",entity:b.dataset.entity,object_id:b.dataset.id,source:"pro",destination:"staging"});if(!r.success){progress(b.dataset.entity,(r.data&&r.data.message)||"Error");b.disabled=false;return;}stopRequested=false;setBusy(true);setProcessState(true,scanLabels[b.dataset.entity]||b.dataset.entity);progress(b.dataset.entity,"Actualizado. Verificando…");try{await scanOne(b.dataset.entity,true);setTimeout(()=>location.reload(),700);}catch(err){progress(b.dataset.entity,err.message);if(stopRequested){setProcessState(false,"");setTimeout(()=>location.reload(),500);}else{setBusy(false);if(stopButton)stopButton.disabled=false;}}}));
document.querySelectorAll(".seo-env-bulk").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(uiBusy||b.disabled)return;const isMaster=b.dataset.master==="1";const msg=isMaster?"Vas a ALINEAR este maestro con PRO. PRO es la fuente de verdad: se actualizarán las diferencias, se crearán en STAGING los maestros que existan solo en PRO y se ELIMINARÁN de STAGING los maestros que no existan en PRO, junto con sus asignaciones dependientes. ¿Continuar?":"Vas a sincronizar esta capa PRO → STAGING. Se actualizarán las diferencias desde PRO. Los objetos exclusivos de STAGING no se borran automáticamente porque estas capas todavía usan IDs locales. ¿Continuar?";if(!window.confirm(msg))return;stopRequested=false;setBusy(true);let total=0;progress(b.dataset.entity,(b.dataset.master==="1"?"Alineando maestro con PRO por lotes…":"Sincronizando PRO → STAGING por lotes…"));while(true){const r=await post({action:"seo_environment_sync_bulk",entity:b.dataset.entity,source:"pro",destination:"staging"});if(!r.success){progress(b.dataset.entity,(r.data&&r.data.message)||"Error");setBusy(false);return;}total+=Number(r.data.updated||0);progress(b.dataset.entity,"Alineados "+total+" · pendientes "+Number(r.data.remaining||0)+" · bloqueados "+Number(r.data.blocked||0));if(r.data.done)break;if(Number(r.data.updated||0)===0&&Number(r.data.remaining||0)>0){progress(b.dataset.entity,"Hay filas bloqueadas porque cambiaron después del escaneo o requieren revisión. Reescanea.");setBusy(false);return;}await sleep(400);}setProcessState(true,scanLabels[b.dataset.entity]||b.dataset.entity);progress(b.dataset.entity,"Sincronización terminada. Verificando…");try{await scanOne(b.dataset.entity,true);setTimeout(()=>location.reload(),700);}catch(err){progress(b.dataset.entity,err.message);if(stopRequested){setProcessState(false,"");setTimeout(()=>location.reload(),500);}else{setBusy(false);if(stopButton)stopButton.disabled=false;}}}));
})();</script>';
        echo '</div>';
    }
}
