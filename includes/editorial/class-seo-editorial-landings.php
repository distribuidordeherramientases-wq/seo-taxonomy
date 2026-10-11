<?php
/**
 * Shared landing proposal / approval workflow used by Solucionador and Ingeniero.
 *
 * One canonical seo_landing_candidates row per buyer intent, with separate
 * evidence lanes for each service. A proposal is never a WordPress page.
 */
defined('ABSPATH') || exit;

final class SEO_Editorial_Landings {
    const META_CANDIDATE_ID = '_seo_editorial_landing_candidate_id';

    public static function init() {
        add_action('admin_post_seo_editorial_landings_sync', array(__CLASS__, 'handle_sync'));
        add_action('admin_post_seo_editorial_landings_review', array(__CLASS__, 'handle_review'));
    }

    private static function table() {
        return seo_landing_candidates_table();
    }

    private static function get_candidate($id) {
        global $wpdb;
        $table = esc_sql(self::table());
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", absint($id)), ARRAY_A);
    }

    private static function decode($candidate) {
        return seo_landing_decode_json($candidate['proposal_json'] ?? '');
    }

    private static function normalize_intent($intent) {
        $text = sanitize_text_field(remove_accents(mb_strtolower(trim((string) $intent))));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private static function valid_categories($ids) {
        $valid = array();
        foreach (array_values(array_unique(array_filter(array_map('absint', (array) $ids)))) as $id) {
            $term = get_term($id,'product_cat');
            if ($term && !is_wp_error($term)) $valid[] = $id;
        }
        return $valid;
    }

    private static function source_material($service, array $category_ids) {
        $rows = array();
        $ids = array();
        $category_ids = array_slice(self::valid_categories($category_ids), 0, 6);

        foreach ($category_ids as $category_id) {
            if ($service === 'solucionador' && class_exists('SEO_Solucionador_Dossiers')) {
                $dossier = SEO_Solucionador_Dossiers::get_by_category($category_id);
                if (!$dossier || empty($dossier['question_count'])) continue;
                $details = (array) SEO_Solucionador_Dossiers::question_details($category_id);
                foreach (array_slice($details, 0, 18) as $item) {
                    $answer = SEO_Solucionador_Dossiers::answer_text((array) $item);
                    $question = sanitize_text_field((string) ($item['question'] ?? ''));
                    if ($question === '' || $answer === '') continue;
                    $source_id = sanitize_text_field((string) ($item['item_key'] ?? ''));
                    $rows[] = array(
                        'category_id'=>$category_id,
                        'id'=>$source_id,
                        'layer'=>sanitize_key((string) ($item['origin'] ?? 'faq')),
                        'question'=>$question,
                        'text'=>wp_strip_all_tags($answer),
                        'verified'=>true,
                    );
                    $ids[] = $source_id;
                }
            }

            if ($service === 'ingeniero' && class_exists('SEO_Ingeniero')) {
                foreach (array_slice((array) SEO_Ingeniero::editorial_knowledge($category_id), 0, 18) as $item) {
                    $id = absint($item['id'] ?? 0);
                    $summary = wp_strip_all_tags((string) ($item['summary'] ?? ''));
                    if (!$id || $summary === '') continue;
                    $layer = sanitize_key((string) ($item['lesson'] ?? ''));
                    $rows[] = array(
                        'category_id'=>$category_id,
                        'id'=>$id,
                        'layer'=>$layer,
                        'question'=>sanitize_text_field((string) ($item['concept'] ?? '')),
                        'text'=>$summary,
                        'source_ids'=>array_values(array_filter(array_map('absint',(array) ($item['source_ids'] ?? array())))),
                        'verified'=>($layer === 'l1_technical' && ($item['status'] ?? '') === 'active'),
                    );
                    $ids[] = $id;
                }
            }
        }

        $rows = array_slice($rows, 0, 24);
        $hash = hash('sha256', wp_json_encode($rows));
        return array(
            'source_ids'=>array_values(array_unique($ids)),
            'source_hash'=>$hash,
            'rows'=>$rows,
            'count'=>count($rows),
            'category_ids'=>$category_ids,
        );
    }

    private static function initial_text($title, $intent, array $material) {
        $paragraphs = array();
        $seen = array();
        foreach (array_slice((array) ($material['rows'] ?? array()), 0, 12) as $row) {
            // Community comments and news are working evidence, not asserted facts.
            if (empty($row['verified'])) continue;
            $text = trim((string) ($row['text'] ?? ''));
            $label = trim((string) ($row['question'] ?? ''));
            $key = md5($label . $text);
            if ($text === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $paragraphs[] = trim(($label !== '' ? $label . "\n" : '') . $text);
        }
        // Do not invent buying advice or publish generic generated prose.
        return implode("\n\n", $paragraphs);
    }

    public static function absorb_signal(array $signal, $service) {
        $service = sanitize_key((string) $service);
        if (!in_array($service, array('solucionador','ingeniero'), true)) return new WP_Error('landing_service', 'Servicio no válido.');
        if (($signal['source'] ?? '') !== 'Analista' || ($signal['kind'] ?? '') !== 'landing' ||
            ($signal['action'] ?? '') !== 'ESTUDIAR_LANDING') return false;

        $intent = sanitize_text_field((string) ($signal['intent'] ?? ''));
        $normalized = self::normalize_intent($intent);
        if (strlen($normalized) < 8) return false;
        $categories = self::valid_categories((array) ($signal['category_ids'] ?? array()));
        if (!$categories) return 'no_knowledge';
        $material = self::source_material($service, $categories);
        if (empty($material['count'])) return 'no_knowledge';

        seo_landing_maybe_install();
        global $wpdb;
        $table = esc_sql(self::table());
        $external_key = 'editorial_landing_' . md5($normalized);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE external_key=%s ORDER BY id ASC LIMIT 1", $external_key), ARRAY_A);
        $audit = SEO_Editorial_Opportunities::relevant_auditor_tasks($categories, 'landing');
        $proposal = $existing ? self::decode($existing) : array();
        $previous = (array) ($proposal['services'][$service] ?? array());
        $proposal['version'] = 1;
        $proposal['intent_key'] = $external_key;
        $proposal['analyst_task_ids'] = array_values(array_unique(array_merge(
            (array) ($proposal['analyst_task_ids'] ?? array()),
            array(sanitize_text_field((string) ($signal['key'] ?? '')))
        )));
        $proposal['auditor_task_ids'] = array_values(array_unique(array_merge(
            (array) ($proposal['auditor_task_ids'] ?? array()),
            array_map(static function($row) { return sanitize_text_field((string) $row['key']); }, $audit)
        )));
        $proposal['diagnostics'] = array(
            'analista'=>array('topic'=>$intent,'action'=>$signal['action'],'priority'=>$signal['priority'] ?? 0,'observed_at'=>$signal['observed_at'] ?? ''),
            'auditor'=>array_map(static function($row) {
                return array('task_id'=>$row['key'],'reason'=>$row['reason'],'priority'=>$row['priority']);
            }, $audit),
        );
        $proposal['category_ids'] = self::valid_categories(array_merge((array) ($proposal['category_ids'] ?? array()), $categories));
        $proposal['services'][$service] = $material;
        $changed = !empty($previous) && (string) ($previous['source_hash'] ?? '') !== $material['source_hash'];
        if (!isset($proposal['draft_text'])) {
            $proposal['draft_text'] = self::initial_text($intent, $intent, $material);
        }
        if (empty($proposal['draft_text'])) {
            // A source dossier is not necessarily a finished public landing.
            $proposal['draft_text'] = '';
        }

        $status = sanitize_key((string) ($existing['status'] ?? 'candidate'));
        if ($existing && in_array($status, array('rejected','rejected_requirements','paused'), true)) return false;
        if ($existing && in_array($status, array('created','published'), true) && $changed) {
            $status = 'needs_update'; // never alter an editor's page.
        }

        $now = current_time('mysql');
        $data = array(
            'proposal_json'=>wp_json_encode($proposal, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>$now,
        );
        if ($existing) {
            if ($status !== (string) $existing['status']) $data['status'] = $status;
            $ok = $wpdb->update($table, $data, array('id'=>absint($existing['id'])));
            return $ok === false ? new WP_Error('landing_update', 'No se pudo sincronizar la candidata.') : absint($existing['id']);
        }

        $requirements = array_fill_keys(array_keys(seo_landing_requirement_labels()), -1);
        $data += array(
            'title'=>$intent,
            'intent'=>$intent,
            'landing_type'=>'need',
            'status'=>'candidate',
            'source'=>count($audit) ? 'Analista + Auditor' : 'Analista',
            'requirements_json'=>wp_json_encode($requirements),
            'scores_json'=>wp_json_encode(array()),
            'total_score'=>0,
            'existing_destination'=>'',
            'differentiation_reason'=>'',
            'page_id'=>0,
            'external_key'=>$external_key,
            'created_at'=>$now,
        );
        $ok = $wpdb->insert($table,$data);
        return $ok === false ? new WP_Error('landing_insert', 'No se pudo crear la candidata.') : absint($wpdb->insert_id);
    }

    public static function count_pending() {
        global $wpdb;
        $table = esc_sql(self::table());
        // Do not query non-installed tables on a first bootstrap.
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))) !== $table) return 0;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE external_key LIKE 'editorial_landing_%' AND status IN ('candidate','review','approved','needs_update')");
    }

    private static function rows($service) {
        global $wpdb;
        $table = esc_sql(self::table());
        $rows = (array) $wpdb->get_results(
            "SELECT * FROM {$table} WHERE external_key LIKE 'editorial_landing_%' ORDER BY FIELD(status,'candidate','review','needs_update','approved','created','published','rejected'),updated_at DESC LIMIT 150",
            ARRAY_A
        );
        return array_values(array_filter($rows, static function($row) use ($service) {
            $data = seo_landing_decode_json($row['proposal_json'] ?? '');
            return isset($data['services'][$service]);
        }));
    }

    public static function handle_sync() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        check_admin_referer('seo_editorial_landings_sync');
        $service = sanitize_key((string) ($_POST['service'] ?? ''));
        if (!in_array($service,array('solucionador','ingeniero'),true)) wp_die('Servicio no válido.');
        SEO_Editorial_Opportunities::sync('manual');
        self::redirect($service,array('landing_notice'=>'synced'));
    }

    private static function redirect($service, $args = array()) {
        $page = $service === 'ingeniero' ? 'seo-ingeniero' : 'seo-solucionador';
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>$page,'tab'=>'landings'), $args),admin_url('admin.php')));
        exit;
    }

    private static function shared_approval($candidate, array $proposal) {
        global $wpdb;
        $id = absint($candidate['id'] ?? 0);
        $categories = self::valid_categories((array) ($proposal['category_ids'] ?? array()));
        $requirements = seo_landing_decode_json($candidate['requirements_json'] ?? '');
        if (!seo_landing_requirements_pass($requirements)) return new WP_Error('landing_requirements', 'Los cinco requisitos deben estar demostrados y aprobados.');
        if (count($categories) < 2) return new WP_Error('landing_catalog', 'Para evitar duplicar una categoría, esta propuesta requiere al menos dos categorías comerciales verificadas.');
        if (trim((string) ($candidate['differentiation_reason'] ?? '')) === '') {
            return new WP_Error('landing_differentiation', 'Documenta por qué ninguna URL existente resuelve la misma intención.');
        }
        if (trim((string) ($proposal['draft_text'] ?? '')) === '') {
            return new WP_Error('landing_material', 'No hay contenido específico revisado para convertir en borrador.');
        }

        $post_id = absint($candidate['page_id'] ?? 0);
        if ($post_id && get_post_type($post_id) === 'page' && get_post_status($post_id) !== 'trash') return $post_id;

        $slug = sanitize_title((string) ($candidate['title'] ?? ''));
        if ($slug === '') return new WP_Error('landing_slug', 'Título de landing no válido.');
        $collision = get_page_by_path($slug, OBJECT, 'page');
        if ($collision && absint($collision->ID) !== $post_id && get_post_status($collision->ID) !== 'trash') {
            return new WP_Error('landing_collision', 'Ya existe una página con este slug; revisa el destino para evitar canibalización.');
        }
        if (function_exists('seo_landing_get_existing')) {
            foreach (seo_landing_get_existing() as $page) {
                if (absint($page->ID) === $post_id) continue;
                if (self::normalize_intent((string) $page->post_title) === self::normalize_intent((string) $candidate['title'])) {
                    return new WP_Error('landing_duplicate', 'Ya existe una landing con intención/título equivalente.');
                }
            }
        }

        $lock = 'seo_eland_' . md5((string) $id);
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock));
        if ($locked !== '1') return new WP_Error('landing_lock', 'Otra aprobación está en curso. Reinténtalo.');
        try {
            $current = self::get_candidate($id);
            if (!$current) return new WP_Error('landing_missing', 'La propuesta ya no existe.');
            $current_page = absint($current['page_id'] ?? 0);
            if ($current_page && get_post_type($current_page) === 'page' && get_post_status($current_page) !== 'trash') return $current_page;

            $content = wpautop(esc_html((string) ($proposal['draft_text'] ?? '')));
            $created = wp_insert_post(array(
                'post_title'=>sanitize_text_field((string) $candidate['title']),
                'post_name'=>$slug,
                'post_type'=>'page',
                'post_status'=>'draft',
                'post_excerpt'=>sanitize_textarea_field((string) ($candidate['intent'] ?? '')),
                'post_content'=>wp_kses_post($content),
            ), true);
            if (is_wp_error($created) || !$created) return is_wp_error($created) ? $created : new WP_Error('landing_insert_page', 'No se pudo crear el borrador.');

            $role = seo_page_editor_save_node_role($created, 'landing');
            if (is_wp_error($role)) {
                wp_delete_post($created, true);
                return $role;
            }
            $relations = seo_page_editor_replace_landing_categories($created,$categories);
            if (is_wp_error($relations)) {
                wp_delete_post($created, true);
                return $relations;
            }
            update_post_meta($created,self::META_CANDIDATE_ID,$id);
            update_post_meta($created,'_seo_editorial_landing_signal_ids', array_values(array_unique(array_merge(
                (array) ($proposal['analyst_task_ids'] ?? array()),(array) ($proposal['auditor_task_ids'] ?? array())
            ))));
            update_post_meta($created,'_seo_editorial_landing_source_dossier',$proposal);
            update_post_meta($created,'_seo_editorial_landing_approved_by',get_current_user_id());
            $table = esc_sql(self::table());
            $ok = $wpdb->update($table,array('page_id'=>$created,'status'=>'created','updated_at'=>current_time('mysql')),array('id'=>$id));
            if ($ok === false) {
                wp_delete_post($created,true);
                return new WP_Error('landing_candidate_link', 'Falló el enlace entre propuesta y borrador.');
            }
            return (int) $created;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function handle_review() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        $service = sanitize_key((string) ($_POST['service'] ?? ''));
        $id = absint($_POST['candidate_id'] ?? 0);
        if (!in_array($service,array('solucionador','ingeniero'),true)) wp_die('Servicio no válido.');
        check_admin_referer('seo_editorial_landings_' . $id);
        $candidate = self::get_candidate($id);
        if (!$candidate) self::redirect($service,array('landing_error'=>'Propuesta inexistente.'));
        $proposal = self::decode($candidate);
        if (!isset($proposal['services'][$service]) || !preg_match('/^editorial_landing_/',(string) ($candidate['external_key'] ?? ''))) {
            self::redirect($service,array('landing_error'=>'Propuesta ajena al servicio.'));
        }
        if (in_array($candidate['status'],array('created','published','needs_update'),true) && !empty($candidate['page_id'])) {
            self::redirect($service,array('landing_error'=>'El borrador ya existe; edítalo desde Páginas.'));
        }

        $action = sanitize_key((string) ($_POST['command'] ?? 'save'));
        if ($action === 'reject') {
            global $wpdb;
            $wpdb->update(self::table(),array('status'=>'rejected','updated_at'=>current_time('mysql')),array('id'=>$id));
            self::redirect($service,array('landing_notice'=>'rejected'));
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $intent = sanitize_textarea_field(wp_unslash($_POST['intent'] ?? ''));
        $differentiation = sanitize_textarea_field(wp_unslash($_POST['differentiation_reason'] ?? ''));
        $existing_destination = sanitize_textarea_field(wp_unslash($_POST['existing_destination'] ?? ''));
        $text = sanitize_textarea_field(wp_unslash($_POST['draft_text'] ?? ''));
        $categories_raw = sanitize_text_field(wp_unslash($_POST['category_ids'] ?? ''));
        $category_ids = array_map('absint',preg_split('/[\s,;]+/', $categories_raw));
        $valid_categories = self::valid_categories($category_ids);
        if (count(array_unique(array_filter($category_ids))) !== count($valid_categories) || $title === '') {
            self::redirect($service,array('landing_error'=>'Título vacío o categorías inexistentes.','candidate_id'=>$id));
        }
        $requirements = array();
        foreach (seo_landing_requirement_labels() as $key=>$label) {
            $value = isset($_POST['requirements'][$key]) ? (string) wp_unslash($_POST['requirements'][$key]) : '-1';
            $requirements[$key] = seo_landing_requirement_value($value);
        }
        $proposal['category_ids'] = $valid_categories;
        $proposal['draft_text'] = $text;
        $proposal['human_reviewed_at'] = current_time('mysql');
        $proposal['human_reviewer_id'] = get_current_user_id();
        global $wpdb;
        $updated = $wpdb->update(self::table(),array(
            'title'=>$title,'intent'=>$intent,
            'differentiation_reason'=>$differentiation,'existing_destination'=>$existing_destination,
            'requirements_json'=>wp_json_encode($requirements),
            'proposal_json'=>wp_json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'status'=>'review',
            'updated_at'=>current_time('mysql'),
        ),array('id'=>$id));
        if ($updated === false) self::redirect($service,array('landing_error'=>'No se pudo guardar la revisión.','candidate_id'=>$id));
        if ($action === 'approve') {
            $candidate = self::get_candidate($id);
            $result = self::shared_approval($candidate,$proposal);
            if (is_wp_error($result)) self::redirect($service,array('landing_error'=>$result->get_error_message(),'candidate_id'=>$id));
            self::redirect($service,array('landing_notice'=>'created','page_id'=>absint($result)));
        }
        self::redirect($service,array('landing_notice'=>'saved','candidate_id'=>$id));
    }

    public static function render_tab($service) {
        $service = sanitize_key((string) $service);
        if (!current_user_can('manage_options')) return;
        seo_landing_maybe_install();
        $health = SEO_Editorial_Opportunities::source_health();
        $state = SEO_Editorial_Opportunities::state();
        $rows = self::rows($service);
        echo '<div style="margin:18px 0"><h2>Landings · propuestas de ' . esc_html(ucfirst($service)) . '</h2>';
        echo '<p>Analista propone oportunidades comerciales; Auditor verifica huecos y estructura. ' .
             esc_html(ucfirst($service)) . ' añade solamente su propio conocimiento. Las candidatas se comparten con Páginas → Proyectos; una aceptación crea una página en borrador, nunca la publica.</p>';
        $source_info = array();
        foreach (array('analista'=>'Analista','auditor'=>'Auditor') as $key=>$name) {
            $src = $health[$key];
            $source_info[] = $name . ': ' . ($src['fresh'] ? 'reciente' : 'sin diagnóstico reciente') .
                ($src['date'] !== '' ? ' (' . $src['date'] . ')' : '');
        }
        echo '<p><strong>Estado de fuentes:</strong> ' . esc_html(implode(' · ',$source_info)) . '</p>';
        echo '<p><strong>Ciclo de cobertura:</strong> ' . esc_html((string) ($state['coverage_status'] ?? 'sin_ejecutar')) .
             ' · propuestas pendientes ' . esc_html((string) self::count_pending()) .
             ' · último ciclo ' . esc_html((string) ($state['last_run'] ?? 'nunca')) . '.</p>';
        echo '<p class="description">«Sin huecos en las últimas capturas» no garantiza cobertura perfecta: requiere Analista y Auditor recientes y validación de los contenidos. Tras la siguiente auditoría se vuelven a evaluar las necesidades.</p>';
        if (!empty($_GET['landing_error'])) echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['landing_error']))) . '</p></div>';
        if (!empty($_GET['landing_notice'])) echo '<div class="notice notice-success"><p>' . esc_html(sanitize_key(wp_unslash($_GET['landing_notice']))) . '</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_editorial_landings_sync"><input type="hidden" name="service" value="' . esc_attr($service) . '">';
        wp_nonce_field('seo_editorial_landings_sync');
        submit_button('Actualizar propuestas desde Analista y Auditor','secondary','submit',false);
        echo '</form><hr>';
        if (!$rows) echo '<p>No existen propuestas sustentadas en necesidades comerciales explícitas y conocimiento propio de este servicio. Comprueba los diagnósticos, las relaciones product_cat y el conocimiento de origen.</p>';
        foreach ($rows as $row) {
            $proposal = self::decode($row);
            $material = (array) ($proposal['services'][$service] ?? array());
            $open = absint($_GET['candidate_id'] ?? 0) === absint($row['id']);
            echo '<details style="border:1px solid #ccd0d4;border-radius:5px;padding:12px;margin:12px 0;background:#fff" ' . ($open ? 'open' : '') . '>';
            echo '<summary style="cursor:pointer"><strong>' . esc_html($row['title']) . '</strong> · ' .
                esc_html((string) ($row['status'] ?? 'candidate')) . ' · ' .
                esc_html((string) ($material['count'] ?? 0)) . ' evidencias del servicio</summary>';
            echo '<p><strong>Origen:</strong> ' . esc_html($row['source']) .
                ' · <strong>Analista:</strong> ' . esc_html(implode(', ', (array) ($proposal['analyst_task_ids'] ?? array()))) .
                ' · <strong>Auditor:</strong> ' . esc_html(implode(', ', (array) ($proposal['auditor_task_ids'] ?? array()))) . '</p>';
            if (!empty($row['page_id']) && get_post_type(absint($row['page_id'])) === 'page') {
                echo '<p><a class="button button-primary" href="' . esc_url(get_edit_post_link(absint($row['page_id']),'raw')) . '">Editar página borrador #' . esc_html((string) $row['page_id']) . '</a></p>';
            } elseif (in_array($row['status'],array('candidate','review','approved'),true)) {
                self::render_form($service,$row,$proposal);
            } else {
                echo '<p>Esta candidata mantiene su estado editorial; no se recrea automáticamente.</p>';
            }
            echo '<h4>Evidencias originales de ' . esc_html(ucfirst($service)) . '</h4>';
            foreach (array_slice((array) ($material['rows'] ?? array()),0,12) as $item) {
                echo '<p style="border-top:1px solid #eee;padding-top:8px"><strong>' .
                    esc_html((string) ($item['question'] ?? '')) . '</strong><br>' .
                    esc_html(wp_trim_words((string) ($item['text'] ?? ''), 65)) .
                    '<br><small>' . esc_html((string) ($item['layer'] ?? '')) .
                    (empty($item['verified']) ? ' · requiere contrastación editorial' : ' · conocimiento validado') .
                    ' · fuente #' . esc_html((string) ($item['id'] ?? '')) . '</small></p>';
            }
            echo '</details>';
        }
        echo '</div>';
    }

    private static function render_form($service,array $row,array $proposal) {
        $id = absint($row['id']);
        $requirements = seo_landing_decode_json($row['requirements_json'] ?? '');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_editorial_landings_review">';
        echo '<input type="hidden" name="service" value="' . esc_attr($service) . '">';
        echo '<input type="hidden" name="candidate_id" value="' . esc_attr((string) $id) . '">';
        wp_nonce_field('seo_editorial_landings_' . $id);
        echo '<p><label><strong>Título</strong><br><input style="width:100%" name="title" value="' . esc_attr($row['title']) . '" required></label></p>';
        echo '<p><label><strong>Intención de compra</strong><br><textarea style="width:100%" rows="2" name="intent">' . esc_textarea($row['intent']) . '</textarea></label></p>';
        echo '<p><label><strong>IDs de categorías product_cat relacionadas (separadas por comas)</strong><br><input style="width:100%" name="category_ids" value="' .
            esc_attr(implode(',',self::valid_categories((array) ($proposal['category_ids'] ?? array())))) . '"></label></p>';
        echo '<p><label><strong>URL / destino existente comparable</strong><br><textarea style="width:100%" name="existing_destination" rows="2">' . esc_textarea($row['existing_destination']) . '</textarea></label></p>';
        echo '<p><label><strong>Por qué merece una landing distinta (anticannibalización)</strong><br><textarea style="width:100%" name="differentiation_reason" rows="3">' . esc_textarea($row['differentiation_reason']) . '</textarea></label></p>';
        echo '<h4>Requisitos obligatorios (pendiente no es aprobado)</h4>';
        foreach (seo_landing_requirement_labels() as $key=>$label) {
            $value = seo_landing_requirement_value($requirements[$key] ?? -1);
            echo '<p><label>' . esc_html($label) . ': <select name="requirements[' . esc_attr($key) . ']">';
            foreach (array('-1'=>'Pendiente de verificar','0'=>'No cumple','1'=>'Verificado: cumple') as $option=>$caption) {
                echo '<option value="' . esc_attr($option) . '" ' . selected((string) $value,$option,false) . '>' . esc_html($caption) . '</option>';
            }
            echo '</select></label></p>';
        }
        echo '<p><label><strong>Propuesta textual para el borrador (revisión humana)</strong><br>';
        echo '<textarea name="draft_text" rows="12" style="width:100%">' . esc_textarea((string) ($proposal['draft_text'] ?? '')) . '</textarea></label></p>';
        echo '<p><button name="command" value="save" type="submit" class="button">Guardar revisión</button> ';
        echo '<button name="command" value="approve" type="submit" class="button button-primary">Aprobar y crear página en borrador</button> ';
        echo '<button name="command" value="reject" type="submit" class="button" onclick="return confirm(\'¿Rechazar esta candidata?\')">Rechazar</button></p>';
        echo '</form>';
    }
}
