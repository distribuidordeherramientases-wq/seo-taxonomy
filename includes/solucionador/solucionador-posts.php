<?php
/**
 * Solucionador - creacion de borradores aprobados.
 *
 * El inventario/dossier interno es la fuente de verdad. El post_content recibe
 * una copia editorial legible para trabajarla; editar esa copia nunca modifica
 * ni sustituye la trazabilidad interna de preguntas y runs.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Posts {
    const META_TOPIC_ID = '_seo_solucionador_topic_id';
    const META_CANONICAL_KEY = '_seo_solucionador_canonical_key';
    const META_CONTENT_ROLE = '_seo_solucionador_content_role';
    const META_DOSSIER_CATEGORY_ID = '_seo_solucionador_dossier_category_id';
    const META_QUESTION_IDS = '_seo_solucionador_question_ids';
    const META_RUN_IDS = '_seo_solucionador_run_ids';
    const META_SOURCE_HASH = '_seo_solucionador_source_hash';
    const META_SOURCE_SNAPSHOT = '_seo_solucionador_source_snapshot';
    const META_ITEM_HASHES = '_seo_solucionador_item_hashes';
    const META_PENDING_QUESTION_IDS = '_seo_solucionador_pending_question_ids';
    const META_PENDING_SOURCE_HASH = '_seo_solucionador_pending_source_hash';
    const META_PENDING_DETECTED_AT = '_seo_solucionador_pending_detected_at';

    public static function init() {
        add_action('transition_post_status', array(__CLASS__, 'transition_post_status'), 10, 3);
        add_action('before_delete_post', array(__CLASS__, 'before_delete_post'), 20, 1);
        add_action('seo_post_editor_before_form', array(__CLASS__, 'render_pending_review_panel'), 10, 1);
    }

    public static function edit_url($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return '';
        return add_query_arg(
            array('page'=>'seo-post-editor','post_id'=>$post_id),
            admin_url('edit.php')
        );
    }

    private static function category_ids(array $topic) {
        $ids = array();
        foreach (SEO_Solucionador_DB::proposed_categories($topic) as $row) {
            if (is_array($row) && !empty($row['id'])) $ids[] = absint($row['id']);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function primary_category_id(array $topic) {
        $term_id = absint($topic['primary_category_id'] ?? 0);
        if (!$term_id) {
            $ids = self::category_ids($topic);
            $term_id = $ids ? absint($ids[0]) : 0;
        }
        if (!$term_id) return 0;
        $term = get_term($term_id, 'product_cat');
        return ($term && !is_wp_error($term)) ? $term_id : 0;
    }

    private static function canonical_category_vocabulary($category_id) {
        global $wpdb;
        $category_id = absint($category_id);
        $out = array();
        $groups = function_exists('seo_content_vocab_groups')
            ? array_keys(seo_content_vocab_groups())
            : array('rol','tipo','aplicacion','plataforma','subtipo');
        foreach ($groups as $group) $out[$group] = array();

        if (!$category_id) return $out;
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($objects) || !SEO_Solucionador_DB::table_exists($vocabulary)) {
            return $out;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id,v.semantic_group
                 FROM {$objects} ov
                 INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id
                 WHERE ov.object_type='product_cat'
                   AND ov.object_id=%d
                   AND ov.status=1
                   AND v.active=1",
                $category_id
            ),
            ARRAY_A
        );
        foreach ($rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            $id = absint($row['id'] ?? 0);
            if ($id && array_key_exists($group, $out)) $out[$group][] = $id;
        }
        foreach ($out as $group=>$ids) {
            $out[$group] = array_values(array_unique(array_filter(array_map('absint',$ids))));
        }
        return $out;
    }

    private static function vocabulary_ids_by_group(array $topic, $category_id = 0) {
        $proposal = SEO_Solucionador_DB::proposed_vocabulary($topic);
        $canonical = self::canonical_category_vocabulary($category_id);
        $out = array();
        $groups = function_exists('seo_content_vocab_groups')
            ? array_keys(seo_content_vocab_groups())
            : array('rol','tipo','aplicacion','plataforma','subtipo');

        foreach ($groups as $group) {
            // La product_cat es la fuente principal: el post hereda exactamente
            // su Vocabulary canonico cuando existe para este grupo.
            $ids = array_values(array_unique(array_filter(array_map(
                'absint',
                (array) ($canonical[$group] ?? array())
            ))));

            // Si la categoria aun no tiene datos para un grupo concreto,
            // usamos como respaldo la propuesta aprendida por Solucionador.
            if (!$ids) {
                foreach ((array) ($proposal[$group] ?? array()) as $row) {
                    if (is_array($row) && !empty($row['id'])) $ids[] = absint($row['id']);
                }
                $ids = array_values(array_unique(array_filter($ids)));
            }

            $out[$group] = $ids;
        }

        return $out;
    }

    private static function assign_vocabulary($post_id, array $topic, $category_id) {
        if (!function_exists('seo_content_vocab_replace_manual_group')) {
            return new WP_Error('solucionador_vocab_api', 'No esta disponible la API canonica de Vocabulary para posts.');
        }
        foreach (self::vocabulary_ids_by_group($topic, $category_id) as $group => $ids) {
            $result = seo_content_vocab_replace_manual_group('post', $post_id, $group, $ids);
            if (is_wp_error($result)) return $result;
        }
        return true;
    }

    private static function assign_categories($post_id, array $topic, $primary_category_id = 0) {
        $ids = $primary_category_id ? array(absint($primary_category_id)) : self::category_ids($topic);
        $ids = array_values(array_unique(array_filter($ids)));
        if (function_exists('seo_post_editor_replace_product_cat_relations')) {
            return seo_post_editor_replace_product_cat_relations($post_id, $ids);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seo_relations';
        if (!SEO_Solucionador_DB::table_exists($table)) {
            return new WP_Error('solucionador_relations_table', 'No existe la tabla seo_relations.');
        }

        foreach ($ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return new WP_Error('solucionador_invalid_category', 'Una categoria propuesta ya no existe.');
            }
        }

        $wpdb->delete($table, array(
            'source_type' => 'post',
            'source_id' => $post_id,
            'target_type' => 'product_cat',
            'relation_type' => 'post_to_category',
        ));

        foreach ($ids as $term_id) {
            $ok = $wpdb->insert($table, array(
                'source_type' => 'post',
                'source_id' => $post_id,
                'target_type' => 'product_cat',
                'target_id' => $term_id,
                'relation_type' => 'post_to_category',
                'created_at' => current_time('mysql'),
            ));
            if (false === $ok) {
                return new WP_Error('solucionador_relation_write', 'No se pudo guardar la relacion post_to_category.');
            }
        }
        return true;
    }

    private static function guides_category_id() {
        $term = term_exists('Guías', 'category');
        if (!$term) $term = term_exists('guias', 'category');
        if (!$term) {
            $term = wp_insert_term('Guías', 'category', array('slug'=>'guias'));
        }
        if (is_wp_error($term)) return $term;
        $term_id = is_array($term) ? absint($term['term_id'] ?? 0) : absint($term);
        return $term_id ?: new WP_Error('solucionador_guides_category', 'No se pudo resolver la categoria editorial Guías.');
    }

    private static function question_details($category_id) {
        if (!class_exists('SEO_Solucionador_Dossiers') || !method_exists('SEO_Solucionador_Dossiers','question_details')) {
            return array();
        }
        return array_values((array) SEO_Solucionador_Dossiers::question_details(absint($category_id)));
    }

    private static function dossier_snapshot($category_id, array $details) {
        $dossier = class_exists('SEO_Solucionador_Dossiers')
            ? (array) SEO_Solucionador_Dossiers::get_by_category(absint($category_id))
            : array();

        $question_ids = array();
        $run_ids = array();
        foreach ($details as $row) {
            $qid = absint($row['question_id'] ?? 0);
            $rid = absint($row['run_id'] ?? 0);
            if ($qid) $question_ids[] = $qid;
            if ($rid) $run_ids[] = $rid;
        }
        $question_ids = array_values(array_unique($question_ids));
        $run_ids = array_values(array_unique($run_ids));

        return array(
            'dossier_id'=>absint($dossier['id'] ?? 0),
            'category_id'=>absint($category_id),
            'category_name'=>(string) ($dossier['category_name'] ?? ''),
            'question_ids'=>$question_ids,
            'run_ids'=>$run_ids,
            'question_count'=>count($question_ids),
            'source_hash'=>(string) ($dossier['source_hash'] ?? ''),
            'item_hashes'=>SEO_Solucionador_DB::decode_json($dossier['item_hashes'] ?? '{}', array()),
            'last_validated_at'=>(string) ($dossier['last_validated_at'] ?? ''),
            'captured_at'=>current_time('mysql'),
        );
    }

    private static function persist_source_snapshot($post_id, array $snapshot) {
        $post_id = absint($post_id);
        if (!$post_id) return false;

        $ok = true;
        $writes = array(
            self::META_DOSSIER_CATEGORY_ID => absint($snapshot['category_id'] ?? 0),
            self::META_QUESTION_IDS => array_values((array) ($snapshot['question_ids'] ?? array())),
            self::META_RUN_IDS => array_values((array) ($snapshot['run_ids'] ?? array())),
            self::META_SOURCE_HASH => (string) ($snapshot['source_hash'] ?? ''),
            self::META_ITEM_HASHES => (array) ($snapshot['item_hashes'] ?? array()),
            self::META_SOURCE_SNAPSHOT => $snapshot,
        );
        foreach ($writes as $key=>$value) {
            $written = update_post_meta($post_id, $key, $value);
            if ($written === false) {
                // update_post_meta() también devuelve false cuando el valor ya
                // era idéntico; sólo es error si el valor persistido difiere.
                $current = get_post_meta($post_id,$key,true);
                if (maybe_serialize($current) !== maybe_serialize($value)) $ok = false;
            }
        }
        return $ok;
    }

    private static function managed_post_id_by_category($category_id) {
        $category_id = absint($category_id);
        if (!$category_id) return 0;

        $ids = get_posts(array(
            'post_type'=>'post',
            'post_status'=>array('draft','publish','future','pending','private'),
            'posts_per_page'=>1,
            'fields'=>'ids',
            'orderby'=>'ID',
            'order'=>'ASC',
            'meta_key'=>self::META_DOSSIER_CATEGORY_ID,
            'meta_value'=>$category_id,
            'no_found_rows'=>true,
        ));
        return $ids ? absint($ids[0]) : 0;
    }

    public static function managed_post_id_by_category_public($category_id) {
        return self::managed_post_id_by_category($category_id);
    }

    public static function pending_question_ids($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return array();
        return array_values(array_unique(array_filter(array_map(
            'absint',
            (array) get_post_meta($post_id,self::META_PENDING_QUESTION_IDS,true)
        ))));
    }

    public static function pending_question_count($post_id) {
        return count(self::pending_question_ids($post_id));
    }

    public static function pending_question_details($post_id) {
        $post_id = absint($post_id);
        $category_id = absint(get_post_meta($post_id,self::META_DOSSIER_CATEGORY_ID,true));
        if (!$post_id || !$category_id) return array();

        $pending = self::pending_question_ids($post_id);
        if (!$pending) return array();
        $lookup = array_fill_keys($pending,true);

        return array_values(array_filter(
            self::question_details($category_id),
            static function($row) use ($lookup) {
                return !empty($lookup[absint($row['question_id'] ?? 0)]);
            }
        ));
    }

    private static function clear_pending_questions($post_id) {
        delete_post_meta($post_id,self::META_PENDING_QUESTION_IDS);
        delete_post_meta($post_id,self::META_PENDING_SOURCE_HASH);
        delete_post_meta($post_id,self::META_PENDING_DETECTED_AT);
    }

    /**
     * Cuando Academia aprende preguntas nuevas para una categoría que ya tiene
     * post gestionado, NO se modifica post_content. Se guardan como novedades
     * pendientes para que la Editora decida qué incorporar.
     */
    public static function sync_category_post($category_id) {
        $category_id = absint($category_id);
        $post_id = self::managed_post_id_by_category($category_id);
        if (!$post_id) return false;

        $dossier = class_exists('SEO_Solucionador_Dossiers')
            ? (array) SEO_Solucionador_Dossiers::get_by_category($category_id)
            : array();
        if (!$dossier) return 0;

        $changed_ids = class_exists('SEO_Solucionador_Dossiers')
            ? SEO_Solucionador_Dossiers::changed_item_ids($category_id)
            : array();

        $old_pending = self::pending_question_ids($post_id);
        sort($old_pending,SORT_NUMERIC);
        $pending_ids = array_values(array_unique(array_filter(array_map('absint',(array)$changed_ids))));
        sort($pending_ids,SORT_NUMERIC);

        if (!$pending_ids) {
            self::clear_pending_questions($post_id);
            return 0;
        }

        update_post_meta($post_id,self::META_PENDING_QUESTION_IDS,$pending_ids);
        update_post_meta($post_id,self::META_PENDING_SOURCE_HASH,(string)($dossier['source_hash'] ?? ''));
        update_post_meta($post_id,self::META_PENDING_DETECTED_AT,current_time('mysql'));
        SEO_Solucionador_DB::update_dossier_editorial($category_id,array(
            'editorial_status'=>SEO_Editorial_Service_Contract::NEEDS_UPDATE,
        ));

        $topic_id = absint(get_post_meta($post_id,self::META_TOPIC_ID,true));
        if ($topic_id) {
            SEO_Solucionador_DB::update_topic($topic_id,array(
                'workflow_state'=>'needs_update',
                'recommended_action'=>'IMPROVE_POST',
                'decision_reason'=>'El dossier contiene elementos nuevos o modificados desde la ultima revision editorial.',
            ));
            if ($old_pending !== $pending_ids) {
                SEO_Solucionador_DB::record_workflow(
                    $topic_id,
                    'needs_update',
                    'El source_hash ha cambiado. Se muestran exclusivamente preguntas nuevas o modificadas; el contenido publicado no se altera automaticamente.',
                    'IMPROVE_POST'
                );
            }
        }
        return count($pending_ids);
    }

    public static function refresh_pending_for_post($post_id) {
        $post_id = absint($post_id);
        $category_id = absint(get_post_meta($post_id,self::META_DOSSIER_CATEGORY_ID,true));
        if (!$post_id || !$category_id || get_post_type($post_id) !== 'post') {
            return new WP_Error('solucionador_post_invalid','El post no pertenece a un dossier válido de Solucionador.');
        }
        $result = self::sync_category_post($category_id);
        return is_wp_error($result) ? $result : absint($result);
    }

    public static function mark_pending_reviewed($post_id) {
        $post_id = absint($post_id);
        $category_id = absint(get_post_meta($post_id,self::META_DOSSIER_CATEGORY_ID,true));
        if (!$post_id || !$category_id || get_post_type($post_id) !== 'post') {
            return new WP_Error('solucionador_post_invalid','El post no pertenece a un dossier válido de Solucionador.');
        }

        $details = self::question_details($category_id);
        $snapshot = self::dossier_snapshot($category_id,$details);
        if (!self::persist_source_snapshot($post_id,$snapshot)) {
            return new WP_Error('solucionador_review_snapshot','No se pudo guardar el nuevo punto de control del dossier.');
        }
        self::clear_pending_questions($post_id);
        if (class_exists('SEO_Solucionador_Dossiers')) {
            SEO_Solucionador_Dossiers::mark_reviewed($category_id);
        }

        $topic_id = absint(get_post_meta($post_id,self::META_TOPIC_ID,true));
        if ($topic_id) {
            $status = (string) get_post_status($post_id);
            $workflow = $status === 'publish' ? 'monitoring' : 'in_editing';
            SEO_Solucionador_DB::update_topic($topic_id,array(
                'workflow_state'=>$workflow,
                'recommended_action'=>'NO_ACTION',
                'decision_reason'=>'Las nuevas preguntas fueron revisadas por la Editora; el contenido queda bajo control editorial manual.',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                $workflow,
                'La Editora revisó las nuevas preguntas detectadas y decidió qué incorporar al contenido.',
                'NO_ACTION'
            );
        }
        return true;
    }

    public static function render_pending_review_panel($post_id) {
        $post_id = absint($post_id);
        $category_id = absint(get_post_meta($post_id,self::META_DOSSIER_CATEGORY_ID,true));
        if (!$post_id || !$category_id || get_post_type($post_id) !== 'post') return;

        $pending = self::pending_question_details($post_id);
        $count = count($pending);
        $detected_at = (string) get_post_meta($post_id,self::META_PENDING_DETECTED_AT,true);
        $update_state = sanitize_key((string)($_GET['sol_update'] ?? ''));

        echo '<div style="background:#fff;border:1px solid ' . ($count ? '#dba617' : '#c3c4c7') . ';border-left:4px solid ' . ($count ? '#dba617' : '#2271b1') . ';border-radius:6px;padding:16px 18px;margin:14px 0 18px;">';
        if ($update_state === 'rescanned') {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px"><p>Solucionador ha vuelto a comparar este post con el dossier actual.</p></div>';
        } elseif ($update_state === 'reviewed') {
            echo '<div class="notice notice-success inline" style="margin:0 0 12px"><p>Novedades marcadas como revisadas. Las próximas preguntas nuevas volverán a aparecer aquí.</p></div>';
        } elseif ($update_state === 'error') {
            $message = get_transient('seo_solucionador_notice_' . get_current_user_id());
            delete_transient('seo_solucionador_notice_' . get_current_user_id());
            echo '<div class="notice notice-error inline" style="margin:0 0 12px"><p>' . esc_html($message ?: 'No se pudo actualizar la revisión de Solucionador.') . '</p></div>';
        }
        echo '<div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">';
        echo '<div><h2 style="margin:0 0 6px">Solucionador · revisión de nuevas preguntas</h2>';
        if ($count) {
            echo '<p style="margin:0"><strong>' . esc_html(number_format_i18n($count)) . ' preguntas/respuestas nuevas</strong> detectadas desde la última revisión. El post mantiene su estado actual —también si está publicado— y la versión pública no cambia hasta que la Editora decida guardar una actualización.</p>';
            if ($detected_at !== '') {
                echo '<p class="description" style="margin:4px 0 0">Detectadas: ' . esc_html($detected_at) . '</p>';
            }
        } else {
            echo '<p style="margin:0">No hay preguntas nuevas pendientes de revisión.</p>';
        }
        echo '</div>';

        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
        echo '<input type="hidden" name="action" value="seo_solucionador_post_action">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr($post_id) . '">';
        echo '<input type="hidden" name="post_action" value="rescan">';
        echo '<input type="hidden" name="return_to" value="editor">';
        wp_nonce_field('seo_solucionador_post_action_' . $post_id);
        echo '<button type="submit" class="button">Reescanear preguntas</button>';
        echo '</form>';

        if ($count) {
            $insert_html = '<h2>Nuevas preguntas de Solucionador</h2><ul>';
            foreach ($pending as $row) {
                $question = trim((string)($row['question'] ?? ''));
                if ($question === '') continue;
                $answer = class_exists('SEO_Solucionador_Dossiers')
                    ? SEO_Solucionador_Dossiers::answer_text((array)$row)
                    : '';
                $insert_html .= '<li><strong>' . esc_html($question) . '</strong>';
                if ($answer !== '') $insert_html .= '<br>' . esc_html($answer);
                $insert_html .= '</li>';
            }
            $insert_html .= '</ul>';

            if (function_exists('seo_post_editor_render_prepend_payload')) {
                seo_post_editor_render_prepend_payload(
                    'seo-solucionador-pending-' . $post_id,
                    $insert_html,
                    'Incorporar al contenido de trabajo'
                );
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
            echo '<input type="hidden" name="action" value="seo_solucionador_post_action">';
            echo '<input type="hidden" name="post_id" value="' . esc_attr($post_id) . '">';
            echo '<input type="hidden" name="post_action" value="mark_reviewed">';
            echo '<input type="hidden" name="return_to" value="editor">';
            wp_nonce_field('seo_solucionador_post_action_' . $post_id);
            echo '<button type="submit" class="button">Marcar novedades como revisadas</button>';
            echo '</form>';
        }
        echo '</div></div>';

        if ($count) {
            echo '<div style="margin-top:14px;max-height:460px;overflow:auto;border-top:1px solid #dcdcde;padding-top:10px">';
            foreach ($pending as $row) {
                $question = trim((string)($row['question'] ?? ''));
                if ($question === '') continue;
                $answer = class_exists('SEO_Solucionador_Dossiers')
                    ? SEO_Solucionador_Dossiers::answer_text((array)$row)
                    : '';
                echo '<div style="padding:10px 0;border-bottom:1px solid #f0f0f1">';
                echo '<strong>' . esc_html($question) . '</strong>';
                if ($answer !== '') {
                    echo '<div style="margin-top:5px;line-height:1.55">' . esc_html($answer) . '</div>';
                }
                $trace = array();
                if (!empty($row['lesson_key'])) $trace[] = 'lección ' . (string) $row['lesson_key'];
                if (!empty($row['question_type'])) $trace[] = 'tipo ' . (string) $row['question_type'];
                if (!empty($row['evaluation_status'])) {
                    $score = isset($row['evaluation_score'])
                        ? ' · ' . number_format_i18n((float)$row['evaluation_score'] * 100, 0) . '%'
                        : '';
                    $trace[] = 'validación ' . (string) $row['evaluation_status'] . $score;
                }
                if (!empty($row['observed_at'])) $trace[] = 'observada ' . (string) $row['observed_at'];
                if ($trace) {
                    echo '<div class="description" style="margin-top:5px">Origen: Academia/Dependiente · ' . esc_html(implode(' · ', $trace)) . '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<p class="description" style="margin:10px 0 0">El botón <strong>Incorporar al contenido de trabajo</strong> sólo modifica el editor abierto en tu navegador: no guarda ni despublica el post. Revisa/reescribe, guarda cuando corresponda y después pulsa <strong>Marcar novedades como revisadas</strong>.</p>';
        }
        echo '</div>';
    }

    private static function json_material($value) {
        if ($value === null || $value === '' || $value === array()) {
            return '<p><em>No consta material almacenado en este campo.</em></p>';
        }
        $encoded = wp_json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded) || $encoded === '') {
            return '<p><em>No se pudo representar el material almacenado.</em></p>';
        }
        return '<pre style="white-space:pre-wrap;">' . esc_html($encoded) . '</pre>';
    }

    private static function existing_content_references(array $topic, $category_id) {
        global $wpdb;
        $items = array();
        $seen = array();

        $entity_type = sanitize_key((string) ($topic['existing_entity_type'] ?? ''));
        $entity_id = absint($topic['existing_entity_id'] ?? 0);
        if ($entity_id) {
            if (in_array($entity_type,array('post','page','product'),true)) {
                $post = get_post($entity_id);
                if ($post instanceof WP_Post) {
                    $items[] = array(
                        'label'=>get_the_title($post),
                        'url'=>get_permalink($post),
                        'type'=>$entity_type,
                    );
                    $seen[$entity_type . ':' . $entity_id] = true;
                }
            } elseif (in_array($entity_type,array('product_cat','category'),true)) {
                $term = get_term($entity_id,'product_cat');
                if ($term && !is_wp_error($term)) {
                    $url = get_term_link($term);
                    $items[] = array(
                        'label'=>(string) $term->name,
                        'url'=>is_wp_error($url) ? '' : $url,
                        'type'=>'product_cat',
                    );
                    $seen['product_cat:' . $entity_id] = true;
                }
            }
        }

        $relations = $wpdb->prefix . 'seo_relations';
        if ($category_id && SEO_Solucionador_DB::table_exists($relations)) {
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT p.ID,p.post_title
                     FROM {$relations} r
                     INNER JOIN {$wpdb->posts} p ON p.ID=r.source_id
                     WHERE r.source_type='post'
                       AND r.target_type='product_cat'
                       AND r.relation_type='post_to_category'
                       AND r.target_id=%d
                       AND p.post_type='post'
                       AND p.post_status='publish'
                     ORDER BY p.post_date DESC
                     LIMIT %d",
                    absint($category_id),
                    12
                ),
                ARRAY_A
            );
            foreach ($rows as $row) {
                $id = absint($row['ID'] ?? 0);
                if (!$id || isset($seen['post:' . $id])) continue;
                $items[] = array(
                    'label'=>(string) ($row['post_title'] ?? ''),
                    'url'=>get_permalink($id),
                    'type'=>'post',
                );
                $seen['post:' . $id] = true;
            }
        }
        return array_slice($items,0,12);
    }

    private static function internal_link_recommendations($category_id) {
        $category_id = absint($category_id);
        if (!$category_id) return array();

        $items = array();
        $term = get_term($category_id,'product_cat');
        if ($term && !is_wp_error($term)) {
            $url = get_term_link($term);
            $items[] = array(
                'label'=>'Categoría: ' . (string) $term->name,
                'url'=>is_wp_error($url) ? '' : $url,
                'type'=>'product_cat',
            );
        }

        $product_ids = get_posts(array(
            'post_type'=>'product',
            'post_status'=>'publish',
            'posts_per_page'=>6,
            'fields'=>'ids',
            'orderby'=>'date',
            'order'=>'DESC',
            'no_found_rows'=>true,
            'tax_query'=>array(array(
                'taxonomy'=>'product_cat',
                'field'=>'term_id',
                'terms'=>array($category_id),
                'include_children'=>true,
            )),
        ));
        foreach ((array) $product_ids as $product_id) {
            $product_id = absint($product_id);
            if (!$product_id) continue;
            $items[] = array(
                'label'=>get_the_title($product_id),
                'url'=>get_permalink($product_id),
                'type'=>'product',
            );
        }
        return $items;
    }

    private static function render_reference_list(array $items, $empty_message) {
        if (!$items) return '<p><em>' . esc_html($empty_message) . '</em></p>';
        $html = '<ul>';
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            $type = sanitize_key((string) ($item['type'] ?? ''));
            if ($label === '') continue;
            $html .= '<li>';
            if ($url !== '') {
                $html .= '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            } else {
                $html .= esc_html($label);
            }
            if ($type !== '') $html .= ' <small>(' . esc_html($type) . ')</small>';
            $html .= '</li>';
        }
        return $html . '</ul>';
    }

    private static function build_excerpt($category_id) {
        $category_id = absint($category_id);
        $term = $category_id ? get_term($category_id, 'product_cat') : null;
        $name = ($term && !is_wp_error($term)) ? trim((string) $term->name) : '';
        if ($name === '') {
            return 'Guía práctica basada en dudas reales de cliente sobre elección, uso y errores habituales.';
        }

        return sprintf(
            'Guía práctica de %s basada en preguntas útiles de clientes sobre elección, uso, compatibilidad y errores habituales.',
            $name
        );
    }

    private static function build_editorial_brief($topic_id, array $topic, $category_id, array $details) {
        $html = '<ul>';

        foreach ($details as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            if ($question === '') continue;
            $answer = class_exists('SEO_Solucionador_Dossiers')
                ? SEO_Solucionador_Dossiers::answer_text((array) $row)
                : '';

            $html .= '<li><strong>' . esc_html($question) . '</strong><br>';
            $html .= esc_html($answer !== '' ? $answer : 'Sin respuesta legible almacenada.');
            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    public static function create_draft($topic_id, $human_override = false) {
        $topic_id = absint($topic_id);
        $topic = SEO_Solucionador_DB::get_topic($topic_id);
        if (!$topic) return new WP_Error('solucionador_topic_missing', 'La propuesta ya no existe.');

        $existing_draft = absint($topic['draft_post_id'] ?? 0);
        if ($existing_draft && get_post_type($existing_draft) === 'post' && get_post_status($existing_draft) !== 'trash') {
            return $existing_draft;
        }

        $title = sanitize_text_field((string) ($topic['suggested_title'] ?? ''));
        if ($title === '') return new WP_Error('solucionador_title_missing', 'La propuesta no tiene titulo.');

        $primary_category_id = self::primary_category_id($topic);
        if (!$primary_category_id) {
            return new WP_Error('solucionador_primary_category_missing', 'El dossier ya no tiene una product_cat de origen válida.');
        }

        $details = self::question_details($primary_category_id);
        if (!$details) {
            return new WP_Error(
                'solucionador_question_material_missing',
                'El dossier no contiene material editorial util asociado de forma demostrable a esta categoria.'
            );
        }
        SEO_Solucionador_DB::update_dossier_editorial($primary_category_id,array(
            'editorial_status'=>SEO_Editorial_Service_Contract::ACCEPTED,
        ));
        $snapshot = self::dossier_snapshot($primary_category_id,$details);
        $brief_content = self::build_editorial_brief($topic_id,$topic,$primary_category_id,$details);

        $guides_category_id = self::guides_category_id();
        if (is_wp_error($guides_category_id)) return $guides_category_id;

        $post_id = wp_insert_post(wp_slash(array(
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => $brief_content,
            'post_excerpt' => self::build_excerpt($primary_category_id),
            'post_author' => get_current_user_id(),
            'post_category' => array(absint($guides_category_id)),
        )), true);
        if (is_wp_error($post_id) || absint($post_id) <= 0) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('solucionador_post_create', 'WordPress no pudo crear el borrador.');
        }
        $post_id = absint($post_id);

        update_post_meta($post_id, self::META_TOPIC_ID, $topic_id);
        update_post_meta($post_id, self::META_CANONICAL_KEY, (string) ($topic['canonical_key'] ?? ''));

        $role_result = function_exists('seo_post_editor_set_public_content_role')
            ? seo_post_editor_set_public_content_role($post_id, 'dependiente_qa_basic')
            : update_post_meta($post_id, self::META_CONTENT_ROLE, 'dependiente_qa_basic');
        if (is_wp_error($role_result) || $role_result === false) {
            wp_delete_post($post_id, true);
            return is_wp_error($role_result)
                ? $role_result
                : new WP_Error('solucionador_content_role', 'No se pudo asignar el rol editorial dependiente_qa_basic.');
        }

        $vocab_result = self::assign_vocabulary($post_id, $topic, $primary_category_id);
        if (is_wp_error($vocab_result)) {
            wp_delete_post($post_id, true);
            return $vocab_result;
        }

        $relation_result = self::assign_categories($post_id, $topic, $primary_category_id);
        if (is_wp_error($relation_result)) {
            wp_delete_post($post_id, true);
            return $relation_result;
        }

        if (!self::persist_source_snapshot($post_id,$snapshot)) {
            wp_delete_post($post_id,true);
            return new WP_Error(
                'solucionador_source_snapshot',
                'No se pudo conservar la trazabilidad interna dossier/post/preguntas/runs.'
            );
        }

        SEO_Solucionador_DB::update_topic($topic_id, array(
            'status' => 'draft_created',
            'workflow_state' => 'in_editing',
            'draft_post_id' => $post_id,
            'approved_at' => current_time('mysql'),
            'draft_created_at' => current_time('mysql'),
        ));
        SEO_Solucionador_DB::record_workflow(
            $topic_id,
            'in_editing',
            'La Editora crea un borrador de trabajo desde el dossier. Los indicadores de calidad no bloquean esta decision humana.',
            'CREATE_POST'
        );
        SEO_Solucionador_DB::update_dossier_editorial($primary_category_id,array(
            'reviewed_hash'=>(string)($snapshot['source_hash'] ?? ''),
            'reviewed_item_hashes'=>wp_json_encode((array)($snapshot['item_hashes'] ?? array())),
            'editorial_status'=>SEO_Editorial_Service_Contract::DRAFT,
            'reviewed_at'=>current_time('mysql'),
        ));

        return $post_id;
    }

    public static function transition_post_status($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || $post->post_type !== 'post') return;
        $topic_id = absint(get_post_meta($post->ID, self::META_TOPIC_ID, true));
        if (!$topic_id) return;
        $category_id = absint(get_post_meta($post->ID,self::META_DOSSIER_CATEGORY_ID,true));

        if ($new_status === 'publish') {
            $pending = self::pending_question_count($post->ID);
            $workflow_state = $pending > 0 ? 'needs_update' : 'monitoring';
            $action = $pending > 0 ? 'IMPROVE_POST' : 'NO_ACTION';
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status' => 'covered',
                'workflow_state' => $workflow_state,
                'coverage_status' => 'covered',
                'existing_entity_type' => 'post',
                'existing_entity_id' => absint($post->ID),
                'existing_post_id' => absint($post->ID),
                'draft_post_id' => absint($post->ID),
                'recommended_action' => $action,
            ));
            if ($category_id) SEO_Solucionador_DB::update_dossier_editorial($category_id,array(
                'editorial_status'=>$pending > 0 ? SEO_Editorial_Service_Contract::NEEDS_UPDATE : SEO_Editorial_Service_Contract::PUBLISHED,
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                $workflow_state,
                $pending > 0
                    ? 'Contenido publicado con novedades pendientes de revisión editorial.'
                    : 'Contenido publicado. Solucionador espera ahora resultados posteriores de Analista.',
                $action
            );
            return;
        }

        if ($new_status === 'future') {
            SEO_Solucionador_DB::update_topic($topic_id,array('workflow_state'=>'scheduled'));
            SEO_Solucionador_DB::record_workflow($topic_id,'scheduled','Contenido programado para publicacion.','CREATE_POST');
            return;
        }

        if (in_array($new_status,array('draft','pending','private'),true) && $old_status !== $new_status) {
            if ($category_id) SEO_Solucionador_DB::update_dossier_editorial($category_id,array('editorial_status'=>SEO_Editorial_Service_Contract::DRAFT));
            SEO_Solucionador_DB::update_topic($topic_id,array('workflow_state'=>'in_editing'));
            SEO_Solucionador_DB::record_workflow($topic_id,'in_editing','Contenido en proceso editorial.','CREATE_POST');
            return;
        }

        if ($new_status === 'trash') {
            self::release_topic($topic_id, $post->ID);
        }
    }

    public static function before_delete_post($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'post') return;
        $topic_id = absint(get_post_meta($post_id, self::META_TOPIC_ID, true));
        if ($topic_id) self::release_topic($topic_id, $post_id);
    }

    private static function release_topic($topic_id, $post_id) {
        $topic = SEO_Solucionador_DB::get_topic($topic_id);
        if (!$topic) return;
        $changes = array(
            'status' => 'candidate',
            'workflow_state' => 'candidate',
            'coverage_status' => 'uncovered',
            'recommended_action' => 'CREATE_POST',
            'draft_post_id' => null,
        );
        if (absint($topic['existing_post_id'] ?? 0) === absint($post_id)) $changes['existing_post_id'] = null;
        SEO_Solucionador_DB::update_topic($topic_id, $changes);
        SEO_Solucionador_DB::record_workflow(
            $topic_id,
            'candidate',
            'El borrador asociado se elimino; la necesidad vuelve a decision editorial.',
            'CREATE_POST'
        );
    }
}
