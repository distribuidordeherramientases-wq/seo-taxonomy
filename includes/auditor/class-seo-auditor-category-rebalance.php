<?php
/**
 * Auditor: propuestas semiautomaticas para equilibrar categorias de producto.
 *
 * Divide categorias grandes por cohortes semanticas y propone concentrar
 * categorias pequenas en otra categoria elegida por el administrador.
 *
 * Ninguna propuesta modifica el catalogo hasta quedar aprobada y ejecutarse
 * mediante una accion POST protegida por nonce.
 */
defined('ABSPATH') || exit;

final class SEO_Auditor_Category_Rebalance {
    const OPTION = 'seo_auditor_category_rebalance_v1';
    const VERSION = 2;
    const IDEAL_MIN = 5;
    const IDEAL_MAX = 10;
    const DIAGNOSTIC_LIMIT = 250;

    public static function init() {
        add_action('admin_post_seo_auditor_rebalance_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_seo_auditor_rebalance_apply', array(__CLASS__, 'handle_apply'));
        add_action('admin_post_seo_auditor_rebalance_apply_all', array(__CLASS__, 'handle_apply_all'));
    }

    public static function render($report) {
        $report = is_array($report) ? $report : array();
        $proposals = self::build_proposals($report);
        $state = self::state();
        $split = array_values(array_filter($proposals, static function($row){ return 'split' === ($row['kind'] ?? ''); }));
        $merge = array_values(array_filter($proposals, static function($row){ return 'merge' === ($row['kind'] ?? ''); }));

        $inventory_from_report = !empty($report['category_rebalance_inventory']) && is_array($report['category_rebalance_inventory']);
        $inventory = self::size_inventory($report);
        $profiles = self::profile_map($report);

        $split_review = array_values(array_filter($inventory, static function($row){
            return absint($row['products'] ?? 0) > self::IDEAL_MAX;
        }));
        $merge_review = array_values(array_filter($inventory, static function($row){
            $n = absint($row['products'] ?? 0);
            return $n > 0 && $n < self::IDEAL_MIN;
        }));

        $split_ids = array();
        foreach ($split as $row) $split_ids[absint($row['source_id'] ?? 0)] = true;
        $merge_ids = array();
        foreach ($merge as $row) $merge_ids[absint($row['source_id'] ?? 0)] = true;

        self::render_notice();

        echo '<div class="seo-auditor__rebalance">';
        echo '<div class="seo-auditor__metrics">';
        self::metric('Revisar por division', count($split_review));
        self::metric('Propuestas de division', count($split));
        self::metric('Revisar concentracion', count($merge_review));
        self::metric('Propuestas de concentracion', count($merge));
        self::metric('Tamano objetivo', self::IDEAL_MIN . '-' . self::IDEAL_MAX);
        self::metric('Aprobadas', self::approved_count($state));
        echo '</div>';

        echo '<div class="notice notice-info inline"><p><strong>Diagnostico + propuesta.</strong> El Auditor ya no oculta una categoria solo porque aun no sepa como dividirla o con que hermana concentrarla. Primero marca las categorias que merecen revision por tamano; cuando existe evidencia semantica suficiente, ademas genera una propuesta ejecutable. Ninguna revision por tamano modifica el catalogo.</p></div>';

        if (!$inventory_from_report) {
            echo '<div class="notice notice-warning inline"><p>La auditoria guardada es anterior a este diagnostico ampliado. Los recuentos por tamano se han calculado con el catalogo actual; las propuestas semanticas siguen procediendo de la ultima auditoria guardada. Repite la auditoria completa para sincronizar ambas capas.</p></div>';
        }

        echo '<div class="seo-auditor__rebalance-toolbar">';
        self::render_apply_all_form('split', 'Aplicar todas las divisiones aprobadas');
        self::render_apply_all_form('merge', 'Aplicar todas las concentraciones aprobadas');
        echo '</div>';

        echo '<h3>Dividir categorias grandes o mezcladas</h3>';
        echo '<p class="description">Una categoria con mas de ' . esc_html(self::IDEAL_MAX) . ' productos se muestra como <strong>revision por tamano</strong>. Solo se convierte en propuesta ejecutable cuando el Auditor encuentra cohortes TIPO/SUBTIPO suficientemente diferenciadas. Los productos ambiguos o no asignados permanecen en origen.</p>';

        if (!$split) {
            echo '<p><strong>No hay propuestas de division ejecutables en la ultima auditoria.</strong> Esto no significa que no existan categorias grandes que revisar.</p>';
        } else {
            echo '<h4>Propuestas con evidencia semantica suficiente</h4>';
            foreach ($split as $proposal) self::render_split($proposal, $state);
        }

        $split_diagnostics = array_values(array_filter($split_review, static function($row) use ($split_ids){
            return empty($split_ids[absint($row['category_id'] ?? 0)]);
        }));
        self::render_diagnostic_table('split', $split_diagnostics, $profiles);

        echo '<h3 style="margin-top:28px">Concentrar categorias pequenas</h3>';
        echo '<p class="description">Todas las categorias con 1-' . esc_html(self::IDEAL_MIN - 1) . ' productos aparecen como <strong>revision por tamano</strong>. Solo se genera una propuesta ejecutable cuando existe una categoria hermana suficientemente parecida; el destino sugerido sigue siendo orientativo y puede cambiarse antes de aprobar.</p>';

        if (!$merge) {
            echo '<p><strong>No hay propuestas de concentracion ejecutables en la ultima auditoria.</strong> Esto no significa que no existan categorias pequenas que revisar.</p>';
        } else {
            echo '<h4>Propuestas con destino suficientemente parecido</h4>';
            foreach ($merge as $proposal) self::render_merge($proposal, $state);
        }

        $merge_diagnostics = array_values(array_filter($merge_review, static function($row) use ($merge_ids){
            return empty($merge_ids[absint($row['category_id'] ?? 0)]);
        }));
        self::render_diagnostic_table('merge', $merge_diagnostics, $profiles);
        echo '</div>';
    }

    private static function size_inventory($report) {
        $rows = array();
        if (!empty($report['category_rebalance_inventory']) && is_array($report['category_rebalance_inventory'])) {
            foreach ((array) $report['category_rebalance_inventory'] as $row) {
                $cid = absint($row['category_id'] ?? 0);
                $products = absint($row['products'] ?? 0);
                if (!$cid || $products < 1) continue;
                $rows[] = array(
                    'category_id'=>$cid,
                    'category'=>(string) ($row['category'] ?? ''),
                    'parent_id'=>absint($row['parent_id'] ?? 0),
                    'products'=>$products,
                );
            }
        } else {
            global $wpdb;
            $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
            if (is_wp_error($terms)) $terms = array();

            $counts = array();
            $sql = "SELECT tt.term_id,COUNT(DISTINCT p.ID) products
                    FROM {$wpdb->term_taxonomy} tt
                    INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id
                    INNER JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish'
                    WHERE tt.taxonomy='product_cat'
                    GROUP BY tt.term_id";
            foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
                $counts[absint($row['term_id'] ?? 0)] = absint($row['products'] ?? 0);
            }

            foreach ((array) $terms as $term) {
                $cid = absint($term->term_id ?? 0);
                $products = absint($counts[$cid] ?? 0);
                if (!$cid || $products < 1) continue;
                $rows[] = array(
                    'category_id'=>$cid,
                    'category'=>(string) ($term->name ?? ''),
                    'parent_id'=>absint($term->parent ?? 0),
                    'products'=>$products,
                );
            }
        }

        usort($rows, static function($a, $b){
            $cmp = absint($b['products'] ?? 0) <=> absint($a['products'] ?? 0);
            if (0 !== $cmp) return $cmp;
            return strcasecmp((string) ($a['category'] ?? ''), (string) ($b['category'] ?? ''));
        });
        return $rows;
    }

    private static function profile_map($report) {
        $map = array();
        foreach ((array) ($report['category_profiles'] ?? array()) as $profile) {
            $cid = absint($profile['category_id'] ?? 0);
            if ($cid) $map[$cid] = (array) $profile;
        }
        return $map;
    }

    private static function render_diagnostic_table($kind, $rows, $profiles) {
        $rows = array_values((array) $rows);
        $is_split = 'split' === $kind;
        $title = $is_split ? 'Revisar por tamano, sin propuesta de division' : 'Categorias pequenas sin destino sugerido';
        echo '<h4 style="margin-top:18px">' . esc_html($title) . ' (' . esc_html(number_format_i18n(count($rows))) . ')</h4>';

        if (!$rows) {
            echo '<p class="description">No quedan categorias de este tipo sin una propuesta ejecutable.</p>';
            return;
        }

        $visible = array_slice($rows, 0, self::DIAGNOSTIC_LIMIT);
        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr>';
        echo '<th>Categoria</th><th>Productos</th>';
        if ($is_split) echo '<th>Evidencia semantica</th>';
        else echo '<th>Rama</th>';
        echo '<th>Diagnostico</th><th>Accion</th></tr></thead><tbody>';

        foreach ($visible as $row) {
            $cid = absint($row['category_id'] ?? 0);
            $products = absint($row['products'] ?? 0);
            $profile = (array) ($profiles[$cid] ?? array());
            $flags = array_map('sanitize_key', (array) ($profile['flags'] ?? array()));

            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['category'] ?? '')) . '</strong><br><small>#' . esc_html($cid) . '</small></td>';
            echo '<td><strong>' . esc_html(number_format_i18n($products)) . '</strong></td>';

            if ($is_split) {
                if ($profile) {
                    $coherence = isset($profile['coherence']) ? number_format_i18n(100 * (float) $profile['coherence'], 1) . '%' : '—';
                    echo '<td>Coherencia: <strong>' . esc_html($coherence) . '</strong>';
                    $concepts = array();
                    foreach (array_slice((array) ($profile['top_concepts'] ?? array()), 0, 3) as $concept) {
                        $label = (string) ($concept['concept'] ?? '');
                        $ratio = isset($concept['ratio']) ? number_format_i18n(100 * (float) $concept['ratio'], 0) . '%' : '';
                        if ($label !== '') $concepts[] = $label . ($ratio !== '' ? ' ' . $ratio : '');
                    }
                    if ($concepts) echo '<br><small>' . esc_html(implode(' · ', $concepts)) . '</small>';
                    echo '</td>';
                } else {
                    echo '<td><span class="description">Sin perfil semantico guardado.</span></td>';
                }

                if (!$profile || empty($profile['top_concepts'])) {
                    $reason = 'Categoria grande, pero falta cobertura TIPO/SUBTIPO suficiente para justificar una division concreta.';
                } elseif (in_array('heterogeneous', $flags, true)) {
                    $reason = 'Hay mezcla semantica, pero no se han formado dos cohortes TIPO/SUBTIPO suficientemente exclusivas para mover productos con seguridad.';
                } else {
                    $reason = 'Tamano fuera del objetivo; todavia no cumple la regla de dos cohortes TIPO/SUBTIPO diferenciadas (25–75 % y solapamiento bajo).';
                }
            } else {
                $parent_id = absint($row['parent_id'] ?? 0);
                $parent = $parent_id ? get_term($parent_id, 'product_cat') : null;
                $parent_name = ($parent && !is_wp_error($parent)) ? (string) $parent->name : 'Nivel raiz';
                echo '<td>' . esc_html($parent_name) . ($parent_id ? '<br><small>#' . esc_html($parent_id) . '</small>' : '') . '</td>';
                $reason = 'Categoria pequena; no se encontro una hermana del mismo nivel con similitud suficiente (>=30 %) en nombre o Vocabulary para proponer una concentracion segura.';
            }

            echo '<td>' . esc_html($reason) . '</td>';
            $edit = get_edit_term_link($cid, 'product_cat');
            echo '<td>';
            if ($edit && !is_wp_error($edit)) echo '<a class="button button-small" href="' . esc_url($edit) . '">Abrir categoria</a>';
            else echo '—';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';

        if (count($rows) > count($visible)) {
            echo '<p class="description">Se muestran las primeras ' . esc_html(number_format_i18n(count($visible))) . ' de ' . esc_html(number_format_i18n(count($rows))) . ' categorias, ordenadas por numero de productos.</p>';
        }
    }

    private static function render_notice() {
        $status = sanitize_key((string) ($_GET['rebalance_status'] ?? ''));
        $message = isset($_GET['rebalance_message']) ? sanitize_text_field(wp_unslash($_GET['rebalance_message'])) : '';
        if (!$status && !$message) return;
        $class = 'ok' === $status ? 'notice-success' : ('warning' === $status ? 'notice-warning' : 'notice-error');
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message ?: ('ok' === $status ? 'Operacion completada.' : 'No se pudo completar la operacion.')) . '</p></div>';
    }

    private static function metric($label, $value) {
        echo '<div class="seo-auditor__metric"><strong>' . esc_html((string) $value) . '</strong><span>' . esc_html((string) $label) . '</span></div>';
    }

    private static function render_apply_all_form($kind, $label) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Se volveran a validar todas las propuestas aprobadas antes de aplicar. ¿Continuar?\');">';
        echo '<input type="hidden" name="action" value="seo_auditor_rebalance_apply_all">';
        echo '<input type="hidden" name="kind" value="' . esc_attr($kind) . '">';
        wp_nonce_field('seo_auditor_rebalance_apply_all');
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function render_split($proposal, $state) {
        $source_id = absint($proposal['source_id'] ?? 0);
        $decision = (array) ($state['decisions'][$source_id] ?? array());
        $status = sanitize_key((string) ($decision['status'] ?? 'pending'));
        $targets = (array) ($decision['groups'] ?? array());

        echo '<section class="postbox seo-auditor__rebalance-card">';
        echo '<div class="seo-auditor__rebalance-head"><div><h4>' . esc_html((string) $proposal['source_name']) . ' <code>#' . esc_html($source_id) . '</code></h4>';
        echo '<p>' . esc_html(absint($proposal['product_count'])) . ' productos · coherencia ' . esc_html(number_format_i18n(100 * (float) ($proposal['coherence'] ?? 0), 1)) . '% · estado: <strong>' . esc_html(self::status_label($status)) . '</strong></p></div>';
        echo '<a class="button" href="' . esc_url(get_edit_term_link($source_id, 'product_cat')) . '">Abrir categoria</a></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_auditor_rebalance_save">';
        echo '<input type="hidden" name="kind" value="split"><input type="hidden" name="source_id" value="' . esc_attr($source_id) . '">';
        wp_nonce_field('seo_auditor_rebalance_save');

        echo '<table class="widefat striped"><thead><tr><th>Cohorte</th><th>Productos</th><th>Destino</th></tr></thead><tbody>';
        foreach ((array) $proposal['groups'] as $group) {
            $concept = (string) ($group['concept'] ?? '');
            $key = sanitize_key(str_replace(':', '__', $concept));
            $saved = (array) ($targets[$concept] ?? array());
            $target_id = array_key_exists('target_id', $saved) ? absint($saved['target_id']) : absint($group['suggested_target_id'] ?? 0);
            $target_name = trim((string) ($saved['target_name'] ?? ($group['suggested_name'] ?? $group['label'] ?? '')));
            echo '<tr><td><strong>' . esc_html((string) ($group['label'] ?? $concept)) . '</strong><div class="description"><code>' . esc_html($concept) . '</code></div>';
            self::render_product_details((array) ($group['product_ids'] ?? array()));
            echo '</td><td>' . esc_html(count((array) ($group['product_ids'] ?? array()))) . '</td><td>';
            self::render_category_select('split_target_' . $key, $target_id, $source_id, 'Crear categoria nueva');
            echo '<div class="description" style="margin-top:5px">Si eliges crear nueva:</div><input type="text" class="regular-text" name="split_name_' . esc_attr($key) . '" value="' . esc_attr($target_name) . '">';
            echo '<input type="hidden" name="split_concept_' . esc_attr($key) . '" value="' . esc_attr($concept) . '">';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        if (!empty($proposal['ambiguous_ids'])) {
            echo '<p class="seo-auditor__rebalance-warning"><strong>Ambiguos:</strong> ' . esc_html(count($proposal['ambiguous_ids'])) . ' productos pertenecen a mas de una cohorte y se quedan en origen.</p>';
            self::render_product_details($proposal['ambiguous_ids'], 'Ver ambiguos');
        }
        if (!empty($proposal['remaining_ids'])) {
            echo '<p class="description"><strong>Sin asignacion segura:</strong> ' . esc_html(count($proposal['remaining_ids'])) . ' productos permanecen en la categoria origen.</p>';
        }

        echo '<div class="seo-auditor__rebalance-actions">';
        echo '<button class="button button-primary" name="decision" value="approve">Aprobar division</button> ';
        echo '<button class="button" name="decision" value="reject">Descartar propuesta</button>';
        echo '</div></form>';

        if ('approved' === $status) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-auditor__rebalance-apply" onsubmit="return confirm(\'Se moveran productos y podran crearse categorias nuevas. ¿Aplicar esta division?\');">';
            echo '<input type="hidden" name="action" value="seo_auditor_rebalance_apply"><input type="hidden" name="source_id" value="' . esc_attr($source_id) . '">';
            wp_nonce_field('seo_auditor_rebalance_apply');
            submit_button('Aplicar division aprobada', 'primary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
    }

    private static function render_merge($proposal, $state) {
        $source_id = absint($proposal['source_id'] ?? 0);
        $decision = (array) ($state['decisions'][$source_id] ?? array());
        $status = sanitize_key((string) ($decision['status'] ?? 'pending'));
        $target_id = array_key_exists('target_id', $decision) ? absint($decision['target_id']) : absint($proposal['suggested_target_id'] ?? 0);

        echo '<section class="postbox seo-auditor__rebalance-card">';
        echo '<div class="seo-auditor__rebalance-head"><div><h4>' . esc_html((string) $proposal['source_name']) . ' <code>#' . esc_html($source_id) . '</code></h4>';
        echo '<p>' . esc_html(absint($proposal['product_count'])) . ' productos · destino sugerido: <strong>' . esc_html((string) ($proposal['suggested_target_name'] ?? '—')) . '</strong> · similitud ' . esc_html(number_format_i18n(100 * (float) ($proposal['similarity'] ?? 0), 0)) . '% · estado: <strong>' . esc_html(self::status_label($status)) . '</strong></p></div>';
        echo '<a class="button" href="' . esc_url(get_edit_term_link($source_id, 'product_cat')) . '">Abrir categoria</a></div>';

        self::render_product_details((array) ($proposal['product_ids'] ?? array()));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_auditor_rebalance_save"><input type="hidden" name="kind" value="merge"><input type="hidden" name="source_id" value="' . esc_attr($source_id) . '">';
        wp_nonce_field('seo_auditor_rebalance_save');
        echo '<p><label><strong>Categoria destino</strong><br>';
        self::render_category_select('merge_target', $target_id, $source_id, 'Selecciona destino');
        echo '</label></p>';
        echo '<p class="description">Al aplicar: se conservan las demas categorias de cada producto, se sustituye solo esta categoria origen por el destino, se valida que el origen no tenga hijas ni landings, se elimina el origen y se crea un redirect 301 hacia el destino.</p>';
        echo '<div class="seo-auditor__rebalance-actions"><button class="button button-primary" name="decision" value="approve">Aprobar concentracion</button> <button class="button" name="decision" value="reject">Descartar propuesta</button></div>';
        echo '</form>';

        if ('approved' === $status && $target_id) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-auditor__rebalance-apply" onsubmit="return confirm(\'Esta accion movera los productos, eliminara la categoria origen y creara un 301. ¿Continuar?\');">';
            echo '<input type="hidden" name="action" value="seo_auditor_rebalance_apply"><input type="hidden" name="source_id" value="' . esc_attr($source_id) . '">';
            wp_nonce_field('seo_auditor_rebalance_apply');
            submit_button('Aplicar concentracion aprobada', 'primary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
    }

    private static function render_product_details($ids, $label = 'Ver productos') {
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        echo '<details class="seo-auditor__rebalance-products"><summary>' . esc_html($label) . ' (' . esc_html(count($ids)) . ')</summary><ul>';
        foreach ($ids as $id) {
            $title = get_the_title($id);
            echo '<li><a href="' . esc_url(get_edit_post_link($id)) . '">#' . esc_html($id) . ' ' . esc_html($title ?: 'Producto') . '</a></li>';
        }
        echo '</ul></details>';
    }

    private static function render_category_select($name, $selected, $exclude, $empty_label) {
        $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'orderby'=>'name','order'=>'ASC'));
        echo '<select name="' . esc_attr($name) . '" style="min-width:300px;max-width:100%"><option value="0">' . esc_html($empty_label) . '</option>';
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (absint($term->term_id) === absint($exclude)) continue;
                echo '<option value="' . esc_attr(absint($term->term_id)) . '" ' . selected(absint($selected), absint($term->term_id), false) . '>' . esc_html($term->name) . ' (#' . esc_html(absint($term->term_id)) . ')</option>';
            }
        }
        echo '</select>';
    }

    public static function handle_save() {
        self::guard('seo_auditor_rebalance_save');
        $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
        $source_id = absint($_POST['source_id'] ?? 0);
        $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
        if (!in_array($kind, array('split','merge'), true) || !$source_id || !in_array($decision, array('approve','reject'), true)) {
            self::redirect('error', 'Propuesta no valida.');
        }

        $report = self::last_report();
        $proposals = self::build_proposals($report);
        $proposal = self::find_proposal($proposals, $source_id, $kind);
        if (!$proposal) self::redirect('error', 'La propuesta ya no existe en la ultima auditoria.');

        $state = self::state();
        $row = array(
            'version' => self::VERSION,
            'kind' => $kind,
            'source_id' => $source_id,
            'status' => 'approve' === $decision ? 'approved' : 'rejected',
            'audit_generated_at' => (string) ($report['generated_at'] ?? ''),
            'saved_at' => current_time('mysql'),
            'saved_by' => get_current_user_id(),
        );

        if ('merge' === $kind) {
            $target_id = absint($_POST['merge_target'] ?? 0);
            if ('approve' === $decision && (!$target_id || $target_id === $source_id)) {
                self::redirect('error', 'Selecciona una categoria destino distinta antes de aprobar la concentracion.');
            }
            $row['target_id'] = $target_id;
        } else {
            $row['groups'] = array();
            foreach ((array) ($proposal['groups'] ?? array()) as $group) {
                $concept = (string) ($group['concept'] ?? '');
                $key = sanitize_key(str_replace(':', '__', $concept));
                $posted_concept = sanitize_text_field((string) ($_POST['split_concept_' . $key] ?? ''));
                if ($posted_concept !== $concept) continue;
                $target_id = absint($_POST['split_target_' . $key] ?? 0);
                $target_name = sanitize_text_field((string) ($_POST['split_name_' . $key] ?? ''));
                if ('approve' === $decision && !$target_id && '' === trim($target_name)) {
                    self::redirect('error', 'Cada cohorte necesita una categoria destino existente o un nombre para crearla.');
                }
                $row['groups'][$concept] = array('target_id'=>$target_id,'target_name'=>$target_name);
            }
        }

        $state['decisions'][$source_id] = $row;
        update_option(self::OPTION, $state, false);
        self::redirect('ok', 'Propuesta guardada. No se ha modificado el catalogo.');
    }

    public static function handle_apply() {
        self::guard('seo_auditor_rebalance_apply');
        $source_id = absint($_POST['source_id'] ?? 0);
        $state = self::state();
        $decision = (array) ($state['decisions'][$source_id] ?? array());
        if (!$source_id || 'approved' !== ($decision['status'] ?? '')) self::redirect('error', 'La propuesta no esta aprobada.');

        $result = self::apply_decision($source_id, $decision);
        if (is_wp_error($result)) self::redirect('error', $result->get_error_message());

        $state = self::state();
        if (isset($state['decisions'][$source_id])) {
            $state['decisions'][$source_id]['status'] = 'applied';
            $state['decisions'][$source_id]['applied_at'] = current_time('mysql');
            $state['decisions'][$source_id]['result'] = $result;
            update_option(self::OPTION, $state, false);
        }
        self::redirect('ok', (string) ($result['message'] ?? 'Operacion aplicada.'));
    }

    public static function handle_apply_all() {
        self::guard('seo_auditor_rebalance_apply_all');
        $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
        if (!in_array($kind, array('split','merge'), true)) self::redirect('error', 'Tipo de operacion no valido.');

        $state = self::state();
        $ok = 0; $failed = 0; $messages = array();
        foreach ((array) ($state['decisions'] ?? array()) as $source_id=>$decision) {
            if (($decision['kind'] ?? '') !== $kind || ($decision['status'] ?? '') !== 'approved') continue;
            $result = self::apply_decision(absint($source_id), (array) $decision);
            if (is_wp_error($result)) {
                $failed++;
                $messages[] = '#' . absint($source_id) . ': ' . $result->get_error_message();
                continue;
            }
            $ok++;
            $state['decisions'][$source_id]['status'] = 'applied';
            $state['decisions'][$source_id]['applied_at'] = current_time('mysql');
            $state['decisions'][$source_id]['result'] = $result;
        }
        update_option(self::OPTION, $state, false);
        $message = sprintf('Aplicadas: %d. No aplicadas: %d.', $ok, $failed);
        if ($messages) $message .= ' ' . implode(' | ', array_slice($messages, 0, 3));
        self::redirect($failed ? 'warning' : 'ok', $message);
    }

    private static function apply_decision($source_id, $decision) {
        $report = self::last_report();
        $proposal = self::find_proposal(self::build_proposals($report), $source_id, (string) ($decision['kind'] ?? ''));
        if (!$proposal) return new WP_Error('seo_rebalance_stale', 'La propuesta ya no es valida con la ultima auditoria. Revisa y vuelve a aprobar.');

        if ('split' === ($decision['kind'] ?? '')) return self::apply_split($proposal, $decision);
        if ('merge' === ($decision['kind'] ?? '')) return self::apply_merge($proposal, $decision);
        return new WP_Error('seo_rebalance_kind', 'Tipo de propuesta no valido.');
    }

    private static function apply_split($proposal, $decision) {
        $source_id = absint($proposal['source_id']);
        $source = get_term($source_id, 'product_cat');
        if (!$source || is_wp_error($source)) return new WP_Error('seo_rebalance_source', 'La categoria origen ya no existe.');

        $moved = 0; $created = array(); $targets = array();
        foreach ((array) ($proposal['groups'] ?? array()) as $group) {
            $concept = (string) ($group['concept'] ?? '');
            $choice = (array) (($decision['groups'][$concept] ?? array()));
            $target_id = absint($choice['target_id'] ?? 0);
            if (!$target_id) {
                $name = trim((string) ($choice['target_name'] ?? $group['suggested_name'] ?? $group['label'] ?? ''));
                if ($name === '') return new WP_Error('seo_rebalance_target_name', 'Falta el nombre de una categoria destino.');
                $target_id = self::create_split_target($source, $name, $concept);
                if (is_wp_error($target_id)) return $target_id;
                $created[] = $target_id;
            }
            if ($target_id === $source_id) return new WP_Error('seo_rebalance_same_target', 'La categoria destino no puede ser la misma que la origen.');
            $target = get_term($target_id, 'product_cat');
            if (!$target || is_wp_error($target)) return new WP_Error('seo_rebalance_target', 'Una categoria destino ya no existe.');

            $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($group['product_ids'] ?? array())))));
            foreach ($ids as $product_id) {
                if (!self::product_has_category($product_id, $source_id)) continue;
                $move = self::replace_category_for_product($product_id, $source_id, $target_id);
                if (is_wp_error($move)) return $move;
                $moved++;
            }
            $targets[$concept] = $target_id;
        }

        self::recount_terms(array_merge(array($source_id), array_values($targets)));
        return array(
            'kind'=>'split',
            'source_id'=>$source_id,
            'moved'=>$moved,
            'created_categories'=>$created,
            'targets'=>$targets,
            'message'=>sprintf('Division aplicada: %d movimientos de producto; %d categorias nuevas.', $moved, count($created)),
        );
    }

    private static function apply_merge($proposal, $decision) {
        global $wpdb;
        $source_id = absint($proposal['source_id']);
        $target_id = absint($decision['target_id'] ?? 0);
        if (!$source_id || !$target_id || $source_id === $target_id) return new WP_Error('seo_rebalance_merge_target', 'Selecciona un destino valido y distinto.');

        $preflight = self::merge_preflight($source_id, $target_id);
        if (is_wp_error($preflight)) return $preflight;

        $source = get_term($source_id, 'product_cat');
        $target = get_term($target_id, 'product_cat');
        $origin_url = get_term_link($source);
        $target_url = get_term_link($target);
        if (is_wp_error($origin_url) || is_wp_error($target_url)) return new WP_Error('seo_rebalance_permalink', 'No se pudieron resolver las URLs para el redirect.');

        $product_ids = self::all_product_ids_for_category($source_id);
        $snapshots = array();
        foreach ($product_ids as $product_id) {
            $current = wp_get_object_terms($product_id, 'product_cat', array('fields'=>'ids'));
            if (is_wp_error($current)) return $current;
            $snapshots[$product_id] = array_values(array_map('absint', $current));
        }

        $redirect_id = self::prepare_redirect((string) $origin_url, (string) $target_url);
        if (is_wp_error($redirect_id)) return $redirect_id;

        foreach ($product_ids as $product_id) {
            $move = self::replace_category_for_product($product_id, $source_id, $target_id);
            if (is_wp_error($move)) {
                self::rollback_product_categories($snapshots);
                self::remove_redirect($redirect_id);
                return $move;
            }
        }

        if (self::all_product_ids_for_category($source_id)) {
            self::rollback_product_categories($snapshots);
            self::remove_redirect($redirect_id);
            return new WP_Error('seo_rebalance_merge_not_empty', 'La categoria origen sigue teniendo productos tras el movimiento. No se ha eliminado.');
        }

        $deleted = wp_delete_term($source_id, 'product_cat');
        if (!$deleted || is_wp_error($deleted)) {
            self::rollback_product_categories($snapshots);
            self::remove_redirect($redirect_id);
            return new WP_Error('seo_rebalance_delete_failed', 'No se pudo eliminar la categoria origen. Se han restaurado las categorias de producto.');
        }

        try {
            if (function_exists('seo_reports_cleanup_deleted_category_data')) {
                $faq_table = function_exists('seo_get_faq_table_name') ? seo_get_faq_table_name() : false;
                seo_reports_cleanup_deleted_category_data($source_id, $faq_table);
            }
        } catch (Throwable $e) {
            error_log('[SEO Auditor Rebalance] Limpieza categoria #' . $source_id . ': ' . $e->getMessage());
        }

        self::recount_terms(array($target_id));
        return array(
            'kind'=>'merge',
            'source_id'=>$source_id,
            'target_id'=>$target_id,
            'moved'=>count($product_ids),
            'redirect_id'=>absint($redirect_id),
            'message'=>sprintf('Concentracion aplicada: %d productos movidos; categoria origen eliminada; redirect 301 creado.', count($product_ids)),
        );
    }

    private static function merge_preflight($source_id, $target_id) {
        global $wpdb;
        $source = get_term($source_id, 'product_cat');
        $target = get_term($target_id, 'product_cat');
        if (!$source || is_wp_error($source) || !$target || is_wp_error($target)) return new WP_Error('seo_rebalance_term_missing', 'Origen o destino ya no existen.');
        if (absint(get_option('default_product_cat')) === $source_id) return new WP_Error('seo_rebalance_default_cat', 'No se puede concentrar la categoria predeterminada de WooCommerce.');

        $children = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$source_id,'fields'=>'ids'));
        if (!is_wp_error($children) && $children) return new WP_Error('seo_rebalance_children', 'La categoria origen tiene subcategorias. Reubicalas antes de concentrarla.');

        $landing_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}seo_relations WHERE target_type='product_cat' AND target_id=%d AND relation_type='landing_to_category'",
            $source_id
        ));
        if ($landing_count > 0) return new WP_Error('seo_rebalance_landings', 'La categoria origen tiene landings asociadas. Deben revisarse antes de eliminarla.');

        if (!function_exists('seo_reports_cleanup_deleted_category_data')) {
            return new WP_Error('seo_rebalance_cleanup_unavailable', 'La limpieza segura de datos de categoria no esta disponible.');
        }
        return array('ready'=>true);
    }

    private static function replace_category_for_product($product_id, $source_id, $target_id) {
        $current = wp_get_object_terms($product_id, 'product_cat', array('fields'=>'ids'));
        if (is_wp_error($current)) return $current;
        $final = array_values(array_unique(array_filter(array_map('absint', $current), static function($id) use ($source_id){ return absint($id) !== absint($source_id); })));
        if (!in_array($target_id, $final, true)) $final[] = $target_id;
        $result = wp_set_object_terms($product_id, $final, 'product_cat', false);
        if (is_wp_error($result)) return $result;
        clean_object_term_cache($product_id, 'product');
        clean_post_cache($product_id);
        return true;
    }

    private static function rollback_product_categories($snapshots) {
        foreach ((array) $snapshots as $product_id=>$category_ids) {
            wp_set_object_terms(absint($product_id), array_values(array_map('absint', (array) $category_ids)), 'product_cat', false);
            clean_object_term_cache(absint($product_id), 'product');
            clean_post_cache(absint($product_id));
        }
    }

    private static function create_split_target($source, $name, $concept) {
        $slug = sanitize_title($name);
        $existing = get_term_by('slug', $slug, 'product_cat');
        if ($existing && !is_wp_error($existing)) return absint($existing->term_id);

        $created = wp_insert_term($name, 'product_cat', array('slug'=>$slug,'parent'=>absint($source->parent)));
        if (is_wp_error($created)) return $created;
        $term_id = absint($created['term_id'] ?? 0);
        if (!$term_id) return new WP_Error('seo_rebalance_create_category', 'No se pudo crear la categoria destino.');

        self::copy_category_context(absint($source->term_id), $term_id, $concept);
        return $term_id;
    }

    private static function copy_category_context($source_id, $target_id, $concept) {
        global $wpdb;
        $relations = $wpdb->prefix . 'seo_relations';
        $v = $wpdb->prefix . 'seo_vocabulary';
        $map = $wpdb->prefix . 'seo_type_role_map';

        // Mantener la nueva categoria dentro del mismo hub secundario.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT source_type,source_id,target_type,relation_type FROM {$relations} WHERE target_type='product_cat' AND target_id=%d AND relation_type='hub_secondary_to_category'",
            $source_id
        ), ARRAY_A);

        $missing_relations = array();
        foreach ($rows as $row) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$relations} WHERE source_type=%s AND source_id=%d AND target_type='product_cat' AND target_id=%d AND relation_type=%s",
                $row['source_type'], absint($row['source_id']), $target_id, $row['relation_type']
            ));
            if (!$exists) $missing_relations[] = $row;
        }

        if ($missing_relations) {
            if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
                return new WP_Error('seo_rebalance_data_layer', 'No esta disponible la capa transaccional para registrar la nueva categoria en la jerarquia.');
            }
            $operation = SEO_Data_Layer::operation(array(
                'type'=>'auditor_rebalance_category_relation',
                'label'=>'Vincular categoria creada por Auditor',
                'source_module'=>'auditor_rebalance',
                'rollbackable'=>true,
                'risk_level'=>'medium',
                'audit_level'=>'full',
                'metadata'=>array('source_category_id'=>$source_id,'target_category_id'=>$target_id),
            ));
            $operation->mark_validated(array('relations'=>count($missing_relations)));
            $operation->mark_previewed(count($missing_relations));
            $operation->execute(static function($op) use ($missing_relations, $target_id) {
                foreach ($missing_relations as $row) {
                    $op->insert('relations', array(
                        'source_type'=>(string)$row['source_type'],
                        'source_id'=>absint($row['source_id']),
                        'target_type'=>'product_cat',
                        'target_id'=>$target_id,
                        'relation_type'=>(string)$row['relation_type'],
                        'created_at'=>current_time('mysql'),
                    ), array(
                        'related_object_type'=>'product_cat',
                        'related_object_id'=>$target_id,
                        'reason'=>'auditor_category_split',
                    ));
                }
            });
        }

        // Construir Vocabulary de la nueva categoria a partir de la categoria
        // origen, sustituyendo la dimension TIPO/SUBTIPO que define la cohorte.
        if (!function_exists('seo_category_vocabulary_replace')) {
            return new WP_Error('seo_rebalance_vocab_writer', 'No esta disponible la escritura canonica de Vocabulary de categorias.');
        }

        $groups = array('rol'=>array(),'tipo'=>array(),'aplicacion'=>array(),'plataforma'=>array(),'subtipo'=>array());
        $source_vocab = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ov.vocabulary_id,v.semantic_group
             FROM {$wpdb->prefix}seo_object_vocabulary ov
             INNER JOIN {$v} v ON v.id=ov.vocabulary_id
             WHERE ov.object_type='product_cat' AND ov.object_id=%d AND ov.status=1 AND v.active=1
               AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')",
            $source_id
        ), ARRAY_A);

        list($group, $slug) = array_pad(explode(':', $concept, 2), 2, '');
        $group = sanitize_key($group);
        $slug = sanitize_key($slug);
        $replace_groups = 'tipo' === $group ? array('tipo','subtipo','rol') : array('subtipo');

        foreach ($source_vocab as $row) {
            $semantic_group = sanitize_key((string)($row['semantic_group'] ?? ''));
            if (!isset($groups[$semantic_group]) || in_array($semantic_group, $replace_groups, true)) continue;
            $groups[$semantic_group][] = absint($row['vocabulary_id']);
        }

        $concept_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$v} WHERE semantic_group=%s AND slug=%s AND active=1 LIMIT 1",
            $group, $slug
        )));
        if ($concept_id && isset($groups[$group])) {
            $groups[$group][] = $concept_id;
            if ('tipo' === $group) {
                $role_id = absint($wpdb->get_var($wpdb->prepare(
                    "SELECT role_vocabulary_id FROM {$map} WHERE type_vocabulary_id=%d AND active=1 LIMIT 1",
                    $concept_id
                )));
                if ($role_id) $groups['rol'][] = $role_id;
            }
        }

        foreach ($groups as $key=>$ids) {
            $groups[$key] = array_values(array_unique(array_filter(array_map('absint', $ids))));
        }
        $saved = seo_category_vocabulary_replace($target_id, $groups, 'auditor_rebalance');
        if (is_wp_error($saved)) return $saved;

        return true;
    }

    private static function prepare_redirect($origin_url, $target_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_redirects';
        $origin = self::url_path($origin_url);
        $target = self::url_path($target_url);
        if (!$origin || !$target || $origin === $target) return new WP_Error('seo_rebalance_redirect', 'El redirect de concentracion no es valido.');

        $existing = $wpdb->get_row($wpdb->prepare("SELECT id,target_url FROM {$table} WHERE origin_url=%s LIMIT 1", $origin), ARRAY_A);
        if ($existing) {
            if (self::url_path((string) $existing['target_url']) !== $target) return new WP_Error('seo_rebalance_redirect_exists', 'Ya existe un redirect distinto para la categoria origen.');
            return absint($existing['id']);
        }

        if (function_exists('seo_redirects_admin_would_create_cycle')) {
            $rows = function_exists('seo_redirects_admin_existing_rows') ? seo_redirects_admin_existing_rows($table, $wpdb) : array();
            if (seo_redirects_admin_would_create_cycle($rows, $origin, $target)) return new WP_Error('seo_rebalance_redirect_cycle', 'El redirect propuesto crearia un ciclo.');
        }

        $ok = $wpdb->insert($table, array(
            'origin_url'=>$origin,'target_url'=>$target,'status_code'=>301,'hits'=>0,'last_hit'=>null,
            'created_at'=>current_time('mysql', true),'updated_at'=>current_time('mysql', true),
        ), array('%s','%s','%d','%d','%s','%s','%s'));
        return false === $ok ? new WP_Error('seo_rebalance_redirect_insert', 'No se pudo crear el redirect 301.') : absint($wpdb->insert_id);
    }

    private static function remove_redirect($redirect_id) {
        global $wpdb;
        if ($redirect_id) $wpdb->delete($wpdb->prefix . 'seo_redirects', array('id'=>absint($redirect_id)), array('%d'));
    }

    private static function url_path($url) {
        $path = wp_parse_url((string) $url, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) return '';
        $path = '/' . ltrim(preg_replace('#/+#','/',$path), '/');
        return '/' === $path ? '/' : rtrim($path, '/');
    }

    private static function product_has_category($product_id, $category_id) {
        return has_term(absint($category_id), 'product_cat', absint($product_id));
    }

    private static function recount_terms($term_ids) {
        global $wpdb;
        $ttids = array();
        foreach (array_values(array_unique(array_filter(array_map('absint', (array) $term_ids)))) as $term_id) {
            $ttid = absint($wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat' AND term_id=%d", $term_id)));
            if ($ttid) $ttids[] = $ttid;
        }
        if ($ttids) wp_update_term_count_now($ttids, 'product_cat');
        clean_taxonomy_cache('product_cat');
    }

    private static function build_proposals($report) {
        $out = array();
        foreach ((array) ($report['findings'] ?? array()) as $finding) {
            $code = (string) ($finding['code'] ?? '');
            if ('category_split_candidate' === $code) {
                $proposal = self::split_proposal($finding);
                if ($proposal) $out[] = $proposal;
            } elseif ('category_merge_candidate' === $code) {
                $proposal = self::merge_proposal($finding);
                if ($proposal) $out[] = $proposal;
            }
        }
        return $out;
    }

    private static function split_proposal($finding) {
        $source_id = absint($finding['entity_id'] ?? 0);
        $term = $source_id ? get_term($source_id, 'product_cat') : null;
        $cohorts = (array) (($finding['evidence']['cohorts'] ?? array()));
        if (!$source_id || !$term || is_wp_error($term) || count($cohorts) < 2) return array();

        $source_ids = self::published_product_ids_for_category($source_id);
        $membership = array();
        $groups = array();
        foreach ($cohorts as $cohort) {
            $concept = sanitize_text_field((string) ($cohort['concept'] ?? ''));
            if (!$concept) continue;
            $ids = self::published_product_ids_for_concept($source_id, $concept);
            foreach ($ids as $id) $membership[$id] = 1 + absint($membership[$id] ?? 0);
            $meta = self::concept_meta($concept);
            $groups[] = array(
                'concept'=>$concept,
                'label'=>$meta['label'],
                'product_ids'=>$ids,
                'suggested_target_id'=>self::suggest_target_category($source_id, $concept, $meta['label']),
                'suggested_name'=>$meta['label'],
            );
        }
        if (count($groups) < 2) return array();

        $ambiguous = array_keys(array_filter($membership, static function($n){ return $n > 1; }));
        foreach ($groups as &$group) {
            $group['product_ids'] = array_values(array_diff($group['product_ids'], $ambiguous));
        }
        unset($group);
        $assigned = array();
        foreach ($groups as $group) $assigned = array_merge($assigned, $group['product_ids']);
        $remaining = array_values(array_diff($source_ids, array_unique($assigned), $ambiguous));

        return array(
            'kind'=>'split','source_id'=>$source_id,'source_name'=>(string)$term->name,
            'product_count'=>count($source_ids),'coherence'=>(float)($finding['evidence']['coherence'] ?? 0),
            'groups'=>$groups,'ambiguous_ids'=>array_values(array_map('absint',$ambiguous)),'remaining_ids'=>$remaining,
        );
    }

    private static function merge_proposal($finding) {
        $source_id = absint($finding['entity_id'] ?? 0);
        $source = $source_id ? get_term($source_id, 'product_cat') : null;
        $candidate = (array) (($finding['evidence']['candidate'] ?? array()));
        $target_id = absint($candidate['category_id'] ?? 0);
        $target = $target_id ? get_term($target_id, 'product_cat') : null;
        if (!$source_id || !$source || is_wp_error($source) || !$target_id || !$target || is_wp_error($target)) return array();

        return array(
            'kind'=>'merge','source_id'=>$source_id,'source_name'=>(string)$source->name,
            'product_ids'=>self::all_product_ids_for_category($source_id),
            'product_count'=>count(self::all_product_ids_for_category($source_id)),
            'suggested_target_id'=>$target_id,'suggested_target_name'=>(string)$target->name,
            'similarity'=>(float)($candidate['similarity'] ?? 0),
        );
    }

    private static function concept_meta($concept) {
        global $wpdb;
        list($group, $slug) = array_pad(explode(':', (string) $concept, 2), 2, '');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,label,slug,semantic_group FROM {$wpdb->prefix}seo_vocabulary WHERE semantic_group=%s AND slug=%s AND active=1 LIMIT 1",
            sanitize_key($group), sanitize_key($slug)
        ), ARRAY_A);
        return array('id'=>absint($row['id'] ?? 0),'label'=>(string)($row['label'] ?? ucfirst(str_replace('_',' ',$slug))),'group'=>sanitize_key($group),'slug'=>sanitize_key($slug));
    }

    private static function suggest_target_category($source_id, $concept, $label) {
        global $wpdb;
        $meta = self::concept_meta($concept);
        if (!empty($meta['id'])) {
            $ids = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT object_id FROM {$wpdb->prefix}seo_object_vocabulary WHERE object_type='product_cat' AND vocabulary_id=%d AND status=1 AND object_id<>%d ORDER BY object_id ASC",
                $meta['id'], $source_id
            ));
            foreach ($ids as $id) {
                $term = get_term(absint($id), 'product_cat');
                if ($term && !is_wp_error($term)) return absint($id);
            }
        }

        $source = get_term($source_id, 'product_cat');
        $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        if (is_wp_error($terms)) return 0;
        $best_id = 0; $best_score = 0.0;
        foreach ($terms as $term) {
            if (absint($term->term_id) === $source_id) continue;
            $score = self::name_similarity($label, $term->name);
            if ($source && !is_wp_error($source) && absint($term->parent) === absint($source->parent)) $score += 0.10;
            if ($score > $best_score) { $best_score = $score; $best_id = absint($term->term_id); }
        }
        return $best_score >= 0.55 ? $best_id : 0;
    }

    private static function name_similarity($a, $b) {
        $norm = static function($text){
            $text = strtolower(remove_accents(wp_strip_all_tags((string)$text)));
            $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $stop = array('de','del','la','las','el','los','y','para','con','sin');
            return array_values(array_unique(array_diff($tokens, $stop)));
        };
        $aa = $norm($a); $bb = $norm($b);
        if (!$aa || !$bb) return 0.0;
        $inter = count(array_intersect($aa, $bb));
        $union = count(array_unique(array_merge($aa, $bb)));
        return $union ? $inter / $union : 0.0;
    }

    private static function published_product_ids_for_category($category_id) {
        global $wpdb;
        return array_values(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             WHERE p.post_type='product' AND p.post_status='publish' AND tt.term_id=%d ORDER BY p.ID",
            $category_id
        ))));
    }

    private static function all_product_ids_for_category($category_id) {
        global $wpdb;
        return array_values(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             WHERE p.post_type='product' AND tt.term_id=%d ORDER BY p.ID",
            $category_id
        ))));
    }

    private static function published_product_ids_for_concept($category_id, $concept) {
        global $wpdb;
        list($group, $slug) = array_pad(explode(':', (string)$concept, 2), 2, '');
        return array_values(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat' AND tt.term_id=%d
             INNER JOIN {$wpdb->prefix}seo_object_vocabulary ov ON ov.object_type='product' AND ov.object_id=p.ID AND ov.status=1
             INNER JOIN {$wpdb->prefix}seo_vocabulary v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE p.post_type='product' AND p.post_status='publish' AND v.semantic_group=%s AND v.slug=%s
             ORDER BY p.ID",
            $category_id, sanitize_key($group), sanitize_key($slug)
        ))));
    }

    private static function state() {
        $state = get_option(self::OPTION, array());
        if (!is_array($state)) $state = array();
        if (!isset($state['decisions']) || !is_array($state['decisions'])) $state['decisions'] = array();
        $state['version'] = self::VERSION;
        return $state;
    }

    private static function approved_count($state) {
        $n = 0;
        foreach ((array)($state['decisions'] ?? array()) as $row) if ('approved' === ($row['status'] ?? '')) $n++;
        return $n;
    }

    private static function find_proposal($proposals, $source_id, $kind) {
        foreach ((array)$proposals as $row) if (absint($row['source_id'] ?? 0) === absint($source_id) && ($row['kind'] ?? '') === $kind) return $row;
        return array();
    }

    private static function status_label($status) {
        $map = array('pending'=>'pendiente','approved'=>'aprobada','rejected'=>'descartada','applied'=>'aplicada');
        return $map[$status] ?? $status;
    }

    private static function last_report() {
        $report = get_option('seo_auditor_last_catalog_report', array());
        return is_array($report) ? $report : array();
    }

    private static function guard($nonce) {
        $cap = class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
        if (!current_user_can($cap)) wp_die(esc_html__('No tienes permisos para ejecutar esta accion.', 'seo-taxonomy'));
        check_admin_referer($nonce);
    }

    private static function redirect($status, $message) {
        wp_safe_redirect(add_query_arg(array(
            'page'=>'seo-content-auditor',
            'audit_view'=>'rebalance',
            'rebalance_status'=>sanitize_key($status),
            'rebalance_message'=>(string)$message,
        ), admin_url('admin.php')));
        exit;
    }
}
