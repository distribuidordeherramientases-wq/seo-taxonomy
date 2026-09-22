<?php
/**
 * SEO System
 *
 * Archivo: seo-faq.php
 * Descripción: Administración manual, auditoría y limpieza reversible de FAQs, huérfanas y duplicadas para Hubs SEO, categorías y productos.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;


/**
 * Nombre de la tabla de FAQs.
 *
 * Si tu tabla se llama exactamente "seo_faq", sin el prefijo de WordPress,
 * sustituye el retorno por: return 'seo_faq';
 */
function seo_faq_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'seo_faq';
}


/**
 * Registra la tabla de FAQs en el Data Layer.
 *
 * No modifica el esquema. Permite que altas, cambios y eliminaciones migradas
 * puedan auditarse y, cuando proceda, revertirse desde el Centro de Operaciones.
 */
function seo_faq_register_data_layer_table($tables)
{
    if (!is_array($tables)) {
        $tables = [];
    }

    $tables['faqs'] = [
        'table'       => seo_faq_table_name(),
        'primary_key' => ['id'],
        'entity_type' => 'faq',
    ];

    return $tables;
}
add_filter('seo_data_layer_tables', 'seo_faq_register_data_layer_table');


/**
 * Tabla agregada de telemetria de bloques FAQ.
 *
 * Mantiene las cargas/aperturas historicas por FAQ en seo_faq, pero separa
 * las metricas propias del bloque (render, viewport, engagement y errores).
 */
function seo_faq_metrics_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'seo_faq_metrics';
}

/**
 * Crea o actualiza la tabla de telemetria sin recopilar datos personales.
 */
function seo_faq_maybe_upgrade_metrics_schema()
{
    $schema_version = '2.1.0';

    if ((string) get_option('seo_faq_metrics_schema_version', '') === $schema_version) {
        return;
    }

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table   = seo_faq_metrics_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        object_type tinyint unsigned NOT NULL,
        object_id bigint unsigned NOT NULL,
        block_render_count bigint unsigned NOT NULL DEFAULT 0,
        block_view_count bigint unsigned NOT NULL DEFAULT 0,
        engaged_block_count bigint unsigned NOT NULL DEFAULT 0,
        faq_open_count bigint unsigned NOT NULL DEFAULT 0,
        render_error_count bigint unsigned NOT NULL DEFAULT 0,
        partial_render_count bigint unsigned NOT NULL DEFAULT 0,
        missing_render_count bigint unsigned NOT NULL DEFAULT 0,
        last_rendered_at datetime NULL,
        last_viewed_at datetime NULL,
        last_opened_at datetime NULL,
        last_error_at datetime NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (object_type, object_id),
        KEY last_rendered_at (last_rendered_at),
        KEY last_error_at (last_error_at)
    ) {$charset};";

    dbDelta($sql);
    update_option('seo_faq_metrics_schema_version', $schema_version, false);
}
add_action('init', 'seo_faq_maybe_upgrade_metrics_schema', 5);

/**
 * Firma estable por destino para aceptar telemetria publica sin exponer un
 * endpoint de escritura generico. No identifica al visitante.
 */
function seo_faq_tracking_token($object_type, $object_id)
{
    return hash_hmac(
        'sha256',
        (int) $object_type . ':' . absint($object_id),
        wp_salt('nonce')
    );
}

/**
 * Resuelve el destino FAQ correspondiente a la pagina publica actual.
 */
function seo_faq_frontend_tracking_context()
{
    if (is_admin() || wp_doing_ajax()) {
        return null;
    }

    global $wpdb;

    $table = seo_faq_table_name();
    $object_type = 0;
    $object_id   = 0;

    if (function_exists('is_product') && is_product()) {
        $object_type = 3;
        $object_id   = absint(get_queried_object_id());
    } elseif (function_exists('is_product_category') && is_product_category()) {
        $object_type = 2;
        $object_id   = absint(get_queried_object_id());
    } elseif (is_singular()) {
        $candidate = absint(get_queried_object_id());
        if ($candidate > 0) {
            $has_hub_faq = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE object_type = 1 AND object_id = %d AND active = 1",
                    $candidate
                )
            );
            if ($has_hub_faq > 0) {
                $object_type = 1;
                $object_id   = $candidate;
            }
        }
    }

    if ($object_type <= 0 || $object_id <= 0) {
        return null;
    }

    $faqs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, question
             FROM {$table}
             WHERE object_type = %d
               AND object_id = %d
               AND active = 1
             ORDER BY sort_order ASC, id ASC",
            $object_type,
            $object_id
        ),
        ARRAY_A
    );

    if (!is_array($faqs) || !$faqs) {
        return null;
    }

    return [
        'object_type' => $object_type,
        'object_id'   => $object_id,
        'faqs'        => array_map(
            static function ($row) {
                return [
                    'id'       => (int) $row['id'],
                    'question' => trim(wp_strip_all_tags((string) $row['question'])),
                ];
            },
            $faqs
        ),
    ];
}

/**
 * Inserta o incrementa contadores agregados del bloque FAQ.
 */
function seo_faq_increment_block_metric($object_type, $object_id, $metric)
{
    global $wpdb;

    $allowed = [
        'block_render_count'   => 'last_rendered_at',
        'block_view_count'     => 'last_viewed_at',
        'engaged_block_count'  => 'last_opened_at',
        'faq_open_count'       => 'last_opened_at',
        'render_error_count'   => 'last_error_at',
        'partial_render_count' => 'last_error_at',
        'missing_render_count' => 'last_error_at',
    ];

    if (!isset($allowed[$metric])) {
        return false;
    }

    seo_faq_maybe_upgrade_metrics_schema();

    $table      = seo_faq_metrics_table_name();
    $time_field = $allowed[$metric];
    $now        = current_time('mysql', true);

    $sql = "INSERT INTO {$table}
                (object_type, object_id, {$metric}, {$time_field}, updated_at)
            VALUES (%d, %d, 1, %s, %s)
            ON DUPLICATE KEY UPDATE
                {$metric} = {$metric} + 1,
                {$time_field} = VALUES({$time_field}),
                updated_at = VALUES(updated_at)";

    return $wpdb->query(
        $wpdb->prepare($sql, (int) $object_type, absint($object_id), $now, $now)
    );
}

/**
 * Endpoint de telemetria del frontend.
 *
 * Eventos:
 * - render: el bloque existe en el DOM; incrementa load_count de todas las FAQs activas.
 * - view: al menos una pregunta del bloque entra en viewport.
 * - open: se abre una FAQ concreta.
 * - render_error: el destino tiene FAQs activas pero no se localizaron en el DOM.
 */
function seo_faq_track_event()
{
    global $wpdb;

    $event       = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
    $object_type = isset($_POST['object_type']) ? absint($_POST['object_type']) : 0;
    $object_id   = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
    $faq_id      = isset($_POST['faq_id']) ? absint($_POST['faq_id']) : 0;
    $faq_ids     = isset($_POST['faq_ids']) ? wp_parse_id_list(wp_unslash($_POST['faq_ids'])) : [];
    $count_block = !isset($_POST['count_block']) || !empty($_POST['count_block']) ? 1 : 0;
    $error_type  = isset($_POST['error_type']) ? sanitize_key(wp_unslash($_POST['error_type'])) : '';
    $first_open  = !empty($_POST['first_open']) ? 1 : 0;
    $token       = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

    if (!in_array($object_type, [1, 2, 3], true) || $object_id <= 0) {
        wp_send_json_error(['message' => 'Destino FAQ invalido.'], 400);
    }

    if (!hash_equals(seo_faq_tracking_token($object_type, $object_id), $token)) {
        wp_send_json_error(['message' => 'Firma de tracking invalida.'], 403);
    }

    if (!in_array($event, ['render', 'view', 'open', 'render_error'], true)) {
        wp_send_json_error(['message' => 'Evento FAQ invalido.'], 400);
    }

    $table = seo_faq_table_name();

    $active_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE object_type = %d AND object_id = %d AND active = 1",
            $object_type,
            $object_id
        )
    );

    if ($active_count <= 0) {
        wp_send_json_error(['message' => 'El destino no tiene FAQs activas.'], 404);
    }

    if ($event === 'render') {
        $faq_ids = array_values(array_unique(array_filter(array_map('absint', $faq_ids))));

        if ($faq_ids) {
            $placeholders = implode(',', array_fill(0, count($faq_ids), '%d'));
            $params = array_merge([$object_type, $object_id], $faq_ids);
            $sql = "UPDATE {$table}
                    SET load_count = load_count + 1, updated_at = updated_at
                    WHERE object_type = %d
                      AND object_id = %d
                      AND active = 1
                      AND id IN ({$placeholders})";
            $wpdb->query($wpdb->prepare($sql, $params));
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET load_count = load_count + 1, updated_at = updated_at
                     WHERE object_type = %d AND object_id = %d AND active = 1",
                    $object_type,
                    $object_id
                )
            );
        }

        if ($count_block) {
            seo_faq_increment_block_metric($object_type, $object_id, 'block_render_count');
        }
    }

    if ($event === 'view') {
        seo_faq_increment_block_metric($object_type, $object_id, 'block_view_count');
    }

    if ($event === 'render_error') {
        seo_faq_increment_block_metric($object_type, $object_id, 'render_error_count');
        if ($error_type === 'partial') {
            seo_faq_increment_block_metric($object_type, $object_id, 'partial_render_count');
        } else {
            seo_faq_increment_block_metric($object_type, $object_id, 'missing_render_count');
        }
    }

    if ($event === 'open') {
        if ($faq_id <= 0) {
            wp_send_json_error(['message' => 'FAQ no indicada.'], 400);
        }

        $valid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE id = %d
                   AND object_type = %d
                   AND object_id = %d
                   AND active = 1",
                $faq_id,
                $object_type,
                $object_id
            )
        );

        if ($valid <= 0) {
            wp_send_json_error(['message' => 'FAQ no valida para este destino.'], 404);
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET open_count = open_count + 1, updated_at = updated_at WHERE id = %d",
                $faq_id
            )
        );

        seo_faq_increment_block_metric($object_type, $object_id, 'faq_open_count');
        if ($first_open) {
            seo_faq_increment_block_metric($object_type, $object_id, 'engaged_block_count');
        }
    }

    wp_send_json_success(['event' => $event]);
}
add_action('wp_ajax_seo_faq_track', 'seo_faq_track_event');
add_action('wp_ajax_nopriv_seo_faq_track', 'seo_faq_track_event');

/**
 * Puente de tracking compatible con renderizadores FAQ antiguos.
 *
 * No obliga a cambiar el HTML existente: localiza las preguntas activas por
 * texto, marca render/viewport y detecta aperturas en summary, botones y
 * cabeceras de acordeon. Si el renderizador ya expone data-seo-faq-id, se usa
 * ese atributo como via prioritaria.
 */
function seo_faq_render_frontend_tracking_bridge()
{
    $context = seo_faq_frontend_tracking_context();
    if (!$context) {
        return;
    }

    $payload = [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'objectType'  => (int) $context['object_type'],
        'objectId'    => (int) $context['object_id'],
        'token'       => seo_faq_tracking_token($context['object_type'], $context['object_id']),
        'faqs'        => $context['faqs'],
    ];
    ?>
    <script id="seo-faq-telemetry">
    (function () {
        'use strict';

        var cfg = <?php echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        if (!cfg || !cfg.faqs || !cfg.faqs.length) return;

        var sentRender = false;
        var sentView = false;
        var sentError = false;
        var firstOpen = true;
        var opened = new Set();
        var renderedIds = new Set();
        var observer = null;
        var mutationObserver = null;
        var stopTimer = null;

        function normalize(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[\u2018\u2019\u201c\u201d]/g, '"')
                .replace(/\s+/g, ' ')
                .trim();
        }

        cfg.faqs = cfg.faqs.map(function (faq) {
            faq.normalized = normalize(faq.question);
            return faq;
        });

        function send(event, extra) {
            var data = new URLSearchParams();
            data.set('action', 'seo_faq_track');
            data.set('event', event);
            data.set('object_type', cfg.objectType);
            data.set('object_id', cfg.objectId);
            data.set('token', cfg.token);

            Object.keys(extra || {}).forEach(function (key) {
                data.set(key, extra[key]);
            });

            if (navigator.sendBeacon) {
                navigator.sendBeacon(cfg.ajaxUrl, data);
                return;
            }

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: data.toString()
            }).catch(function () {});
        }

        function explicitNode(faq) {
            return document.querySelector('[data-seo-faq-id="' + faq.id + '"]');
        }

        function questionNode(faq) {
            var explicit = explicitNode(faq);
            if (explicit) return explicit;

            var selectors = [
                'details summary',
                'button',
                '[role="button"]',
                '.faq-question',
                '.accordion-title',
                '.accordion-header',
                '.elementor-tab-title',
                '.vc_tta-panel-title',
                'dt',
                'h2', 'h3', 'h4', 'h5'
            ];
            var nodes = document.querySelectorAll(selectors.join(','));

            for (var i = 0; i < nodes.length; i++) {
                var text = normalize(nodes[i].innerText || nodes[i].textContent);
                if (!text) continue;
                if (text === faq.normalized || text.indexOf(faq.normalized) === 0) {
                    return nodes[i];
                }
            }

            var fallback = document.querySelectorAll('div,span,p,a,strong');
            for (var j = 0; j < fallback.length; j++) {
                if (fallback[j].children && fallback[j].children.length > 4) continue;
                var fallbackText = normalize(fallback[j].innerText || fallback[j].textContent);
                if (fallbackText === faq.normalized) return fallback[j];
            }
            return null;
        }

        function markView(node) {
            if (sentView || !node) return;

            if (!('IntersectionObserver' in window)) {
                sentView = true;
                send('view');
                return;
            }

            if (!observer) {
                observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!sentView && entry.isIntersecting) {
                            sentView = true;
                            send('view');
                            observer.disconnect();
                        }
                    });
                }, {threshold: 0.1});
            }

            observer.observe(node);
        }

        function recordOpen(faq) {
            if (!faq || opened.has(faq.id)) return;
            if (!sentView) {
                sentView = true;
                send('view');
                if (observer) observer.disconnect();
            }
            opened.add(faq.id);
            send('open', {
                faq_id: faq.id,
                first_open: firstOpen ? 1 : 0
            });
            firstOpen = false;
        }

        function attachFaqNode(node, faq) {
            if (!node || node.dataset.seoFaqTracked === '1') return;
            node.dataset.seoFaqTracked = '1';
            node.dataset.seoFaqId = String(faq.id);
            node.addEventListener('click', function () { recordOpen(faq); }, true);
        }

        document.addEventListener('click', function (event) {
            var node = event.target;
            for (var depth = 0; node && depth < 7; depth++, node = node.parentElement) {
                var explicitId = node.dataset && node.dataset.seoFaqId ? parseInt(node.dataset.seoFaqId, 10) : 0;
                var text = normalize(node.innerText || node.textContent);
                var faq = cfg.faqs.find(function (item) {
                    if (explicitId && item.id === explicitId) return true;
                    return text && (text === item.normalized || (text.indexOf(item.normalized) === 0 && text.length <= item.normalized.length + 80));
                });
                if (faq) {
                    recordOpen(faq);
                    break;
                }
            }
        }, true);

        function scan() {
            var found = [];
            var bodyText = normalize(document.body ? document.body.innerText : '');
            var newIds = [];

            cfg.faqs.forEach(function (faq) {
                var appears = bodyText.indexOf(faq.normalized) !== -1;
                if (appears && !renderedIds.has(faq.id)) {
                    renderedIds.add(faq.id);
                    newIds.push(faq.id);
                }

                var node = questionNode(faq);
                if (node) {
                    found.push({faq: faq, node: node});
                    attachFaqNode(node, faq);
                }
            });

            if (newIds.length) {
                send('render', {
                    faq_ids: newIds.join(','),
                    count_block: sentRender ? 0 : 1
                });
                sentRender = true;
            }

            if (found.length) markView(found[0].node);
            return renderedIds.size === cfg.faqs.length;
        }

        function start() {
            scan();

            mutationObserver = new MutationObserver(function () { scan(); });
            mutationObserver.observe(document.documentElement, {childList: true, subtree: true});

            stopTimer = setTimeout(function () {
                scan();
                if (mutationObserver) mutationObserver.disconnect();
                mutationObserver = null;
                if (renderedIds.size < cfg.faqs.length && !sentError) {
                    sentError = true;
                    send('render_error', {
                        missing_count: cfg.faqs.length - renderedIds.size,
                        error_type: renderedIds.size > 0 ? 'partial' : 'missing'
                    });
                }
            }, 5000);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, {once: true});
        } else {
            start();
        }
    }());
    </script>
    <?php
}
add_action('wp_footer', 'seo_faq_render_frontend_tracking_bridge', 100);

/**
 * Exporta el informe completo de KPIs y filas de rendimiento en JSON.
 */
function seo_faq_export_json()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para exportar el informe de FAQs.');
    }

    check_admin_referer('seo_faq_export_json');
    seo_faq_maybe_upgrade_metrics_schema();

    $filters = seo_faq_report_read_performance_filters($_GET);
    $payload = [
        'meta' => [
            'schema_version' => '2.1.0',
            'generated_at'   => current_time('mysql', true),
            'timezone'       => 'UTC',
            'filters'        => $filters,
        ],
        'summary'          => seo_faq_report_get_summary(),
        'targets'          => seo_faq_report_get_target_counts(),
        'quality'          => seo_faq_report_get_quality_metrics(),
        'usage'            => seo_faq_report_get_usage_metrics(),
        'tracking'         => seo_faq_report_get_tracking_metrics(),
        'editorial'        => seo_faq_report_get_editorial_quality(),
        'performance_rows' => seo_faq_report_get_performance_rows($filters, 50000),
        'object_tracking'  => seo_faq_report_get_object_tracking_rows(50000),
    ];

    $filename = 'seo-faq-report-' . gmdate('Ymd-His') . '.json';
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
add_action('admin_post_seo_faq_export_json', 'seo_faq_export_json');


/**
 * Indica si el motor transaccional y de rollback está cargado.
 */
function seo_faq_data_layer_available()
{
    return class_exists('SEO_Data_Layer')
        && class_exists('SEO_Data_Operation')
        && class_exists('SEO_Data_Rollback');
}


/**
 * Crea una operación auditable del módulo FAQ.
 */
function seo_faq_begin_operation($type, $label, $risk_level, $expected_changes, $metadata = [])
{
    if (!seo_faq_data_layer_available()) {
        throw new RuntimeException('La capa de datos transaccional no está disponible.');
    }

    $operation = SEO_Data_Layer::operation([
        'type'          => sanitize_key($type),
        'label'         => sanitize_text_field($label),
        'source_module' => 'seo_faq',
        'rollbackable'  => true,
        'risk_level'    => sanitize_key($risk_level),
        'audit_level'   => 'full',
        'metadata'      => is_array($metadata) ? $metadata : [],
    ]);

    $operation->mark_validated([
        'validated_by'   => get_current_user_id(),
        'validated_from' => 'seo_faq',
    ]);

    $operation->mark_previewed(
        max(0, (int) $expected_changes),
        [
            'preview_generated_at' => current_time('mysql', true),
        ]
    );

    return $operation;
}


/**
 * Nombre técnico del destino relacionado con una FAQ.
 */
function seo_faq_related_object_type($object_type)
{
    if ((int) $object_type === 1) {
        return 'faq_hub';
    }

    if ((int) $object_type === 2) {
        return 'faq_category';
    }

    if ((int) $object_type === 3) {
        return 'faq_product';
    }

    return 'faq_unknown';
}


/**
 * Comprueba de nuevo, justo antes del borrado, que el destino sigue siendo
 * definitivamente huérfano. Evita eliminar una FAQ si el objeto fue recreado
 * entre la generación del informe y la confirmación del administrador.
 */
function seo_faq_is_target_definitively_orphan($object_type, $object_id)
{
    $object_type = (int) $object_type;
    $object_id   = absint($object_id);

    if ($object_id <= 0) {
        return true;
    }

    if ($object_type === 1) {
        return get_post($object_id) === null;
    }

    if ($object_type === 2) {
        $term = get_term($object_id, 'product_cat');
        return !$term || is_wp_error($term);
    }

    if ($object_type === 3) {
        $post = get_post($object_id);
        return !$post || $post->post_type !== 'product';
    }

    return true;
}


/**
 * Elimina filas de FAQ mediante una única operación transaccional.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{deleted:int,operation_id:int}
 */
function seo_faq_delete_rows_with_data_layer(
    $rows,
    $operation_type,
    $operation_label,
    $risk_level,
    $metadata = [],
    $require_orphan = false,
    $row_validator = null
) {
    if (!is_array($rows)) {
        $rows = [];
    }

    $unique = [];

    foreach ($rows as $row) {
        $id = isset($row['id']) ? absint($row['id']) : 0;

        if ($id <= 0) {
            continue;
        }

        $normalized = is_array($row) ? $row : [];
        $normalized['id']          = $id;
        $normalized['object_type'] = isset($row['object_type']) ? (int) $row['object_type'] : 0;
        $normalized['object_id']   = isset($row['object_id']) ? absint($row['object_id']) : 0;

        $unique[$id] = $normalized;
    }

    $rows = array_values($unique);

    if (!$rows) {
        return ['deleted' => 0, 'operation_id' => 0];
    }

    $counts_by_type = [];
    $destinations   = [];

    foreach ($rows as $row) {
        $type = (int) $row['object_type'];
        $counts_by_type[$type] = isset($counts_by_type[$type])
            ? $counts_by_type[$type] + 1
            : 1;
        $destinations[$type . ':' . (int) $row['object_id']] = true;
    }

    $metadata = array_replace_recursive(
        is_array($metadata) ? $metadata : [],
        [
            'faq_count'          => count($rows),
            'destination_count'  => count($destinations),
            'counts_by_type'     => $counts_by_type,
            'require_orphan'     => (bool) $require_orphan,
        ]
    );

    $operation = seo_faq_begin_operation(
        $operation_type,
        $operation_label,
        $risk_level,
        count($rows),
        $metadata
    );

    $deleted = $operation->execute(
        function ($transaction) use ($rows, $require_orphan, $row_validator) {
            $count  = 0;
            $config = SEO_Data_Layer::table('faqs');
            $table  = (string) $config['table'];

            foreach ($rows as $row) {
                $current = SEO_Data_Layer::fetch_row(
                    $table,
                    ['id' => (int) $row['id']],
                    true
                );

                if ($current === null) {
                    throw new RuntimeException(
                        'La FAQ #' . (int) $row['id'] . ' ya no existe.'
                    );
                }

                if (
                    (int) $current['object_type'] !== (int) $row['object_type'] ||
                    (int) $current['object_id'] !== (int) $row['object_id']
                ) {
                    throw new RuntimeException(
                        'La FAQ #' . (int) $row['id'] . ' cambió de destino antes de la operación.'
                    );
                }

                if (
                    $require_orphan &&
                    !seo_faq_is_target_definitively_orphan(
                        (int) $current['object_type'],
                        (int) $current['object_id']
                    )
                ) {
                    throw new RuntimeException(
                        'El destino de la FAQ #' . (int) $row['id'] . ' vuelve a existir. Se ha cancelado toda la operación.'
                    );
                }

                if (is_callable($row_validator)) {
                    $validation = call_user_func($row_validator, $current, $row);

                    if ($validation !== true) {
                        throw new RuntimeException(
                            is_string($validation) && $validation !== ''
                                ? $validation
                                : 'La FAQ #' . (int) $row['id'] . ' ya no cumple las condiciones de la operación.'
                        );
                    }
                }

                $transaction->delete(
                    'faqs',
                    ['id' => (int) $row['id']],
                    [
                        'related_object_type' => seo_faq_related_object_type((int) $current['object_type']),
                        'related_object_id'   => (int) $current['object_id'],
                    ]
                );

                $count++;
            }

            return $count;
        }
    );

    foreach ($rows as $row) {
        seo_faq_clear_cache((int) $row['object_type'], (int) $row['object_id']);
    }

    return [
        'deleted'      => (int) $deleted,
        'operation_id' => (int) $operation->id(),
    ];
}


/**
 * Muestra los criterios editoriales y las recomendaciones de Google para
 * crear FAQs de producto útiles, verificables y no repetitivas.
 */
function seo_faq_render_product_guidelines_details()
{
    ?>
    <details
        class="seo-faq-product-guidelines"
        style="
            margin:20px 0;
            max-width:1100px;
            background:#fff;
            border:1px solid #c3c4c7;
            border-left:4px solid #2271b1;
            border-radius:4px;
        "
    >
        <summary
            style="
                cursor:pointer;
                padding:16px 18px;
                font-size:15px;
                font-weight:600;
                color:#1d2327;
            "
        >
            Criterios de calidad y recomendaciones de Google para FAQs de producto
        </summary>

        <div style="padding:0 18px 20px;line-height:1.6;">
            <p>
                Las FAQs de producto deben responder dudas reales que un cliente
                podría plantearse antes de comprar. No deben utilizarse para
                rellenar contenido, repetir la ficha técnica, añadir palabras
                clave ni fabricar respuestas sin información suficiente.
            </p>

            <div
                style="
                    margin:16px 0;
                    padding:14px 16px;
                    background:#f0f6fc;
                    border-left:4px solid #2271b1;
                "
            >
                <h3 style="margin:0 0 8px;">Regla principal</h3>

                <p style="margin:0 0 8px;">
                    Los atributos aportan datos técnicos y las etiquetas ayudan
                    a comprender el contexto. Las FAQs deben convertir esa
                    información en respuestas útiles para tomar una decisión de
                    compra.
                </p>

                <p style="margin:0;">
                    Los atributos y las etiquetas se usarán solo como apoyo
                    contextual. No se copiarán, enumerarán ni convertirán
                    automáticamente en preguntas y respuestas.
                </p>
            </div>

            <h3>Qué debe resolver una FAQ de producto</h3>

            <ul>
                <li>
                    <strong>Compatibilidad:</strong> qué debe comprobar el cliente
                    para saber si el producto sirve con su máquina, batería,
                    vehículo, conexión, medida o accesorio.
                </li>
                <li>
                    <strong>Uso:</strong> para qué tareas resulta adecuado y en
                    qué situaciones puede utilizarse.
                </li>
                <li>
                    <strong>Límites:</strong> cuándo no es apropiado, qué no hace
                    o qué condición puede impedir su utilización.
                </li>
                <li>
                    <strong>Instalación:</strong> si necesita montaje,
                    herramientas, adaptadores, configuración o conocimientos
                    específicos.
                </li>
                <li>
                    <strong>Accesorios:</strong> qué incluye y qué debe comprarse
                    por separado, únicamente cuando esté documentado.
                </li>
                <li>
                    <strong>Elección del modelo:</strong> qué medida, potencia,
                    capacidad o versión conviene según la necesidad del comprador.
                </li>
                <li>
                    <strong>Mantenimiento:</strong> limpieza, conservación,
                    calibración o sustitución de consumibles cuando exista
                    información verificable.
                </li>
            </ul>

            <h3>Función y prioridad de las fuentes</h3>

            <ol>
                <li>
                    <strong>Título:</strong> identifica el producto, modelo y
                    características principales.
                </li>
                <li>
                    <strong>Categoría:</strong> confirma la función real y la
                    familia comercial del artículo.
                </li>
                <li>
                    <strong>Atributos verificados:</strong> aportan medidas,
                    potencia, tensión, capacidad, material, conexión u otros datos
                    técnicos.
                </li>
                <li>
                    <strong>Marca, modelo, referencia y SKU:</strong> identifican
                    la variante concreta, pero el SKU no debe interpretarse como
                    una especificación técnica.
                </li>
                <li>
                    <strong>Etiquetas coherentes:</strong> aportan contexto de
                    uso, aplicación o familia, pero no demuestran por sí solas una
                    compatibilidad.
                </li>
                <li>
                    <strong>Fabricante o proveedor:</strong> puede respaldar
                    compatibilidades, contenido del paquete, instalación y límites.
                </li>
                <li>
                    <strong>Excerpt y descripción:</strong> se usarán solo cuando
                    estén validados para ese producto concreto y no presenten
                    contenido genérico o cruzado con otra categoría.
                </li>
            </ol>

            <h3>Recomendaciones de Google aplicables</h3>

            <div
                style="
                    margin:16px 0;
                    padding:14px 16px;
                    background:#fff8e5;
                    border-left:4px solid #dba617;
                "
            >
                <p style="margin-top:0;">
                    Las FAQs se crean para ayudar al comprador, no para obtener
                    automáticamente un resultado enriquecido ni para aumentar de
                    forma artificial la cantidad de texto de la página.
                </p>

                <ul style="margin-bottom:0;">
                    <li>
                        Google no garantiza que unos datos estructurados correctos
                        se muestren como resultado enriquecido.
                    </li>
                    <li>
                        Google anunció que los resultados enriquecidos de FAQ se
                        mostrarían regularmente solo en sitios gubernamentales y
                        sanitarios conocidos y autorizados. Una tienda no debe
                        basar su estrategia en conseguir ese formato visual.
                    </li>
                    <li>
                        El contenido descrito por datos estructurados debe coincidir
                        con el contenido que el usuario puede consultar en la página
                        y no debe ser engañoso.
                    </li>
                    <li>
                        Las preguntas pueden mostrarse dentro de un acordeón o
                        elemento <code>&lt;details&gt;</code>, siempre que el usuario
                        pueda abrirlas y leerlas normalmente.
                    </li>
                    <li>
                        Generar contenido a gran escala con IA o automatización no
                        es un problema por sí mismo. Puede infringir las políticas
                        de spam cuando se produce principalmente para manipular la
                        búsqueda y aporta poco o ningún valor al usuario.
                    </li>
                    <li>
                        En una página donde se vende un producto, los datos
                        estructurados principales deben representar el producto y
                        su oferta: <code>Product</code>, <code>Offer</code>, precio,
                        disponibilidad, imagen y demás propiedades aplicables.
                    </li>
                </ul>
            </div>

            <p>
                Si el sitio genera marcado estructurado para las FAQs, las
                preguntas y respuestas del marcado deben coincidir con las que el
                cliente puede leer en la ficha. No deben añadirse al JSON-LD FAQs
                ocultas, diferentes o inexistentes en la página.
            </p>

            <h3>Transformar datos en dudas reales</h3>

            <p>
                Una característica técnica solo debe convertirse en FAQ cuando
                permita resolver una decisión, una condición de uso o una
                limitación relevante.
            </p>

            <div style="overflow-x:auto;">
                <table class="widefat striped" style="margin:12px 0 18px;">
                    <thead>
                        <tr>
                            <th>Dato disponible</th>
                            <th>Pregunta que repite la ficha</th>
                            <th>Pregunta útil para comprar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Tensión: 18 V</td>
                            <td>¿Qué tensión tiene?</td>
                            <td>¿Puedo utilizarlo con cualquier batería de 18 V?</td>
                        </tr>
                        <tr>
                            <td>Capacidad: 50 litros</td>
                            <td>¿Qué capacidad tiene?</td>
                            <td>¿Qué tipo de uso permite una capacidad de 50 litros?</td>
                        </tr>
                        <tr>
                            <td>Rosca: M14</td>
                            <td>¿Qué rosca utiliza?</td>
                            <td>¿Qué debo medir para comprobar que la rosca M14 es compatible?</td>
                        </tr>
                        <tr>
                            <td>Material: acero inoxidable</td>
                            <td>¿De qué material está fabricado?</td>
                            <td>¿Es adecuado para humedad o uso exterior?</td>
                        </tr>
                        <tr>
                            <td>Potencia: 2,2 kW</td>
                            <td>¿Cuál es su potencia?</td>
                            <td>¿Para qué nivel de trabajo resulta adecuada una potencia de 2,2 kW?</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Reglas de redacción</h3>

            <ul>
                <li>La pregunta debe parecer formulada por un comprador real.</li>
                <li>La respuesta debe contestar directamente desde la primera frase.</li>
                <li>
                    Debe explicar la consecuencia práctica del dato técnico, no
                    limitarse a repetirlo.
                </li>
                <li>
                    Cuando la compatibilidad dependa de varios factores, debe
                    indicar qué debe comprobar el cliente.
                </li>
                <li>
                    No deben copiarse frases completas del título, atributos,
                    etiquetas, excerpt o descripción.
                </li>
                <li>
                    No debe convertirse cada atributo disponible en una FAQ.
                </li>
                <li>
                    No deben repetirse las mismas preguntas en todos los productos
                    de una categoría cambiando únicamente el modelo o una cifra.
                </li>
                <li>
                    No deben afirmarse compatibilidades, certificaciones,
                    materiales, accesorios, duración o rendimiento que no estén
                    documentados.
                </li>
                <li>
                    Las preguntas sobre pagos, envíos y devoluciones pertenecen a
                    la tienda, no a la FAQ específica del producto.
                </li>
            </ul>

            <h3>Cantidad recomendada</h3>

            <ul>
                <li><strong>2 FAQs:</strong> productos sencillos y bien definidos.</li>
                <li>
                    <strong>3 o 4 FAQs:</strong> productos con variantes,
                    compatibilidades o condiciones de instalación.
                </li>
                <li>
                    <strong>5 FAQs:</strong> productos técnicos cuya elección
                    requiera comparar modelos, capacidades o configuraciones.
                </li>
                <li>
                    <strong>0 FAQs automáticas:</strong> productos con información
                    insuficiente, contradictoria, genérica o no verificada.
                </li>
            </ul>

            <h3>Ejemplo correcto</h3>

            <div
                style="
                    margin:12px 0 18px;
                    padding:14px 16px;
                    background:#edfaef;
                    border-left:4px solid #00a32a;
                "
            >
                <p style="margin-top:0;">
                    <strong>¿Puedo utilizar este cargador con cualquier batería de 18 V?</strong>
                </p>

                <p>
                    No necesariamente. Además de la tensión, comprueba la
                    referencia de la batería, el tipo de conexión y la familia de
                    herramientas indicada por el fabricante. Dos baterías de 18 V
                    pueden utilizar conectores o sistemas electrónicos diferentes.
                </p>

                <p style="margin-bottom:0;">
                    La pregunta parte de un atributo, pero resuelve una duda real
                    y evita asumir una compatibilidad universal.
                </p>
            </div>

            <h3>Ejemplo incorrecto</h3>

            <div
                style="
                    margin:12px 0 18px;
                    padding:14px 16px;
                    background:#fcf0f1;
                    border-left:4px solid #d63638;
                "
            >
                <p style="margin-top:0;">
                    <strong>¿Qué tensión tiene este cargador?</strong>
                </p>

                <p>Este cargador tiene una tensión de 18 V.</p>

                <p style="margin-bottom:0;">
                    La respuesta solo repite un atributo y no ayuda a decidir,
                    instalar, comprobar compatibilidad ni evitar un error.
                </p>
            </div>

            <h3>Validación antes de guardar o generar</h3>

            <ol>
                <li>¿Existe una duda de compra concreta?</li>
                <li>¿La respuesta está respaldada por información verificada?</li>
                <li>¿Aporta algo distinto de los atributos y etiquetas visibles?</li>
                <li>¿Corresponde realmente a este producto y a su categoría?</li>
                <li>¿Evita deducir compatibilidades por una sola coincidencia?</li>
                <li>¿Es distinta de las demás FAQs del producto?</li>
                <li>¿Seguiría siendo útil aunque no existiera ningún beneficio SEO?</li>
            </ol>

            <p>
                Si alguna respuesta esencial es negativa, no debe generarse la
                FAQ automática. El producto debe marcarse para revisión manual.
            </p>

            <div
                style="
                    margin:16px 0;
                    padding:14px 16px;
                    background:#f6f7f7;
                    border:1px solid #dcdcde;
                "
            >
                <p style="margin-top:0;">
                    <strong>Resumen:</strong> los atributos aportan datos, las
                    etiquetas aportan contexto y las FAQs explican cómo afecta esa
                    información a la compra, compatibilidad o uso del producto.
                    Cuando no exista una duda concreta o falte información
                    verificada, no se generará la FAQ.
                </p>

                <p style="margin-bottom:6px;"><strong>Fuentes oficiales de Google:</strong></p>

                <ul style="margin-bottom:0;">
                    <li>
                        <a href="https://developers.google.com/search/docs/appearance/structured-data/sd-policies?hl=es" target="_blank" rel="noopener noreferrer">
                            Directrices generales sobre datos estructurados
                        </a>
                    </li>
                    <li>
                        <a href="https://developers.google.com/search/docs/essentials/spam-policies?hl=es" target="_blank" rel="noopener noreferrer">
                            Políticas de spam de la Búsqueda de Google
                        </a>
                    </li>
                    <li>
                        <a href="https://developers.google.com/search/docs/fundamentals/using-gen-ai-content?hl=es" target="_blank" rel="noopener noreferrer">
                            Directrices sobre contenido generado con IA
                        </a>
                    </li>
                    <li>
                        <a href="https://developers.google.com/search/docs/appearance/structured-data/product?hl=es" target="_blank" rel="noopener noreferrer">
                            Datos estructurados de producto
                        </a>
                    </li>
                    <li>
                        <a href="https://developers.google.com/search/blog/2023/08/howto-faq-changes?hl=es" target="_blank" rel="noopener noreferrer">
                            Cambios en los resultados enriquecidos de FAQ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </details>
    <?php
}


/**
 * Pantalla principal del módulo.
 */
function seo_faq_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para acceder a esta página.');
    }

    $tabs = [
        'hubs' => [
            'label'       => 'Hubs SEO',
            'object_type' => 1,
        ],
        'categories' => [
            'label'       => 'Categorías',
            'object_type' => 2,
        ],
        'products' => [
            'label'       => 'Productos',
            'object_type' => 3,
        ],
        'report' => [
            'label'       => 'Informe',
            'object_type' => 0,
        ],
    ];

    $current_tab = isset($_GET['tab'])
        ? sanitize_key(wp_unslash($_GET['tab']))
        : 'hubs';

    if (!isset($tabs[$current_tab])) {
        $current_tab = 'hubs';
    }

    $object_type = (int) $tabs[$current_tab]['object_type'];

    $filters   = [];
    $hierarchy = [];
    $targets   = [];
    $object_id = 0;
    $edit_id   = 0;

    /*
     * El informe mantiene la creación y edición general en modo lectura, pero
     * puede ejecutar limpiezas de FAQs definitivamente huérfanas. Todas esas
     * eliminaciones pasan por el Data Layer y son reversibles.
     */
    if ($current_tab === 'report') {
        seo_faq_process_report_action();
    } else {
        /*
         * Procesar antes de generar HTML para permitir redirecciones seguras.
         */
        seo_faq_process_bulk_create($current_tab, $object_type);
        seo_faq_process_action($current_tab, $object_type);

        $filters   = seo_faq_read_classification_filters($_GET);
        $hierarchy = seo_faq_get_classification_hierarchy($filters);
        $filters   = $hierarchy['filters'];

        $targets = seo_faq_get_classification_targets(
            $current_tab,
            $filters,
            $hierarchy
        );

        $object_id = isset($_GET['object_id'])
            ? absint($_GET['object_id'])
            : 0;

        $edit_id = isset($_GET['edit_faq'])
            ? absint($_GET['edit_faq'])
            : 0;
    }

    ?>
    <div class="wrap seo-faq-admin">

        <h1>SEO System - FAQs</h1>



<nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_key => $tab) : ?>
                <?php
                $tab_url = add_query_arg(
                    [
                        'page' => 'seo-faq',
                        'tab'  => $tab_key,
                    ],
                    admin_url('admin.php')
                );
                ?>

                <a
                    href="<?php echo esc_url($tab_url); ?>"
                    class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>"
                >
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php seo_faq_show_notice(); ?>

        <?php if ($current_tab === 'products') : ?>
            <?php seo_faq_render_product_guidelines_details(); ?>
        <?php endif; ?>

        <?php if ($current_tab === 'report') : ?>
            <?php seo_faq_render_report(); ?>
        <?php else : ?>
            <?php
            seo_faq_render_classification_filters(
                $current_tab,
                $filters,
                $hierarchy
            );
            ?>

            <?php
            seo_faq_render_classification_targets(
                $current_tab,
                $object_type,
                $filters,
                $targets
            );
            ?>

            <?php if ($object_id > 0) : ?>
                <?php
                seo_faq_render_management(
                    $current_tab,
                    $object_type,
                    $object_id,
                    $edit_id,
                    $filters
                );
                ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
    <?php
}


/**
 * Lee y sanea los filtros de clasificación.
 */
function seo_faq_read_classification_filters($source)
{
    $hub_level = isset($source['hub_level'])
        ? sanitize_key(wp_unslash($source['hub_level']))
        : 'hub_secondary';

    if (!in_array($hub_level, ['cluster', 'hub_primary', 'hub_secondary'], true)) {
        $hub_level = 'hub_secondary';
    }

    $faq_presence = isset($source['faq_presence'])
        ? sanitize_key(wp_unslash($source['faq_presence']))
        : 'all';

    if (!in_array($faq_presence, ['all', 'with', 'without'], true)) {
        $faq_presence = 'all';
    }

    return [
        'cluster'        => isset($source['cluster']) ? absint($source['cluster']) : 0,
        'hub_primario'   => isset($source['hub_primario']) ? absint($source['hub_primario']) : 0,
        'hub_secundario' => isset($source['hub_secundario']) ? absint($source['hub_secundario']) : 0,
        'cat'            => isset($source['cat']) ? absint($source['cat']) : 0,
        'hub_level'      => $hub_level,
        'faq_search'     => isset($source['faq_search']) ? sanitize_text_field(wp_unslash($source['faq_search'])) : '',
        'faq_presence'   => $faq_presence,
    ];
}


/**
 * Obtiene la jerarquía desde wp_seo_relations y normaliza filtros inválidos.
 */
function seo_faq_get_classification_hierarchy($filters)
{
    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    $cluster_ids = array_map(
        'absint',
        $wpdb->get_col(
            "
            SELECT DISTINCT source_id
            FROM {$relations_table}
            WHERE source_type = 'cluster'
              AND source_id > 0
            ORDER BY source_id ASC
            "
        )
    );

    if (
        $filters['cluster'] > 0 &&
        !in_array($filters['cluster'], $cluster_ids, true)
    ) {
        $filters['cluster'] = 0;
    }

    $hub_primarios_ids = [];

    if ($filters['cluster'] > 0) {
        $hub_primarios_ids = array_map(
            'absint',
            $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT target_id
                    FROM {$relations_table}
                    WHERE source_id = %d
                      AND relation_type = 'cluster_to_primary'
                      AND target_id > 0
                    ORDER BY target_id ASC
                    ",
                    $filters['cluster']
                )
            )
        );
    }

    if (
        $filters['hub_primario'] > 0 &&
        !in_array($filters['hub_primario'], $hub_primarios_ids, true)
    ) {
        $filters['hub_primario']   = 0;
        $filters['hub_secundario'] = 0;
        $filters['cat']            = 0;
    }

    $hub_secundarios_ids = [];

    if ($filters['hub_primario'] > 0) {
        $hub_secundarios_ids = array_map(
            'absint',
            $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT target_id
                    FROM {$relations_table}
                    WHERE source_id = %d
                      AND relation_type = 'hub_primary_to_hub_secondary'
                      AND target_id > 0
                    ORDER BY target_id ASC
                    ",
                    $filters['hub_primario']
                )
            )
        );
    }

    if (
        $filters['hub_secundario'] > 0 &&
        !in_array($filters['hub_secundario'], $hub_secundarios_ids, true)
    ) {
        $filters['hub_secundario'] = 0;
        $filters['cat']            = 0;
    }

    $category_ids = [];

    if ($filters['hub_secundario'] > 0) {
        $category_ids = array_map(
            'absint',
            $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT target_id
                    FROM {$relations_table}
                    WHERE source_id = %d
                      AND relation_type = 'hub_secondary_to_category'
                      AND target_id > 0
                    ORDER BY target_id ASC
                    ",
                    $filters['hub_secundario']
                )
            )
        );
    }

    if (
        $filters['cat'] > 0 &&
        !in_array($filters['cat'], $category_ids, true)
    ) {
        $filters['cat'] = 0;
    }

    return [
        'filters'             => $filters,
        'cluster_ids'         => $cluster_ids,
        'hub_primarios_ids'   => $hub_primarios_ids,
        'hub_secundarios_ids' => $hub_secundarios_ids,
        'category_ids'        => $category_ids,
    ];
}


/**
 * Devuelve los elementos seleccionables según la pestaña y los filtros.
 */
function seo_faq_get_classification_targets($current_tab, $filters, $hierarchy)
{
    $targets = [];

    if ($current_tab === 'hubs') {
        if ($filters['hub_level'] === 'cluster') {
            $ids = $filters['cluster'] > 0
                ? [$filters['cluster']]
                : $hierarchy['cluster_ids'];
        } elseif ($filters['hub_level'] === 'hub_primary') {
            $ids = $filters['hub_primario'] > 0
                ? [$filters['hub_primario']]
                : $hierarchy['hub_primarios_ids'];
        } else {
            $ids = $filters['hub_secundario'] > 0
                ? [$filters['hub_secundario']]
                : $hierarchy['hub_secundarios_ids'];
        }

        foreach (array_unique(array_map('absint', $ids)) as $post_id) {
            $post = get_post($post_id);

            if (!$post) {
                continue;
            }

            $targets[] = [
                'id'    => $post_id,
                'label' => $post->post_title ?: 'Página #' . $post_id,
            ];
        }
    }

    if ($current_tab === 'categories') {
        $ids = $filters['cat'] > 0
            ? [$filters['cat']]
            : $hierarchy['category_ids'];

        foreach (array_unique(array_map('absint', $ids)) as $term_id) {
            $term = get_term($term_id, 'product_cat');

            if (!$term || is_wp_error($term)) {
                continue;
            }

            $targets[] = [
                'id'    => $term_id,
                'label' => $term->name,
            ];
        }
    }

    if ($current_tab === 'products' && $filters['cat'] > 0) {
        $query = new WP_Query(
            [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => [
                    [
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => [$filters['cat']],
                        'include_children' => false,
                    ],
                ],
            ]
        );

        foreach ($query->posts as $product_id) {
            $product_id = absint($product_id);

            $targets[] = [
                'id'    => $product_id,
                'label' => get_the_title($product_id) ?: 'Producto #' . $product_id,
            ];
        }
    }

    usort(
        $targets,
        static function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        }
    );

    return $targets;
}


/**
 * Renderiza los filtros Cluster > Hub primario > Hub secundario > Categoría.
 */
function seo_faq_render_classification_filters($current_tab, $filters, $hierarchy)
{
    ?>
    <div class="seo-faq-classification-filters" style="margin-top:24px;padding:16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">
        <h2 style="margin-top:0;">Clasificacion y busqueda</h2>
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="seo-faq">
            <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">

            <?php if ($current_tab === 'hubs') : ?>
                <select name="hub_level" onchange="this.form.submit()">
                    <option value="cluster" <?php selected($filters['hub_level'], 'cluster'); ?>>Clusters</option>
                    <option value="hub_primary" <?php selected($filters['hub_level'], 'hub_primary'); ?>>Hubs primarios</option>
                    <option value="hub_secondary" <?php selected($filters['hub_level'], 'hub_secondary'); ?>>Hubs secundarios</option>
                </select>
            <?php endif; ?>

            <select name="cluster" onchange="this.form.submit()">
                <option value="0">Todos los clusters</option>
                <?php foreach ($hierarchy['cluster_ids'] as $id) : ?>
                    <?php $post = get_post($id); ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($filters['cluster'], $id); ?>>
                        <?php echo esc_html($post ? $post->post_title : 'Cluster ' . $id); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="hub_primario" onchange="this.form.submit()">
                <option value="0">Todos los hubs primarios</option>
                <?php foreach ($hierarchy['hub_primarios_ids'] as $id) : ?>
                    <?php $post = get_post($id); ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($filters['hub_primario'], $id); ?>>
                        <?php echo esc_html($post ? $post->post_title : 'Hub primario ' . $id); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="hub_secundario" onchange="this.form.submit()">
                <option value="0">Todos los hubs secundarios</option>
                <?php foreach ($hierarchy['hub_secundarios_ids'] as $id) : ?>
                    <?php $post = get_post($id); ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($filters['hub_secundario'], $id); ?>>
                        <?php echo esc_html($post ? $post->post_title : 'Hub secundario ' . $id); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($current_tab === 'categories' || $current_tab === 'products') : ?>
                <select name="cat" onchange="this.form.submit()">
                    <option value="0">Todas las categorias</option>
                    <?php foreach ($hierarchy['category_ids'] as $term_id) : ?>
                        <?php $term = get_term($term_id, 'product_cat'); ?>
                        <?php if (!$term || is_wp_error($term)) continue; ?>
                        <option value="<?php echo esc_attr($term_id); ?>" <?php selected($filters['cat'], $term_id); ?>>
                            <?php echo esc_html($term->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <input type="search" name="faq_search" value="<?php echo esc_attr($filters['faq_search']); ?>" placeholder="Buscar por nombre o ID" style="min-width:220px;">

            <select name="faq_presence">
                <option value="all" <?php selected($filters['faq_presence'], 'all'); ?>>Con y sin FAQs</option>
                <option value="with" <?php selected($filters['faq_presence'], 'with'); ?>>Solo con FAQs</option>
                <option value="without" <?php selected($filters['faq_presence'], 'without'); ?>>Solo sin FAQs</option>
            </select>

            <?php submit_button('Aplicar', 'secondary', '', false); ?>
            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'seo-faq', 'tab' => $current_tab], admin_url('admin.php'))); ?>">Limpiar</a>
        </form>
    </div>
    <?php
}


/**
 * Renderiza los resultados y el formulario de creación individual o múltiple.
 */
function seo_faq_render_classification_targets(
    $current_tab,
    $object_type,
    $filters,
    $targets
) {
    if (!$targets) {
        echo '<p style="margin-top:20px;color:#646970;">Selecciona la clasificacion necesaria para mostrar elementos.</p>';
        return;
    }

    $counts = seo_faq_get_target_counts($object_type, wp_list_pluck($targets, 'id'));
    $search = trim((string) ($filters['faq_search'] ?? ''));
    $presence = (string) ($filters['faq_presence'] ?? 'all');

    $targets = array_values(array_filter(
        $targets,
        static function ($target) use ($counts, $search, $presence) {
            $id = (int) $target['id'];
            $count = isset($counts[$id]) ? (int) $counts[$id] : 0;

            if ($presence === 'with' && $count <= 0) return false;
            if ($presence === 'without' && $count > 0) return false;

            if ($search !== '') {
                $haystack = (string) $target['label'] . ' #' . $id;
                if (function_exists('mb_stripos')) {
                    if (mb_stripos($haystack, $search, 0, 'UTF-8') === false) return false;
                } elseif (stripos($haystack, $search) === false) {
                    return false;
                }
            }
            return true;
        }
    ));

    if (!$targets) {
        echo '<div class="notice notice-info inline"><p>No hay elementos que coincidan con los filtros actuales.</p></div>';
        return;
    }

    ?>
    <form method="post" style="margin-top:24px;">
        <?php wp_nonce_field('seo_faq_bulk_create'); ?>
        <input type="hidden" name="seo_faq_action" value="bulk_create">
        <?php seo_faq_render_filter_hidden_fields($filters); ?>

        <h2>Elementos <span class="description">(<?php echo esc_html(number_format_i18n(count($targets))); ?>)</span></h2>
        <p>Selecciona uno o varios destinos para crear una misma FAQ. El contador muestra todas las FAQs existentes del destino.</p>

        <table class="widefat striped">
            <thead><tr>
                <th style="width:50px;"><input type="checkbox" onclick="var checked=this.checked;document.querySelectorAll('.seo-faq-target').forEach(function(el){el.checked=checked;});" aria-label="Seleccionar todos"></th>
                <th>Elemento</th><th style="width:100px;">FAQs</th><th style="width:140px;">Accion</th>
            </tr></thead>
            <tbody>
            <?php foreach ($targets as $target) : ?>
                <?php
                $manage_args = seo_faq_build_filter_args($current_tab, $filters);
                $manage_args['object_id'] = $target['id'];
                $manage_url = add_query_arg($manage_args, admin_url('admin.php'));
                ?>
                <tr>
                    <td><input type="checkbox" class="seo-faq-target" name="target_ids[]" value="<?php echo esc_attr($target['id']); ?>"></td>
                    <td><?php echo esc_html($target['label']); ?> <code>#<?php echo esc_html((string) $target['id']); ?></code></td>
                    <td><?php echo esc_html($counts[$target['id']] ?? 0); ?></td>
                    <td><a href="<?php echo esc_url($manage_url); ?>" class="button button-secondary">Gestionar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="max-width:900px;margin-top:24px;padding:20px;background:#fff;border:1px solid #ccd0d4;">
            <h2>Nueva FAQ</h2>
            <?php seo_faq_render_fields(null, 'bulk'); ?>
            <?php submit_button('Crear FAQ en los elementos seleccionados'); ?>
        </div>
    </form>
    <?php
}


/**
 * Procesa la creación para uno o varios elementos.
 *
 * Cada elemento seleccionado genera un INSERT independiente.
 */
function seo_faq_process_bulk_create($current_tab, $object_type)
{
    if (
        !isset($_SERVER['REQUEST_METHOD']) ||
        $_SERVER['REQUEST_METHOD'] !== 'POST'
    ) {
        return;
    }

    $action = isset($_POST['seo_faq_action'])
        ? sanitize_key(wp_unslash($_POST['seo_faq_action']))
        : '';

    if ($action !== 'bulk_create') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    check_admin_referer('seo_faq_bulk_create');

    global $wpdb;

    $filters   = seo_faq_read_classification_filters($_POST);
    $hierarchy = seo_faq_get_classification_hierarchy($filters);
    $filters   = $hierarchy['filters'];

    $allowed_targets = seo_faq_get_classification_targets(
        $current_tab,
        $filters,
        $hierarchy
    );

    $allowed_ids = array_map(
        'absint',
        wp_list_pluck($allowed_targets, 'id')
    );

    $posted_ids = isset($_POST['target_ids'])
        ? wp_parse_id_list(wp_unslash($_POST['target_ids']))
        : [];

    $target_ids = array_values(
        array_intersect($posted_ids, $allowed_ids)
    );

    $question = isset($_POST['question'])
        ? sanitize_text_field(wp_unslash($_POST['question']))
        : '';

    $question = seo_faq_limit_question($question);

    $answer = isset($_POST['answer'])
        ? wp_kses_post(wp_unslash($_POST['answer']))
        : '';

    $sort_order = isset($_POST['sort_order'])
        ? absint($_POST['sort_order'])
        : 0;

    $active = isset($_POST['active']) ? 1 : 0;

    if (!$target_ids || $question === '' || trim(wp_strip_all_tags($answer)) === '') {
        seo_faq_redirect(
            $current_tab,
            $filters,
            0,
            'missing',
            0
        );
    }

    $table    = seo_faq_table_name();
    $inserted = 0;

    foreach ($target_ids as $target_id) {
        $result = $wpdb->insert(
            $table,
            [
                'object_type' => $object_type,
                'object_id'   => $target_id,
                'question'    => $question,
                'answer'      => $answer,
                'sort_order'  => $sort_order,
                'active'      => $active,
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%d',
                '%d',
            ]
        );

        if ($result !== false) {
            $inserted++;
            seo_faq_clear_cache($object_type, $target_id);
        }
    }

    seo_faq_redirect(
        $current_tab,
        $filters,
        0,
        $inserted > 0 ? 'created_multiple' : 'error',
        $inserted
    );
}


/**
 * Procesa alta individual, edición, activación, desactivación y eliminación.
 */
function seo_faq_process_action($current_tab, $object_type)
{
    if (
        !isset($_SERVER['REQUEST_METHOD']) ||
        $_SERVER['REQUEST_METHOD'] !== 'POST'
    ) {
        return;
    }

    $action = isset($_POST['seo_faq_action'])
        ? sanitize_key(wp_unslash($_POST['seo_faq_action']))
        : '';

    if (!in_array($action, ['save', 'toggle', 'delete'], true)) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    check_admin_referer('seo_faq_' . $action);

    global $wpdb;

    $table     = seo_faq_table_name();
    $filters   = seo_faq_read_classification_filters($_POST);
    $object_id = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
    $faq_id    = isset($_POST['faq_id']) ? absint($_POST['faq_id']) : 0;

    if ($object_id <= 0) {
        seo_faq_redirect($current_tab, $filters, 0, 'missing', 0);
    }

    if ($action === 'save') {
        $question = isset($_POST['question'])
            ? sanitize_text_field(wp_unslash($_POST['question']))
            : '';

        $question = seo_faq_limit_question($question);

        $answer = isset($_POST['answer'])
            ? wp_kses_post(wp_unslash($_POST['answer']))
            : '';

        $sort_order = isset($_POST['sort_order'])
            ? absint($_POST['sort_order'])
            : 0;

        $active = isset($_POST['active']) ? 1 : 0;

        if ($question === '' || trim(wp_strip_all_tags($answer)) === '') {
            seo_faq_redirect($current_tab, $filters, $object_id, 'missing', 0);
        }

        $data = [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'question'    => $question,
            'answer'      => $answer,
            'sort_order'  => $sort_order,
            'active'      => $active,
        ];

        $formats = [
            '%d',
            '%d',
            '%s',
            '%s',
            '%d',
            '%d',
        ];

        if ($faq_id > 0) {
            $result = $wpdb->update(
                $table,
                $data,
                [
                    'id'          => $faq_id,
                    'object_type' => $object_type,
                    'object_id'   => $object_id,
                ],
                $formats,
                [
                    '%d',
                    '%d',
                    '%d',
                ]
            );

            if ($result === false) {
                seo_faq_redirect($current_tab, $filters, $object_id, 'error', 0);
            }

            seo_faq_clear_cache($object_type, $object_id);
            seo_faq_redirect($current_tab, $filters, $object_id, 'updated', 0);
        }

        $result = $wpdb->insert($table, $data, $formats);

        if ($result === false) {
            seo_faq_redirect($current_tab, $filters, $object_id, 'error', 0);
        }

        seo_faq_clear_cache($object_type, $object_id);
        seo_faq_redirect($current_tab, $filters, $object_id, 'created', 1);
    }

    if ($action === 'toggle') {
        $active = isset($_POST['active']) ? absint($_POST['active']) : 0;
        $active = $active === 1 ? 1 : 0;

        $result = $wpdb->update(
            $table,
            ['active' => $active],
            [
                'id'          => $faq_id,
                'object_type' => $object_type,
                'object_id'   => $object_id,
            ],
            ['%d'],
            ['%d', '%d', '%d']
        );

        if ($result === false) {
            seo_faq_redirect($current_tab, $filters, $object_id, 'error', 0);
        }

        seo_faq_clear_cache($object_type, $object_id);
        seo_faq_redirect($current_tab, $filters, $object_id, 'status', 0);
    }

    if ($action === 'delete') {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, object_type, object_id
                 FROM {$table}
                 WHERE id = %d AND object_type = %d AND object_id = %d
                 LIMIT 1",
                $faq_id,
                $object_type,
                $object_id
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            seo_faq_redirect($current_tab, $filters, $object_id, 'error', 0);
        }

        try {
            $result = seo_faq_delete_rows_with_data_layer(
                [$row],
                'delete_faq',
                'Eliminar FAQ #' . (int) $faq_id,
                'medium',
                [
                    'strategy' => 'single_manual_delete',
                ],
                false
            );
        } catch (Throwable $exception) {
            error_log('[SEO FAQ] No se pudo eliminar la FAQ: ' . $exception->getMessage());
            seo_faq_redirect($current_tab, $filters, $object_id, 'error', 0);
        }

        seo_faq_redirect(
            $current_tab,
            $filters,
            $object_id,
            'deleted',
            1,
            (int) $result['operation_id']
        );
    }
}


/**
 * Listado y gestión de FAQs de un objeto concreto.
 */
function seo_faq_render_management(
    $current_tab,
    $object_type,
    $object_id,
    $edit_id,
    $filters
) {
    global $wpdb;

    $table = seo_faq_table_name();

    $faqs = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT *
            FROM {$table}
            WHERE object_type = %d
              AND object_id = %d
            ORDER BY sort_order ASC, id ASC
            ",
            $object_type,
            $object_id
        )
    );

    $editing_faq = null;

    if ($edit_id > 0) {
        $editing_faq = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE id = %d
                  AND object_type = %d
                  AND object_id = %d
                LIMIT 1
                ",
                $edit_id,
                $object_type,
                $object_id
            )
        );
    }

    ?>
    <hr style="margin:30px 0;">

    <h2>
        FAQs de: <?php echo esc_html(seo_faq_get_object_name($object_type, $object_id)); ?>
    </h2>

    <table class="widefat striped">
        <thead>
            <tr>
                <th style="width:70px;">Orden</th>
                <th>Pregunta</th>
                <th style="width:90px;">Activa</th>
                <th style="width:280px;">Acciones</th>
            </tr>
        </thead>

        <tbody>
            <?php if (!$faqs) : ?>
                <tr>
                    <td colspan="4">Este elemento todavía no tiene FAQs.</td>
                </tr>
            <?php else : ?>
                <?php foreach ($faqs as $faq) : ?>
                    <?php
                    $edit_args = seo_faq_build_filter_args($current_tab, $filters);
                    $edit_args['object_id'] = $object_id;
                    $edit_args['edit_faq']  = $faq->id;

                    $edit_url = add_query_arg(
                        $edit_args,
                        admin_url('admin.php')
                    );
                    ?>

                    <tr>
                        <td><?php echo esc_html($faq->sort_order); ?></td>

                        <td>
                            <strong><?php echo esc_html($faq->question); ?></strong>
                        </td>

                        <td><?php echo (int) $faq->active === 1 ? 'Sí' : 'No'; ?></td>

                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                Editar
                            </a>

                            <form method="post" style="display:inline-block;">
                                <?php wp_nonce_field('seo_faq_toggle'); ?>
                                <input type="hidden" name="seo_faq_action" value="toggle">
                                <input type="hidden" name="faq_id" value="<?php echo esc_attr($faq->id); ?>">
                                <input type="hidden" name="object_id" value="<?php echo esc_attr($object_id); ?>">
                                <input type="hidden" name="active" value="<?php echo (int) $faq->active === 1 ? 0 : 1; ?>">
                                <?php seo_faq_render_filter_hidden_fields($filters); ?>

                                <button type="submit" class="button button-small">
                                    <?php echo (int) $faq->active === 1 ? 'Desactivar' : 'Activar'; ?>
                                </button>
                            </form>

                            <form
                                method="post"
                                style="display:inline-block;"
                                onsubmit="return confirm('¿Eliminar esta FAQ?');"
                            >
                                <?php wp_nonce_field('seo_faq_delete'); ?>
                                <input type="hidden" name="seo_faq_action" value="delete">
                                <input type="hidden" name="faq_id" value="<?php echo esc_attr($faq->id); ?>">
                                <input type="hidden" name="object_id" value="<?php echo esc_attr($object_id); ?>">
                                <?php seo_faq_render_filter_hidden_fields($filters); ?>

                                <button type="submit" class="button button-small">
                                    Eliminar
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php
    seo_faq_render_form(
        $current_tab,
        $object_id,
        $editing_faq,
        $filters
    );
}


/**
 * Formulario de alta y edición individual.
 */
function seo_faq_render_form(
    $current_tab,
    $object_id,
    $editing_faq,
    $filters
) {
    $faq_id = $editing_faq ? absint($editing_faq->id) : 0;

    $cancel_args = seo_faq_build_filter_args($current_tab, $filters);
    $cancel_args['object_id'] = $object_id;

    $cancel_url = add_query_arg(
        $cancel_args,
        admin_url('admin.php')
    );

    ?>
    <div
        style="
            max-width:900px;
            margin-top:30px;
            padding:20px;
            background:#fff;
            border:1px solid #ccd0d4;
        "
    >
        <h2><?php echo $faq_id > 0 ? 'Editar FAQ' : 'Nueva FAQ'; ?></h2>

        <form method="post">
            <?php wp_nonce_field('seo_faq_save'); ?>

            <input type="hidden" name="seo_faq_action" value="save">
            <input type="hidden" name="faq_id" value="<?php echo esc_attr($faq_id); ?>">
            <input type="hidden" name="object_id" value="<?php echo esc_attr($object_id); ?>">
            <?php seo_faq_render_filter_hidden_fields($filters); ?>

            <?php seo_faq_render_fields($editing_faq, 'individual'); ?>

            <?php
            submit_button(
                $faq_id > 0 ? 'Actualizar FAQ' : 'Guardar FAQ',
                'primary',
                '',
                false
            );
            ?>

            <?php if ($faq_id > 0) : ?>
                <a href="<?php echo esc_url($cancel_url); ?>" class="button">
                    Cancelar
                </a>
            <?php endif; ?>
        </form>
    </div>
    <?php
}


/**
 * Campos comunes de alta, edición y creación múltiple.
 */
function seo_faq_render_fields($editing_faq = null, $context = 'individual')
{
    $question = $editing_faq ? $editing_faq->question : '';
    $answer   = $editing_faq ? $editing_faq->answer : '';

    $sort_order = $editing_faq
        ? absint($editing_faq->sort_order)
        : 0;

    $active = $editing_faq
        ? absint($editing_faq->active)
        : 1;

    $suffix = $context === 'bulk' ? 'bulk' : 'individual';

    ?>
    <table class="form-table">
        <tr>
            <th>
                <label for="seo-faq-question-<?php echo esc_attr($suffix); ?>">
                    Pregunta
                </label>
            </th>

            <td>
                <input
                    type="text"
                    id="seo-faq-question-<?php echo esc_attr($suffix); ?>"
                    name="question"
                    value="<?php echo esc_attr($question); ?>"
                    class="regular-text"
                    style="width:100%;"
                    maxlength="255"
                    required
                >
            </td>
        </tr>

        <tr>
            <th>Respuesta</th>

            <td>
                <?php
                wp_editor(
                    $answer,
                    'seo_faq_answer_' . $suffix,
                    [
                        'textarea_name' => 'answer',
                        'textarea_rows' => 8,
                        'media_buttons' => false,
                        'teeny'         => true,
                    ]
                );
                ?>
            </td>
        </tr>

        <tr>
            <th>
                <label for="seo-faq-order-<?php echo esc_attr($suffix); ?>">
                    Orden
                </label>
            </th>

            <td>
                <input
                    type="number"
                    id="seo-faq-order-<?php echo esc_attr($suffix); ?>"
                    name="sort_order"
                    value="<?php echo esc_attr($sort_order); ?>"
                    min="0"
                    step="1"
                    style="width:100px;"
                >
            </td>
        </tr>

        <tr>
            <th>Activa</th>

            <td>
                <label>
                    <input
                        type="checkbox"
                        name="active"
                        value="1"
                        <?php checked($active, 1); ?>
                    >
                    Mostrar esta FAQ
                </label>
            </td>
        </tr>
    </table>
    <?php
}


/**
 * Cuenta las FAQs existentes para cada objeto mostrado.
 */
function seo_faq_get_target_counts($object_type, $object_ids)
{
    global $wpdb;

    $object_ids = wp_parse_id_list($object_ids);

    if (!$object_ids) {
        return [];
    }

    $table        = seo_faq_table_name();
    $placeholders = implode(',', array_fill(0, count($object_ids), '%d'));

    $query = "
        SELECT object_id, COUNT(*) AS total
        FROM {$table}
        WHERE object_type = %d
          AND object_id IN ({$placeholders})
        GROUP BY object_id
    ";

    $params   = array_merge([$object_type], $object_ids);
    $prepared = $wpdb->prepare($query, $params);
    $rows     = $wpdb->get_results($prepared);
    $counts   = [];

    foreach ($rows as $row) {
        $counts[(int) $row->object_id] = (int) $row->total;
    }

    return $counts;
}


/**
 * Limita la pregunta a la longitud de la columna VARCHAR(255).
 */
function seo_faq_limit_question($question)
{
    if (function_exists('mb_substr')) {
        return mb_substr($question, 0, 255);
    }

    return substr($question, 0, 255);
}


/**
 * Nombre legible del objeto.
 */
function seo_faq_get_object_name($object_type, $object_id)
{
    if ($object_type === 1) {
        $title = get_the_title($object_id);

        return $title ? 'Hub SEO: ' . $title : 'Hub SEO #' . $object_id;
    }

    if ($object_type === 2) {
        $term = get_term($object_id, 'product_cat');

        if ($term && !is_wp_error($term)) {
            return 'Categoría: ' . $term->name;
        }

        return 'Categoría #' . $object_id;
    }

    if ($object_type === 3) {
        $title = get_the_title($object_id);

        return $title ? 'Producto: ' . $title : 'Producto #' . $object_id;
    }

    return 'Elemento #' . $object_id;
}


/**
 * Limpia la caché de FAQs del objeto modificado.
 */
function seo_faq_clear_cache($object_type, $object_id)
{
    $cache_key = $object_type . ':' . $object_id;

    wp_cache_delete($cache_key, 'seo_faq');
    delete_transient('seo_faq_editorial_quality_v2');

    do_action(
        'seo_system_faq_cache_cleared',
        $object_type,
        $object_id
    );
}


/**
 * Genera los argumentos GET comunes.
 */
function seo_faq_build_filter_args($current_tab, $filters)
{
    return [
        'page'            => 'seo-faq',
        'tab'             => $current_tab,
        'cluster'         => $filters['cluster'],
        'hub_primario'    => $filters['hub_primario'],
        'hub_secundario'  => $filters['hub_secundario'],
        'cat'             => $filters['cat'],
        'hub_level'       => $filters['hub_level'],
        'faq_search'      => $filters['faq_search'] ?? '',
        'faq_presence'    => $filters['faq_presence'] ?? 'all',
    ];
}


/**
 * Añade los filtros como campos ocultos a formularios POST.
 */
function seo_faq_render_filter_hidden_fields($filters)
{
    ?>
    <input type="hidden" name="cluster" value="<?php echo esc_attr($filters['cluster']); ?>">
    <input type="hidden" name="hub_primario" value="<?php echo esc_attr($filters['hub_primario']); ?>">
    <input type="hidden" name="hub_secundario" value="<?php echo esc_attr($filters['hub_secundario']); ?>">
    <input type="hidden" name="cat" value="<?php echo esc_attr($filters['cat']); ?>">
    <input type="hidden" name="hub_level" value="<?php echo esc_attr($filters['hub_level']); ?>">
    <input type="hidden" name="faq_search" value="<?php echo esc_attr($filters['faq_search'] ?? ''); ?>">
    <input type="hidden" name="faq_presence" value="<?php echo esc_attr($filters['faq_presence'] ?? 'all'); ?>">
    <?php
}


/**
 * Redirige conservando pestaña y clasificación.
 */
function seo_faq_redirect(
    $current_tab,
    $filters,
    $object_id,
    $message,
    $total,
    $operation_id = 0
) {
    $args = seo_faq_build_filter_args($current_tab, $filters);

    if ($object_id > 0) {
        $args['object_id'] = $object_id;
    }

    $args['faq_msg']   = $message;
    $args['faq_total'] = absint($total);

    if ((int) $operation_id > 0) {
        $args['faq_operation'] = absint($operation_id);
    }

    $url = add_query_arg(
        $args,
        admin_url('admin.php')
    );

    wp_safe_redirect($url);
    exit;
}



/**
 * Redirige a la pestaña Informe después de una acción sobre huérfanas.
 */
function seo_faq_report_redirect($message, $total = 0, $operation_id = 0)
{
    $args = [
        'page'      => 'seo-faq',
        'tab'       => 'report',
        'faq_msg'   => sanitize_key($message),
        'faq_total' => absint($total),
    ];

    if ((int) $operation_id > 0) {
        $args['faq_operation'] = absint($operation_id);
    }

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}


/**
 * Procesa los borrados de FAQs definitivamente huérfanas.
 */
function seo_faq_process_report_action()
{
    if (
        !isset($_SERVER['REQUEST_METHOD']) ||
        $_SERVER['REQUEST_METHOD'] !== 'POST'
    ) {
        return;
    }

    $action = isset($_POST['seo_faq_action'])
        ? sanitize_key(wp_unslash($_POST['seo_faq_action']))
        : '';

    $orphan_actions = [
        'delete_orphan_faq',
        'delete_orphan_group',
        'delete_selected_orphan_faqs',
        'delete_all_orphan_faqs',
    ];

    $duplicate_actions = [
        'delete_duplicate_faq_copy',
        'delete_duplicate_faq_group',
        'delete_selected_duplicate_faqs',
        'delete_all_duplicate_faqs',
    ];

    if (
        !in_array($action, $orphan_actions, true) &&
        !in_array($action, $duplicate_actions, true)
    ) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    if (in_array($action, $duplicate_actions, true)) {
        check_admin_referer('seo_faq_duplicate_action');

        $groups = seo_faq_report_get_duplicate_groups();

        if (!$groups) {
            seo_faq_report_redirect('duplicate_none');
        }

        $rows_by_id      = [];
        $groups_by_keeper = [];

        foreach ($groups as $group) {
            $keeper_id = (int) $group['keeper']['id'];
            $groups_by_keeper[$keeper_id] = $group;

            foreach ($group['duplicates'] as $row) {
                $rows_by_id[(int) $row['id']] = $row;
            }
        }

        $selected = [];
        $label    = '';
        $type     = '';
        $risk     = 'high';
        $strategy = '';
        $group_count = 0;
        $keeper_ids  = [];

        if ($action === 'delete_duplicate_faq_copy') {
            $faq_id = isset($_POST['faq_id']) ? absint($_POST['faq_id']) : 0;

            if ($faq_id > 0 && isset($rows_by_id[$faq_id])) {
                $selected[] = $rows_by_id[$faq_id];
                $keeper_ids[] = (int) $rows_by_id[$faq_id]['duplicate_keeper_id'];
                $group_count = 1;
            }

            $label    = 'Eliminar copia duplicada de FAQ #' . $faq_id;
            $type     = 'clean_duplicate_faq_copy';
            $risk     = 'medium';
            $strategy = 'single_duplicate_copy';
        }

        if ($action === 'delete_duplicate_faq_group') {
            $keeper_id = isset($_POST['keeper_id']) ? absint($_POST['keeper_id']) : 0;

            if ($keeper_id > 0 && isset($groups_by_keeper[$keeper_id])) {
                $selected = $groups_by_keeper[$keeper_id]['duplicates'];
                $keeper_ids[] = $keeper_id;
                $group_count = 1;
            }

            $label    = 'Eliminar copias de un grupo de FAQs duplicadas';
            $type     = 'clean_duplicate_faq_group';
            $risk     = 'high';
            $strategy = 'duplicate_group';
        }

        if ($action === 'delete_selected_duplicate_faqs') {
            $posted_ids = isset($_POST['faq_ids'])
                ? wp_parse_id_list(wp_unslash($_POST['faq_ids']))
                : [];

            foreach ($posted_ids as $faq_id) {
                if (isset($rows_by_id[$faq_id])) {
                    $selected[] = $rows_by_id[$faq_id];
                    $keeper_ids[] = (int) $rows_by_id[$faq_id]['duplicate_keeper_id'];
                }
            }

            $keeper_ids = array_values(array_unique(array_filter($keeper_ids)));
            $group_count = count($keeper_ids);
            $label    = 'Eliminar copias duplicadas de FAQ seleccionadas';
            $type     = 'clean_selected_duplicate_faqs';
            $risk     = 'high';
            $strategy = 'selected_duplicate_copies';
        }

        if ($action === 'delete_all_duplicate_faqs') {
            foreach ($groups as $group) {
                foreach ($group['duplicates'] as $row) {
                    $selected[] = $row;
                }
                $keeper_ids[] = (int) $group['keeper']['id'];
            }

            $keeper_ids = array_values(array_unique(array_filter($keeper_ids)));
            $group_count = count($groups);
            $label    = 'Eliminar todas las copias duplicadas de FAQ';
            $type     = 'clean_all_duplicate_faqs';
            $risk     = 'critical';
            $strategy = 'all_duplicate_copies';
        }

        if (!$selected) {
            seo_faq_report_redirect('duplicate_invalid');
        }

        try {
            $result = seo_faq_delete_rows_with_data_layer(
                $selected,
                $type,
                $label,
                $risk,
                [
                    'strategy'             => $strategy,
                    'duplicate_group_count'=> (int) $group_count,
                    'keeper_ids'           => $keeper_ids,
                    'selection_rule'       => [
                        'active_desc',
                        'open_count_desc',
                        'load_count_desc',
                        'updated_at_desc',
                        'id_asc',
                    ],
                    'report_generated_at'  => current_time('mysql', true),
                ],
                false,
                'seo_faq_validate_duplicate_delete_row'
            );
        } catch (Throwable $exception) {
            error_log('[SEO FAQ] Limpieza de duplicadas fallida: ' . $exception->getMessage());
            seo_faq_report_redirect('duplicate_error');
        }

        seo_faq_report_redirect(
            'duplicate_deleted',
            (int) $result['deleted'],
            (int) $result['operation_id']
        );
    }

    check_admin_referer('seo_faq_orphan_action');

    $orphans = seo_faq_report_get_orphan_rows();

    if (!$orphans) {
        seo_faq_report_redirect('orphan_none');
    }

    $rows_by_id = [];

    foreach ($orphans as $row) {
        $rows_by_id[(int) $row['id']] = $row;
    }

    $selected = [];
    $label    = '';
    $type     = '';
    $risk     = 'high';
    $strategy = '';

    if ($action === 'delete_orphan_faq') {
        $faq_id = isset($_POST['faq_id']) ? absint($_POST['faq_id']) : 0;

        if ($faq_id > 0 && isset($rows_by_id[$faq_id])) {
            $selected[] = $rows_by_id[$faq_id];
        }

        $label    = 'Eliminar FAQ huérfana #' . $faq_id;
        $type     = 'delete_orphan_faq';
        $risk     = 'medium';
        $strategy = 'single';
    }

    if ($action === 'delete_orphan_group') {
        $object_type = isset($_POST['object_type']) ? (int) $_POST['object_type'] : 0;
        $object_id   = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;

        foreach ($orphans as $row) {
            if (
                (int) $row['object_type'] === $object_type &&
                (int) $row['object_id'] === $object_id
            ) {
                $selected[] = $row;
            }
        }

        $label = sprintf(
            'Eliminar FAQs huérfanas de %s #%d',
            seo_faq_report_type_label($object_type),
            $object_id
        );
        $type     = 'delete_orphan_faq_group';
        $risk     = 'high';
        $strategy = 'destination_group';
    }

    if ($action === 'delete_selected_orphan_faqs') {
        $posted_ids = isset($_POST['faq_ids'])
            ? wp_parse_id_list(wp_unslash($_POST['faq_ids']))
            : [];

        foreach ($posted_ids as $faq_id) {
            if (isset($rows_by_id[$faq_id])) {
                $selected[] = $rows_by_id[$faq_id];
            }
        }

        $label    = 'Eliminar FAQs huérfanas seleccionadas';
        $type     = 'delete_selected_orphan_faqs';
        $risk     = 'high';
        $strategy = 'selected';
    }

    if ($action === 'delete_all_orphan_faqs') {
        $selected = $orphans;
        $label    = 'Eliminar todas las FAQs definitivamente huérfanas';
        $type     = 'delete_all_orphan_faqs';
        $risk     = 'critical';
        $strategy = 'all_definitive_orphans';
    }

    if (!$selected) {
        seo_faq_report_redirect('orphan_invalid');
    }

    try {
        $result = seo_faq_delete_rows_with_data_layer(
            $selected,
            $type,
            $label,
            $risk,
            [
                'strategy'            => $strategy,
                'report_generated_at' => current_time('mysql', true),
            ],
            true
        );
    } catch (Throwable $exception) {
        error_log('[SEO FAQ] Limpieza de huérfanas fallida: ' . $exception->getMessage());
        seo_faq_report_redirect('orphan_error');
    }

    seo_faq_report_redirect(
        'orphan_deleted',
        (int) $result['deleted'],
        (int) $result['operation_id']
    );
}


/**
 * Normaliza una pregunta para identificar duplicados dentro del mismo objeto.
 */
function seo_faq_duplicate_question_key($question)
{
    $question = trim(wp_strip_all_tags((string) $question));
    $question = preg_replace('/\s+/u', ' ', $question);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower((string) $question, 'UTF-8');
    }

    return strtolower((string) $question);
}


/**
 * Ordena las FAQs de un grupo para conservar la de mayor valor real.
 * Prioridad: activa, aperturas, cargas, actualización reciente e ID menor.
 */
function seo_faq_compare_duplicate_priority($a, $b)
{
    foreach (['active', 'open_count', 'load_count'] as $field) {
        $a_value = isset($a[$field]) ? (int) $a[$field] : 0;
        $b_value = isset($b[$field]) ? (int) $b[$field] : 0;

        if ($a_value !== $b_value) {
            return $b_value <=> $a_value;
        }
    }

    $a_updated = isset($a['updated_at']) ? (string) $a['updated_at'] : '';
    $b_updated = isset($b['updated_at']) ? (string) $b['updated_at'] : '';

    if ($a_updated !== $b_updated) {
        return strcmp($b_updated, $a_updated);
    }

    return ((int) $a['id']) <=> ((int) $b['id']);
}


/**
 * Devuelve grupos de preguntas repetidas dentro del mismo destino.
 * La primera fila de cada grupo es la que debe conservarse.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_faq_report_get_duplicate_groups()
{
    global $wpdb;

    $table = seo_faq_table_name();

    $rows = $wpdb->get_results(
        "SELECT f.*
         FROM {$table} f
         INNER JOIN (
            SELECT object_type, object_id, TRIM(question) AS normalized_question, COUNT(*) AS total
            FROM {$table}
            GROUP BY object_type, object_id, TRIM(question)
            HAVING COUNT(*) > 1
         ) duplicate_group
            ON duplicate_group.object_type = f.object_type
           AND duplicate_group.object_id = f.object_id
           AND duplicate_group.normalized_question = TRIM(f.question)
         ORDER BY f.object_type ASC, f.object_id ASC, f.question ASC, f.id ASC",
        ARRAY_A
    );

    if (!is_array($rows) || !$rows) {
        return [];
    }

    $grouped = [];

    foreach ($rows as $row) {
        $question_key = seo_faq_duplicate_question_key($row['question']);
        $key = (int) $row['object_type'] . ':' . (int) $row['object_id'] . ':' . sha1($question_key);

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'group_key'   => $key,
                'object_type' => (int) $row['object_type'],
                'object_id'   => (int) $row['object_id'],
                'question'    => trim((string) $row['question']),
                'rows'        => [],
            ];
        }

        $grouped[$key]['rows'][] = $row;
    }

    $groups = [];

    foreach ($grouped as $group) {
        if (count($group['rows']) < 2) {
            continue;
        }

        usort($group['rows'], 'seo_faq_compare_duplicate_priority');

        $keeper    = array_shift($group['rows']);
        $duplicates = [];
        $question_key = seo_faq_duplicate_question_key($keeper['question']);

        foreach ($group['rows'] as $row) {
            $row['duplicate_keeper_id']   = (int) $keeper['id'];
            $row['duplicate_question_key'] = $question_key;
            $duplicates[] = $row;
        }

        $groups[] = [
            'group_key'   => $group['group_key'],
            'object_type' => (int) $group['object_type'],
            'object_id'   => (int) $group['object_id'],
            'question'    => (string) $group['question'],
            'keeper'      => $keeper,
            'duplicates'  => $duplicates,
            'total'       => 1 + count($duplicates),
        ];
    }

    return $groups;
}


/**
 * Verifica dentro de la transacción que la fila sigue siendo una copia del
 * keeper elegido. Si el grupo cambió, cancela toda la operación.
 */
function seo_faq_validate_duplicate_delete_row($current, $requested)
{
    $keeper_id = isset($requested['duplicate_keeper_id'])
        ? absint($requested['duplicate_keeper_id'])
        : 0;

    $expected_question = isset($requested['duplicate_question_key'])
        ? (string) $requested['duplicate_question_key']
        : '';

    if ($keeper_id <= 0 || $keeper_id === (int) $current['id']) {
        return 'No se ha podido identificar de forma segura la FAQ que debe conservarse.';
    }

    if (seo_faq_duplicate_question_key($current['question']) !== $expected_question) {
        return 'La pregunta de la FAQ #' . (int) $current['id'] . ' cambió antes de la limpieza.';
    }

    $config = SEO_Data_Layer::table('faqs');
    $keeper = SEO_Data_Layer::fetch_row(
        (string) $config['table'],
        ['id' => $keeper_id],
        true
    );

    if ($keeper === null) {
        return 'La FAQ #' . $keeper_id . ' que debía conservarse ya no existe.';
    }

    if (
        (int) $keeper['object_type'] !== (int) $current['object_type'] ||
        (int) $keeper['object_id'] !== (int) $current['object_id'] ||
        seo_faq_duplicate_question_key($keeper['question']) !== $expected_question
    ) {
        return 'El grupo duplicado cambió antes de la limpieza. No se ha eliminado ninguna FAQ.';
    }

    return true;
}


/**
 * Renderiza la limpieza reversible de preguntas duplicadas dentro del mismo
 * hub, categoría o producto.
 */
function seo_faq_report_render_duplicate_management($groups)
{
    $groups = is_array($groups) ? $groups : [];

    echo '<h3>FAQs duplicadas dentro del mismo objeto</h3>';
    echo '<p>Cuando la misma pregunta aparece varias veces en el mismo destino, se conserva automáticamente la fila de mayor valor: activa, con más aperturas, más cargas, actualización más reciente y, finalmente, ID menor.</p>';

    if (!$groups) {
        echo '<div class="notice notice-success inline"><p><strong>Correcto:</strong> no se han encontrado preguntas duplicadas dentro del mismo objeto.</p></div>';
        return;
    }

    $duplicate_count = 0;

    foreach ($groups as $group) {
        $duplicate_count += count($group['duplicates']);
    }

    echo '<div class="seo-faq-duplicate-summary">';
    echo '<strong>' . esc_html(number_format_i18n(count($groups))) . ' grupos duplicados</strong>';
    echo '<span>' . esc_html(number_format_i18n($duplicate_count)) . ' copias sobrantes</span>';
    echo '<span>' . esc_html(number_format_i18n(count($groups))) . ' FAQs se conservarán</span>';
    echo '</div>';

    echo '<div class="seo-faq-duplicate-toolbar">';

    echo '<form method="post" id="seo-faq-duplicate-selected-form">';
    wp_nonce_field('seo_faq_duplicate_action');
    echo '<input type="hidden" name="seo_faq_action" value="delete_selected_duplicate_faqs">';
    echo '<button type="submit" class="button button-secondary" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminarán las copias duplicadas seleccionadas. Las FAQs marcadas para conservar permanecerán intactas. ¿Continuar?')) . ');">Eliminar copias seleccionadas</button>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('seo_faq_duplicate_action');
    echo '<input type="hidden" name="seo_faq_action" value="delete_all_duplicate_faqs">';
    echo '<button type="submit" class="button button-primary" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminarán todas las copias duplicadas, conservando una FAQ por pregunta y destino. La operación será reversible. ¿Continuar?')) . ');">Eliminar todas las copias</button>';
    echo '</form>';

    echo '</div>';

    foreach ($groups as $group) {
        $keeper = $group['keeper'];
        $object_label = seo_faq_get_object_name(
            (int) $group['object_type'],
            (int) $group['object_id']
        );

        echo '<section class="seo-faq-duplicate-group">';
        echo '<div class="seo-faq-duplicate-group-head">';
        echo '<div><h4>' . esc_html($object_label) . '</h4>';
        echo '<p><strong>Pregunta:</strong> ' . esc_html((string) $group['question']) . '</p>';
        echo '<p>' . esc_html(number_format_i18n((int) $group['total'])) . ' filas · se conserva #' . esc_html((string) $keeper['id']) . ' · se eliminan ' . esc_html(number_format_i18n(count($group['duplicates']))) . '</p></div>';

        echo '<form method="post">';
        wp_nonce_field('seo_faq_duplicate_action');
        echo '<input type="hidden" name="seo_faq_action" value="delete_duplicate_faq_group">';
        echo '<input type="hidden" name="keeper_id" value="' . esc_attr($keeper['id']) . '">';
        echo '<button type="submit" class="button" onclick="return confirm(' . esc_attr(wp_json_encode('Se conservará la FAQ indicada y se eliminarán las demás copias de este grupo mediante una operación reversible. ¿Continuar?')) . ');">Eliminar copias del grupo</button>';
        echo '</form>';
        echo '</div>';

        echo '<table class="widefat striped seo-faq-report-table seo-faq-duplicate-table"><thead><tr>';
        echo '<th style="width:42px;"><span class="screen-reader-text">Seleccionar</span></th>';
        echo '<th style="width:85px;">Decisión</th><th style="width:75px;">FAQ</th><th style="width:75px;">Activa</th><th style="width:130px;">Uso</th><th>Actualizada</th><th style="width:100px;">Acción</th>';
        echo '</tr></thead><tbody>';

        echo '<tr class="seo-faq-duplicate-keeper">';
        echo '<td>-</td>';
        echo '<td><span class="seo-faq-keep-badge">Conservar</span></td>';
        echo '<td><code>#' . esc_html((string) $keeper['id']) . '</code></td>';
        echo '<td>' . ((int) $keeper['active'] === 1 ? 'Sí' : 'No') . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $keeper['load_count'])) . ' cargas<br>' . esc_html(number_format_i18n((int) $keeper['open_count'])) . ' aperturas</td>';
        echo '<td>' . esc_html(!empty($keeper['updated_at']) ? (string) $keeper['updated_at'] : '-') . '</td>';
        echo '<td><span class="description">Protegida</span></td>';
        echo '</tr>';

        foreach ($group['duplicates'] as $row) {
            $faq_id = (int) $row['id'];
            echo '<tr>';
            echo '<td><input type="checkbox" form="seo-faq-duplicate-selected-form" name="faq_ids[]" value="' . esc_attr($faq_id) . '" aria-label="Seleccionar copia de FAQ ' . esc_attr($faq_id) . '"></td>';
            echo '<td><span class="seo-faq-delete-badge">Copia</span></td>';
            echo '<td><code>#' . esc_html((string) $faq_id) . '</code></td>';
            echo '<td>' . ((int) $row['active'] === 1 ? 'Sí' : 'No') . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['load_count'])) . ' cargas<br>' . esc_html(number_format_i18n((int) $row['open_count'])) . ' aperturas</td>';
            echo '<td>' . esc_html(!empty($row['updated_at']) ? (string) $row['updated_at'] : '-') . '</td>';
            echo '<td>';
            echo '<form method="post">';
            wp_nonce_field('seo_faq_duplicate_action');
            echo '<input type="hidden" name="seo_faq_action" value="delete_duplicate_faq_copy">';
            echo '<input type="hidden" name="faq_id" value="' . esc_attr($faq_id) . '">';
            echo '<button type="submit" class="button button-small" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminará esta copia duplicada. La FAQ seleccionada para conservar permanecerá intacta. ¿Continuar?')) . ');">Eliminar</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    echo '<p class="description">Las preguntas repetidas en objetos distintos no se eliminan automáticamente: pueden ser válidas en contextos diferentes. Cada limpieza de copias aparece en <strong>SEO Data Table → Operaciones</strong> y puede revertirse si no existen conflictos.</p>';
}


/**
 * Devuelve las FAQs cuyo destino ya no existe de forma definitiva.
 * Los productos o páginas existentes pero no publicados se excluyen y se
 * muestran en una sección separada de revisión.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_faq_report_get_orphan_rows()
{
    global $wpdb;

    $table = seo_faq_table_name();

    $rows = $wpdb->get_results(
        "SELECT f.*,
                p.ID AS linked_post_id,
                p.post_type AS linked_post_type,
                p.post_status AS linked_post_status,
                p.post_title AS linked_post_title,
                tt.term_id AS linked_term_id,
                t.name AS linked_term_name
         FROM {$table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
           AND f.object_type IN (1, 3)
         LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = f.object_id
           AND tt.taxonomy = 'product_cat'
           AND f.object_type = 2
         LEFT JOIN {$wpdb->terms} t
            ON t.term_id = tt.term_id
         WHERE (f.object_type = 1 AND p.ID IS NULL)
            OR (f.object_type = 2 AND tt.term_id IS NULL)
            OR (f.object_type = 3 AND (p.ID IS NULL OR p.post_type <> 'product'))
            OR f.object_type NOT IN (1, 2, 3)
         ORDER BY f.object_type ASC, f.object_id ASC, f.sort_order ASC, f.id ASC",
        ARRAY_A
    );

    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as &$row) {
        $object_type = (int) $row['object_type'];

        if ($object_type === 1) {
            $row['orphan_reason'] = 'La página o hub ya no existe.';
        } elseif ($object_type === 2) {
            $row['orphan_reason'] = 'La categoría product_cat ya no existe.';
        } elseif ($object_type === 3) {
            $row['orphan_reason'] = empty($row['linked_post_id'])
                ? 'El producto ya no existe.'
                : 'El ID existe, pero ya no corresponde a un producto.';
        } else {
            $row['orphan_reason'] = 'El object_type de la FAQ no está registrado.';
        }
    }
    unset($row);

    return $rows;
}


/**
 * Devuelve FAQs asociadas a páginas o productos existentes pero no publicados.
 * Son incidencias de revisión, no huérfanas definitivas, y no se ofrecen para
 * borrado masivo automático.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_faq_report_get_unpublished_rows()
{
    global $wpdb;

    $table = seo_faq_table_name();

    $rows = $wpdb->get_results(
        "SELECT f.id, f.object_type, f.object_id, f.question, f.active,
                f.load_count, f.open_count, p.post_type, p.post_status,
                p.post_title
         FROM {$table} f
         INNER JOIN {$wpdb->posts} p ON p.ID = f.object_id
         WHERE (f.object_type = 1 AND p.post_status <> 'publish')
            OR (f.object_type = 3 AND p.post_type = 'product' AND p.post_status <> 'publish')
         ORDER BY f.object_type ASC, f.object_id ASC, f.id ASC",
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
}


/**
 * Agrupa huérfanas por tipo e ID del destino perdido.
 */
function seo_faq_report_group_orphans($rows)
{
    $groups = [];

    foreach ((array) $rows as $row) {
        $key = (int) $row['object_type'] . ':' . (int) $row['object_id'];

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'object_type' => (int) $row['object_type'],
                'object_id'   => (int) $row['object_id'],
                'reason'      => (string) $row['orphan_reason'],
                'rows'        => [],
            ];
        }

        $groups[$key]['rows'][] = $row;
    }

    return $groups;
}


/**
 * Etiqueta de un destino inexistente.
 */
function seo_faq_report_orphan_destination_label($object_type, $object_id)
{
    $type = seo_faq_report_type_label((int) $object_type);
    return $type . ' inexistente #' . absint($object_id);
}


/**
 * Renderiza acciones reversibles sobre FAQs definitivamente huérfanas.
 */
function seo_faq_report_render_orphan_management($orphans)
{
    $orphans = is_array($orphans) ? $orphans : [];

    echo '<h3>FAQs huérfanas: limpieza reversible</h3>';
    echo '<p>Solo se consideran huérfanas definitivas las FAQs cuyo hub, categoría o producto ya no existe. Los destinos existentes pero no publicados se muestran aparte y no se eliminan automáticamente.</p>';

    if (!$orphans) {
        echo '<div class="notice notice-success inline"><p><strong>Correcto:</strong> no se han encontrado FAQs definitivamente huérfanas.</p></div>';
        return;
    }

    $groups = seo_faq_report_group_orphans($orphans);
    $counts = [1 => 0, 2 => 0, 3 => 0, 0 => 0];

    foreach ($orphans as $row) {
        $type = (int) $row['object_type'];
        $key  = isset($counts[$type]) ? $type : 0;
        $counts[$key]++;
    }

    echo '<div class="seo-faq-orphan-summary">';
    echo '<strong>' . esc_html(number_format_i18n(count($orphans))) . ' FAQs huérfanas</strong>';
    echo '<span>Hubs: ' . esc_html(number_format_i18n($counts[1])) . '</span>';
    echo '<span>Categorías: ' . esc_html(number_format_i18n($counts[2])) . '</span>';
    echo '<span>Productos: ' . esc_html(number_format_i18n($counts[3])) . '</span>';
    if ($counts[0] > 0) {
        echo '<span>Tipo desconocido: ' . esc_html(number_format_i18n($counts[0])) . '</span>';
    }
    echo '</div>';

    echo '<div class="seo-faq-orphan-toolbar">';

    echo '<form method="post" id="seo-faq-orphan-selected-form">';
    wp_nonce_field('seo_faq_orphan_action');
    echo '<input type="hidden" name="seo_faq_action" value="delete_selected_orphan_faqs">';
    echo '<button type="submit" class="button button-secondary" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminarán las FAQs seleccionadas mediante una operación reversible. ¿Continuar?')) . ');">Eliminar seleccionadas</button>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('seo_faq_orphan_action');
    echo '<input type="hidden" name="seo_faq_action" value="delete_all_orphan_faqs">';
    echo '<button type="submit" class="button button-primary" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminarán todas las FAQs definitivamente huérfanas. La operación quedará auditada y podrá revertirse si no aparecen conflictos. ¿Continuar?')) . ');">Eliminar todas las huérfanas</button>';
    echo '</form>';

    echo '</div>';

    foreach ($groups as $group) {
        $rows  = $group['rows'];
        $total = count($rows);

        echo '<section class="seo-faq-orphan-group">';
        echo '<div class="seo-faq-orphan-group-head">';
        echo '<div><h4>' . esc_html(seo_faq_report_orphan_destination_label($group['object_type'], $group['object_id'])) . '</h4>';
        echo '<p>' . esc_html($group['reason']) . ' · ' . esc_html(number_format_i18n($total)) . ' FAQ</p></div>';

        echo '<form method="post">';
        wp_nonce_field('seo_faq_orphan_action');
        echo '<input type="hidden" name="seo_faq_action" value="delete_orphan_group">';
        echo '<input type="hidden" name="object_type" value="' . esc_attr($group['object_type']) . '">';
        echo '<input type="hidden" name="object_id" value="' . esc_attr($group['object_id']) . '">';
        echo '<button type="submit" class="button" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminarán todas las FAQs de este destino perdido mediante una única operación reversible. ¿Continuar?')) . ');">Eliminar las ' . esc_html(number_format_i18n($total)) . '</button>';
        echo '</form>';
        echo '</div>';

        echo '<table class="widefat striped seo-faq-report-table seo-faq-orphan-table"><thead><tr>';
        echo '<th style="width:42px;"><span class="screen-reader-text">Seleccionar</span></th>';
        echo '<th style="width:75px;">FAQ</th><th>Pregunta</th><th style="width:80px;">Activa</th><th style="width:120px;">Uso</th><th style="width:100px;">Acción</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $faq_id = (int) $row['id'];
            echo '<tr>';
            echo '<td><input type="checkbox" form="seo-faq-orphan-selected-form" name="faq_ids[]" value="' . esc_attr($faq_id) . '" aria-label="Seleccionar FAQ ' . esc_attr($faq_id) . '"></td>';
            echo '<td><code>#' . esc_html((string) $faq_id) . '</code></td>';
            echo '<td><strong>' . esc_html((string) $row['question']) . '</strong></td>';
            echo '<td>' . ((int) $row['active'] === 1 ? 'Sí' : 'No') . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['load_count'])) . ' cargas<br>' . esc_html(number_format_i18n((int) $row['open_count'])) . ' aperturas</td>';
            echo '<td>';
            echo '<form method="post">';
            wp_nonce_field('seo_faq_orphan_action');
            echo '<input type="hidden" name="seo_faq_action" value="delete_orphan_faq">';
            echo '<input type="hidden" name="faq_id" value="' . esc_attr($faq_id) . '">';
            echo '<button type="submit" class="button button-small" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminará esta FAQ mediante una operación reversible. ¿Continuar?')) . ');">Eliminar</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    echo '<p class="description">Cada acción crea una operación en <strong>SEO Data Table → Operaciones</strong>. El rollback restaura las filas exactamente como estaban, aunque seguirán siendo huérfanas mientras el destino original no exista.</p>';
}


/**
 * Renderiza destinos existentes pero no publicados. No ofrece borrado masivo.
 */
function seo_faq_report_render_unpublished_targets($rows)
{
    $rows = is_array($rows) ? $rows : [];

    echo '<h3>FAQs con destino existente pero no publicado</h3>';
    echo '<p>Estas FAQs no están huérfanas: su página o producto todavía existe. Conviene revisar si está en borrador, privado, programado o en la papelera antes de decidir.</p>';

    if (!$rows) {
        echo '<div class="notice notice-success inline"><p><strong>Correcto:</strong> no hay FAQs asociadas a destinos no publicados.</p></div>';
        return;
    }

    echo '<table class="widefat striped seo-faq-report-table"><thead><tr>';
    echo '<th>FAQ</th><th>Tipo</th><th>Destino</th><th>Estado</th><th>Pregunta</th><th>Uso</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><code>#' . esc_html((string) $row['id']) . '</code></td>';
        echo '<td>' . esc_html(seo_faq_report_type_label((int) $row['object_type'])) . '</td>';
        echo '<td><strong>' . esc_html($row['post_title'] ?: 'Sin título') . '</strong> <code>#' . esc_html((string) $row['object_id']) . '</code></td>';
        echo '<td><code>' . esc_html((string) $row['post_status']) . '</code></td>';
        echo '<td>' . esc_html((string) $row['question']) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row['load_count'])) . ' / ' . esc_html(number_format_i18n((int) $row['open_count'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}



/**
 * KPIs agregados de telemetria por bloque.
 */
function seo_faq_report_get_tracking_metrics()
{
    global $wpdb;

    seo_faq_maybe_upgrade_metrics_schema();

    $metrics_table = seo_faq_metrics_table_name();
    $faq_table     = seo_faq_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $metrics_table));

    $empty = [
        'available'               => false,
        'objects_tracked'         => 0,
        'block_renders'           => 0,
        'block_views'             => 0,
        'engaged_blocks'          => 0,
        'faq_opens'               => 0,
        'render_errors'           => 0,
        'partial_renders'         => 0,
        'missing_renders'         => 0,
        'objects_with_errors'     => 0,
        'render_success_rate'     => 0,
        'view_rate'               => 0,
        'engagement_rate'         => 0,
        'opens_per_engaged_block' => 0,
        'active_never_rendered'   => 0,
        'high_exposure_no_open'   => 0,
        'open_without_render'     => 0,
    ];

    if ($exists !== $metrics_table) {
        return $empty;
    }

    $row = $wpdb->get_row(
        "SELECT COUNT(*) AS objects_tracked,
                COALESCE(SUM(block_render_count), 0) AS block_renders,
                COALESCE(SUM(block_view_count), 0) AS block_views,
                COALESCE(SUM(engaged_block_count), 0) AS engaged_blocks,
                COALESCE(SUM(faq_open_count), 0) AS faq_opens,
                COALESCE(SUM(render_error_count), 0) AS render_errors,
                COALESCE(SUM(partial_render_count), 0) AS partial_renders,
                COALESCE(SUM(missing_render_count), 0) AS missing_renders,
                COALESCE(SUM(render_error_count > 0), 0) AS objects_with_errors
         FROM {$metrics_table}"
    );

    $renders = $row ? (int) $row->block_renders : 0;
    $views   = $row ? (int) $row->block_views : 0;
    $engaged = $row ? (int) $row->engaged_blocks : 0;
    $opens   = $row ? (int) $row->faq_opens : 0;
    $errors   = $row ? (int) $row->render_errors : 0;
    $partial  = $row ? (int) $row->partial_renders : 0;
    $missing  = $row ? (int) $row->missing_renders : 0;
    $attempts = $renders + $missing;
    $healthy  = max(0, $renders - $partial);

    return [
        'available'               => true,
        'objects_tracked'         => $row ? (int) $row->objects_tracked : 0,
        'block_renders'           => $renders,
        'block_views'             => $views,
        'engaged_blocks'          => $engaged,
        'faq_opens'               => $opens,
        'render_errors'           => $errors,
        'partial_renders'         => $partial,
        'missing_renders'         => $missing,
        'objects_with_errors'     => $row ? (int) $row->objects_with_errors : 0,
        'render_success_rate'     => $attempts > 0 ? round(($healthy / $attempts) * 100, 2) : 0,
        'view_rate'               => $renders > 0 ? round(($views / $renders) * 100, 2) : 0,
        'engagement_rate'         => $views > 0 ? round(($engaged / $views) * 100, 2) : 0,
        'opens_per_engaged_block' => $engaged > 0 ? round($opens / $engaged, 2) : 0,
        'active_never_rendered'   => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$faq_table} WHERE active = 1 AND load_count = 0"
        ),
        'high_exposure_no_open'   => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$faq_table} WHERE active = 1 AND load_count >= 20 AND open_count = 0"
        ),
        'open_without_render'     => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$faq_table} WHERE open_count > load_count"
        ),
    ];
}

/**
 * Filtros del explorador de rendimiento.
 */
function seo_faq_report_read_performance_filters($source)
{
    $type = isset($source['perf_type']) ? absint($source['perf_type']) : 0;
    if (!in_array($type, [0, 1, 2, 3], true)) {
        $type = 0;
    }

    $status = isset($source['perf_status']) ? sanitize_key(wp_unslash($source['perf_status'])) : 'active';
    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        $status = 'active';
    }

    $usage = isset($source['perf_usage']) ? sanitize_key(wp_unslash($source['perf_usage'])) : 'all';
    if (!in_array($usage, ['all', 'never_rendered', 'no_opens', 'opened', 'low_engagement', 'inconsistent'], true)) {
        $usage = 'all';
    }

    $order = isset($source['perf_order']) ? sanitize_key(wp_unslash($source['perf_order'])) : 'loads';
    if (!in_array($order, ['loads', 'opens', 'open_rate', 'recent'], true)) {
        $order = 'loads';
    }

    return [
        'type'      => $type,
        'status'    => $status,
        'usage'     => $usage,
        'search'    => isset($source['perf_search']) ? sanitize_text_field(wp_unslash($source['perf_search'])) : '',
        'min_loads' => isset($source['perf_min_loads']) ? absint($source['perf_min_loads']) : 0,
        'min_opens' => isset($source['perf_min_opens']) ? absint($source['perf_min_opens']) : 0,
        'order'     => $order,
    ];
}

/**
 * Filas de rendimiento individual, filtrables y exportables.
 */
function seo_faq_report_get_performance_rows($filters, $limit = 100)
{
    global $wpdb;

    $table = seo_faq_table_name();
    $limit = max(1, min(50000, (int) $limit));
    $where = ['1=1'];
    $args  = [];

    if (!empty($filters['type'])) {
        $where[] = 'object_type = %d';
        $args[]  = (int) $filters['type'];
    }

    if (($filters['status'] ?? 'active') === 'active') {
        $where[] = 'active = 1';
    } elseif (($filters['status'] ?? '') === 'inactive') {
        $where[] = 'active = 0';
    }

    $usage = $filters['usage'] ?? 'all';
    if ($usage === 'never_rendered') {
        $where[] = 'load_count = 0';
    } elseif ($usage === 'no_opens') {
        $where[] = 'load_count > 0 AND open_count = 0';
    } elseif ($usage === 'opened') {
        $where[] = 'open_count > 0';
    } elseif ($usage === 'low_engagement') {
        $where[] = 'load_count >= 20 AND (open_count / NULLIF(load_count, 0)) < 0.01';
    } elseif ($usage === 'inconsistent') {
        $where[] = 'open_count > load_count';
    }

    if (!empty($filters['search'])) {
        $where[] = 'question LIKE %s';
        $args[]  = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
    }

    if (!empty($filters['min_loads'])) {
        $where[] = 'load_count >= %d';
        $args[]  = (int) $filters['min_loads'];
    }

    if (!empty($filters['min_opens'])) {
        $where[] = 'open_count >= %d';
        $args[]  = (int) $filters['min_opens'];
    }

    $order_map = [
        'loads'     => 'load_count DESC, open_count DESC, id ASC',
        'opens'     => 'open_count DESC, load_count DESC, id ASC',
        'open_rate' => '(open_count / NULLIF(load_count, 0)) DESC, load_count DESC, id ASC',
        'recent'    => 'updated_at DESC, id DESC',
    ];
    $order_by = $order_map[$filters['order'] ?? 'loads'];

    $sql = "SELECT id, object_type, object_id, question, active,
                   load_count, open_count,
                   ROUND(open_count / NULLIF(load_count, 0) * 100, 2) AS open_rate,
                   updated_at
            FROM {$table}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$order_by}
            LIMIT {$limit}";

    if ($args) {
        $sql = $wpdb->prepare($sql, $args);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : [];
}

/**
 * Telemetria por objeto para exportacion y diagnostico de errores.
 */
function seo_faq_report_get_object_tracking_rows($limit = 100)
{
    global $wpdb;

    seo_faq_maybe_upgrade_metrics_schema();
    $table = seo_faq_metrics_table_name();
    $limit = max(1, min(50000, (int) $limit));

    $rows = $wpdb->get_results(
        "SELECT object_type, object_id, block_render_count, block_view_count,
                engaged_block_count, faq_open_count, render_error_count,
                partial_render_count, missing_render_count,
                ROUND(engaged_block_count / NULLIF(block_view_count, 0) * 100, 2) AS engagement_rate,
                last_rendered_at, last_viewed_at, last_opened_at, last_error_at, updated_at
         FROM {$table}
         ORDER BY render_error_count DESC, block_view_count DESC, object_type ASC, object_id ASC
         LIMIT {$limit}",
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
}

/**
 * Boton de descarga JSON respetando los filtros del explorador.
 */
function seo_faq_report_render_export_button($filters)
{
    $args = [
        'action'         => 'seo_faq_export_json',
        'perf_type'      => (int) $filters['type'],
        'perf_status'    => (string) $filters['status'],
        'perf_usage'     => (string) $filters['usage'],
        'perf_search'    => (string) $filters['search'],
        'perf_min_loads' => (int) $filters['min_loads'],
        'perf_min_opens' => (int) $filters['min_opens'],
        'perf_order'     => (string) $filters['order'],
    ];

    $url = add_query_arg($args, admin_url('admin-post.php'));
    $url = wp_nonce_url($url, 'seo_faq_export_json');

    echo '<p class="seo-faq-report-actions">';
    echo '<a class="button button-primary" href="' . esc_url($url) . '">Descargar informe JSON</a>';
    echo '<span class="description">Incluye KPIs, calidad, telemetria por objeto y las filas que coinciden con los filtros actuales.</span>';
    echo '</p>';
}

/**
 * Filtros modernos del explorador de rendimiento.
 */
function seo_faq_report_render_performance_filters($filters)
{
    echo '<form method="get" class="seo-faq-performance-filters">';
    echo '<input type="hidden" name="page" value="seo-faq">';
    echo '<input type="hidden" name="tab" value="report">';

    echo '<label>Buscar <input type="search" name="perf_search" value="' . esc_attr($filters['search']) . '" placeholder="Pregunta"></label>';

    echo '<label>Nivel <select name="perf_type">';
    echo '<option value="0"' . selected($filters['type'], 0, false) . '>Todos</option>';
    foreach ([1, 2, 3] as $type) {
        echo '<option value="' . esc_attr($type) . '"' . selected($filters['type'], $type, false) . '>' . esc_html(seo_faq_report_type_label($type)) . '</option>';
    }
    echo '</select></label>';

    echo '<label>Estado <select name="perf_status">';
    echo '<option value="active"' . selected($filters['status'], 'active', false) . '>Activas</option>';
    echo '<option value="inactive"' . selected($filters['status'], 'inactive', false) . '>Inactivas</option>';
    echo '<option value="all"' . selected($filters['status'], 'all', false) . '>Todas</option>';
    echo '</select></label>';

    $usage_labels = [
        'all'             => 'Cualquier uso',
        'never_rendered'  => 'Nunca renderizadas',
        'no_opens'        => 'Renderizadas sin aperturas',
        'opened'          => 'Con aperturas',
        'low_engagement'  => '20+ renders y <1% apertura',
        'inconsistent'    => 'Aperturas > renders',
    ];
    echo '<label>Diagnostico <select name="perf_usage">';
    foreach ($usage_labels as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($filters['usage'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label>Min. renders <input type="number" min="0" name="perf_min_loads" value="' . esc_attr($filters['min_loads']) . '"></label>';
    echo '<label>Min. aperturas <input type="number" min="0" name="perf_min_opens" value="' . esc_attr($filters['min_opens']) . '"></label>';

    echo '<label>Orden <select name="perf_order">';
    $orders = ['loads' => 'Mas renderizadas', 'opens' => 'Mas abiertas', 'open_rate' => 'Mayor tasa apertura', 'recent' => 'Actualizadas recientemente'];
    foreach ($orders as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($filters['order'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

    submit_button('Aplicar filtros', 'secondary', '', false);
    echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'seo-faq', 'tab' => 'report'], admin_url('admin.php'))) . '">Limpiar</a>';
    echo '</form>';
}

/**
 * Explorador de rendimiento de FAQs individuales.
 */
function seo_faq_report_render_performance_explorer($filters)
{
    $rows = seo_faq_report_get_performance_rows($filters, 100);

    echo '<h3>Explorador de rendimiento</h3>';
    echo '<p>Filtra preguntas por nivel, estado y uso. Se muestran hasta 100 filas; el JSON exporta hasta 50.000.</p>';
    seo_faq_report_render_performance_filters($filters);

    seo_faq_report_render_rows(
        'Resultados filtrados',
        $rows,
        ['id', 'object_type', 'object_id', 'question', 'active', 'load_count', 'open_count', 'open_rate', 'updated_at']
    );
}

/**
 * Diagnostico de la capa de tracking del bloque.
 */
function seo_faq_report_render_tracking($tracking)
{
    echo '<h3>KPIs de tracking</h3>';
    echo '<p>Separa renderizado, visibilidad en viewport y engagement. Los contadores empiezan a acumularse desde que esta version queda desplegada.</p>';

    echo '<table class="widefat striped seo-faq-report-table"><thead><tr><th>KPI</th><th>Valor</th><th>Interpretacion</th></tr></thead><tbody>';
    $rows = [
        ['Bloques renderizados', number_format_i18n($tracking['block_renders']), 'El bloque FAQ se encontro realmente en el DOM.'],
        ['Bloques vistos', number_format_i18n($tracking['block_views']), 'Al menos una cabecera FAQ entro en el viewport.'],
        ['Bloques con interaccion', number_format_i18n($tracking['engaged_blocks']), 'En esa vista se abrio al menos una FAQ.'],
        ['Engagement del bloque', $tracking['engagement_rate'] . '%', 'Bloques con apertura / bloques vistos.'],
        ['Aperturas por bloque interactuado', $tracking['opens_per_engaged_block'], 'Numero medio de preguntas abiertas cuando hay interaccion.'],
        ['Exito de render completo', $tracking['render_success_rate'] . '%', 'Bloques con todas las FAQs esperadas / intentos detectados.'],
        ['Renders parciales', number_format_i18n($tracking['partial_renders']), 'El bloque aparecio, pero faltaba al menos una FAQ activa.'],
        ['Bloques ausentes', number_format_i18n($tracking['missing_renders']), 'Habia FAQs activas para el destino pero no aparecio ninguna en el DOM tras 5 s.'],
        ['Incidencias de render', number_format_i18n($tracking['render_errors']), 'Suma de renders parciales y bloques ausentes.'],
        ['Objetos con algun fallo', number_format_i18n($tracking['objects_with_errors']), 'Destinos distintos con al menos un fallo historico.'],
        ['Activas nunca renderizadas', number_format_i18n($tracking['active_never_rendered']), 'FAQs activas cuyo load_count sigue en cero.'],
        ['20+ renders sin aperturas', number_format_i18n($tracking['high_exposure_no_open']), 'Preguntas con exposicion suficiente y ninguna interaccion.'],
        ['Aperturas > renders', number_format_i18n($tracking['open_without_render']), 'Inconsistencia de datos que conviene revisar.'],
    ];
    foreach ($rows as $row) {
        echo '<tr><td><strong>' . esc_html($row[0]) . '</strong></td><td>' . esc_html((string) $row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
    }
    echo '</tbody></table>';

    $errors = array_filter(
        seo_faq_report_get_object_tracking_rows(50),
        static function ($row) {
            return (int) $row['render_error_count'] > 0;
        }
    );
    $errors = array_slice(array_values($errors), 0, 25);
    seo_faq_report_render_rows(
        'Destinos con errores de render',
        $errors,
        ['object_type', 'object_id', 'block_render_count', 'block_view_count', 'engaged_block_count', 'partial_render_count', 'missing_render_count', 'render_error_count', 'last_error_at']
    );
}

/**
 * Renderiza el informe de situación y permite limpiar, de forma reversible,
 * únicamente las FAQs definitivamente huérfanas.
 */
function seo_faq_render_report()
{
    global $wpdb;

    $table = seo_faq_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    if ($exists !== $table) {
        echo '<div class="notice notice-error"><p>No existe la tabla <code>' . esc_html($table) . '</code>.</p></div>';
        return;
    }

    seo_faq_maybe_upgrade_metrics_schema();

    $summary     = seo_faq_report_get_summary();
    $targets     = seo_faq_report_get_target_counts();
    $quality     = seo_faq_report_get_quality_metrics();
    $usage       = seo_faq_report_get_usage_metrics();
    $tracking    = seo_faq_report_get_tracking_metrics();
    $editorial   = seo_faq_report_get_editorial_quality();
    $duplicates  = seo_faq_report_get_duplicate_groups();
    $orphans     = seo_faq_report_get_orphan_rows();
    $unpublished = seo_faq_report_get_unpublished_rows();
    $perf_filter = seo_faq_report_read_performance_filters($_GET);

    seo_faq_report_render_styles();

    echo '<div class="seo-faq-report">';
    echo '<div class="seo-faq-report-head">';
    echo '<div><h2>Informe de FAQs</h2>';
    echo '<p>Panel de cobertura, calidad editorial y telemetria real. El tracking distingue renderizado, entrada en viewport y apertura de cada pregunta.</p></div>';
    seo_faq_report_render_export_button($perf_filter);
    echo '</div>';

    seo_faq_report_render_cards($summary, $quality, $usage, $tracking);
    seo_faq_report_render_tracking($tracking);
    seo_faq_report_render_usage($usage, $tracking);
    seo_faq_report_render_performance_explorer($perf_filter);
    seo_faq_report_render_editorial_quality($editorial);
    seo_faq_report_render_levels($summary, $targets);
    seo_faq_report_render_quality($quality);
    seo_faq_report_render_duplicate_management($duplicates);
    seo_faq_report_render_orphan_management($orphans);
    seo_faq_report_render_unpublished_targets($unpublished);
    seo_faq_report_render_missing();

    echo '</div>';
}

/**
 * Devuelve resumen por object_type.
 */
function seo_faq_report_get_summary()
{
    global $wpdb;
    $table = seo_faq_table_name();

    $summary = [
        1 => seo_faq_report_empty_level(1),
        2 => seo_faq_report_empty_level(2),
        3 => seo_faq_report_empty_level(3),
    ];

    $rows = $wpdb->get_results(
        "SELECT object_type,
                COUNT(*) AS total_faqs,
                COUNT(DISTINCT object_id) AS objects_with_faq,
                SUM(active = 1) AS active_faqs,
                SUM(active = 0) AS inactive_faqs,
                SUM(load_count) AS total_loads,
                SUM(open_count) AS total_opens,
                MAX(updated_at) AS last_updated_at
         FROM {$table}
         GROUP BY object_type
         ORDER BY object_type ASC"
    );

    foreach ($rows as $row) {
        $type = (int) $row->object_type;

        if (!isset($summary[$type])) {
            $summary[$type] = seo_faq_report_empty_level($type);
        }

        $summary[$type]['total_faqs']       = (int) $row->total_faqs;
        $summary[$type]['objects_with_faq'] = (int) $row->objects_with_faq;
        $summary[$type]['active_faqs']      = (int) $row->active_faqs;
        $summary[$type]['inactive_faqs']    = (int) $row->inactive_faqs;
        $summary[$type]['total_loads']      = (int) $row->total_loads;
        $summary[$type]['total_opens']      = (int) $row->total_opens;
        $summary[$type]['last_updated_at']  = (string) $row->last_updated_at;
    }

    return $summary;
}

/**
 * Crea un nivel vacío para evitar avisos si no hay FAQs en hubs o productos.
 */
function seo_faq_report_empty_level($object_type)
{
    return [
        'object_type'       => (int) $object_type,
        'label'             => seo_faq_report_type_label((int) $object_type),
        'total_faqs'        => 0,
        'objects_with_faq'  => 0,
        'active_faqs'       => 0,
        'inactive_faqs'     => 0,
        'total_loads'       => 0,
        'total_opens'       => 0,
        'last_updated_at'   => '',
    ];
}

/**
 * Traduce object_type a etiqueta legible.
 */
function seo_faq_report_type_label($object_type)
{
    if ((int) $object_type === 1) {
        return 'Hubs / páginas SEO';
    }

    if ((int) $object_type === 2) {
        return 'Categorías';
    }

    if ((int) $object_type === 3) {
        return 'Productos';
    }

    return 'Desconocido';
}

/**
 * Cuenta los objetos que deberían poder recibir FAQs.
 */
function seo_faq_report_get_target_counts()
{
    global $wpdb;

    return [
        1 => count(seo_faq_report_get_hub_ids()),
        2 => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat'"),
        3 => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"),
    ];
}

/**
 * Detecta hubs desde wp_seo_relations si existe.
 */
function seo_faq_report_get_hub_ids()
{
    global $wpdb;
    $relations_table = $wpdb->prefix . 'seo_relations';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $relations_table));

    if ($exists !== $relations_table) {
        return [];
    }

    $ids = $wpdb->get_col(
        "SELECT DISTINCT source_id AS object_id
         FROM {$relations_table}
         WHERE source_type = 'cluster' AND source_id > 0
         UNION
         SELECT DISTINCT target_id AS object_id
         FROM {$relations_table}
         WHERE relation_type IN ('cluster_to_primary', 'hub_primary_to_hub_secondary') AND target_id > 0"
    );

    return array_values(array_unique(array_map('absint', is_array($ids) ? $ids : [])));
}

/**
 * Calcula métricas de calidad y coherencia.
 */
function seo_faq_report_get_quality_metrics()
{
    global $wpdb;
    $table = seo_faq_table_name();

    return [
        'short_or_empty' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE TRIM(question) = '' OR TRIM(answer) = '' OR CHAR_LENGTH(TRIM(question)) < 20 OR CHAR_LENGTH(TRIM(answer)) < 80"
        ),
        'duplicates_same_object' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT object_type, object_id, TRIM(question) AS normalized_question, COUNT(*) AS total
                FROM {$table}
                GROUP BY object_type, object_id, TRIM(question)
                HAVING COUNT(*) > 1
             ) duplicated"
        ),
        'duplicate_rows_to_delete' => (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(total - 1), 0) FROM (
                SELECT object_type, object_id, TRIM(question) AS normalized_question, COUNT(*) AS total
                FROM {$table}
                GROUP BY object_type, object_id, TRIM(question)
                HAVING COUNT(*) > 1
             ) duplicated"
        ),
        'duplicates_global' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT TRIM(question) AS normalized_question, COUNT(*) AS total
                FROM {$table}
                GROUP BY TRIM(question)
                HAVING COUNT(*) > 1
             ) duplicated"
        ),
        'orphan_hubs' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.object_id WHERE f.object_type = 1 AND p.ID IS NULL"
        ),
        'orphan_categories' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} f LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = f.object_id AND tt.taxonomy = 'product_cat' WHERE f.object_type = 2 AND tt.term_id IS NULL"
        ),
        'orphan_products' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.object_id WHERE f.object_type = 3 AND (p.ID IS NULL OR p.post_type <> 'product')"
        ),
        'products_not_published' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} f INNER JOIN {$wpdb->posts} p ON p.ID = f.object_id WHERE f.object_type = 3 AND p.post_type = 'product' AND p.post_status <> 'publish'"
        ),
    ];
}

/**
 * Calcula uso agregado de FAQs.
 */
function seo_faq_report_get_usage_metrics()
{
    global $wpdb;
    $table = seo_faq_table_name();

    $row = $wpdb->get_row(
        "SELECT SUM(load_count) AS total_loads,
                SUM(open_count) AS total_opens,
                SUM(load_count > 0 AND open_count = 0) AS loaded_never_opened,
                SUM(load_count = 0) AS never_loaded
         FROM {$table}"
    );

    $loads = $row ? (int) $row->total_loads : 0;
    $opens = $row ? (int) $row->total_opens : 0;

    return [
        'total_loads'         => $loads,
        'total_opens'         => $opens,
        'open_rate'           => $loads > 0 ? round(($opens / $loads) * 100, 2) : 0,
        'loaded_never_opened' => $row ? (int) $row->loaded_never_opened : 0,
        'never_loaded'        => $row ? (int) $row->never_loaded : 0,
    ];
}

/**
 * Tarjetas superiores del informe.
 */
function seo_faq_report_render_cards($summary, $quality, $usage, $tracking)
{
    $total_faqs    = array_sum(array_column($summary, 'total_faqs'));
    $active_faqs   = array_sum(array_column($summary, 'active_faqs'));
    $inactive_faqs = array_sum(array_column($summary, 'inactive_faqs'));
    $objects_with  = array_sum(array_column($summary, 'objects_with_faq'));

    echo '<div class="seo-faq-report-cards">';
    seo_faq_report_card('Total FAQs', $total_faqs, 'Registros en seo_faq');
    seo_faq_report_card('Activas', $active_faqs, 'Preguntas disponibles en frontend');
    seo_faq_report_card('Inactivas', $inactive_faqs, 'Preguntas no mostradas');
    seo_faq_report_card('Objetos con FAQ', $objects_with, 'Hubs, categorias o productos');
    seo_faq_report_card('Bloques renderizados', $tracking['block_renders'], 'Bloques FAQ detectados en el DOM');
    seo_faq_report_card('Bloques vistos', $tracking['block_views'], 'Entraron realmente en viewport');
    seo_faq_report_card('Engagement', $tracking['engagement_rate'] . '%', 'Bloques vistos con alguna apertura');
    seo_faq_report_card('Errores de render', $tracking['render_errors'], 'FAQs esperadas que no aparecieron');
    echo '</div>';
}

/**
 * Tarjeta individual.
 */
function seo_faq_report_card($label, $value, $description)
{
    echo '<div class="seo-faq-report-card">';
    echo '<strong>' . esc_html((string) $value) . '</strong>';
    echo '<span>' . esc_html($label) . '</span>';
    echo '<small>' . esc_html($description) . '</small>';
    echo '</div>';
}

/**
 * Tabla por nivel.
 */
function seo_faq_report_render_levels($summary, $targets)
{
    echo '<h3>FAQs por nivel</h3>';
    echo '<table class="widefat striped seo-faq-report-table"><thead><tr>';
    echo '<th>Nivel</th><th>FAQs</th><th>Activas</th><th>Inactivas</th><th>Objetos con FAQ</th><th>Objetos totales</th><th>Sin FAQ</th><th>Cobertura</th><th>Ultima actualizacion</th>';
    echo '</tr></thead><tbody>';

    foreach ($summary as $object_type => $row) {
        $total_targets = isset($targets[$object_type]) ? (int) $targets[$object_type] : 0;
        $with_faq      = (int) $row['objects_with_faq'];
        $without_faq   = max(0, $total_targets - $with_faq);
        $coverage      = $total_targets > 0 ? round(($with_faq / $total_targets) * 100, 2) : 0;

        echo '<tr>';
        echo '<td><strong>' . esc_html($row['label']) . '</strong></td>';
        echo '<td>' . esc_html(number_format_i18n($row['total_faqs'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($row['active_faqs'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($row['inactive_faqs'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($with_faq)) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($total_targets)) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($without_faq)) . '</td>';
        echo '<td>' . esc_html($coverage) . '%</td>';
        echo '<td>' . esc_html($row['last_updated_at'] ?: '-') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

/**
 * Tabla de calidad.
 */
function seo_faq_report_render_quality($quality)
{
    echo '<h3>Calidad y coherencia</h3>';
    echo '<table class="widefat striped seo-faq-report-table"><thead><tr><th>Control</th><th>Resultado</th><th>Interpretación</th></tr></thead><tbody>';
    seo_faq_report_quality_row('FAQs vacías o demasiado cortas', $quality['short_or_empty'], 'Pregunta menor de 20 caracteres, respuesta menor de 80 caracteres o campos vacíos.');
    seo_faq_report_quality_row(
        'Duplicadas dentro del mismo objeto',
        $quality['duplicates_same_object'],
        'Misma pregunta repetida en el mismo hub, categoría o producto. Copias sobrantes eliminables: ' . number_format_i18n((int) $quality['duplicate_rows_to_delete']) . '.'
    );
    seo_faq_report_quality_row('Preguntas repetidas globalmente', $quality['duplicates_global'], 'Puede ser correcto, pero si es alto indica FAQs demasiado genéricas.');
    seo_faq_report_quality_row('FAQs huérfanas en hubs', $quality['orphan_hubs'], 'FAQs asociadas a páginas/hubs inexistentes.');
    seo_faq_report_quality_row('FAQs huérfanas en categorías', $quality['orphan_categories'], 'FAQs asociadas a product_cat inexistentes.');
    seo_faq_report_quality_row('FAQs huérfanas en productos', $quality['orphan_products'], 'FAQs asociadas a productos inexistentes.');
    seo_faq_report_quality_row('FAQs en productos no publicados', $quality['products_not_published'], 'FAQs asociadas a productos no publicados.');
    echo '</tbody></table>';
}

/**
 * Fila de calidad.
 */
function seo_faq_report_quality_row($label, $count, $description)
{
    $count = (int) $count;
    $class = $count > 0 ? 'seo-faq-report-warning' : 'seo-faq-report-ok';
    $state = $count > 0 ? 'Revisar' : 'OK';

    echo '<tr>';
    echo '<td><strong>' . esc_html($label) . '</strong></td>';
    echo '<td><span class="' . esc_attr($class) . '">' . esc_html($state) . '</span> · ' . esc_html(number_format_i18n($count)) . '</td>';
    echo '<td>' . esc_html($description) . '</td>';
    echo '</tr>';
}



/**
 * Calcula una auditoria editorial orientativa para todo el inventario FAQ.
 *
 * El resultado se cachea porque puntuar decenas de miles de respuestas en
 * cada carga del admin seria innecesariamente costoso.
 */
function seo_faq_report_get_editorial_quality()
{
    $cached = get_transient('seo_faq_editorial_quality_v2');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    $table = seo_faq_table_name();

    $rows = $wpdb->get_results(
        "SELECT f.id, f.object_type, f.object_id, f.question, f.answer, f.active,
                CASE
                    WHEN f.object_type = 2 THEN t.name
                    WHEN f.object_type IN (1, 3) THEN p.post_title
                    ELSE NULL
                END AS object_name
         FROM {$table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
           AND f.object_type IN (1, 3)
         LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = f.object_id
           AND tt.taxonomy = 'product_cat'
           AND f.object_type = 2
         LEFT JOIN {$wpdb->terms} t
            ON t.term_id = tt.term_id
         WHERE f.object_type IN (1, 2, 3)
         ORDER BY f.object_type ASC, f.object_id ASC, f.sort_order ASC, f.id ASC",
        ARRAY_A
    );

    $result = [
        'total'       => 0,
        'excellent'   => 0,
        'correct'     => 0,
        'improvable'  => 0,
        'by_type'     => [],
        'by_category' => [],
    ];

    foreach ([1, 2, 3] as $type) {
        $result['by_type'][$type] = [
            'label'      => seo_faq_report_type_label($type),
            'total'      => 0,
            'excellent'  => 0,
            'correct'    => 0,
            'improvable' => 0,
            'score_total'=> 0,
            'avg_score'  => 0,
        ];
    }

    if (!is_array($rows) || !$rows) {
        return $result;
    }

    foreach ($rows as $row) {
        $score = seo_faq_report_editorial_score($row['question'], $row['answer']);
        $class = seo_faq_report_editorial_class($score);
        $type  = (int) $row['object_type'];

        $result['total']++;
        $result['by_type'][$type]['total']++;
        $result['by_type'][$type]['score_total'] += $score;

        if ($class === 'A') {
            $result['excellent']++;
            $result['by_type'][$type]['excellent']++;
        } elseif ($class === 'B') {
            $result['correct']++;
            $result['by_type'][$type]['correct']++;
        } else {
            $result['improvable']++;
            $result['by_type'][$type]['improvable']++;
        }

        if ($type !== 2) {
            continue;
        }

        $object_id = (int) $row['object_id'];
        if (!isset($result['by_category'][$object_id])) {
            $result['by_category'][$object_id] = [
                'object_id'       => $object_id,
                'label'           => $row['object_name'] ?: ('Categoria #' . $object_id),
                'faqs'            => 0,
                'score_total'     => 0,
                'excellent'       => 0,
                'correct'         => 0,
                'improvable'      => 0,
                'sample_question' => '',
            ];
        }

        $result['by_category'][$object_id]['faqs']++;
        $result['by_category'][$object_id]['score_total'] += $score;
        if ($class === 'A') {
            $result['by_category'][$object_id]['excellent']++;
        } elseif ($class === 'B') {
            $result['by_category'][$object_id]['correct']++;
        } else {
            $result['by_category'][$object_id]['improvable']++;
            if ($result['by_category'][$object_id]['sample_question'] === '') {
                $result['by_category'][$object_id]['sample_question'] = (string) $row['question'];
            }
        }
    }

    foreach ($result['by_type'] as &$type_row) {
        $type_row['avg_score'] = $type_row['total'] > 0
            ? round($type_row['score_total'] / $type_row['total'], 2)
            : 0;
    }
    unset($type_row);

    foreach ($result['by_category'] as &$category) {
        $category['avg_score'] = $category['faqs'] > 0
            ? round($category['score_total'] / $category['faqs'], 2)
            : 0;
    }
    unset($category);

    uasort(
        $result['by_category'],
        static function ($a, $b) {
            if ($a['improvable'] !== $b['improvable']) {
                return $b['improvable'] <=> $a['improvable'];
            }
            return $a['avg_score'] <=> $b['avg_score'];
        }
    );

    set_transient('seo_faq_editorial_quality_v2', $result, 6 * HOUR_IN_SECONDS);
    return $result;
}

/**
 * Puntúa una FAQ con reglas editoriales simples.
 */
function seo_faq_report_editorial_score($question, $answer) {
    $question = trim(wp_strip_all_tags((string) $question));
    $answer   = trim(wp_strip_all_tags((string) $answer));

    $question_words = preg_split('/\s+/u', $question, -1, PREG_SPLIT_NO_EMPTY);
    $answer_words   = preg_split('/\s+/u', $answer, -1, PREG_SPLIT_NO_EMPTY);

    $q_count = is_array($question_words) ? count($question_words) : 0;
    $a_count = is_array($answer_words) ? count($answer_words) : 0;

    $score = 0;

    if ($q_count >= 8 && $q_count <= 24) {
        $score += 2;
    } elseif ($q_count >= 6 && $q_count <= 30) {
        $score += 1;
    }

    if ($a_count >= 45 && $a_count <= 100) {
        $score += 2;
    } elseif ($a_count >= 35 && $a_count <= 125) {
        $score += 1;
    }

    if (preg_match('/\b(quiero|necesito|no sé|cómo|conviene|puedo|debo|sirve|mejor|diferencia|incluye|compatible|evitar|error)\b/iu', $question)) {
        $score += 2;
    }

    if (preg_match('/\b(elegir|decidir|conviene|mejor|compatible|incluye|diferencia|sirve|puedo|debo|evitar|error|regalo)\b/iu', $question)) {
        $score += 2;
    }

    if (preg_match('/\b(revisa|comprueba|valora|elige|define|mide|consulta|confirma|verifica)\b/iu', $answer)) {
        $score += 1;
    }

    if (preg_match('/\b(no|no siempre|no necesariamente|no conviene|no debes|evita)\b/iu', $answer)) {
        $score += 1;
    }

    return $score;
}

/**
 * Traduce puntuación editorial a clase A/B/C.
 */
function seo_faq_report_editorial_class($score) {
    $score = (int) $score;

    if ($score >= 8) {
        return 'A';
    }

    if ($score >= 6) {
        return 'B';
    }

    return 'C';
}

/**
 * Renderiza el diagnostico editorial del inventario completo.
 */
function seo_faq_report_render_editorial_quality($editorial)
{
    $total = isset($editorial['total']) ? (int) $editorial['total'] : 0;

    echo '<h3>Diagnostico editorial de FAQs</h3>';
    echo '<p>Clasificacion heuristica para priorizar revision. Ahora audita hubs, categorias y productos; no sustituye una revision humana ni valida la veracidad de la respuesta.</p>';

    if ($total <= 0) {
        echo '<p>No hay FAQs validas para auditar.</p>';
        return;
    }

    echo '<div class="seo-faq-report-cards">';
    seo_faq_report_card('FAQs auditadas', $total, 'Inventario completo');
    seo_faq_report_card('Clase A', (int) $editorial['excellent'], 'Senales editoriales fuertes');
    seo_faq_report_card('Clase B', (int) $editorial['correct'], 'Correctas con margen de mejora');
    seo_faq_report_card('Clase C', (int) $editorial['improvable'], 'Prioridad de revision');
    echo '</div>';

    echo '<table class="widefat striped seo-faq-report-table"><thead><tr>';
    echo '<th>Nivel</th><th>FAQs</th><th>A</th><th>B</th><th>C</th><th>Puntuacion media</th>';
    echo '</tr></thead><tbody>';
    foreach ((array) $editorial['by_type'] as $type_row) {
        echo '<tr>';
        echo '<td><strong>' . esc_html($type_row['label']) . '</strong></td>';
        echo '<td>' . esc_html(number_format_i18n((int) $type_row['total'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $type_row['excellent'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $type_row['correct'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $type_row['improvable'])) . '</td>';
        echo '<td>' . esc_html((string) $type_row['avg_score']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<details style="margin:10px 0 20px;">';
    echo '<summary><strong>Como se calcula la calidad editorial</strong></summary>';
    echo '<p>La puntuacion usa longitud, lenguaje de intencion de compra, orientacion a compatibilidad/decision y presencia de recomendaciones o limites. A no significa perfecta y C no significa incorrecta.</p>';
    echo '</details>';

    echo '<h4>Categorias prioritarias para mejorar FAQs</h4>';
    echo '<p>Se muestran las categorias con mas FAQs clase C o menor puntuacion media.</p>';

    $quality_filter = isset($_GET['quality_filter']) ? sanitize_key(wp_unslash($_GET['quality_filter'])) : 'all';
    echo '<form method="get" style="margin-bottom:15px;">';
    echo '<input type="hidden" name="page" value="seo-faq">';
    echo '<input type="hidden" name="tab" value="report">';
    echo '<select name="quality_filter">';
    echo '<option value="all"' . selected($quality_filter, 'all', false) . '>Todas</option>';
    echo '<option value="c"' . selected($quality_filter, 'c', false) . '>Solo categorias con FAQs C</option>';
    echo '<option value="c3"' . selected($quality_filter, 'c3', false) . '>3 o mas FAQs C</option>';
    echo '<option value="c5"' . selected($quality_filter, 'c5', false) . '>5 o mas FAQs C</option>';
    echo '<option value="good"' . selected($quality_filter, 'good', false) . '>Categorias sin FAQs C</option>';
    echo '</select> ';
    submit_button('Filtrar calidad', 'secondary', '', false);
    echo '</form>';

    echo '<table class="widefat striped seo-faq-report-table"><thead><tr>';
    echo '<th>Categoria</th><th>FAQs</th><th>A</th><th>B</th><th>C</th><th>Puntuacion media</th><th>Ejemplo a revisar</th>';
    echo '</tr></thead><tbody>';

    $shown = 0;
    foreach ((array) $editorial['by_category'] as $row) {
        if ($quality_filter === 'c' && (int) $row['improvable'] === 0) continue;
        if ($quality_filter === 'c3' && (int) $row['improvable'] < 3) continue;
        if ($quality_filter === 'c5' && (int) $row['improvable'] < 5) continue;
        if ($quality_filter === 'good' && (int) $row['improvable'] > 0) continue;
        if ($shown >= 30) break;
        $shown++;

        echo '<tr>';
        echo '<td>' . esc_html($row['label']) . ' <code>#' . esc_html((string) $row['object_id']) . '</code></td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row['faqs'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row['excellent'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row['correct'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row['improvable'])) . '</td>';
        echo '<td>' . esc_html((string) $row['avg_score']) . '</td>';
        echo '<td>' . esc_html(wp_trim_words((string) $row['sample_question'], 18, '...')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/**
 * Uso agregado.
 */
function seo_faq_report_render_usage($usage, $tracking)
{
    echo '<h3>Uso e interaccion</h3>';
    echo '<p><strong>Importante:</strong> las cargas individuales se cuentan por FAQ. Un bloque con 4 preguntas renderizado una vez suma 1 bloque renderizado y 4 cargas individuales. Por eso el KPI principal del bloque sale de la tabla agregada de telemetria.</p>';
    echo '<table class="widefat striped seo-faq-report-table"><thead><tr><th>Metrica</th><th>Valor</th><th>Interpretacion</th></tr></thead><tbody>';
    echo '<tr><td>Renders individuales de FAQ</td><td>' . esc_html(number_format_i18n($usage['total_loads'])) . '</td><td>Suma de load_count de cada pregunta renderizada.</td></tr>';
    echo '<tr><td>Aperturas individuales</td><td>' . esc_html(number_format_i18n($usage['total_opens'])) . '</td><td>Suma de preguntas abiertas.</td></tr>';
    echo '<tr><td>Tasa individual de apertura</td><td>' . esc_html($usage['open_rate']) . '%</td><td>Aperturas / renders individuales.</td></tr>';
    echo '<tr><td>Renderizadas pero nunca abiertas</td><td>' . esc_html(number_format_i18n($usage['loaded_never_opened'])) . '</td><td>FAQs con exposicion pero sin ninguna apertura.</td></tr>';
    echo '<tr><td>Activas nunca renderizadas</td><td>' . esc_html(number_format_i18n($tracking['active_never_rendered'])) . '</td><td>FAQs activas cuyo bloque todavia no se ha detectado en frontend.</td></tr>';
    echo '<tr><td>Bloques renderizados</td><td>' . esc_html(number_format_i18n($tracking['block_renders'])) . '</td><td>Una carga de pagina con bloque FAQ detectado cuenta una vez.</td></tr>';
    echo '<tr><td>Bloques vistos</td><td>' . esc_html(number_format_i18n($tracking['block_views'])) . '</td><td>El usuario llego a la zona FAQ del documento.</td></tr>';
    echo '<tr><td>Bloques con alguna apertura</td><td>' . esc_html(number_format_i18n($tracking['engaged_blocks'])) . '</td><td>Vistas de bloque donde se abrio al menos una pregunta.</td></tr>';
    echo '<tr><td>Engagement del bloque</td><td>' . esc_html($tracking['engagement_rate']) . '%</td><td>Bloques interactuados / bloques vistos.</td></tr>';
    echo '</tbody></table>';
}

/**
 * Oportunidades de creación de FAQs.
 */
function seo_faq_report_render_missing()
{
    echo '<h3>Oportunidades de cobertura</h3>';
    echo '<p>Muestra una muestra de elementos sin FAQs. La cobertura es una senal de inventario, no un objetivo por si sola: conviene crear FAQs solo cuando exista una duda de compra real y documentada.</p>';
    echo '<div class="seo-faq-report-columns">';

    foreach ([1, 2, 3] as $object_type) {
        echo '<div class="seo-faq-report-column">';
        echo '<h4>' . esc_html(seo_faq_report_type_label($object_type)) . ' sin FAQs</h4>';
        seo_faq_report_render_missing_items($object_type, 15);
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Renderiza una muestra de elementos sin FAQs.
 */
function seo_faq_report_render_missing_items($object_type, $limit = 15)
{
    $items = seo_faq_report_get_missing_items((int) $object_type, (int) $limit);

    if (!$items) {
        echo '<p>No se han encontrado elementos pendientes en esta muestra.</p>';
        return;
    }

    echo '<ol>';
    foreach ($items as $item) {
        echo '<li>' . esc_html($item['label']) . ' <code>#' . esc_html((string) $item['id']) . '</code></li>';
    }
    echo '</ol>';
}

/**
 * Obtiene elementos sin FAQs.
 */
function seo_faq_report_get_missing_items($object_type, $limit = 15)
{
    global $wpdb;
    $table = seo_faq_table_name();
    $limit = max(1, min(100, (int) $limit));

    if ($object_type === 1) {
        $hub_ids = seo_faq_report_get_hub_ids();
        if (!$hub_ids) {
            return [];
        }
        $ids_sql = implode(',', array_map('absint', $hub_ids));
        $rows = $wpdb->get_results(
            "SELECT p.ID AS id, p.post_title AS label
             FROM {$wpdb->posts} p
             LEFT JOIN {$table} f ON f.object_type = 1 AND f.object_id = p.ID
             WHERE p.ID IN ({$ids_sql}) AND f.id IS NULL
             ORDER BY p.post_title ASC
             LIMIT {$limit}",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    if ($object_type === 2) {
        $rows = $wpdb->get_results(
            "SELECT t.term_id AS id, t.name AS label
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
             LEFT JOIN {$table} f ON f.object_type = 2 AND f.object_id = t.term_id
             WHERE f.id IS NULL
             ORDER BY t.name ASC
             LIMIT {$limit}",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    if ($object_type === 3) {
        $rows = $wpdb->get_results(
            "SELECT p.ID AS id, p.post_title AS label
             FROM {$wpdb->posts} p
             LEFT JOIN {$table} f ON f.object_type = 3 AND f.object_id = p.ID
             WHERE p.post_type = 'product' AND p.post_status = 'publish' AND f.id IS NULL
             ORDER BY p.post_title ASC
             LIMIT {$limit}",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    return [];
}



/**
 * Renderiza tabla genérica de detalles.
 */
function seo_faq_report_render_rows($title, $rows, $columns)
{
    $labels = [
        'id'                  => 'FAQ',
        'object_type'         => 'Nivel',
        'object_id'           => 'Destino',
        'question'            => 'Pregunta',
        'active'              => 'Activa',
        'load_count'          => 'Renders',
        'open_count'          => 'Aperturas',
        'open_rate'           => 'Apertura',
        'updated_at'          => 'Actualizada',
        'block_render_count'  => 'Bloques renderizados',
        'block_view_count'    => 'Bloques vistos',
        'engaged_block_count' => 'Con interaccion',
        'faq_open_count'      => 'Aperturas FAQ',
        'render_error_count'  => 'Errores render',
        'partial_render_count'=> 'Parciales',
        'missing_render_count'=> 'Ausentes',
        'engagement_rate'     => 'Engagement',
        'last_error_at'       => 'Ultimo error',
    ];

    echo '<h3>' . esc_html($title) . '</h3>';

    if (!$rows) {
        echo '<p>No se han encontrado registros en esta seccion.</p>';
        return;
    }

    echo '<div class="seo-faq-table-scroll"><table class="widefat striped seo-faq-report-table"><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . esc_html($labels[$column] ?? $column) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $value = isset($row[$column]) ? $row[$column] : '';
            if ($column === 'object_type') {
                $value = seo_faq_report_type_label((int) $value);
            } elseif ($column === 'active') {
                $value = (int) $value === 1 ? 'Si' : 'No';
            } elseif (in_array($column, ['open_rate', 'engagement_rate'], true) && $value !== '' && $value !== null) {
                $value = $value . '%';
            }
            echo '<td>' . seo_faq_report_format_cell($value) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

/**
 * Formatea celdas largas.
 */
function seo_faq_report_format_cell($value)
{
    if ($value === null || $value === '') {
        return '<span style="color:#777;">-</span>';
    }

    $text = (string) $value;
    if (function_exists('mb_strlen') && mb_strlen($text) > 220) {
        $text = mb_substr($text, 0, 220) . '...';
    } elseif (strlen($text) > 220) {
        $text = substr($text, 0, 220) . '...';
    }

    return esc_html($text);
}

/**
 * Estilos del informe.
 */
function seo_faq_report_render_styles()
{
    echo '<style>
        .seo-faq-report-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0;}
        .seo-faq-report-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px;}
        .seo-faq-report-card strong{display:block;font-size:24px;line-height:1.2;margin-bottom:6px;}
        .seo-faq-report-card span{display:block;font-weight:600;}
        .seo-faq-report-card small{display:block;color:#646970;margin-top:4px;}
        .seo-faq-report-table{margin:12px 0 28px;}
        .seo-faq-report-columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:12px 0 28px;}
        .seo-faq-report-column{background:#fff;border:1px solid #dcdcde;padding:14px;}
        .seo-faq-report-column ol{margin-left:20px;}
        .seo-faq-report-ok{color:#008a20;font-weight:700;}
        .seo-faq-report-warning{color:#b32d2e;font-weight:700;}
        .seo-faq-duplicate-summary{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 14px;margin:12px 0;}
        .seo-faq-duplicate-summary strong{font-size:16px;}
        .seo-faq-duplicate-toolbar{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px;}
        .seo-faq-duplicate-toolbar form{margin:0;}
        .seo-faq-duplicate-group{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:0 0 18px;overflow:hidden;}
        .seo-faq-duplicate-group-head{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:14px;background:#f6f7f7;border-bottom:1px solid #dcdcde;}
        .seo-faq-duplicate-group-head h4{font-size:15px;margin:0 0 4px;}
        .seo-faq-duplicate-group-head p{margin:3px 0;color:#50575e;}
        .seo-faq-duplicate-table{margin:0;border:0;}
        .seo-faq-duplicate-table thead th{background:#f0f0f1;color:#1d2327;position:static;height:auto;line-height:1.4;}
        .seo-faq-duplicate-table td,.seo-faq-duplicate-table th{vertical-align:top;}
        .seo-faq-duplicate-keeper td{background:#edfaef;}
        .seo-faq-keep-badge,.seo-faq-delete-badge{display:inline-block;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;}
        .seo-faq-keep-badge{background:#d1e7dd;color:#0f5132;}
        .seo-faq-delete-badge{background:#fff3cd;color:#664d03;}
        .seo-faq-orphan-summary{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;background:#fff8e5;border-left:4px solid #dba617;padding:12px 14px;margin:12px 0;}
        .seo-faq-orphan-summary strong{font-size:16px;}
        .seo-faq-orphan-toolbar{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px;}
        .seo-faq-orphan-toolbar form{margin:0;}
        .seo-faq-orphan-group{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:0 0 18px;overflow:hidden;}
        .seo-faq-orphan-group-head{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:14px;background:#f6f7f7;border-bottom:1px solid #dcdcde;}
        .seo-faq-orphan-group-head h4{font-size:15px;margin:0 0 4px;}
        .seo-faq-orphan-group-head p{margin:0;color:#646970;}
        .seo-faq-orphan-table{margin:0;border:0;}
        .seo-faq-orphan-table thead th{background:#f0f0f1;color:#1d2327;position:static;height:auto;line-height:1.4;}
        .seo-faq-orphan-table td,.seo-faq-orphan-table th{vertical-align:top;}
        .seo-faq-report-head{display:flex;gap:20px;align-items:flex-start;justify-content:space-between;margin-top:18px;}
        .seo-faq-report-head h2{margin-top:0;}
        .seo-faq-report-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-start;min-width:260px;margin:0;}
        .seo-faq-performance-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;border:1px solid #dcdcde;padding:14px;margin:12px 0 18px;}
        .seo-faq-performance-filters label{display:flex;flex-direction:column;gap:4px;font-weight:600;}
        .seo-faq-performance-filters input[type=number]{width:110px;}
        .seo-faq-table-scroll{overflow-x:auto;}
        .seo-faq-report-table td{vertical-align:top;}
        @media (max-width:782px){.seo-faq-report-head{flex-direction:column;}.seo-faq-performance-filters{align-items:stretch;}.seo-faq-duplicate-group-head,.seo-faq-orphan-group-head{align-items:flex-start;flex-direction:column;}.seo-faq-duplicate-table,.seo-faq-orphan-table{display:block;overflow-x:auto;}}
    </style>';
}


/**
 * Avisos administrativos.
 */
function seo_faq_show_notice()
{
    $message = isset($_GET['faq_msg'])
        ? sanitize_key(wp_unslash($_GET['faq_msg']))
        : '';

    if ($message === '') {
        return;
    }

    $total = isset($_GET['faq_total'])
        ? absint($_GET['faq_total'])
        : 0;

    $operation_id = isset($_GET['faq_operation'])
        ? absint($_GET['faq_operation'])
        : 0;

    if ($message === 'created_multiple') {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        _n(
                            'Se ha creado %d fila de FAQ.',
                            'Se han creado %d filas de FAQ.',
                            $total,
                            'seo-system'
                        ),
                        $total
                    )
                );
                ?>
            </p>
        </div>
        <?php
        return;
    }

    if ($message === 'duplicate_deleted') {
        $operation_text = $operation_id > 0
            ? ' Operación auditable: #' . $operation_id . '.'
            : '';
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        _n(
                            'Se ha eliminado %d copia duplicada de FAQ.',
                            'Se han eliminado %d copias duplicadas de FAQ.',
                            $total,
                            'seo-system'
                        ),
                        $total
                    ) . $operation_text
                );
                ?>
            </p>
        </div>
        <?php
        return;
    }

    if ($message === 'duplicate_none') {
        echo '<div class="notice notice-info is-dismissible"><p>No quedan copias duplicadas dentro del mismo objeto.</p></div>';
        return;
    }

    if ($message === 'duplicate_invalid') {
        echo '<div class="notice notice-error"><p>No se seleccionaron copias duplicadas válidas. El informe se ha actualizado sin modificar datos.</p></div>';
        return;
    }

    if ($message === 'duplicate_error') {
        echo '<div class="notice notice-error"><p>No se pudo completar la limpieza de FAQs duplicadas. La transacción se revirtió y no se confirmó ningún borrado.</p></div>';
        return;
    }

    if ($message === 'orphan_deleted') {
        $operation_text = $operation_id > 0
            ? ' Operación auditable: #' . $operation_id . '.'
            : '';
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        _n(
                            'Se ha eliminado %d FAQ huérfana.',
                            'Se han eliminado %d FAQs huérfanas.',
                            $total,
                            'seo-system'
                        ),
                        $total
                    ) . $operation_text
                );
                ?>
            </p>
        </div>
        <?php
        return;
    }

    if ($message === 'orphan_none') {
        echo '<div class="notice notice-info is-dismissible"><p>No quedan FAQs definitivamente huérfanas.</p></div>';
        return;
    }

    if ($message === 'orphan_invalid') {
        echo '<div class="notice notice-error"><p>No se seleccionaron FAQs huérfanas válidas. El informe se ha actualizado sin modificar datos.</p></div>';
        return;
    }

    if ($message === 'orphan_error') {
        echo '<div class="notice notice-error"><p>No se pudo completar la limpieza de FAQs huérfanas. La transacción se revirtió y no se confirmó ningún borrado.</p></div>';
        return;
    }

    $messages = [
        'created' => 'FAQ creada correctamente.',
        'updated' => 'FAQ actualizada correctamente.',
        'deleted' => $operation_id > 0
            ? 'FAQ eliminada correctamente. Operación auditable: #' . $operation_id . '.'
            : 'FAQ eliminada correctamente.',
        'status'  => 'Estado actualizado correctamente.',
        'missing' => 'Selecciona al menos un elemento y completa la pregunta y la respuesta.',
        'error'   => 'No se pudo completar la operación.',
    ];

    if (!isset($messages[$message])) {
        return;
    }

    $class = in_array($message, ['missing', 'error'], true)
        ? 'notice notice-error'
        : 'notice notice-success is-dismissible';

    ?>
    <div class="<?php echo esc_attr($class); ?>">
        <p><?php echo esc_html($messages[$message]); ?></p>
    </div>
    <?php
}