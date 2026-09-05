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
 * - una version mas antigua nunca pisa una mas reciente. Las diferencias con
 *   la misma fecha quedan como conflicto y requieren revision;
 * - las imagenes se excluyen siempre de comparacion y sincronizacion: pueden
 *   vivir en hosts externos de proveedores y no son identidad de contenido.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_environment_compare_entities' ) ) {
    function seo_environment_compare_entities() {
        return [
            'products'   => [ 'label' => 'Productos',   'singular' => 'producto',  'post_type' => 'product' ],
            'categories' => [ 'label' => 'Categorías',  'singular' => 'categoría' ],
            'pages'      => [ 'label' => 'Páginas',     'singular' => 'página',    'post_type' => 'page' ],
            'posts'      => [ 'label' => 'Posts',       'singular' => 'post',      'post_type' => 'post' ],
            'faqs'       => [ 'label' => 'FAQs',        'singular' => 'FAQ' ],
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
                'newer_pro'     => 0,
                'newer_staging' => 0,
                'conflict'      => 0,
                'started_at'    => 0,
                'finished_at'   => 0,
                'error'         => '',
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

        if ( in_array( $entity, [ 'products', 'pages', 'posts' ], true ) ) {
            $post_type = [ 'products' => 'product', 'pages' => 'page', 'posts' => 'post' ][ $entity ];
            $sql = "SELECT ID FROM `{$prefix}posts` WHERE post_type='" . mysqli_real_escape_string( $mysqli, $post_type ) . "' AND post_status<>'trash' AND ID>{$cursor} ORDER BY ID ASC LIMIT {$limit}";
        } elseif ( 'categories' === $entity ) {
            $sql = "SELECT term_id AS ID FROM `{$prefix}term_taxonomy` WHERE taxonomy='product_cat' AND term_id>{$cursor} ORDER BY term_id ASC LIMIT {$limit}";
        } else {
            $sql = "SELECT id AS ID FROM `{$prefix}seo_faq` WHERE id>{$cursor} ORDER BY id ASC LIMIT {$limit}";
        }

        $rows = seo_environment_compare_query_rows( $mysqli, $sql );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        return array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'ID' ) ) ) );
    }
}

if ( ! function_exists( 'seo_environment_compare_merge_date' ) ) {
    function seo_environment_compare_merge_date( $a, $b ) {
        $a = trim( (string) $a );
        $b = trim( (string) $b );
        if ( '' === $a ) return $b;
        if ( '' === $b ) return $a;
        return strtotime( $b . ' UTC' ) > strtotime( $a . ' UTC' ) ? $b : $a;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_post_snapshots' ) ) {
    function seo_environment_compare_fetch_post_snapshots( $mysqli, $prefix, $post_type, array $ids, $product = false ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );
        $post_type_sql = mysqli_real_escape_string( $mysqli, $post_type );

        // Alcance editorial deliberadamente reducido: titulo, excerpt y description.
        // Slug, estado, fechas editoriales, layout, metadatos comerciales e imagenes
        // no forman parte de la identidad que compara este panel.
        $sql = "SELECT ID,post_title,post_modified_gmt,
                       SHA2(COALESCE(post_title,''),256) AS base_hash,
                       SHA2(COALESCE(post_excerpt,''),256) AS excerpt_hash,
                       SHA2(COALESCE(post_content,''),256) AS description_hash
                FROM `{$prefix}posts`
                WHERE post_type='{$post_type_sql}' AND post_status<>'trash' AND ID IN ({$id_sql})";
        $rows = seo_environment_compare_query_rows( $mysqli, $sql );
        if ( is_wp_error( $rows ) ) return $rows;

        $out = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['ID'] );
            $out[ $id ] = [
                'id'         => $id,
                'name'       => (string) $row['post_title'],
                'modified'   => (string) $row['post_modified_gmt'],
                'components' => [
                    'base'        => (string) $row['base_hash'],
                    'excerpt'     => (string) $row['excerpt_hash'],
                    'description' => (string) $row['description_hash'],
                ],
            ];
        }
        if ( ! $out ) return $out;

        // Productos: solo categoria asociada y etiquetas WooCommerce.
        // Paginas/posts: solo etiquetas WordPress si la taxonomia existe.
        $taxonomy_groups = $product
            ? [ 'categories' => [ 'product_cat' ], 'tags' => [ 'product_tag' ] ]
            : [ 'tags' => [ 'post_tag' ] ];

        foreach ( $taxonomy_groups as $component => $taxonomies ) {
            $quoted_tax = array_map(
                static function ( $v ) use ( $mysqli ) {
                    return "'" . mysqli_real_escape_string( $mysqli, $v ) . "'";
                },
                $taxonomies
            );
            $tax_sql = "SELECT tr.object_id,
                               SHA2(GROUP_CONCAT(CONCAT(tt.taxonomy,':',t.slug) ORDER BY tt.taxonomy,t.slug SEPARATOR '|'),256) AS row_hash
                        FROM `{$prefix}term_relationships` tr
                        JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                        JOIN `{$prefix}terms` t ON t.term_id=tt.term_id
                        WHERE tr.object_id IN ({$id_sql})
                          AND tt.taxonomy IN (" . implode( ',', $quoted_tax ) . ")
                        GROUP BY tr.object_id";
            $tax_rows = seo_environment_compare_query_rows( $mysqli, $tax_sql );
            if ( ! is_wp_error( $tax_rows ) ) {
                foreach ( $tax_rows as $row ) {
                    $id = absint( $row['object_id'] );
                    if ( isset( $out[ $id ] ) ) $out[ $id ]['components'][ $component ] = (string) $row['row_hash'];
                }
            }
        }

        if ( ! $product ) {
            // Etiquetas editoriales guardadas en seo_nodes. Excluimos explicitamente
            // excerpt/description/ambito: el contenido ya se compara desde wp_posts
            // y el ambito no forma parte del criterio solicitado.
            $node_sql = "SELECT object_id,
                                SHA2(GROUP_CONCAT(CONCAT(seo_role,':',SHA2(COALESCE(keywords,''),256)) ORDER BY seo_role,id SEPARATOR '|'),256) AS row_hash
                         FROM `{$prefix}seo_nodes`
                         WHERE object_type='{$post_type_sql}'
                           AND object_id IN ({$id_sql})
                           AND status=1
                           AND seo_role NOT IN ('excerpt','description','ambito')
                         GROUP BY object_id";
            $node_rows = seo_environment_compare_query_rows( $mysqli, $node_sql );
            if ( ! is_wp_error( $node_rows ) ) {
                foreach ( $node_rows as $row ) {
                    $id = absint( $row['object_id'] );
                    if ( isset( $out[ $id ] ) ) $out[ $id ]['components']['seo_tags'] = (string) $row['row_hash'];
                }
            }
        }

        if ( $product ) {
            // Clasificacion/etiquetas semanticas canonicas del producto.
            $sem_sql = "SELECT ov.object_id,
                               SHA2(GROUP_CONCAT(CONCAT(v.semantic_group,':',v.slug) ORDER BY v.semantic_group,v.slug SEPARATOR '|'),256) AS row_hash
                        FROM `{$prefix}seo_object_vocabulary` ov
                        JOIN `{$prefix}seo_vocabulary` v ON v.id=ov.vocabulary_id
                        WHERE ov.object_type='product' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql})
                        GROUP BY ov.object_id";
            $sem_rows = seo_environment_compare_query_rows( $mysqli, $sem_sql );
            if ( ! is_wp_error( $sem_rows ) ) {
                foreach ( $sem_rows as $row ) {
                    $id = absint( $row['object_id'] );
                    if ( isset( $out[ $id ] ) ) $out[ $id ]['components']['semantic'] = (string) $row['row_hash'];
                }
            }

            // Atributos canonicos. No se compara _product_attributes ni ningun
            // metadato de imagen de WooCommerce.
            $attr_sql = "SELECT pa.product_id,
                                SHA2(GROUP_CONCAT(CONCAT(a.slug,':',COALESCE(t.slug,''),':',SHA2(CONCAT_WS('|',COALESCE(pa.valor_texto,''),COALESCE(pa.valor_numero,''),COALESCE(pa.valor_numero_max,''),COALESCE(pa.unidad,''),COALESCE(pa.valor_original,'')),256),':',pa.orden) ORDER BY a.slug,pa.orden,pa.id SEPARATOR '|'),256) AS row_hash
                         FROM `{$prefix}sql_product_atributos` pa
                         JOIN `{$prefix}sql_atributos` a ON a.id=pa.atributo_id
                         LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=pa.termino_id
                         WHERE pa.product_id IN ({$id_sql})
                         GROUP BY pa.product_id";
            $attr_rows = seo_environment_compare_query_rows( $mysqli, $attr_sql );
            if ( ! is_wp_error( $attr_rows ) ) {
                foreach ( $attr_rows as $row ) {
                    $id = absint( $row['product_id'] );
                    if ( isset( $out[ $id ] ) ) $out[ $id ]['components']['attributes'] = (string) $row['row_hash'];
                }
            }
        }

        foreach ( $out as &$item ) {
            ksort( $item['components'] );
            $item['hash'] = seo_environment_compare_hash( $item['components'] );
        }
        unset( $item );
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_category_snapshots' ) ) {
    function seo_environment_compare_fetch_category_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql = seo_environment_compare_sql_ids( $ids );

        // En categorias el criterio es exclusivamente nombre + excerpt +
        // description + etiquetas SEO. Slug, parent, termmeta, relaciones,
        // semantica e imagen destacada quedan fuera del hash.
        $sql = "SELECT t.term_id,t.name,
                       SHA2(COALESCE(t.name,''),256) AS base_hash
                FROM `{$prefix}terms` t
                JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id
                WHERE tt.taxonomy='product_cat' AND t.term_id IN ({$id_sql})";
        $rows = seo_environment_compare_query_rows( $mysqli, $sql );
        if ( is_wp_error( $rows ) ) return $rows;

        $out = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['term_id'] );
            $out[ $id ] = [
                'id'         => $id,
                'name'       => (string) $row['name'],
                'modified'   => '',
                'components' => [ 'base' => (string) $row['base_hash'] ],
            ];
        }
        if ( ! $out ) return $out;

        // La fecha de sincronizacion se usa solo para decidir direccion; nunca
        // entra en el hash de contenido.
        $date_sql = "SELECT term_id,MAX(meta_value) AS sync_date
                     FROM `{$prefix}termmeta`
                     WHERE term_id IN ({$id_sql}) AND meta_key='_seo_sync_modified_gmt'
                     GROUP BY term_id";
        $date_rows = seo_environment_compare_query_rows( $mysqli, $date_sql );
        if ( ! is_wp_error( $date_rows ) ) {
            foreach ( $date_rows as $row ) {
                $id = absint( $row['term_id'] );
                if ( isset( $out[ $id ] ) ) $out[ $id ]['modified'] = (string) $row['sync_date'];
            }
        }

        $nodes_sql = "SELECT object_id,
                             SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='excerpt' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) AS excerpt_hash,
                             SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='description' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) AS description_hash,
                             SHA2(COALESCE(GROUP_CONCAT(CASE WHEN seo_role='category' AND status=1 THEN SHA2(COALESCE(keywords,''),256) END ORDER BY id SEPARATOR '|'),''),256) AS tags_hash,
                             MAX(CASE WHEN status=1 AND seo_role IN ('category','excerpt','description') THEN updated_at ELSE NULL END) AS max_date
                      FROM `{$prefix}seo_nodes`
                      WHERE object_type='category'
                        AND object_id IN ({$id_sql})
                        AND seo_role IN ('category','excerpt','description')
                      GROUP BY object_id";
        $node_rows = seo_environment_compare_query_rows( $mysqli, $nodes_sql );
        if ( ! is_wp_error( $node_rows ) ) {
            foreach ( $node_rows as $row ) {
                $id = absint( $row['object_id'] );
                if ( ! isset( $out[ $id ] ) ) continue;
                $out[ $id ]['components']['excerpt'] = (string) $row['excerpt_hash'];
                $out[ $id ]['components']['description'] = (string) $row['description_hash'];
                $out[ $id ]['components']['tags'] = (string) $row['tags_hash'];
                if ( '' === $out[ $id ]['modified'] ) $out[ $id ]['modified'] = (string) $row['max_date'];
            }
        }

        // Las etiquetas semanticas canonicas de categoria tambien cuentan como
        // etiquetas editoriales. Jerarquia, relaciones e imagenes quedan fuera.
        $sem_sql = "SELECT ov.object_id,
                           SHA2(GROUP_CONCAT(CONCAT(v.semantic_group,':',v.slug) ORDER BY v.semantic_group,v.slug SEPARATOR '|'),256) AS row_hash
                    FROM `{$prefix}seo_object_vocabulary` ov
                    JOIN `{$prefix}seo_vocabulary` v ON v.id=ov.vocabulary_id
                    WHERE ov.object_type='product_cat'
                      AND ov.status=1 AND v.active=1
                      AND ov.object_id IN ({$id_sql})
                    GROUP BY ov.object_id";
        $sem_rows = seo_environment_compare_query_rows( $mysqli, $sem_sql );
        if ( ! is_wp_error( $sem_rows ) ) {
            foreach ( $sem_rows as $row ) {
                $id = absint( $row['object_id'] );
                if ( isset( $out[ $id ] ) ) $out[ $id ]['components']['semantic'] = (string) $row['row_hash'];
            }
        }

        // Las categorias sin ningun nodo deben tener la misma firma vacia que
        // una categoria cuyo entorno tampoco tiene esos campos.
        $empty_hash = hash( 'sha256', '' );
        foreach ( $out as &$item ) {
            foreach ( [ 'excerpt', 'description', 'tags' ] as $component ) {
                if ( ! isset( $item['components'][ $component ] ) ) $item['components'][ $component ] = $empty_hash;
            }
            ksort( $item['components'] );
            $item['hash'] = seo_environment_compare_hash( $item['components'] );
        }
        unset( $item );
        return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_faq_snapshots' ) ) {
    function seo_environment_compare_fetch_faq_snapshots( $mysqli, $prefix, array $ids ) {
        $id_sql=seo_environment_compare_sql_ids($ids);
        $sql="SELECT id,object_type,object_id,updated_at,
                     SHA2(CONCAT_WS(CHAR(31),SHA2(COALESCE(question,''),256),SHA2(COALESCE(answer,''),256)),256) AS content_hash,
                     SHA2(CONCAT_WS(CHAR(31),sort_order,active),256) AS settings_hash,
                     SHA2(CONCAT_WS(CHAR(31),object_type,object_id),256) AS target_hash
              FROM `{$prefix}seo_faq` WHERE id IN ({$id_sql})";
        $rows=seo_environment_compare_query_rows($mysqli,$sql); if(is_wp_error($rows))return $rows;
        $out=[];foreach($rows as $row){$id=absint($row['id']);$components=['content'=>(string)$row['content_hash'],'settings'=>(string)$row['settings_hash'],'target'=>(string)$row['target_hash']];$out[$id]=['id'=>$id,'name'=>'FAQ #'.$id.' · objeto '.absint($row['object_type']).'/'.absint($row['object_id']),'modified'=>(string)$row['updated_at'],'components'=>$components,'hash'=>seo_environment_compare_hash($components)];}return $out;
    }
}

if ( ! function_exists( 'seo_environment_compare_fetch_snapshots' ) ) {
    function seo_environment_compare_fetch_snapshots( $mysqli, $env, $entity, array $ids ) {
        $prefix=seo_environment_compare_db_prefix($env);
        if('products'===$entity)return seo_environment_compare_fetch_post_snapshots($mysqli,$prefix,'product',$ids,true);
        if('pages'===$entity)return seo_environment_compare_fetch_post_snapshots($mysqli,$prefix,'page',$ids,false);
        if('posts'===$entity)return seo_environment_compare_fetch_post_snapshots($mysqli,$prefix,'post',$ids,false);
        if('categories'===$entity)return seo_environment_compare_fetch_category_snapshots($mysqli,$prefix,$ids);
        return seo_environment_compare_fetch_faq_snapshots($mysqli,$prefix,$ids);
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
        foreach($keys as $key){if((string)($a[$key]??'')!==(string)($b[$key]??''))$diff[]=$labels[$key]??$key;}
        return $diff;
    }
}

if ( ! function_exists( 'seo_environment_compare_newer' ) ) {
    function seo_environment_compare_newer( $pro_date, $staging_date ) {
        $p = trim( (string) $pro_date );
        $s = trim( (string) $staging_date );
        if ( $p !== '' && $s !== '' ) {
            $cmp = strcmp( $p, $s );
            if ( $cmp > 0 ) return 'pro';
            if ( $cmp < 0 ) return 'staging';
        }
        return 'conflict';
    }
}

if ( ! function_exists( 'seo_environment_compare_store_diff' ) ) {
    function seo_environment_compare_store_diff( $entity, $id, $pro, $staging, $status, $newer, array $diffs ) {
        global $wpdb;$table=seo_environment_compare_table();
        $wpdb->replace($table,[
            'entity'=>$entity,'object_id'=>absint($id),'status'=>$status,'newer_env'=>$newer,
            'name_pro'=>(string)($pro['name']??''),'name_staging'=>(string)($staging['name']??''),
            'modified_pro'=>!empty($pro['modified'])?$pro['modified']:null,'modified_staging'=>!empty($staging['modified'])?$staging['modified']:null,
            'hash_pro'=>(string)($pro['hash']??''),'hash_staging'=>(string)($staging['hash']??''),
            'summary'=>implode(', ',$diffs),'details_json'=>wp_json_encode(['differences'=>$diffs]),'scanned_at'=>current_time('mysql',true),
        ],['%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
    }
}

if ( ! function_exists( 'seo_environment_compare_scan_ajax' ) ) {
    function seo_environment_compare_scan_ajax() {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Sin permisos.'],403);
        check_ajax_referer('seo_environment_compare','nonce');
        $entity=sanitize_key($_POST['entity']??'');$entities=seo_environment_compare_entities();
        if(!isset($entities[$entity]))wp_send_json_error(['message'=>'Entidad no válida.'],400);
        if(!seo_environment_compare_install())wp_send_json_error(['message'=>'No se pudo preparar la tabla local de comparación.'],500);

        $reset=!empty($_POST['reset']);$state=seo_environment_compare_get_state($entity);
        if($reset||!in_array($state['status'],['running','complete'],true)){
            global $wpdb;$wpdb->delete(seo_environment_compare_table(),['entity'=>$entity],['%s']);
            $state=['status'=>'running','cursor'=>0,'processed'=>0,'pro'=>0,'staging'=>0,'same'=>0,'different'=>0,'only_pro'=>0,'only_staging'=>0,'newer_pro'=>0,'newer_staging'=>0,'conflict'=>0,'started_at'=>time(),'finished_at'=>0,'error'=>''];
        } elseif('complete'===$state['status']&&!$reset){wp_send_json_success(['done'=>true,'state'=>$state]);}

        $pro=seo_environment_compare_open('pro');$stg=seo_environment_compare_open('staging');
        if(is_wp_error($pro)||is_wp_error($stg)){
            if($pro instanceof mysqli)@mysqli_close($pro);if($stg instanceof mysqli)@mysqli_close($stg);
            $message=is_wp_error($pro)?$pro->get_error_message():$stg->get_error_message();$state['status']='error';$state['error']=$message;seo_environment_compare_set_state($entity,$state);wp_send_json_error(['message'=>$message,'state'=>$state],500);
        }
        $cursor=absint($state['cursor']);$candidate_limit=220;$batch_limit=120;
        $pro_ids=seo_environment_compare_candidate_ids($pro,seo_environment_compare_db_prefix('pro'),$entity,$cursor,$candidate_limit);
        $stg_ids=seo_environment_compare_candidate_ids($stg,seo_environment_compare_db_prefix('staging'),$entity,$cursor,$candidate_limit);
        if(is_wp_error($pro_ids)||is_wp_error($stg_ids)){$err=is_wp_error($pro_ids)?$pro_ids:$stg_ids;@mysqli_close($pro);@mysqli_close($stg);$state['status']='error';$state['error']=$err->get_error_message();seo_environment_compare_set_state($entity,$state);wp_send_json_error(['message'=>$state['error']],500);}
        $ids=array_values(array_unique(array_merge($pro_ids,$stg_ids)));sort($ids,SORT_NUMERIC);$ids=array_slice($ids,0,$batch_limit);
        if(!$ids){@mysqli_close($pro);@mysqli_close($stg);$state['status']='complete';$state['finished_at']=time();seo_environment_compare_set_state($entity,$state);wp_send_json_success(['done'=>true,'state'=>$state]);}
        $pro_rows=seo_environment_compare_fetch_snapshots($pro,'pro',$entity,$ids);$stg_rows=seo_environment_compare_fetch_snapshots($stg,'staging',$entity,$ids);@mysqli_close($pro);@mysqli_close($stg);
        if(is_wp_error($pro_rows)||is_wp_error($stg_rows)){$err=is_wp_error($pro_rows)?$pro_rows:$stg_rows;$state['status']='error';$state['error']=$err->get_error_message();seo_environment_compare_set_state($entity,$state);wp_send_json_error(['message'=>$state['error']],500);}
        global $wpdb;$table=seo_environment_compare_table();
        foreach($ids as $id){$p=$pro_rows[$id]??null;$s=$stg_rows[$id]??null;$state['processed']++;
            if($p)$state['pro']++;if($s)$state['staging']++;
            if(!$p||!$s){$status=$p?'only_pro':'only_staging';$state[$status]++;seo_environment_compare_store_diff($entity,$id,$p?:[],$s?:[],$status,'',[$p?'solo existe en PRO':'solo existe en STAGING']);continue;}
            if(hash_equals((string)$p['hash'],(string)$s['hash'])){$state['same']++;$wpdb->delete($table,['entity'=>$entity,'object_id'=>$id],['%s','%d']);continue;}
            $state['different']++;$newer=seo_environment_compare_newer($p['modified'],$s['modified']);if('pro'===$newer)$state['newer_pro']++;elseif('staging'===$newer)$state['newer_staging']++;else$state['conflict']++;
            $diffs=seo_environment_compare_diff_components($p['components'],$s['components']);seo_environment_compare_store_diff($entity,$id,$p,$s,'different',$newer,$diffs);
        }
        $state['cursor']=max($ids);$state['status']='running';seo_environment_compare_set_state($entity,$state);
        wp_send_json_success(['done'=>false,'state'=>$state]);
    }
}
add_action('wp_ajax_seo_environment_compare_scan','seo_environment_compare_scan_ajax');

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
        $type=mysqli_real_escape_string($mysqli,$object_type);$id=absint($object_id);return seo_environment_compare_query_rows($mysqli,"SELECT v.semantic_group,v.slug,ov.updated_at FROM `{$prefix}seo_object_vocabulary` ov JOIN `{$prefix}seo_vocabulary` v ON v.id=ov.vocabulary_id WHERE ov.object_type='{$type}' AND ov.object_id={$id} AND ov.status=1 AND v.active=1 ORDER BY v.semantic_group,v.slug");
    }
}

if ( ! function_exists( 'seo_environment_sync_semantic_groups_local' ) ) {
    function seo_environment_sync_semantic_groups_local( array $rows ) {
        global $wpdb;$table=$wpdb->prefix.'seo_vocabulary';$groups=[];$missing=[];
        foreach($rows as $row){$group=sanitize_key($row['semantic_group']??'');$slug=sanitize_title($row['slug']??'');if(!$group||!$slug)continue;$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE semantic_group=%s AND slug=%s AND active=1 LIMIT 1",$group,$slug));if(!$id){$missing[]=$group.':'.$slug;continue;}$groups[$group][]=absint($id);}
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
        $id=absint($product_id);return seo_environment_compare_query_rows($mysqli,"SELECT a.slug AS attribute_type,a.tipo,t.nombre AS term_name,pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original FROM `{$prefix}sql_product_atributos` pa JOIN `{$prefix}sql_atributos` a ON a.id=pa.atributo_id LEFT JOIN `{$prefix}sql_atributos_terminos` t ON t.id=pa.termino_id WHERE pa.product_id={$id} ORDER BY a.slug,pa.orden,pa.id");
    }
}

if ( ! function_exists( 'seo_environment_sync_attribute_rows_local' ) ) {
    function seo_environment_sync_attribute_rows_local( array $rows ) {
        $out=[];foreach($rows as $row){$value='';if(!empty($row['term_name']))$value=(string)$row['term_name'];elseif(trim((string)($row['valor_original']??''))!=='')$value=(string)$row['valor_original'];elseif(''!==(string)($row['valor_numero']??'')){$value=(string)$row['valor_numero'];if(''!==(string)($row['valor_numero_max']??''))$value.=' - '.(string)$row['valor_numero_max'];if(!empty($row['unidad']))$value.=' '.(string)$row['unidad'];}else$value=(string)($row['valor_texto']??'');if(trim($value)!=='')$out[]=['attribute_type'=>(string)$row['attribute_type'],'attribute_value'=>$value];}return$out;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_post_like' ) ) {
    function seo_environment_sync_pull_post_like( $mysqli, $source_env, $entity, $id ) {
        $prefix    = seo_environment_compare_db_prefix( $source_env );
        $post_type = [ 'products'=>'product', 'pages'=>'page', 'posts'=>'post' ][ $entity ];
        $src       = seo_environment_sync_fetch_post( $mysqli, $prefix, $post_type, $id );
        if ( is_wp_error( $src ) || ! $src ) return is_wp_error( $src ) ? $src : new WP_Error( 'missing_source', 'El objeto origen ya no existe.' );

        $local = get_post( $id );
        if ( ! $local || $local->post_type !== $post_type ) return new WP_Error( 'missing_destination', 'El objeto no existe en el destino. No se crean objetos desde este comparador.' );

        $allowed_taxonomies = 'products' === $entity ? [ 'product_cat', 'product_tag' ] : [ 'post_tag' ];
        $terms = seo_environment_sync_fetch_terms( $mysqli, $prefix, $id, $allowed_taxonomies );
        if ( is_wp_error( $terms ) ) return $terms;
        $resolved = seo_environment_sync_resolve_terms_local( $terms );
        if ( $resolved['missing'] ) return new WP_Error( 'missing_terms', 'Faltan términos en el destino: ' . implode( ', ', array_slice( $resolved['missing'], 0, 8 ) ) );

        $label_nodes = [];
        if ( 'products' !== $entity ) {
            $label_nodes = seo_environment_sync_fetch_selected_nodes( $mysqli, $prefix, $post_type, $id, [], true );
            if ( is_wp_error( $label_nodes ) ) return $label_nodes;
        }

        $semantic = [];
        $attrs    = [];
        if ( 'products' === $entity ) {
            $semantic = seo_environment_sync_fetch_semantic( $mysqli, $prefix, 'product', $id );
            if ( is_wp_error( $semantic ) ) return $semantic;
            $sem_local = seo_environment_sync_semantic_groups_local( $semantic );
            if ( $sem_local['missing'] ) return new WP_Error( 'missing_vocabulary', 'Falta vocabulario en el destino: ' . implode( ', ', array_slice( $sem_local['missing'], 0, 8 ) ) );
            $attrs = seo_environment_sync_fetch_product_attributes( $mysqli, $prefix, $id );
            if ( is_wp_error( $attrs ) ) return $attrs;
        }

        // Sincroniza solo los campos comparados. No toca slug, estado, precio,
        // stock, layout, metadatos ni ningun campo de imagen.
        $postarr = [
            'ID'           => $id,
            'post_title'   => (string) $src['post_title'],
            'post_excerpt' => (string) $src['post_excerpt'],
            'post_content' => (string) $src['post_content'],
        ];
        $updated = wp_update_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $updated ) ) return $updated;

        foreach ( $allowed_taxonomies as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) continue;
            $term_ids = $resolved['terms'][ $tax ] ?? [];
            $r = wp_set_object_terms( $id, $term_ids, $tax, false );
            if ( is_wp_error( $r ) ) return $r;
        }

        if ( 'products' === $entity ) {
            if ( function_exists( 'seo_catalog_apply_product_vocabulary_changes' ) ) {
                $groups = $sem_local['groups'];
                if ( $groups ) {
                    $r = seo_catalog_apply_product_vocabulary_changes( $id, $groups, 'environment_sync' );
                    if ( empty( $r['ok'] ) ) return new WP_Error( 'semantic_sync', (string) ( $r['message'] ?? 'No se pudo sincronizar la semántica.' ) );
                    seo_environment_sync_restore_semantic_dates_local( 'product', $id, $semantic );
                }
            }
            if ( function_exists( 'seo_attributes_replace_product' ) ) {
                seo_attributes_replace_product( $id, seo_environment_sync_attribute_rows_local( $attrs ), 'environment_sync' );
            }
            if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $id );
        } else {
            seo_environment_sync_replace_selected_nodes_local( $post_type, $id, $label_nodes, [], true );
        }

        if ( function_exists( 'seo_ie_sync_restore_post_modified' ) ) {
            seo_ie_sync_restore_post_modified(
                $id,
                [ 'fecha_modificada'=>(string)$src['post_modified'], 'fecha_modificada_gmt'=>(string)$src['post_modified_gmt'] ],
                'fecha_modificada',
                'fecha_modificada_gmt'
            );
        }
        clean_post_cache( $id );
        return true;
    }
}

if ( ! function_exists( 'seo_environment_sync_pull_category' ) ) {
    function seo_environment_sync_pull_category( $mysqli, $source_env, $id ) {
        $prefix = seo_environment_compare_db_prefix( $source_env );
        $id     = absint( $id );

        $rows = seo_environment_compare_query_rows(
            $mysqli,
            "SELECT t.term_id,t.name
             FROM `{$prefix}terms` t
             JOIN `{$prefix}term_taxonomy` tt ON tt.term_id=t.term_id
             WHERE tt.taxonomy='product_cat' AND t.term_id={$id}
             LIMIT 1"
        );
        if ( is_wp_error( $rows ) ) return $rows;
        $src = $rows[0] ?? null;
        if ( ! $src ) return new WP_Error( 'missing_source', 'La categoría origen ya no existe.' );

        $local = get_term( $id, 'product_cat' );
        if ( ! $local || is_wp_error( $local ) ) return new WP_Error( 'missing_destination', 'La categoría no existe en el destino. No se crean categorías desde este comparador.' );

        $roles = [ 'category', 'excerpt', 'description' ];
        $nodes = seo_environment_sync_fetch_selected_nodes( $mysqli, $prefix, 'category', $id, $roles, false );
        if ( is_wp_error( $nodes ) ) return $nodes;

        $semantic = seo_environment_sync_fetch_semantic( $mysqli, $prefix, 'product_cat', $id );
        if ( is_wp_error( $semantic ) ) return $semantic;
        $sem_local = seo_environment_sync_semantic_groups_local( $semantic );
        if ( $sem_local['missing'] ) return new WP_Error( 'missing_vocabulary', 'Falta vocabulario en el destino: ' . implode( ', ', array_slice( $sem_local['missing'], 0, 8 ) ) );

        // Solo el nombre forma parte de WordPress core en este criterio. Slug,
        // parent, descripcion nativa, termmeta e imagen destacada se preservan.
        $r = wp_update_term( $id, 'product_cat', [ 'name' => (string) $src['name'] ] );
        if ( is_wp_error( $r ) ) return $r;

        seo_environment_sync_replace_selected_nodes_local( 'category', $id, $nodes, $roles, false );

        if ( function_exists( 'seo_category_vocabulary_replace' ) ) {
            $all_groups = [ 'rol'=>[], 'tipo'=>[], 'aplicacion'=>[], 'plataforma'=>[], 'subtipo'=>[] ];
            foreach ( $sem_local['groups'] as $group => $ids ) $all_groups[ $group ] = $ids;
            $vr = seo_category_vocabulary_replace( $id, $all_groups, 'environment_sync' );
            if ( is_wp_error( $vr ) ) return $vr;
            seo_environment_sync_restore_semantic_dates_local( 'product_cat', $id, $semantic );
        }

        $sync_date_rows = seo_environment_compare_query_rows(
            $mysqli,
            "SELECT meta_value FROM `{$prefix}termmeta`
             WHERE term_id={$id} AND meta_key='_seo_sync_modified_gmt'
             ORDER BY meta_id DESC LIMIT 1"
        );
        $sync_date = ! is_wp_error( $sync_date_rows ) && ! empty( $sync_date_rows[0]['meta_value'] )
            ? (string) $sync_date_rows[0]['meta_value']
            : '';
        if ( function_exists( 'seo_ie_sync_restore_category_modified' ) && $sync_date ) {
            seo_ie_sync_restore_category_modified( $id, [ 'fecha_modificada_gmt' => $sync_date ] );
        }
        clean_term_cache( $id, 'product_cat' );
        return true;
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
        if(in_array($entity,['products','pages','posts'],true))$result=seo_environment_sync_pull_post_like($mysqli,$source_env,$entity,$id);elseif('categories'===$entity)$result=seo_environment_sync_pull_category($mysqli,$source_env,$id);else$result=seo_environment_sync_pull_faq($mysqli,$source_env,$id);
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
    function seo_environment_sync_revalidate_item( $entity, $id, $source ) {
        $pro=seo_environment_compare_open('pro');$stg=seo_environment_compare_open('staging');if(is_wp_error($pro)||is_wp_error($stg)){if($pro instanceof mysqli)@mysqli_close($pro);if($stg instanceof mysqli)@mysqli_close($stg);return is_wp_error($pro)?$pro:$stg;}
        $p=seo_environment_compare_fetch_snapshots($pro,'pro',$entity,[$id]);$s=seo_environment_compare_fetch_snapshots($stg,'staging',$entity,[$id]);@mysqli_close($pro);@mysqli_close($stg);if(is_wp_error($p)||is_wp_error($s))return is_wp_error($p)?$p:$s;if(empty($p[$id])||empty($s[$id]))return new WP_Error('missing_object','El objeto ya no existe en ambos entornos. Solo se informa; no se crea ni elimina.');if(hash_equals((string)$p[$id]['hash'],(string)$s[$id]['hash']))return new WP_Error('already_equal','El objeto ya está igual en ambos entornos.');$newer=seo_environment_compare_newer($p[$id]['modified'],$s[$id]['modified']);if($newer!==$source)return new WP_Error('not_newer','La versión de '.$source.' no es más reciente. Se bloquea para no pisar datos nuevos.');return true;
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
        global$wpdb;$table=seo_environment_compare_table();$ids=$wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$table} WHERE entity=%s AND status='different' AND newer_env=%s ORDER BY object_id ASC LIMIT 5",$entity,$source));if(!$ids)wp_send_json_success(['done'=>true,'updated'=>0,'errors'=>[]]);$mysqli=seo_environment_compare_open($source);if(is_wp_error($mysqli))wp_send_json_error(['message'=>$mysqli->get_error_message()],500);$updated=0;$errors=[];
        foreach($ids as$id){$id=absint($id);$valid=seo_environment_sync_revalidate_item($entity,$id,$source);if(is_wp_error($valid)){$errors[]='#'.$id.': '.$valid->get_error_message();continue;}$r=seo_environment_sync_pull_item($source,$entity,$id,$mysqli);if(is_wp_error($r)){$errors[]='#'.$id.': '.$r->get_error_message();continue;}$wpdb->delete($table,['entity'=>$entity,'object_id'=>$id],['%s','%d']);$updated++;}
        @mysqli_close($mysqli);$remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s AND status='different' AND newer_env=%s",$entity,$source));wp_send_json_success(['done'=>0===$remaining,'updated'=>$updated,'remaining'=>$remaining,'errors'=>$errors]);
    }
}
add_action('wp_ajax_seo_environment_sync_bulk','seo_environment_sync_bulk_ajax');

if ( ! function_exists( 'seo_environment_compare_render' ) ) {
    function seo_environment_compare_render() {
        if(!current_user_can('manage_options'))return;seo_environment_compare_install();global$wpdb;$table=seo_environment_compare_table();$entities=seo_environment_compare_entities();$current=seo_environment_compare_current_env();$nonce=wp_create_nonce('seo_environment_compare');
        echo '<div class="seo-env-compare">';
        echo '<div class="card" style="max-width:none;padding:18px;margin-bottom:18px;"><h2 style="margin-top:0;">Comparar PRO ↔ STAGING</h2><p>El panel no consulta ninguna BBDD remota al cargar. Cada botón <strong>Escanear</strong> compara solo una entidad, por lotes pequeños, y guarda localmente únicamente hashes, fechas y un resumen de diferencias. No se muestra el contenido de descripciones, páginas o respuestas.</p><p><strong>Criterio de comparación:</strong> productos = título, excerpt, description, etiquetas, atributos y categoría asociada; categorías = nombre, excerpt, description y etiquetas; páginas y posts = título, excerpt, description y etiquetas. Las FAQs mantienen su criterio propio.</p><p><strong>Imágenes excluidas:</strong> cualquier imagen, ID de attachment, miniatura o galería se ignora tanto al comparar como al sincronizar desde este panel. El sistema puede usar imágenes alojadas externamente por proveedores y no se consideran identidad de contenido.</p><p><strong>Regla de sincronización:</strong> nunca crea ni elimina objetos y nunca pisa una versión más reciente. La sincronización de productos, categorías, páginas y posts se limita a los campos que compara este panel. Si un objeto existe solo en un entorno se informa. Si dos versiones difieren pero tienen la misma fecha, se marca como conflicto.</p><p>Entorno actual detectado: <strong>'.esc_html($current?strtoupper($current):'NO IDENTIFICADO').'</strong>.</p></div>';
        echo '<style>.seo-env-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:16px 0 24px}.seo-env-kpi{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px}.seo-env-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}.seo-env-green{background:#00a32a}.seo-env-yellow{background:#dba617}.seo-env-red{background:#d63638}.seo-env-gray{background:#8c8f94}.seo-env-count{font-size:24px;font-weight:650}.seo-env-section{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:16px;margin:0 0 18px}.seo-env-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.seo-env-table{width:100%;border-collapse:collapse}.seo-env-table th,.seo-env-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.seo-env-muted{color:#646970;font-size:12px}.seo-env-progress{margin-left:8px}</style>';
        echo '<div class="seo-env-kpis">';
        foreach($entities as$key=>$def){$s=seo_environment_compare_get_state($key);$color='gray';if('complete'===$s['status']){$color=($s['only_pro']||$s['only_staging']||$s['conflict'])?'red':($s['different']?'yellow':'green');}elseif('error'===$s['status'])$color='red';$diff=(int)$s['different']+(int)$s['only_pro']+(int)$s['only_staging'];echo '<div class="seo-env-kpi" data-kpi="'.esc_attr($key).'"><div><span class="seo-env-dot seo-env-'.esc_attr($color).'"></span><strong>'.esc_html($def['label']).'</strong></div><div class="seo-env-count">'.esc_html('complete'===$s['status']?number_format_i18n($diff):'—').'</div><div class="seo-env-muted">PRO '.number_format_i18n((int)$s['pro']).' · STAGING '.number_format_i18n((int)$s['staging']).'</div><p><button class="button seo-env-scan" data-entity="'.esc_attr($key).'">Escanear '.esc_html(strtolower($def['label'])).'</button><span class="seo-env-progress" data-progress="'.esc_attr($key).'"></span></p></div>';}
        echo '</div>';
        foreach($entities as$key=>$def){$s=seo_environment_compare_get_state($key);$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE entity=%s ORDER BY CASE status WHEN 'different' THEN 0 ELSE 1 END, object_id ASC LIMIT 100",$key),ARRAY_A);$total_diff=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entity=%s",$key));
            echo '<section class="seo-env-section"><h2 style="margin-top:0;">Diferencias de '.esc_html(strtolower($def['label'])).'</h2><div class="seo-env-actions"><button class="button button-primary seo-env-scan" data-entity="'.esc_attr($key).'">Escanear diferencias</button>';
            foreach([['pro','staging'],['staging','pro']] as$direction){[$source,$dest]=$direction;$enabled=$current===$dest&&'complete'===$s['status']&&((int)$s['newer_'.$source]>0);$label='Traer más recientes '.strtoupper($source).' → '.strtoupper($dest);echo '<button class="button seo-env-bulk" data-entity="'.esc_attr($key).'" data-source="'.esc_attr($source).'" data-destination="'.esc_attr($dest).'" '.disabled(!$enabled,true,false).' title="'.esc_attr($current!==$dest?'Ejecuta esta dirección desde '.strtoupper($dest):'Solo se sincronizan filas donde el origen es más reciente').'">'.esc_html($label).'</button>';}
            echo '<span class="seo-env-progress" data-progress="'.esc_attr($key).'"></span></div>';
            if('complete'===$s['status'])echo '<p class="seo-env-muted">Iguales: '.number_format_i18n((int)$s['same']).' · Diferentes: '.number_format_i18n((int)$s['different']).' · Solo PRO: '.number_format_i18n((int)$s['only_pro']).' · Solo STAGING: '.number_format_i18n((int)$s['only_staging']).' · PRO más reciente: '.number_format_i18n((int)$s['newer_pro']).' · STAGING más reciente: '.number_format_i18n((int)$s['newer_staging']).' · Conflictos de fecha: '.number_format_i18n((int)$s['conflict']).'</p>';
            elseif('never'===$s['status'])echo '<p class="seo-env-muted">Todavía no se ha escaneado esta entidad.</p>';elseif('error'===$s['status'])echo '<p style="color:#b32d2e;">'.esc_html($s['error']).'</p>';
            if($rows){echo '<details><summary><strong>Ver diferencias detectadas ('.number_format_i18n($total_diff).')</strong></summary><div style="overflow:auto;margin-top:10px;"><table class="seo-env-table"><thead><tr><th>ID</th><th>Nombre</th><th>Resumen</th><th>Fechas</th><th>Acciones</th></tr></thead><tbody>';
                foreach($rows as$row){$id=absint($row['object_id']);$name=$row['name_pro']?:$row['name_staging'];$missing='only_pro'===$row['status']||'only_staging'===$row['status'];echo '<tr><td><code>'.$id.'</code></td><td>'.esc_html($name).'</td><td>'.esc_html($row['summary']).($missing?'<br><span class="seo-env-muted">Solo se informa; no se crea ni elimina.</span>':'').'</td><td><small>PRO: '.esc_html($row['modified_pro']?:'sin fecha').'<br>STAGING: '.esc_html($row['modified_staging']?:'sin fecha').'<br><strong>'.esc_html('conflict'===$row['newer_env']?'misma fecha / sin fecha':($row['newer_env']?strtoupper($row['newer_env']).' más reciente':'')).'</strong></small></td><td>';
                    foreach([['pro','staging'],['staging','pro']] as$direction){[$source,$dest]=$direction;$can=!$missing&&'different'===$row['status']&&$row['newer_env']===$source&&$current===$dest;echo '<button class="button button-small seo-env-sync-one" data-entity="'.esc_attr($key).'" data-id="'.$id.'" data-source="'.esc_attr($source).'" data-destination="'.esc_attr($dest).'" '.disabled(!$can,true,false).'>'.esc_html(strtoupper($source).' → '.strtoupper($dest)).'</button> ';}
                    echo '</td></tr>';}
                echo '</tbody></table></div>';if($total_diff>100)echo '<p class="seo-env-muted">Se muestran las primeras 100 diferencias para mantener ligera la pantalla. El escaneo conserva el inventario completo.</p>';echo '</details>';}
            echo '</section>';}
        echo '<script>(function(){const ajax='.wp_json_encode(admin_url('admin-ajax.php')).',nonce='.wp_json_encode($nonce).';function post(data){data.nonce=nonce;return fetch(ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(data)}).then(r=>r.json());}function progress(entity,text){document.querySelectorAll("[data-progress=\""+entity+"\"]").forEach(n=>n.textContent=text||"");}async function scan(entity,reset){document.querySelectorAll(".seo-env-scan[data-entity=\""+entity+"\"]").forEach(b=>b.disabled=true);let first=reset;try{while(true){const r=await post({action:"seo_environment_compare_scan",entity:entity,reset:first?1:0});first=false;if(!r.success)throw new Error((r.data&&r.data.message)||"Error de escaneo");const s=r.data.state||{};progress(entity,"Comparados "+(s.processed||0)+"…");if(r.data.done)break;await new Promise(x=>setTimeout(x,250));}progress(entity,"Escaneo terminado");setTimeout(()=>location.reload(),500);}catch(e){progress(entity,e.message);document.querySelectorAll(".seo-env-scan[data-entity=\""+entity+"\"]").forEach(b=>b.disabled=false);}}document.querySelectorAll(".seo-env-scan").forEach(b=>b.addEventListener("click",e=>{e.preventDefault();scan(b.dataset.entity,true);}));document.querySelectorAll(".seo-env-sync-one").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(b.disabled)return;b.disabled=true;progress(b.dataset.entity,"Actualizando #"+b.dataset.id+"…");const r=await post({action:"seo_environment_sync_item",entity:b.dataset.entity,object_id:b.dataset.id,source:b.dataset.source,destination:b.dataset.destination});if(!r.success){progress(b.dataset.entity,(r.data&&r.data.message)||"Error");b.disabled=false;return;}progress(b.dataset.entity,"Actualizado. Verificando…");scan(b.dataset.entity,true);}));document.querySelectorAll(".seo-env-bulk").forEach(b=>b.addEventListener("click",async e=>{e.preventDefault();if(b.disabled)return;b.disabled=true;let total=0;progress(b.dataset.entity,"Sincronizando por lotes…");while(true){const r=await post({action:"seo_environment_sync_bulk",entity:b.dataset.entity,source:b.dataset.source,destination:b.dataset.destination});if(!r.success){progress(b.dataset.entity,(r.data&&r.data.message)||"Error");b.disabled=false;return;}total+=Number(r.data.updated||0);progress(b.dataset.entity,"Actualizados "+total+" · pendientes "+Number(r.data.remaining||0));if(r.data.done)break;if(Number(r.data.updated||0)===0&&Number(r.data.remaining||0)>0){progress(b.dataset.entity,"Hay filas bloqueadas por fecha o validación. Reescanea para revisar.");b.disabled=false;return;}await new Promise(x=>setTimeout(x,400));}scan(b.dataset.entity,true);}));})();</script>';
        echo '</div>';
    }
}
