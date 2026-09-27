<?php
/**
 * Auditoría SQL de solo lectura para Estado del servidor > MySQL.
 */
defined('ABSPATH') || exit;

function seo_system_mysql_audit_variable($name) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SHOW VARIABLES LIKE %s', sanitize_key($name)), ARRAY_A);
    return is_array($row) && array_key_exists('Value', $row) ? $row['Value'] : null;
}

function seo_system_mysql_audit_status_value($name) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SHOW GLOBAL STATUS LIKE %s', sanitize_key($name)), ARRAY_A);
    if (!is_array($row) || !array_key_exists('Value', $row)) {
        $row = $wpdb->get_row($wpdb->prepare('SHOW STATUS LIKE %s', sanitize_key($name)), ARRAY_A);
    }
    return is_array($row) && array_key_exists('Value', $row) ? $row['Value'] : null;
}

function seo_system_mysql_audit_ratio($a, $b) {
    $a = (float) $a;
    $b = (float) $b;
    return $b > 0 ? ($a / $b) * 100.0 : null;
}

function seo_system_mysql_audit_percent_status($value, $warning, $important, $error) {
    if ($value === null) return 'info';
    if ($value >= $error) return 'error';
    if ($value >= $important) return 'important';
    if ($value >= $warning) return 'warning';
    return 'ok';
}

function seo_system_mysql_audit_runtime() {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $vars = array();
    foreach (array(
        'long_query_time','slow_query_log','max_connections','table_open_cache',
        'tmp_table_size','max_heap_table_size','innodb_buffer_pool_size',
        'innodb_log_file_size','query_cache_size'
    ) as $name) {
        $vars[$name] = seo_system_mysql_audit_variable($name);
    }

    $status = array();
    foreach (array(
        'Uptime','Questions','Connections','Max_used_connections','Aborted_connects','Aborted_clients',
        'Created_tmp_tables','Created_tmp_disk_tables','Sort_merge_passes','Sort_rows','Select_full_join',
        'Handler_read_first','Handler_read_key','Handler_read_next','Handler_read_rnd','Handler_read_rnd_next',
        'Opened_tables','Open_tables','Table_locks_immediate','Table_locks_waited','Threads_created',
        'Threads_connected','Threads_running','Slow_queries','Qcache_free_blocks','Qcache_total_blocks',
        'Qcache_lowmem_prunes'
    ) as $name) {
        $value = seo_system_mysql_audit_status_value($name);
        $status[$name] = is_numeric($value) ? (float) $value : $value;
    }

    $cache = array(
        'variables'=>$vars,
        'status'=>$status,
        'derived'=>array(
            'tmp_disk_percent'=>seo_system_mysql_audit_ratio($status['Created_tmp_disk_tables'] ?? 0, $status['Created_tmp_tables'] ?? 0),
            'max_connections_percent'=>seo_system_mysql_audit_ratio($status['Max_used_connections'] ?? 0, $vars['max_connections'] ?? 0),
            'aborted_connect_percent'=>seo_system_mysql_audit_ratio($status['Aborted_connects'] ?? 0, $status['Connections'] ?? 0),
            'thread_creation_percent'=>seo_system_mysql_audit_ratio($status['Threads_created'] ?? 0, $status['Connections'] ?? 0),
            'table_lock_wait_percent'=>seo_system_mysql_audit_ratio(
                $status['Table_locks_waited'] ?? 0,
                ($status['Table_locks_immediate'] ?? 0) + ($status['Table_locks_waited'] ?? 0)
            ),
            'opened_tables_per_hour'=>!empty($status['Uptime']) ? ((float)($status['Opened_tables'] ?? 0) / (float)$status['Uptime']) * 3600.0 : null,
            'query_cache_fragmentation_percent'=>!empty($status['Qcache_total_blocks'])
                ? seo_system_mysql_audit_ratio($status['Qcache_free_blocks'] ?? 0, $status['Qcache_total_blocks'])
                : null,
        ),
    );
    return $cache;
}

function seo_system_mysql_audit_custom_tables() {
    static $cache = null;
    if (is_array($cache)) return $cache;

    global $wpdb;
    $pattern = $wpdb->esc_like($wpdb->prefix . 'seo_') . '%';

    $tables = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_NAME,ENGINE,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s
         ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC,TABLE_NAME ASC",
        DB_NAME,
        $pattern
    ), ARRAY_A);

    $index_rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s
         ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        DB_NAME,
        $pattern
    ), ARRAY_A);

    $column_rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_NAME,COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s
         ORDER BY TABLE_NAME,ORDINAL_POSITION",
        DB_NAME,
        $pattern
    ), ARRAY_A);

    $indexes = array();
    foreach ($index_rows as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $index = (string)($row['INDEX_NAME'] ?? '');
        if ($table === '' || $index === '') continue;
        if (!isset($indexes[$table][$index])) {
            $indexes[$table][$index] = array('name'=>$index,'non_unique'=>absint($row['NON_UNIQUE'] ?? 1),'columns'=>array());
        }
        $indexes[$table][$index]['columns'][] = (string)($row['COLUMN_NAME'] ?? '');
    }

    $columns = array();
    foreach ($column_rows as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') $columns[$table][$column] = true;
    }

    $lookup_columns = array('object_id','product_id','term_id','source_id','target_id','vocabulary_id','order_id','supplier_id','provider_id','sku','url_hash');
    $inventory = array();
    $candidates = array();
    $duplicates = array();
    $missing_primary = array();

    foreach ($tables as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $estimated = (float)($row['TABLE_ROWS'] ?? 0);
        $table_indexes = (array)($indexes[$table] ?? array());
        $first_columns = array();
        foreach ($table_indexes as $idx) {
            if (!empty($idx['columns'][0])) $first_columns[] = (string)$idx['columns'][0];
        }

        if ($estimated >= 1000 && !isset($table_indexes['PRIMARY'])) {
            $has_unique = false;
            foreach ($table_indexes as $idx) {
                if (empty($idx['non_unique'])) { $has_unique = true; break; }
            }
            if (!$has_unique) $missing_primary[] = $table;
        }

        if ($estimated >= 5000) {
            foreach ($lookup_columns as $column) {
                if (!empty($columns[$table][$column]) && !in_array($column, $first_columns, true)) {
                    $candidates[] = array('table'=>$table,'column'=>$column,'rows'=>$estimated);
                }
            }
        }

        $seen = array();
        foreach ($table_indexes as $idx) {
            if ($idx['name'] === 'PRIMARY') continue;
            $sig = (string)$idx['non_unique'] . '|' . implode(',', $idx['columns']);
            if (isset($seen[$sig])) {
                $duplicates[] = array('table'=>$table,'index_a'=>$seen[$sig],'index_b'=>$idx['name'],'columns'=>implode(', ', $idx['columns']));
            } else {
                $seen[$sig] = $idx['name'];
            }
        }

        $inventory[] = array(
            'table'=>$table,
            'engine'=>(string)($row['ENGINE'] ?? ''),
            'rows'=>$estimated,
            'data_length'=>(float)($row['DATA_LENGTH'] ?? 0),
            'index_length'=>(float)($row['INDEX_LENGTH'] ?? 0),
            'index_count'=>count($table_indexes),
            'has_primary'=>isset($table_indexes['PRIMARY']),
        );
    }

    $cache = array(
        'tables'=>$inventory,
        'indexes'=>$indexes,
        'columns'=>$columns,
        'lookup_without_index'=>$candidates,
        'duplicate_indexes'=>$duplicates,
        'missing_primary'=>$missing_primary,
    );
    return $cache;
}

function seo_system_mysql_audit_explain_definitions() {
    global $wpdb;
    return array(
        array('label'=>'Relaciones SEO por origen','table'=>$wpdb->prefix.'seo_relations','required'=>array('source_type','source_id','relation_type','target_id'),'sql'=>"SELECT target_id FROM {$wpdb->prefix}seo_relations WHERE source_type='hub_secondary' AND source_id=1 AND relation_type='hub_to_category' LIMIT 20"),
        array('label'=>'Vocabulary por objeto','table'=>$wpdb->prefix.'seo_object_vocabulary','required'=>array('object_type','object_id','status','vocabulary_id'),'sql'=>"SELECT vocabulary_id FROM {$wpdb->prefix}seo_object_vocabulary WHERE object_type='product' AND object_id=1 AND status=1"),
        array('label'=>'Proveedor por producto','table'=>$wpdb->prefix.'seo_proveedores_productos','required'=>array('object_id','id'),'sql'=>"SELECT id FROM {$wpdb->prefix}seo_proveedores_productos WHERE object_id=1 ORDER BY id DESC LIMIT 1"),
        array('label'=>'Imágenes externas por producto','table'=>$wpdb->prefix.'seo_supplier_images','required'=>array('product_id','id'),'sql'=>"SELECT id FROM {$wpdb->prefix}seo_supplier_images WHERE product_id=1 ORDER BY id DESC LIMIT 20"),
        array('label'=>'Índice Dependiente por producto','table'=>$wpdb->prefix.'seo_dependiente_index','required'=>array('product_id'),'sql'=>"SELECT product_id FROM {$wpdb->prefix}seo_dependiente_index WHERE product_id=1 LIMIT 1"),
        array('label'=>'Ingeniero · fuentes por categoría','table'=>$wpdb->prefix.'seo_ingeniero_sources','required'=>array('term_id','lesson','status','trust_level','id'),'sql'=>"SELECT id FROM {$wpdb->prefix}seo_ingeniero_sources WHERE term_id=1 AND lesson='l1_technical' AND status<>'superseded' ORDER BY trust_level,id DESC LIMIT 20"),
        array('label'=>'Ingeniero · conocimiento por categoría','table'=>$wpdb->prefix.'seo_ingeniero_knowledge','required'=>array('term_id','lesson','status','knowledge_type'),'sql'=>"SELECT knowledge_type FROM {$wpdb->prefix}seo_ingeniero_knowledge WHERE term_id=1 AND lesson='l1_technical' AND status='active' ORDER BY knowledge_type LIMIT 20"),
        array('label'=>'FAQs por propietario','table'=>$wpdb->prefix.'seo_faq','required'=>array('object_type','object_id','active'),'sql'=>"SELECT id FROM {$wpdb->prefix}seo_faq WHERE object_type='product' AND object_id=1 AND active=1 LIMIT 20"),
    );
}

function seo_system_mysql_audit_explain_plans() {
    static $cache = null;
    if (is_array($cache)) return $cache;

    global $wpdb;
    $inventory = seo_system_mysql_audit_custom_tables();
    $columns = (array)($inventory['columns'] ?? array());
    $plans = array();

    foreach (seo_system_mysql_audit_explain_definitions() as $def) {
        $table = (string)$def['table'];
        if (empty($columns[$table]) || array_diff((array)$def['required'], array_keys($columns[$table]))) continue;

        $wpdb->last_error = '';
        $rows = (array)$wpdb->get_results('EXPLAIN ' . $def['sql'], ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->last_error !== '' || !$rows) continue;

        $state = 'ok';
        $estimated = 0;
        $types = array();
        $keys = array();
        $extras = array();
        $flags = array();

        foreach ($rows as $row) {
            $type = strtoupper((string)($row['type'] ?? ''));
            $count = (float)($row['rows'] ?? 0);
            $extra = (string)($row['Extra'] ?? '');
            $key = (string)($row['key'] ?? '');
            $possible = (string)($row['possible_keys'] ?? '');
            $estimated += $count;
            if ($type !== '') $types[] = $type;
            if ($key !== '') $keys[] = $key;
            if ($extra !== '') $extras[] = $extra;

            if ($type === 'ALL' && $count >= 1000) {
                $flags[] = 'full scan';
                $state = $count >= 10000 ? 'important' : 'warning';
            }
            if ($possible === '' && $count >= 1000) {
                $flags[] = 'sin possible_keys';
                if ($state === 'ok') $state = 'warning';
            }
            if (stripos($extra, 'Using temporary') !== false) {
                $flags[] = 'temporary';
                $state = $count >= 10000 ? 'important' : 'warning';
            }
            if (stripos($extra, 'Using filesort') !== false && $count >= 1000) {
                $flags[] = 'filesort';
                if ($state === 'ok') $state = 'warning';
            }
        }

        $plans[] = array(
            'label'=>$def['label'],'table'=>$table,'status'=>$state,
            'type'=>implode(', ',array_unique($types)),
            'key_used'=>implode(', ',array_unique($keys)),
            'rows'=>$estimated,'extra'=>implode(' · ',array_unique($extras)),
            'detail'=>$flags ? implode(', ',array_unique($flags)) : 'Sin señales problemáticas con los umbrales actuales.',
        );
    }

    $cache = $plans;
    return $cache;
}

function seo_system_mysql_audit_collect() {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $runtime = seo_system_mysql_audit_runtime();
    $custom = seo_system_mysql_audit_custom_tables();
    $plans = seo_system_mysql_audit_explain_plans();
    $problem_plans = array_filter($plans, static function($row) {
        return in_array($row['status'] ?? 'info', array('warning','important','error'), true);
    });

    $cache = array(
        'runtime'=>$runtime,
        'custom'=>$custom,
        'plans'=>$plans,
        'summary'=>array(
            'seo_tables'=>count((array)$custom['tables']),
            'lookup_without_index'=>count((array)$custom['lookup_without_index']),
            'duplicate_indexes'=>count((array)$custom['duplicate_indexes']),
            'missing_primary'=>count((array)$custom['missing_primary']),
            'plans'=>count($plans),
            'problem_plans'=>count($problem_plans),
        ),
    );
    return $cache;
}

function seo_system_mysql_audit_snapshot_checks() {
    $audit = seo_system_mysql_audit_collect();
    $runtime = (array)$audit['runtime'];
    $vars = (array)$runtime['variables'];
    $derived = (array)$runtime['derived'];
    $summary = (array)$audit['summary'];
    $checks = array();

    $long = is_numeric($vars['long_query_time'] ?? null) ? (float)$vars['long_query_time'] : null;
    $slow_on = in_array(strtoupper((string)($vars['slow_query_log'] ?? '')), array('ON','1','YES'), true);
    $checks[] = seo_server_status_make_check(
        'SRV-DB-SLOW-QUERY-VISIBILITY','Base de datos','Visibilidad de consultas lentas',
        ($slow_on?'slow log ON':'slow log OFF') . ($long!==null?' · '.$long.' s':''),
        (!$slow_on || ($long!==null && $long>=10)) ? 'warning' : 'ok',
        'El plugin solo informa; en hosting compartido esta configuración suele depender del proveedor.'
    );

    $tmp = $derived['tmp_disk_percent'] ?? null;
    $checks[] = seo_server_status_make_check(
        'SRV-DB-TMP-DISK','Base de datos','Temporales escritos a disco',
        $tmp===null?'N/D':number_format_i18n($tmp,1).'%',
        seo_system_mysql_audit_percent_status($tmp,25,50,75),
        'Métrica global desde el arranque; no se atribuye automáticamente a SEO Taxonomy.'
    );

    $conn = $derived['max_connections_percent'] ?? null;
    $checks[] = seo_server_status_make_check(
        'SRV-DB-MAX-CONNECTIONS','Base de datos','Pico de conexiones',
        $conn===null?'N/D':number_format_i18n($conn,1).'%',
        seo_system_mysql_audit_percent_status($conn,80,90,98),
        'Max_used_connections frente a max_connections.'
    );

    $problem = absint($summary['problem_plans'] ?? 0);
    $checks[] = seo_server_status_make_check(
        'SRV-DB-SEO-EXPLAIN','Base de datos','Planes SQL SEO Taxonomy a revisar',
        (string)$problem,
        $problem>=3?'important':($problem>0?'warning':'ok'),
        absint($summary['plans'] ?? 0) . ' consultas representativas analizadas con EXPLAIN.'
    );

    $duplicates = absint($summary['duplicate_indexes'] ?? 0);
    $candidates = absint($summary['lookup_without_index'] ?? 0);
    $checks[] = seo_server_status_make_check(
        'SRV-DB-SEO-INDEXES','Base de datos','Índices de tablas SEO Taxonomy',
        $candidates.' candidatos · '.$duplicates.' duplicados',
        $duplicates>0?'warning':'info',
        'Los candidatos son heurísticos y no se crean índices automáticamente.'
    );

    return $checks;
}

function seo_system_mysql_audit_render() {
    $audit = seo_system_mysql_audit_collect();
    $runtime = (array)$audit['runtime'];
    $vars = (array)$runtime['variables'];
    $status = (array)$runtime['status'];
    $derived = (array)$runtime['derived'];
    $custom = (array)$audit['custom'];
    $summary = (array)$audit['summary'];

    echo '<div class="seo-status-card">';
    echo '<h2>Auditoría SQL de SEO Taxonomy</h2>';
    echo '<p>Solo lectura: revisa señales MySQL, tablas propias, índices y planes <code>EXPLAIN</code>. No crea ni elimina índices.</p>';

    echo '<div class="seo-mysql-query-kpis">';
    foreach (array(
        array('Tablas SEO',absint($summary['seo_tables']),'prefijo seo_'),
        array('Índices candidatos',absint($summary['lookup_without_index']),'requieren revisión'),
        array('Índices duplicados',absint($summary['duplicate_indexes']),'coincidencia exacta'),
        array('Planes a revisar',absint($summary['problem_plans']),absint($summary['plans']).' EXPLAIN')
    ) as $card) {
        echo '<div class="seo-mysql-query-kpi"><strong>'.esc_html($card[0]).'</strong><div class="seo-mysql-query-kpi-value">'.esc_html(number_format_i18n($card[1])).'</div><span class="seo-muted">'.esc_html($card[2]).'</span></div>';
    }
    echo '</div>';

    $long = is_numeric($vars['long_query_time'] ?? null) ? (float)$vars['long_query_time'] : null;
    $slow_on = in_array(strtoupper((string)($vars['slow_query_log'] ?? '')),array('ON','1','YES'),true);
    $signals = array(
        array('Slow query log',($slow_on?'Activo':'Desactivado').($long!==null?' · '.$long.' s':''),(!$slow_on||($long!==null&&$long>=10))?'warning':'ok','Visibilidad de consultas lentas.'),
        array('Temporales a disco',$derived['tmp_disk_percent']===null?'N/D':number_format_i18n($derived['tmp_disk_percent'],1).'%',seo_system_mysql_audit_percent_status($derived['tmp_disk_percent'],25,50,75),'Created_tmp_disk_tables / Created_tmp_tables.'),
        array('Máximo de conexiones usado',$derived['max_connections_percent']===null?'N/D':number_format_i18n($derived['max_connections_percent'],1).'%',seo_system_mysql_audit_percent_status($derived['max_connections_percent'],80,90,98),'Pico frente a max_connections.'),
        array('Conexiones abortadas',$derived['aborted_connect_percent']===null?'N/D':number_format_i18n($derived['aborted_connect_percent'],2).'%',seo_system_mysql_audit_percent_status($derived['aborted_connect_percent'],1,3,8),'Aborted_connects / Connections.'),
        array('Creación de hilos',$derived['thread_creation_percent']===null?'N/D':number_format_i18n($derived['thread_creation_percent'],2).'%',seo_system_mysql_audit_percent_status($derived['thread_creation_percent'],5,15,30),'Threads_created / Connections.'),
        array('Esperas de bloqueo',$derived['table_lock_wait_percent']===null?'N/D':number_format_i18n($derived['table_lock_wait_percent'],3).'%',seo_system_mysql_audit_percent_status($derived['table_lock_wait_percent'],1,5,15),'Table_locks_waited frente al total observado.'),
        array('JOIN sin índice acumulados',number_format_i18n((float)($status['Select_full_join']??0)),((float)($status['Select_full_join']??0)>0?'warning':'ok'),'Contador global desde el arranque.'),
        array('Filas ordenadas',number_format_i18n((float)($status['Sort_rows']??0)),'info','Contador global; se interpreta junto a EXPLAIN.'),
        array('Sort merge passes',number_format_i18n((float)($status['Sort_merge_passes']??0)),'info','Pasadas de mezcla de ordenación.')
    );

    echo '<h3>Señales globales del servidor MySQL</h3><table class="seo-status-table"><thead><tr><th>Señal</th><th>Valor</th><th>Estado</th><th>Lectura</th></tr></thead><tbody>';
    foreach ($signals as $row) {
        echo '<tr><td><strong>'.esc_html($row[0]).'</strong></td><td>'.esc_html($row[1]).'</td><td>'.seo_server_status_badge($row[2]).'</td><td class="seo-muted">'.esc_html($row[3]).'</td></tr>';
    }
    echo '</tbody></table>';

    echo '<h3 style="margin-top:22px">Tablas propias e índices</h3><table class="seo-status-table"><thead><tr><th>Tabla</th><th>Filas aprox.</th><th>Tamaño</th><th>Índices</th><th>PRIMARY</th></tr></thead><tbody>';
    foreach (array_slice((array)$custom['tables'],0,30) as $table) {
        $size=(float)$table['data_length']+(float)$table['index_length'];
        echo '<tr><td><code>'.esc_html($table['table']).'</code></td><td>'.esc_html(number_format_i18n($table['rows'])).'</td><td>'.esc_html(seo_server_status_format_bytes($size)).'</td><td>'.esc_html(number_format_i18n($table['index_count'])).'</td><td>'.(!empty($table['has_primary'])?seo_server_status_badge('ok'):seo_server_status_badge('info')).'</td></tr>';
    }
    echo '</tbody></table>';

    if (!empty($custom['lookup_without_index'])) {
        echo '<h3 style="margin-top:22px">Candidatos de índice</h3><p>Heurística sobre tablas con al menos 5.000 filas. No implica crear el índice sin revisar la consulta real.</p>';
        echo '<table class="seo-status-table"><thead><tr><th>Tabla</th><th>Columna</th><th>Filas aprox.</th></tr></thead><tbody>';
        foreach (array_slice($custom['lookup_without_index'],0,40) as $row) {
            echo '<tr><td><code>'.esc_html($row['table']).'</code></td><td><code>'.esc_html($row['column']).'</code></td><td>'.esc_html(number_format_i18n($row['rows'])).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    if (!empty($custom['duplicate_indexes'])) {
        echo '<h3 style="margin-top:22px">Índices duplicados exactos</h3><table class="seo-status-table"><thead><tr><th>Tabla</th><th>Índice A</th><th>Índice B</th><th>Columnas</th></tr></thead><tbody>';
        foreach ($custom['duplicate_indexes'] as $row) {
            echo '<tr><td><code>'.esc_html($row['table']).'</code></td><td><code>'.esc_html($row['index_a']).'</code></td><td><code>'.esc_html($row['index_b']).'</code></td><td>'.esc_html($row['columns']).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3 style="margin-top:22px">EXPLAIN de consultas representativas</h3><p>EXPLAIN analiza el plan del optimizador sin ejecutar el SELECT de negocio.</p>';
    echo '<table class="seo-status-table"><thead><tr><th>Consulta</th><th>Tabla</th><th>Tipo</th><th>Índice</th><th>Filas</th><th>Extra</th><th>Estado</th></tr></thead><tbody>';
    foreach ((array)$audit['plans'] as $plan) {
        echo '<tr><td><strong>'.esc_html($plan['label']).'</strong><div class="seo-muted">'.esc_html($plan['detail']).'</div></td><td><code>'.esc_html($plan['table']).'</code></td><td><code>'.esc_html($plan['type']).'</code></td><td><code>'.esc_html($plan['key_used']?:'—').'</code></td><td>'.esc_html(number_format_i18n($plan['rows'])).'</td><td class="seo-muted">'.esc_html($plan['extra']).'</td><td>'.seo_server_status_badge($plan['status']).'</td></tr>';
    }
    if (empty($audit['plans'])) echo '<tr><td colspan="7">No se pudieron construir planes para las tablas disponibles.</td></tr>';
    echo '</tbody></table>';

    echo '<p class="seo-status-note"><strong>Criterio:</strong> primero diagnosticamos en STAGING. Si aparece un índice claramente útil, se incorporará después mediante una migración versionada; nunca desde este informe.</p>';
    echo '</div>';
}
