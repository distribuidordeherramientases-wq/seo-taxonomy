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
        'long_query_time','slow_query_log','max_connections','table_open_cache','table_definition_cache',
        'tmp_table_size','max_heap_table_size','thread_cache_size','sort_buffer_size','join_buffer_size',
        'read_buffer_size','read_rnd_buffer_size','max_allowed_packet','wait_timeout','interactive_timeout',
        'innodb_buffer_pool_size','innodb_log_file_size','innodb_flush_log_at_trx_commit',
        'performance_schema','query_cache_type','query_cache_size'
    ) as $name) {
        $vars[$name] = seo_system_mysql_audit_variable($name);
    }

    $status = array();
    foreach (array(
        'Uptime','Questions','Queries','Connections','Max_used_connections','Aborted_connects','Aborted_clients',
        'Connection_errors_internal','Connection_errors_max_connections',
        'Com_select','Com_insert','Com_update','Com_delete',
        'Created_tmp_tables','Created_tmp_disk_tables','Created_tmp_files',
        'Sort_merge_passes','Sort_rows','Sort_scan','Sort_range',
        'Select_full_join','Select_full_range_join','Select_range','Select_range_check','Select_scan',
        'Handler_read_first','Handler_read_key','Handler_read_next','Handler_read_rnd','Handler_read_rnd_next',
        'Opened_tables','Open_tables','Table_open_cache_hits','Table_open_cache_misses','Table_open_cache_overflows',
        'Table_locks_immediate','Table_locks_waited','Threads_cached','Threads_created','Threads_connected','Threads_running',
        'Slow_queries','Bytes_received','Bytes_sent',
        'Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Innodb_buffer_pool_write_requests',
        'Innodb_buffer_pool_pages_dirty','Innodb_buffer_pool_wait_free',
        'Innodb_row_lock_current_waits','Innodb_row_lock_time','Innodb_row_lock_waits',
        'Qcache_free_blocks','Qcache_total_blocks','Qcache_lowmem_prunes'
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
            'aborted_client_percent'=>seo_system_mysql_audit_ratio($status['Aborted_clients'] ?? 0, $status['Connections'] ?? 0),
            'thread_creation_percent'=>seo_system_mysql_audit_ratio($status['Threads_created'] ?? 0, $status['Connections'] ?? 0),
            'table_lock_wait_percent'=>seo_system_mysql_audit_ratio(
                $status['Table_locks_waited'] ?? 0,
                ($status['Table_locks_immediate'] ?? 0) + ($status['Table_locks_waited'] ?? 0)
            ),
            'opened_tables_per_hour'=>!empty($status['Uptime']) ? ((float)($status['Opened_tables'] ?? 0) / (float)$status['Uptime']) * 3600.0 : null,
            'questions_per_second'=>!empty($status['Uptime']) ? ((float)($status['Questions'] ?? 0) / (float)$status['Uptime']) : null,
            'bytes_received_per_hour'=>!empty($status['Uptime']) ? ((float)($status['Bytes_received'] ?? 0) / (float)$status['Uptime']) * 3600.0 : null,
            'bytes_sent_per_hour'=>!empty($status['Uptime']) ? ((float)($status['Bytes_sent'] ?? 0) / (float)$status['Uptime']) * 3600.0 : null,
            'full_join_per_million_queries'=>!empty($status['Questions']) ? ((float)($status['Select_full_join'] ?? 0) / (float)$status['Questions']) * 1000000.0 : null,
            'slow_queries_per_million'=>!empty($status['Questions']) ? ((float)($status['Slow_queries'] ?? 0) / (float)$status['Questions']) * 1000000.0 : null,
            'innodb_buffer_pool_hit_percent'=>!empty($status['Innodb_buffer_pool_read_requests'])
                ? max(0.0, min(100.0, (1.0 - ((float)($status['Innodb_buffer_pool_reads'] ?? 0) / (float)$status['Innodb_buffer_pool_read_requests'])) * 100.0))
                : null,
            'innodb_row_lock_avg_ms'=>!empty($status['Innodb_row_lock_waits'])
                ? ((float)($status['Innodb_row_lock_time'] ?? 0) / (float)$status['Innodb_row_lock_waits'])
                : null,
            'table_open_cache_hit_percent'=>(($status['Table_open_cache_hits'] ?? 0) + ($status['Table_open_cache_misses'] ?? 0)) > 0
                ? seo_system_mysql_audit_ratio(
                    $status['Table_open_cache_hits'] ?? 0,
                    ($status['Table_open_cache_hits'] ?? 0) + ($status['Table_open_cache_misses'] ?? 0)
                )
                : null,
            'query_cache_fragmentation_percent'=>!empty($status['Qcache_total_blocks'])
                ? seo_system_mysql_audit_ratio($status['Qcache_free_blocks'] ?? 0, $status['Qcache_total_blocks'])
                : null,
        ),
    );
    return $cache;
}

function seo_system_mysql_audit_database_overview() {
    static $cache = null;
    if (is_array($cache)) return $cache;

    global $wpdb;
    $aggregate = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) table_count,
                COALESCE(SUM(TABLE_ROWS),0) estimated_rows,
                COALESCE(SUM(DATA_LENGTH),0) data_size,
                COALESCE(SUM(INDEX_LENGTH),0) index_size,
                SUM(ENGINE='InnoDB') innodb_tables,
                SUM(ENGINE='MyISAM') myisam_tables
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=%s",
        DB_NAME
    ), ARRAY_A);

    $largest = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_NAME table_name,ENGINE engine,TABLE_ROWS table_rows,
                DATA_LENGTH data_length,INDEX_LENGTH index_length
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=%s
         ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC
         LIMIT 30",
        DB_NAME
    ), ARRAY_A);

    $options_size = (float) $wpdb->get_var($wpdb->prepare(
        'SELECT COALESCE(DATA_LENGTH+INDEX_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
        DB_NAME,
        $wpdb->options
    ));
    $autoload_size = (float) $wpdb->get_var("SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')");

    $data_size = (float) ($aggregate['data_size'] ?? 0);
    $index_size = (float) ($aggregate['index_size'] ?? 0);

    return $cache = array(
        'table_count'=>absint($aggregate['table_count'] ?? 0),
        'estimated_rows'=>(float) ($aggregate['estimated_rows'] ?? 0),
        'data_size'=>$data_size,
        'index_size'=>$index_size,
        'total_size'=>$data_size + $index_size,
        'index_to_data_percent'=>$data_size > 0 ? ($index_size / $data_size) * 100.0 : null,
        'innodb_tables'=>absint($aggregate['innodb_tables'] ?? 0),
        'myisam_tables'=>absint($aggregate['myisam_tables'] ?? 0),
        'options_size'=>$options_size,
        'autoload_size'=>$autoload_size,
        'largest_tables'=>$largest,
    );
}

function seo_system_mysql_audit_history_option_name() {
    return 'seo_system_mysql_audit_history_v1';
}

function seo_system_mysql_audit_load_history() {
    $history = get_option(seo_system_mysql_audit_history_option_name(), array());
    return is_array($history) ? array_values($history) : array();
}

function seo_system_mysql_audit_history_entry($audit = null) {
    if (!is_array($audit)) $audit = seo_system_mysql_audit_collect();

    $runtime = (array) ($audit['runtime'] ?? array());
    $derived = (array) ($runtime['derived'] ?? array());
    $status = (array) ($runtime['status'] ?? array());
    $database = (array) ($audit['database'] ?? array());
    $summary = (array) ($audit['summary'] ?? array());
    $profile = (array) ($audit['query_profile'] ?? array());

    return array(
        'generated_at'=>time(),
        'uptime'=>isset($status['Uptime']) ? (float)$status['Uptime'] : 0.0,
        'database'=>array(
            'total_size'=>(float)($database['total_size'] ?? 0),
            'data_size'=>(float)($database['data_size'] ?? 0),
            'index_size'=>(float)($database['index_size'] ?? 0),
            'table_count'=>absint($database['table_count'] ?? 0),
            'estimated_rows'=>(float)($database['estimated_rows'] ?? 0),
        ),
        'health'=>array(
            'max_connections_percent'=>$derived['max_connections_percent'] ?? null,
            'tmp_disk_percent'=>$derived['tmp_disk_percent'] ?? null,
            'aborted_connect_percent'=>$derived['aborted_connect_percent'] ?? null,
            'thread_creation_percent'=>$derived['thread_creation_percent'] ?? null,
            'table_lock_wait_percent'=>$derived['table_lock_wait_percent'] ?? null,
            'innodb_buffer_pool_hit_percent'=>$derived['innodb_buffer_pool_hit_percent'] ?? null,
            'table_open_cache_hit_percent'=>$derived['table_open_cache_hit_percent'] ?? null,
        ),
        'counters'=>array(
            'questions'=>(float)($status['Questions'] ?? 0),
            'select_full_join'=>(float)($status['Select_full_join'] ?? 0),
            'created_tmp_disk_tables'=>(float)($status['Created_tmp_disk_tables'] ?? 0),
            'sort_rows'=>(float)($status['Sort_rows'] ?? 0),
            'sort_merge_passes'=>(float)($status['Sort_merge_passes'] ?? 0),
            'aborted_connects'=>(float)($status['Aborted_connects'] ?? 0),
            'slow_queries'=>(float)($status['Slow_queries'] ?? 0),
        ),
        'seo_taxonomy'=>array(
            'seo_tables'=>absint($summary['seo_tables'] ?? 0),
            'index_candidates'=>absint($summary['lookup_without_index'] ?? 0),
            'duplicate_indexes'=>absint($summary['duplicate_indexes'] ?? 0),
            'problem_plans'=>absint($summary['problem_plans'] ?? 0),
        ),
        'query_profile'=>array(
            'response_ms'=>isset($profile['response_ms']) ? (float)$profile['response_ms'] : null,
            'average_ms'=>isset($profile['average_ms']) ? (float)$profile['average_ms'] : null,
            'slowest_ms'=>!empty($profile['slowest']['ms']) ? (float)$profile['slowest']['ms'] : null,
            'error_count'=>absint($profile['error_count'] ?? 0),
        ),
    );
}

function seo_system_mysql_audit_store_history($audit = null) {
    $entry = seo_system_mysql_audit_history_entry($audit);
    $history = seo_system_mysql_audit_load_history();
    $last = $history ? end($history) : null;

    // Evita fotografías duplicadas si varias capas del diagnóstico guardan
    // el mismo chequeo durante el mismo minuto.
    if (is_array($last) && !empty($last['generated_at']) && (time() - (int)$last['generated_at']) < 60) {
        $history[count($history)-1] = $entry;
    } else {
        $history[] = $entry;
    }

    $history = array_slice($history, -30);
    update_option(seo_system_mysql_audit_history_option_name(), $history, false);
    return $entry;
}

function seo_system_mysql_audit_history_analysis($history = null) {
    if (!is_array($history)) $history = seo_system_mysql_audit_load_history();
    if (count($history) < 2) return array('available'=>false,'reason'=>'Se necesitan al menos dos chequeos completos.');

    $previous = $history[count($history)-2];
    $current = $history[count($history)-1];
    $elapsed = max(1, (int)($current['generated_at'] ?? 0) - (int)($previous['generated_at'] ?? 0));
    $server_restarted = (float)($current['uptime'] ?? 0) < (float)($previous['uptime'] ?? 0);

    $deltas = array();
    if (!$server_restarted) {
        foreach (array('questions','select_full_join','created_tmp_disk_tables','sort_rows','sort_merge_passes','aborted_connects','slow_queries') as $key) {
            $delta = max(0.0, (float)($current['counters'][$key] ?? 0) - (float)($previous['counters'][$key] ?? 0));
            $deltas[$key] = array(
                'delta'=>$delta,
                'per_hour'=>$delta / ($elapsed / 3600.0),
            );
        }
    }

    return array(
        'available'=>true,
        'elapsed_seconds'=>$elapsed,
        'server_restarted'=>$server_restarted,
        'deltas'=>$deltas,
        'database_size_delta'=>(float)($current['database']['total_size'] ?? 0) - (float)($previous['database']['total_size'] ?? 0),
    );
}

function seo_system_mysql_audit_render_sparkline($history, $path, $label, $suffix = '', $scale = 1.0) {
    $points = array();
    foreach ((array)$history as $row) {
        $value = $row;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { $value = null; break; }
            $value = $value[$part];
        }
        if ($value === null || !is_numeric($value)) continue;
        $points[] = array('x'=>(int)($row['generated_at'] ?? 0),'value'=>(float)$value / max(0.000001, (float)$scale));
    }

    echo '<div class="seo-mysql-history-card"><strong>' . esc_html($label) . '</strong>';
    if (count($points) < 2) {
        echo '<div class="seo-muted" style="padding:24px 0">Necesita al menos 2 chequeos completos.</div></div>';
        return;
    }

    $values = array_column($points, 'value');
    $min = min($values);
    $max = max($values);
    if ($max <= $min) $max = $min + 1.0;
    $w = 300; $h = 92; $pad = 8;
    $coords = array();
    $count = count($points);
    foreach ($points as $i=>$point) {
        $x = $pad + (($w - 2*$pad) * ($count === 1 ? 0 : $i / ($count - 1)));
        $y = $h - $pad - (($point['value'] - $min) / ($max - $min)) * ($h - 2*$pad);
        $coords[] = number_format($x,1,'.','') . ',' . number_format($y,1,'.','');
    }
    $last = end($points);
    echo '<svg viewBox="0 0 300 92" preserveAspectRatio="none" role="img" aria-label="' . esc_attr($label) . '">';
    echo '<polyline points="' . esc_attr(implode(' ', $coords)) . '" fill="none" stroke="currentColor" stroke-width="2.5" vector-effect="non-scaling-stroke"></polyline>';
    echo '</svg>';
    echo '<div class="seo-mysql-history-value">' . esc_html(number_format_i18n((float)$last['value'], 2) . $suffix) . '</div>';
    echo '<span class="seo-muted">' . esc_html(count($points) . ' fotografías guardadas') . '</span></div>';
}

function seo_system_mysql_audit_render_history($history = null) {
    if (!is_array($history)) $history = seo_system_mysql_audit_load_history();
    echo '<div class="seo-status-card"><h2>Evolución MySQL · fotografías del chequeo</h2>';
    echo '<p>Gráficos ligeros generados con las fotografías guardadas al ejecutar el chequeo completo. No consultan MySQL de nuevo.</p>';
    echo '<div class="seo-mysql-history-grid">';
    seo_system_mysql_audit_render_sparkline($history, 'database.total_size', 'Tamaño BBDD', ' GB', 1073741824);
    seo_system_mysql_audit_render_sparkline($history, 'health.max_connections_percent', 'Pico conexiones', '%');
    seo_system_mysql_audit_render_sparkline($history, 'health.tmp_disk_percent', 'Temporales a disco', '%');
    seo_system_mysql_audit_render_sparkline($history, 'query_profile.average_ms', 'Latencia media diagnóstica', ' ms');
    echo '</div>';

    $analysis = seo_system_mysql_audit_history_analysis($history);
    if (!empty($analysis['available'])) {
        echo '<p class="seo-status-note"><strong>Desde el chequeo anterior:</strong> BBDD '
            . esc_html(seo_server_status_format_bytes((float)($analysis['database_size_delta'] ?? 0)))
            . (!empty($analysis['server_restarted']) ? ' · MySQL parece haberse reiniciado; no se calculan tasas de contadores acumulados.' : '')
            . '</p>';
    }
    echo '</div>';
}

function seo_system_mysql_audit_render_table_size_chart($tables) {
    $rows = array_slice((array)$tables, 0, 10);
    if (!$rows) return;
    $max = 0.0;
    foreach ($rows as $row) $max = max($max, (float)($row['data_length'] ?? 0) + (float)($row['index_length'] ?? 0));
    if ($max <= 0) return;

    echo '<div class="seo-status-card"><h2>Distribución de las tablas SEO más grandes</h2>';
    echo '<p>Gráfico estático calculado con el inventario ya obtenido; no realiza consultas adicionales.</p>';
    echo '<div class="seo-mysql-table-chart">';
    foreach ($rows as $row) {
        $total = (float)($row['data_length'] ?? 0) + (float)($row['index_length'] ?? 0);
        $width = max(1.0, min(100.0, ($total / $max) * 100.0));
        echo '<div class="seo-mysql-table-row"><div><code>' . esc_html((string)($row['table'] ?? '')) . '</code></div>';
        echo '<div class="seo-mysql-table-track"><span style="width:' . esc_attr(number_format($width,2,'.','')) . '%"></span></div>';
        echo '<div>' . esc_html(seo_server_status_format_bytes($total)) . '</div></div>';
    }
    echo '</div></div>';
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
    $database = seo_system_mysql_audit_database_overview();
    $custom = seo_system_mysql_audit_custom_tables();
    $plans = seo_system_mysql_audit_explain_plans();
    $query_profile = function_exists('seo_server_status_mysql_query_benchmark')
        ? seo_server_status_mysql_query_benchmark()
        : array();
    $problem_plans = array_filter($plans, static function($row) {
        return in_array($row['status'] ?? 'info', array('warning','important','error'), true);
    });

    $cache = array(
        'schema'=>array('name'=>'seo-system-mysql-audit','version'=>'2.0.0'),
        'generated_at'=>time(),
        'database'=>$database,
        'runtime'=>$runtime,
        'custom'=>$custom,
        'plans'=>$plans,
        'query_profile'=>$query_profile,
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

    seo_system_mysql_audit_render_history();
    seo_system_mysql_audit_render_table_size_chart((array)($custom['tables'] ?? array()));

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
        array('Sort merge passes',number_format_i18n((float)($status['Sort_merge_passes']??0)),'info','Pasadas de mezcla de ordenación.'),
        array('InnoDB buffer hit',$derived['innodb_buffer_pool_hit_percent']===null?'N/D':number_format_i18n($derived['innodb_buffer_pool_hit_percent'],2).'%',($derived['innodb_buffer_pool_hit_percent']!==null&&$derived['innodb_buffer_pool_hit_percent']<99?'warning':'ok'),'Lecturas servidas desde el buffer pool frente a lecturas físicas.'),
        array('Table cache hit',$derived['table_open_cache_hit_percent']===null?'N/D':number_format_i18n($derived['table_open_cache_hit_percent'],2).'%',($derived['table_open_cache_hit_percent']!==null&&$derived['table_open_cache_hit_percent']<95?'warning':'ok'),'Aciertos de table_open_cache.'),
        array('Consultas / segundo',$derived['questions_per_second']===null?'N/D':number_format_i18n($derived['questions_per_second'],2),'info','Promedio global desde el arranque de MySQL.'),
        array('Full JOIN / millón',$derived['full_join_per_million_queries']===null?'N/D':number_format_i18n($derived['full_join_per_million_queries'],2),($derived['full_join_per_million_queries']!==null&&$derived['full_join_per_million_queries']>10?'warning':'info'),'Normaliza Select_full_join por volumen de consultas.'),
        array('Slow queries / millón',$derived['slow_queries_per_million']===null?'N/D':number_format_i18n($derived['slow_queries_per_million'],2),'info','Depende del umbral long_query_time configurado.'),
        array('Espera media lock InnoDB',$derived['innodb_row_lock_avg_ms']===null?'N/D':number_format_i18n($derived['innodb_row_lock_avg_ms'],2).' ms',($derived['innodb_row_lock_avg_ms']!==null&&$derived['innodb_row_lock_avg_ms']>100?'warning':'info'),'Tiempo medio acumulado por espera de bloqueo InnoDB.')
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
