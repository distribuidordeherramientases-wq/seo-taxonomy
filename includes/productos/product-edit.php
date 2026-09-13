<?php
/**
 * Selector y edicion unitaria de productos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_page_edit_products')) {
    function seo_page_edit_products() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return;
        }

        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if ($product_id > 0) {
            echo '<div style="max-width:1180px;padding:10px 0 30px;">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:15px;">';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'product-page-admin', 'tab' => 'editar'], admin_url('admin.php'))) . '">← Volver a selección</a>';
            echo '<h1 style="margin:0;">Editar producto</h1>';
            echo '</div>';

            ?>
            <details open style="margin:0 0 18px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.03);">
                <summary style="cursor:pointer;padding:14px 16px;font-weight:700;font-size:14px;">
                    Guía de contenido: título, excerpt y descripción
                </summary>

                <div style="padding:0 16px 16px;line-height:1.55;color:#2c3338;">
                    <p style="margin-top:0;">
                        <strong>Objetivo:</strong> explicar este producto con precisión y ayudar a elegirlo.
                        La ficha ya muestra por separado especificaciones técnicas, etiquetas semánticas, FAQs, precio, stock y relacionados;
                        <strong>no repitas esos bloques de forma mecánica en la descripción</strong>.
                    </p>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin:14px 0;">
                        <div style="padding:12px;background:#f6f7f7;border-radius:6px;">
                            <strong>1. Título</strong>
                            <p style="margin:6px 0 0;">Identifica el producto: <strong>TIPO + marca/modelo + 1 o 2 datos decisivos</strong>. Evita convertir el título en una descripción larga de proveedor.</p>
                        </div>
                        <div style="padding:12px;background:#f6f7f7;border-radius:6px;">
                            <strong>2. Excerpt</strong>
                            <p style="margin:6px 0 0;">En 1-3 frases responde: <strong>qué es + para qué sirve + qué dato lo diferencia</strong>. Debe poder entenderse sin leer el resto de la ficha.</p>
                        </div>
                        <div style="padding:12px;background:#f6f7f7;border-radius:6px;">
                            <strong>3. Descripción</strong>
                            <p style="margin:6px 0 0;">Texto flexible y específico. Explica uso, criterio de elección, compatibilidad, contexto y limitaciones solo cuando los datos reales del producto lo permitan.</p>
                        </div>
                    </div>

                    <h3 style="font-size:14px;margin:18px 0 8px;">La descripción cambia según el ROL</h3>
                    <ul style="margin:0 0 12px 20px;">
                        <li><strong>Herramienta:</strong> trabajo que realiza, material o pieza, prestaciones que cambian el uso, cuándo elegirla y frente a qué alternativa.</li>
                        <li><strong>Equipamiento:</strong> función dentro del proceso o taller, capacidad, dimensionamiento, instalación/entorno y perfil de uso.</li>
                        <li><strong>Accesorio:</strong> qué completa o adapta, compatibilidad exacta, medidas/interfaz y qué cambia al utilizarlo.</li>
                        <li><strong>Consumible:</strong> operación, material, máquina compatible, medida/grado y contenido del paquete cuando conste.</li>
                        <li><strong>Repuesto:</strong> pieza que sustituye, equipo/modelo compatible, referencia o medidas y comprobaciones antes de pedirlo.</li>
                    </ul>

                    <h3 style="font-size:14px;margin:18px 0 8px;">Usa TIPO, APLICACIÓN, PLATAFORMA, SUBTIPO y atributos como módulos</h3>
                    <p style="margin:0 0 10px;">
                        No hay que rellenar siempre los mismos apartados. Activa únicamente la información que tenga sentido para este producto:
                        compatibilidad de plataforma si existe, contexto de aplicación si aporta valor, y atributos técnicos solo cuando ayudan a explicar o decidir.
                    </p>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;">
                        <div style="padding:12px;border-left:4px solid #00a32a;background:#f0f6fc;">
                            <strong>Prioriza</strong>
                            <ul style="margin:6px 0 0 18px;">
                                <li>Datos reales del producto.</li>
                                <li>Vocabulary canónico asignado.</li>
                                <li>Atributos técnicos canónicos.</li>
                                <li>Marca, modelo, medidas y compatibilidades verificadas.</li>
                                <li>Criterios que ayuden a elegir o utilizar el producto.</li>
                            </ul>
                        </div>
                        <div style="padding:12px;border-left:4px solid #d63638;background:#fcf0f1;">
                            <strong>Evita</strong>
                            <ul style="margin:6px 0 0 18px;">
                                <li>Inventar prestaciones típicas de productos parecidos.</li>
                                <li>Frases como «esta referencia pertenece a...» o «esta ficha corresponde a...».</li>
                                <li>Obligar a usar siempre Características, Ventajas, Aplicaciones, Compatibilidad y Consejos.</li>
                                <li>Repetir en párrafos la tabla de especificaciones que ya muestra la plantilla.</li>
                                <li>Alargar el texto solo para alcanzar una longitud.</li>
                            </ul>
                        </div>
                    </div>

                    <p style="margin:14px 0 0;padding:10px 12px;background:#fff8e5;border-radius:6px;">
                        <strong>Regla final:</strong> es mejor una descripción corta y específica que una larga y genérica.
                        Cada afirmación debe describir, comparar, contextualizar o ayudar a elegir <strong>este producto</strong>.
                    </p>
                </div>
            </details>
            <?php

            if (function_exists('seo_render_product_form')) {
                seo_render_product_form($product_id);
            } else {
                echo '<div class="notice notice-error"><p>No está disponible el formulario compartido de producto.</p></div>';
            }

            echo '</div>';
            return;
        }

        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $category_id = isset($_GET['cat']) ? absint($_GET['cat']) : 0;
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $provider = isset($_GET['provider']) ? sanitize_text_field(wp_unslash($_GET['provider'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $score_order = isset($_GET['score_order']) && 'asc' === strtolower((string) $_GET['score_order']) ? 'asc' : 'desc';
        $score_days = 28;
        $per_page = 40;

        // Reutiliza el snapshot agregado de Google. No consulta producto por producto
        // y queda cacheado por el modulo de informes.
        if (function_exists('seo_product_reports_catalog_snapshot')) {
            seo_product_reports_catalog_snapshot($score_days, false);
        }

        $allowed_statuses = ['publish', 'draft', 'pending', 'private'];
        if ($status !== '' && !in_array($status, $allowed_statuses, true)) {
            $status = '';
        }

        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $providers = function_exists('seo_product_get_provider_suggestions')
            ? seo_product_get_provider_suggestions()
            : [];

        $args = [
            'post_type'      => 'product',
            'post_status'    => $status !== '' ? [$status] : $allowed_statuses,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];

        if (function_exists('seo_product_reports_score_posts_clauses')) {
            $args['seo_product_reports_score_days'] = $score_days;
            $args['seo_product_reports_score_order'] = $score_order;
        }

        if ($category_id > 0) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => [$category_id],
            ]];
        }

        if ($provider !== '') {
            $args['meta_query'] = [[
                'key'   => '_seo_proveedor',
                'value' => $provider,
            ]];
        }

        $forced_ids = [];
        if ($search !== '') {
            if (ctype_digit($search) && get_post_type(absint($search)) === 'product') {
                $forced_ids[] = absint($search);
            }

            if (function_exists('wc_get_product_id_by_sku')) {
                $sku_id = absint(wc_get_product_id_by_sku($search));
                if ($sku_id > 0) {
                    $forced_ids[] = $sku_id;
                }
            }

            $title_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID
                     FROM {$wpdb->posts}
                     WHERE post_type = 'product'
                       AND post_title LIKE %s
                     ORDER BY post_modified DESC
                     LIMIT 100",
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );

            $sku_like_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = '_sku'
                       AND meta_value LIKE %s
                     LIMIT 100",
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );

            $forced_ids = array_values(array_unique(array_filter(array_map('absint', array_merge($forced_ids, $title_ids, $sku_like_ids)))));
            $args['post__in'] = !empty($forced_ids) ? $forced_ids : [0];
        }

        if (function_exists('seo_product_reports_score_posts_clauses')) {
            add_filter('posts_clauses', 'seo_product_reports_score_posts_clauses', 20, 2);
        }
        $query = new WP_Query($args);
        if (function_exists('seo_product_reports_score_posts_clauses')) {
            remove_filter('posts_clauses', 'seo_product_reports_score_posts_clauses', 20);
        }

        echo '<div style="padding:10px 0 30px;max-width:1280px;">';
        echo '<h1 style="margin-bottom:8px;">Editar productos</h1>';
        echo '<p style="margin-top:0;color:#646970;">Selecciona primero un producto. La edición se realiza de forma unitaria para evitar dobles escrituras.</p>';
        ?>

        <form method="get" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:18px 0;display:grid;grid-template-columns:2fr 1.2fr 1fr 1.2fr auto;gap:12px;align-items:end;">
            <input type="hidden" name="page" value="product-page-admin">
            <input type="hidden" name="tab" value="editar">
            <input type="hidden" name="score_order" value="<?php echo esc_attr($score_order); ?>">

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Buscar</label>
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Título, SKU o ID" style="width:100%;">
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Categoría</label>
                <select name="cat" style="width:100%;">
                    <option value="">Todas</option>
                    <?php seo_product_category_option_tree((array) $categories, 0, $category_id ? [$category_id] : []); ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Estado</label>
                <select name="status" style="width:100%;">
                    <option value="">Todos</option>
                    <option value="publish" <?php selected($status, 'publish'); ?>>Publicado</option>
                    <option value="draft" <?php selected($status, 'draft'); ?>>Borrador</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>Pendiente</option>
                    <option value="private" <?php selected($status, 'private'); ?>>Privado</option>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Proveedor</label>
                <select name="provider" style="width:100%;">
                    <option value="">Todos</option>
                    <?php foreach ($providers as $provider_option): ?>
                        <option value="<?php echo esc_attr($provider_option); ?>" <?php selected($provider, $provider_option); ?>><?php echo esc_html($provider_option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:6px;">
                <button class="button button-primary" type="submit">Filtrar</button>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'product-page-admin', 'tab' => 'editar'], admin_url('admin.php'))); ?>">Limpiar</a>
            </div>
        </form>

        <?php
        $toggle_score_order = 'desc' === $score_order ? 'asc' : 'desc';
        $score_sort_url = add_query_arg(
            [
                'page'        => 'product-page-admin',
                'tab'         => 'editar',
                'q'           => $search,
                'cat'         => $category_id,
                'status'      => $status,
                'provider'    => $provider,
                'score_order' => $toggle_score_order,
                'paged'       => 1,
            ],
            admin_url('admin.php')
        );
        $score_arrow = 'desc' === $score_order ? '↓' : '↑';
        ?>

        <table class="widefat striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th style="width:160px;">SKU</th>
                    <th>Producto</th>
                    <th style="width:145px;"><a href="<?php echo esc_url($score_sort_url); ?>" title="Cambiar orden por puntuación">Puntuación Google <?php echo esc_html($score_arrow); ?></a><br><small style="font-weight:400;color:#646970;">28 días</small></th>
                    <th style="width:180px;">Proveedor</th>
                    <th style="width:120px;">Estado</th>
                    <th style="width:170px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$query->have_posts()): ?>
                    <tr><td colspan="7">No se han encontrado productos con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($query->posts as $post): ?>
                        <?php
                        $wc_product = wc_get_product($post->ID);
                        $sku = $wc_product ? $wc_product->get_sku('edit') : '';
                        $row_provider = (string) get_post_meta($post->ID, '_seo_proveedor', true);
                        $score_summary = function_exists('seo_product_reports_get_summary')
                            ? seo_product_reports_get_summary($post->ID, $score_days)
                            : [];
                        $score = max(0, min(100, absint($score_summary['score'] ?? 0)));
                        $score_bg = $score >= 70 ? '#edfaef' : ($score >= 40 ? '#fff8e5' : '#f0f0f1');
                        $score_fg = $score >= 70 ? '#008a20' : ($score >= 40 ? '#996800' : '#50575e');
                        $edit_url = add_query_arg(
                            [
                                'page'       => 'product-page-admin',
                                'tab'        => 'editar',
                                'product_id' => $post->ID,
                            ],
                            admin_url('admin.php')
                        );
                        ?>
                        <tr>
                            <td><?php echo absint($post->ID); ?></td>
                            <td><code><?php echo esc_html($sku ?: '—'); ?></code></td>
                            <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                            <td><span title="Índice de rendimiento Google de los últimos 28 días" style="display:inline-block;min-width:58px;text-align:center;padding:5px 8px;border-radius:999px;font-weight:700;background:<?php echo esc_attr($score_bg); ?>;color:<?php echo esc_attr($score_fg); ?>;"><?php echo esc_html($score . '/100'); ?></span></td>
                            <td><?php echo esc_html($row_provider ?: '—'); ?></td>
                            <td><?php echo esc_html($post->post_status); ?></td>
                            <td style="display:flex;gap:5px;flex-wrap:wrap;"><a class="button button-small" href="<?php echo esc_url($edit_url); ?>">Editar</a><?php if (function_exists('seo_product_reports_admin_url')): ?><a class="button button-small" href="<?php echo esc_url(seo_product_reports_admin_url($post->ID, 28)); ?>">Informe</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ($query->max_num_pages > 1) {
            $base_args = [
                'page'     => 'product-page-admin',
                'tab'      => 'editar',
                'q'        => $search,
                'cat'      => $category_id,
                'status'      => $status,
                'provider'    => $provider,
                'score_order' => $score_order,
                'paged'       => '%#%',
            ];

            echo '<div style="margin-top:18px;">';
            echo wp_kses_post(
                paginate_links([
                    'base'      => esc_url_raw(add_query_arg($base_args, admin_url('admin.php'))),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => max(1, (int) $query->max_num_pages),
                    'type'      => 'list',
                    'prev_text' => '«',
                    'next_text' => '»',
                ])
            );
            echo '</div>';
        }

        wp_reset_postdata();
        echo '</div>';
    }
}
