<?php
/**
 * Bandeja de Editor. Una sola vista para borradores de posts y landings.
 * No sustituye a Ingeniero, Solucionador ni Comparador.
 */
defined('ABSPATH') || exit;

final class SEO_Editor_Admin {
    const PAGE = 'seo-editor';

    public static function init() {
        add_action('admin_menu', array(__CLASS__,'register_page'), 45);
        add_filter('seo_content_items', array(__CLASS__,'content_card'), 28);
        add_filter('parent_file', array(__CLASS__,'parent_file'), 90);
        add_filter('submenu_file', array(__CLASS__,'submenu_file'), 90);
        add_action('admin_post_seo_editor_decision', array(__CLASS__,'handle_decision'));
    }

    public static function register_page() {
        add_submenu_page(null, 'Editor', 'Editor', 'manage_options', self::PAGE, array(__CLASS__,'render'));
    }

    public static function content_card($items) {
        $items = is_array($items) ? $items : array();
        array_unshift($items, array(
            'title'=>'Editor',
            'icon'=>'dashicons-edit-page',
            'page'=>self::PAGE,
            'desc'=>'Revisa borradores de posts y landings, publica, descarta e importa/exporta CSV.',
        ));
        return $items;
    }

    public static function parent_file($parent_file) {
        return self::current_page() ? 'seo-system' : $parent_file;
    }

    public static function submenu_file($submenu_file) {
        return self::current_page() ? 'seo-content' : $submenu_file;
    }

    private static function current_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page selector.
        return isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === self::PAGE;
    }

    private static function url($args = array()) {
        return add_query_arg(array_merge(array('page'=>self::PAGE),$args), admin_url('admin.php'));
    }

    private static function filters_from_request($input) {
        return array(
            'tipo'=>sanitize_key((string)wp_unslash($input['tipo'] ?? '')),
            'origen'=>sanitize_key((string)wp_unslash($input['origen'] ?? '')),
            'buscar'=>sanitize_text_field((string)wp_unslash($input['buscar'] ?? '')),
        );
    }

    private static function safe_page($input) {
        return max(1,absint($input['editor_p'] ?? 1));
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(self::url($args));
        exit;
    }

    public static function handle_decision() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        check_admin_referer('seo_editor_decision');
        if (empty($_POST['confirm_review'])) self::redirect(array('editor_error'=>'Confirma que has revisado los contenidos que vas a tratar.'));
        $decision = sanitize_key((string)wp_unslash($_POST['decision'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_map('absint',(array)wp_unslash($_POST['selected_ids'] ?? array())))));
        $single_publish = absint($_POST['single_publish'] ?? 0);
        $single_trash = absint($_POST['single_trash'] ?? 0);
        if ($single_publish) { $decision='publish'; $ids=array($single_publish); }
        elseif ($single_trash) { $decision='trash'; $ids=array($single_trash); }
        elseif ($decision === 'publish_visible') {
            // Solo filas realmente vistas: nunca volver a calcular una página
            // cuyo orden haya podido cambiar desde que el usuario la revisó.
            $ids = array_values(array_unique(array_filter(array_map('absint',
                (array)wp_unslash($_POST['visible_ids'] ?? array())))));
            $decision = 'publish';
        }
        if (!in_array($decision,array('publish','trash'),true)) {
            self::redirect(array('editor_error'=>'Selecciona una acción válida.'));
        }
        $ids = array_slice($ids,0,SEO_Editor_Core::PAGE_SIZE);
        if (!$ids) self::redirect(array('editor_error'=>'No hay borradores seleccionados o aptos para esta acción.'));
        $ok = 0; $fail = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Revision fingerprints are sanitized individually before hash comparison below.
        $revisions = isset($_POST['revision']) && is_array($_POST['revision'])
            ? map_deep( wp_unslash( $_POST['revision'] ), 'sanitize_text_field' ) : array();
        foreach ($ids as $id) {
            $post = get_post($id);
            $expected = isset($revisions[$id]) ? sanitize_text_field((string)$revisions[$id]) : '';
            $current = SEO_Editor_Core::fingerprint($post);
            if ($expected === '' || $current === '' || !hash_equals($current, $expected)) {
                $fail[]='#'.$id.': el borrador ha cambiado; recarga Editor.';
                continue;
            }
            $result = SEO_Editor_Core::apply_decision($id, $decision);
            if (is_wp_error($result)) $fail[]='#'.$id.': '.$result->get_error_message();
            else $ok++;
        }
        self::redirect(array(
            'editor_done'=>$ok,
            'editor_failed'=>count($fail),
            'editor_action'=>$decision,
            'editor_error'=>$fail ? implode(' | ',array_slice($fail,0,2)) : '',
        ));
    }

    private static function metric($label,$number) {
        echo '<div class="seo-editor-metric"><span>'.esc_html($label).'</span>';
        echo '<strong>'.esc_html(number_format_i18n((int)$number)).'</strong></div>';
    }

    private static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor notice.
        $message = isset($_GET['editor_error']) ? sanitize_text_field(wp_unslash($_GET['editor_error'])) : '';
        if ($message !== '') {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($message).'</p></div>';
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result counters generated by editor redirects.
        if (isset($_GET['editor_done'])) {
            $done=absint(wp_unslash($_GET['editor_done']));
            $failed=isset($_GET['editor_failed']) ? absint(wp_unslash($_GET['editor_failed'])) : 0;
            $action=sanitize_key((string)wp_unslash($_GET['editor_action'] ?? ''));
            echo '<div class="notice notice-info inline"><p>';
            echo esc_html(($action === 'publish' ? 'Publicados' : 'Enviados a papelera') . ': ' . $done . ' · Incidencias: ' . $failed);
            echo '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only import result counters generated by editor redirects.
        if (isset($_GET['editor_created'])) {
            $created = absint(wp_unslash($_GET['editor_created']));
            $updated = isset($_GET['editor_updated']) ? absint(wp_unslash($_GET['editor_updated'])) : 0;
            $failures = isset($_GET['editor_failures']) ? absint(wp_unslash($_GET['editor_failures'])) : 0;
            echo '<div class="notice notice-info inline"><p>';
            echo 'Importación CSV · Nuevos borradores: '.esc_html(number_format_i18n($created));
            echo ' · Actualizados: '.esc_html(number_format_i18n($updated));
            echo ' · Errores: '.esc_html(number_format_i18n($failures));
            echo '. No se ha publicado contenido.</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    private static function edit_url($post) {
        if ($post->post_type === 'page') {
            return add_query_arg(array('page'=>'seo-page-admin','tab'=>'landings','edit_page'=>$post->ID),
                admin_url('admin.php')) . '#seo-page-' . absint($post->ID);
        }
        return add_query_arg(array('page'=>'seo-post-editor','post_id'=>$post->ID), admin_url('admin.php'));
    }

    private static function render_filters($filters) {
        ?>
        <form method="get" class="seo-editor-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <label>Tipo
                <select name="tipo">
                    <option value="">Todos</option>
                    <option value="post" <?php selected($filters['tipo'],'post'); ?>>Posts</option>
                    <option value="landing" <?php selected($filters['tipo'],'landing'); ?>>Landings</option>
                </select>
            </label>
            <label>Origen
                <select name="origen">
                    <?php foreach (array(''=>'Todos','solucionador'=>'Solucionador','ingeniero'=>'Ingeniero',
                        'comparador'=>'Comparador','landings'=>'Landings','noticias'=>'Noticias',
                        'manual'=>'Manual','externo'=>'Externo') as $value=>$label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['origen'],$value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Buscar
                <input type="search" name="buscar" placeholder="Título o ID"
                       value="<?php echo esc_attr($filters['buscar']); ?>">
            </label>
            <button class="button">Filtrar</button>
            <a class="button" href="<?php echo esc_url(self::url()); ?>">Limpiar</a>
        </form>
        <?php
    }

    private static function render_rows($rows, $filters, $page) {
        $pages = max(1,(int)ceil(count($rows)/SEO_Editor_Core::PAGE_SIZE));
        $page = min($page,$pages);
        $visible = array_slice($rows,($page-1)*SEO_Editor_Core::PAGE_SIZE,SEO_Editor_Core::PAGE_SIZE);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="seo_editor_decision">
            <input type="hidden" name="tipo" value="<?php echo esc_attr($filters['tipo']); ?>">
            <input type="hidden" name="origen" value="<?php echo esc_attr($filters['origen']); ?>">
            <input type="hidden" name="buscar" value="<?php echo esc_attr($filters['buscar']); ?>">
            <input type="hidden" name="editor_p" value="<?php echo absint($page); ?>">
            <?php wp_nonce_field('seo_editor_decision'); ?>
            <div class="seo-editor-toolbar">
                <label><input type="checkbox" id="seo-editor-all"> Seleccionar todos los visibles</label>
                <button name="decision" value="publish" class="button button-primary">Publicar seleccionados</button>
                <button name="decision" value="publish_visible" class="button">Publicar todos los aptos de esta página</button>
                <button name="decision" value="trash" class="button">Papelera seleccionados</button>
            </div>
            <div class="seo-editor-table-wrap">
                <table class="widefat striped seo-editor-table">
                    <thead><tr>
                        <th><span class="screen-reader-text">Selección</span></th>
                        <th>ID / Tipo</th><th>Título y contenido</th><th>Origen</th>
                        <th>Categorías</th><th>Comprobación</th><th>Acciones</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($visible as $post) :
                        $type = SEO_Editor_Core::kind($post);
                        $source = SEO_Editor_Core::source($post);
                        $errors = SEO_Editor_Core::readiness($post);
                        $edit = self::edit_url($post);
                        $cat_count = count(SEO_Editor_Core::category_ids($post));
                        $preview = trim(wp_strip_all_tags(strip_shortcodes((string)$post->post_content)));
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="revision[<?php echo absint($post->ID); ?>]"
                                    value="<?php echo esc_attr(SEO_Editor_Core::fingerprint($post)); ?>">
                                <input type="hidden" name="visible_ids[]" value="<?php echo absint($post->ID); ?>">
                                <input class="seo-editor-select" type="checkbox" name="selected_ids[]" value="<?php echo absint($post->ID); ?>" aria-label="Seleccionar borrador <?php echo absint($post->ID); ?>"></td>
                            <td><strong>#<?php echo absint($post->ID); ?></strong><br>
                                <small><?php echo esc_html($type === 'landing'?'Landing':ucfirst(SEO_Editor_Exchange::section($post))); ?></small>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($edit); ?>"><strong><?php echo esc_html($post->post_title); ?></strong></a>
                                <?php if ($post->post_excerpt !== '') : ?>
                                    <p class="seo-editor-excerpt"><?php echo esc_html(wp_html_excerpt($post->post_excerpt,170,'…')); ?></p>
                                <?php endif; ?>
                                <details><summary>Leer material recibido</summary>
                                    <p><?php echo esc_html(mb_substr($preview,0,1100)); ?><?php echo mb_strlen($preview)>1100?'…':''; ?></p>
                                </details>
                            </td>
                            <td><?php echo esc_html($source); ?></td>
                            <td><?php echo absint($cat_count); ?> product_cat</td>
                            <td><?php if (!$errors) : ?>
                                    <strong class="seo-editor-ready">Apto para revisar</strong>
                                <?php else : ?>
                                    <strong class="seo-editor-blocked">Completar</strong>
                                    <small><?php echo esc_html(implode(' ',$errors)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="seo-editor-row-actions">
                                    <a class="button button-small" href="<?php echo esc_url($edit); ?>">Editar</a>
                                    <button type="submit" name="single_publish" value="<?php echo absint($post->ID); ?>"
                                        class="button button-small" <?php disabled((bool)$errors); ?>>Publicar</button>
                                    <button type="submit" name="single_trash" value="<?php echo absint($post->ID); ?>"
                                        class="button button-small">Papelera</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$visible) : ?><tr><td colspan="7">No hay borradores que cumplan los filtros.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="seo-editor-confirm"><label>
                <input type="checkbox" name="confirm_review" value="1" required>
                He revisado personalmente las propuestas seleccionadas. Sé que publicar las hará visibles y que Papelera retirará el borrador.
            </label></p>
        </form>
        <?php if ($pages > 1) : ?>
            <nav class="tablenav-pages seo-editor-pager" aria-label="Paginación de borradores">
                <?php for ($i=1;$i<=$pages;$i++) : ?>
                    <?php if ($i === $page) : ?>
                        <strong aria-current="page"><?php echo absint($i); ?></strong>
                    <?php else : ?>
                        <a href="<?php echo esc_url(self::url(array_merge($filters,array('editor_p'=>$i)))); ?>"><?php echo absint($i); ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
        <script>
            (function(){
                var all=document.getElementById('seo-editor-all');
                if(!all)return;
                all.addEventListener('change',function(){
                    var boxes=document.querySelectorAll('.seo-editor-select');
                    for(var i=0;i<boxes.length;i++)boxes[i].checked=all.checked;
                });
            })();
        </script>
        <?php
    }

    private static function render_exchange($token = '', $draft_count = 0) {
        ?>
        <section class="seo-editor-panel">
            <h2>Importar y exportar CSV</h2>
            <p>Exporta los borradores para revisarlos o modificarlos fuera del plugin y vuelve a importar el CSV.
                Puedes añadir filas nuevas dejando <code>id</code> y <code>revision_hash</code> vacíos.
                <strong>La importación nunca publica ni envía contenidos a la papelera.</strong></p>
            <div class="seo-editor-exchange-grid">
                <div>
                    <h3>Exportar borradores</h3>
                    <p>Formato CSV UTF-8 con separador punto y coma. Hasta 250 posts y 125 landings recientes por exportación.</p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=seo_editor_export_csv'),'seo_editor_export_csv')); ?>">Descargar todo (análisis)</a>
                    <p class="description">Para editar y reimportar, utiliza tandas de 100 filas:</p>
                    <p class="seo-editor-csv-batches">
                        <?php for ($i=1;$i<=min(4,(int)ceil($draft_count / SEO_Editor_Exchange::MAX_ROWS));$i++) : ?>
                            <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(
                                add_query_arg(array('action'=>'seo_editor_export_csv','lote'=>$i),admin_url('admin-post.php')),
                                'seo_editor_export_csv')); ?>">Lote <?php echo absint($i); ?></a>
                        <?php endfor; ?>
                    </p>
                </div>
                <div>
                    <h3>Importar CSV</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        enctype="multipart/form-data">
                        <input type="hidden" name="action" value="seo_editor_csv_preview">
                        <?php wp_nonce_field('seo_editor_csv_preview'); ?>
                        <label>CSV editado (hasta 100 filas / 2 MB)
                            <input type="file" name="editor_csv" accept=".csv,.txt" required>
                        </label>
                        <p><button class="button button-primary">Validar y previsualizar</button></p>
                    </form>
                </div>
            </div>
            <p class="description">Para posts externos, usa <code>tipo=post</code> y
                <code>seccion=guia|comparativa|noticia</code>. Para páginas comerciales,
                <code>tipo=landing</code> y <code>seccion=landing</code>. Las categorías son IDs de
                <code>product_cat</code> separados por comas; las landings necesitan al menos dos.
                El hash protege los cambios ya realizados por otros usuarios.</p>
        </section>
        <?php
        if ($token === '') return;
        $rows = SEO_Editor_Exchange::preview($token);
        if (!$rows) {
            echo '<div class="notice notice-warning"><p>La vista previa ha caducado o no existe. Vuelve a cargar el CSV.</p></div>';
            return;
        }
        $ok = 0; $invalid = 0;
        foreach ($rows as $row) {
            $p = SEO_Editor_Exchange::review_row($row);
            if ($p['valid']) $ok++; else $invalid++;
        }
        ?>
        <section class="seo-editor-panel">
            <h2>Vista previa: <?php echo absint(count($rows)); ?> filas</h2>
            <p><strong><?php echo absint($ok); ?></strong> válidas ·
                <strong><?php echo absint($invalid); ?></strong> con incidencias.
                Los cambios aún no se han aplicado.</p>
            <div class="seo-editor-table-wrap">
                <table class="widefat striped"><thead><tr>
                    <th>Fila</th><th>Operación</th><th>Tipo / sección</th><th>Título</th><th>Diferencias / errores</th>
                </tr></thead><tbody>
                <?php foreach ($rows as $i=>$row) :
                    $p=SEO_Editor_Exchange::review_row($row); ?>
                    <tr>
                        <td><?php echo absint($i+2); ?></td>
                        <td><?php echo esc_html($p['operation']); ?></td>
                        <td><?php echo esc_html($p['kind'].' / '.$p['section']); ?></td>
                        <td><?php echo esc_html($p['title']); ?></td>
                        <td><?php echo $p['valid']
                            ? esc_html($p['id'] ? ($p['diff'] ? implode(', ',$p['diff']) : 'Sin cambios') : 'Nuevo borrador')
                            : '<strong class="seo-editor-blocked">'.esc_html(implode(' | ',$p['errors'])).'</strong>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php if (!$invalid) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="seo_editor_csv_apply">
                    <input type="hidden" name="preview_token" value="<?php echo esc_attr($token); ?>">
                    <?php wp_nonce_field('seo_editor_csv_apply_' . $token); ?>
                    <p><label><input type="checkbox" name="confirm_csv" value="1" required>
                        Confirmo que he revisado esta vista previa y deseo aplicar los cambios a los borradores.</label></p>
                    <button class="button button-primary">Confirmar importación (sin publicar)</button>
                </form>
            <?php else : ?>
                <p>Corrige las filas señaladas y vuelve a importar el archivo. No se aplicará ninguna fila hasta que todas sean válidas.</p>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        $all = SEO_Editor_Core::drafts();
        $filters = self::filters_from_request($_GET);
        $rows = SEO_Editor_Core::drafts($filters);
        $post_count = 0; $landing_count = 0; $ready_count = 0;
        foreach ($all as $post) {
            if (SEO_Editor_Core::kind($post) === 'landing') $landing_count++;
            else $post_count++;
            if (!SEO_Editor_Core::readiness($post)) $ready_count++;
        }
        $preview = isset($_GET['preview_token']) ? sanitize_text_field(wp_unslash($_GET['preview_token'])) : '';
        ?>
        <div class="wrap seo-editor">
            <style>
                .seo-editor{max-width:1720px}.seo-editor-lead{max-width:1020px;color:#50575e;line-height:1.6}
                .seo-editor-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:20px 0}
                .seo-editor-metric{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:16px}
                .seo-editor-metric span{display:block;color:#50575e}.seo-editor-metric strong{font-size:29px;display:block;margin-top:6px}
                .seo-editor-panel{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:18px;margin:20px 0}
                .seo-editor-filters,.seo-editor-toolbar{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin:14px 0}
                .seo-editor-filters label{display:grid;gap:5px}.seo-editor-filters select,.seo-editor-filters input{min-width:150px}
                .seo-editor-toolbar{align-items:center}.seo-editor-table-wrap{overflow-x:auto}
                .seo-editor-table th,.seo-editor-table td{vertical-align:top}.seo-editor-table td small{display:block;color:#50575e}
                .seo-editor-table td:nth-child(3){min-width:220px}.seo-editor-excerpt{max-width:500px;color:#50575e}
                .seo-editor-table details{margin-top:8px;max-width:620px}.seo-editor-table details p{white-space:pre-wrap;line-height:1.5}
                .seo-editor-row-actions{display:flex;gap:5px;flex-wrap:wrap;min-width:174px}
                .seo-editor-ready{color:#087b35}.seo-editor-blocked{color:#b32d2e}
                .seo-editor-confirm{background:#f6f7f7;padding:12px;border-left:4px solid #72aee6}
                .seo-editor-pager{display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end}
                .seo-editor-exchange-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
                .seo-editor-exchange-grid>div{padding:10px 16px;border:1px solid #dcdcde;border-radius:6px}
                .seo-editor-exchange-grid input[type=file]{display:block;max-width:100%;margin-top:10px}
                @media(max-width:782px){.seo-editor-panel{padding:12px}.seo-editor-metric{padding:12px}}
            </style>
            <h1>Editor · Contenidos</h1>
            <p class="seo-editor-lead">Bandeja central de borradores procedentes de Solucionador, Ingeniero,
                Comparador, landings y entradas manuales. Los motores mantienen su investigación y sus fuentes.
                Aquí se revisa, publica o envía a papelera el resultado.</p>
            <?php self::notice(); ?>
            <div class="seo-editor-metrics">
                <?php
                self::metric('Borradores totales', count($all));
                self::metric('Posts', $post_count);
                self::metric('Landings', $landing_count);
                self::metric('Aptos para revisión', $ready_count);
                self::metric('Necesitan completar', count($all)-$ready_count);
                ?>
            </div>
            <p class="description">La bandeja muestra hasta los 250 posts y 125 landings modificados más recientemente.
                «Apto» indica comprobaciones mínimas superadas, no aprobación editorial automática.
                No se muestran páginas corporativas, hubs ni productos.</p>
            <section class="seo-editor-panel">
                <h2>Borradores pendientes de decisión</h2>
                <?php self::render_filters($filters); ?>
                <p><?php echo esc_html(number_format_i18n(count($rows))); ?> borradores coinciden con el filtro.
                    Se muestran <?php echo esc_html(SEO_Editor_Core::PAGE_SIZE); ?> por página.</p>
                <?php self::render_rows($rows,$filters,self::safe_page($_GET)); ?>
            </section>
            <?php self::render_exchange($preview, count($all)); ?>
        </div>
        <?php
    }
}

SEO_Editor_Admin::init();
