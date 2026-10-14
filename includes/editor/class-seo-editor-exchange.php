<?php
/**
 * CSV de Editor: transporte de borradores con previsualizacion obligatoria.
 * El CSV nunca publica ni envia contenido a la papelera por si mismo.
 */
defined('ABSPATH') || exit;

final class SEO_Editor_Exchange {
    const MAX_BYTES = 2097152;
    const MAX_ROWS = 100;
    const COLUMNS = array(
        'id','tipo','origen','seccion','titulo','extracto','contenido',
        'categoria_ids','notas_editor','revision_hash',
    );

    public static function init() {
        add_action('admin_post_seo_editor_export_csv', array(__CLASS__, 'handle_export'));
        add_action('admin_post_seo_editor_csv_preview', array(__CLASS__, 'handle_preview'));
        add_action('admin_post_seo_editor_csv_apply', array(__CLASS__, 'handle_apply'));
    }

    public static function section($post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post) return '';
        if ($post->post_type === 'page') return 'landing';
        if (SEO_Editor_Core::is_news($post->ID)) return 'noticia';
        $role = sanitize_key((string)get_post_meta($post->ID, '_seo_solucionador_content_role', true));
        if ($role === 'comparison' || has_term('comparativas', 'post_tag', $post->ID)) return 'comparativa';
        return 'guia';
    }

    public static function row($post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post) return array();
        return array(
            'id'=>(string)$post->ID,
            'tipo'=>SEO_Editor_Core::kind($post),
            'origen'=>SEO_Editor_Core::source($post),
            'seccion'=>self::section($post),
            'titulo'=>(string)$post->post_title,
            'extracto'=>(string)$post->post_excerpt,
            'contenido'=>(string)$post->post_content,
            'categoria_ids'=>implode(',', SEO_Editor_Core::category_ids($post)),
            'notas_editor'=>(string)get_post_meta($post->ID, '_seo_editor_notes', true),
            'revision_hash'=>SEO_Editor_Core::fingerprint($post),
        );
    }

    private static function csv_safe($value) {
        $value = (string)$value;
        // Evitar ejecución de formulas al abrir el CSV en Excel o Calc.
        return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    private static function csv_restore($value) {
        $value = (string)$value;
        return substr($value,0,1) === "'" && preg_match('/^\s*[=+\-@]/u', substr($value,1))
            ? substr($value,1) : $value;
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        check_admin_referer('seo_editor_export_csv');
        if (headers_sent()) wp_die('No se puede iniciar la descarga CSV; ya se enviaron cabeceras.');

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="editor-' . gmdate('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV requiere un recurso nativo.
        $out = fopen('php://output','w');
        if (!$out) exit;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM Excel UTF-8.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, ';', '"', '');
        foreach (SEO_Editor_Core::drafts() as $post) {
            $row = self::row($post);
            $cells = array();
            foreach (self::COLUMNS as $column) $cells[] = self::csv_safe($row[$column] ?? '');
            fputcsv($out, $cells, ';', '"', '');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre del stream CSV.
        fclose($out);
        exit;
    }

    private static function token_key($token) {
        return 'seo_editor_csv_' . get_current_user_id() . '_' . $token;
    }

    public static function preview($token) {
        $token = (string)$token;
        if (!preg_match('/^[a-f0-9]{20}$/', $token) || !current_user_can('manage_options')) return array();
        $stored = get_transient(self::token_key($token));
        return is_array($stored) ? $stored : array();
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'seo-editor'), (array)$args), admin_url('admin.php')));
        exit;
    }

    private static function parse_upload($upload) {
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('editor_upload', 'No se ha recibido un CSV válido.');
        }
        $path = (string)($upload['tmp_name'] ?? '');
        $size = absint($upload['size'] ?? 0);
        $name = (string)($upload['name'] ?? '');
        if ($size < 1 || $size > self::MAX_BYTES || !preg_match('/\.(csv|txt)$/i', $name)) {
            return new WP_Error('editor_upload_size', 'Sube un archivo .csv o .txt de 2 MB como máximo.');
        }
        if (!is_uploaded_file($path)) {
            return new WP_Error('editor_upload_path', 'El archivo no procede de la carga autorizada.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Requiere fgetcsv sobre upload PHP validado.
        $handle = fopen($path, 'r');
        if (!$handle) return new WP_Error('editor_upload_read', 'No se pudo leer el CSV.');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Detección del separador.
        $first = (string)fgets($handle);
        rewind($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers)) {
            fclose($handle);
            return new WP_Error('editor_headers', 'Falta la cabecera del CSV.');
        }
        $headers = array_map(static function($h) {
            return sanitize_key(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)));
        }, $headers);
        if ($headers !== self::COLUMNS) {
            fclose($handle);
            return new WP_Error('editor_headers', 'Cabeceras incorrectas. Descarga el CSV de Editor para utilizar la estructura válida.');
        }
        $rows = array();
        $seen = array();
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($cells === array(null)) continue;
            if (count($cells) !== count(self::COLUMNS)) {
                fclose($handle);
                return new WP_Error('editor_columns', 'Una fila contiene un número incorrecto de columnas.');
            }
            if (!array_filter($cells, static function($v) {return trim((string)$v) !== ''; })) continue;
            $row = array_combine(self::COLUMNS, array_map(array(__CLASS__, 'csv_restore'), $cells));
            $id = absint($row['id'] ?? 0);
            if ($id) {
                if (isset($seen[$id])) {
                    fclose($handle);
                    return new WP_Error('editor_duplicate_id', 'El CSV contiene el mismo ID más de una vez: ' . $id);
                }
                $seen[$id] = true;
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                return new WP_Error('editor_rows_limit', 'Máximo 100 filas por importación. Divide el CSV en tandas.');
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre del recurso CSV.
        fclose($handle);
        return $rows;
    }

    private static function existing_title($kind, $title) {
        global $wpdb;
        $post_type = $kind === 'landing' ? 'page' : 'post';
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_title=%s
             AND post_status NOT IN ('trash','auto-draft','inherit') LIMIT 1",
            $post_type, $title
        )));
    }

    public static function review_row($row) {
        $errors = array();
        $id = absint($row['id'] ?? 0);
        $kind = sanitize_key((string)($row['tipo'] ?? ''));
        $section = sanitize_key((string)($row['seccion'] ?? ''));
        $title = sanitize_text_field((string)($row['titulo'] ?? ''));
        $content = wp_kses_post((string)($row['contenido'] ?? ''));
        $note = sanitize_textarea_field((string)($row['notas_editor'] ?? ''));
        $excerpt = sanitize_textarea_field((string)($row['extracto'] ?? ''));
        $raw_categories = (string)($row['categoria_ids'] ?? '');
        $categories = SEO_Editor_Core::validate_categories($raw_categories);
        $original = null;
        if (is_wp_error($categories)) {
            $errors[] = $categories->get_error_message();
            $categories = array();
        }
        if (!in_array($kind, array('post','landing'), true)) $errors[] = 'Tipo post/landing no reconocido.';
        if ($title === '' || mb_strlen($title) > 180) $errors[] = 'Título vacío o superior a 180 caracteres.';
        if (trim(wp_strip_all_tags($content)) === '' || mb_strlen($content) > 40000) {
            $errors[] = 'Contenido vacío o superior a 40 000 caracteres.';
        }
        if (mb_strlen($note) > 2000 || mb_strlen($excerpt) > 1000) $errors[] = 'Extracto o nota editorial demasiado largos.';
        if ($kind === 'landing' && count($categories) < 2) $errors[] = 'Una landing requiere dos product_cat.';
        if ($kind === 'post' && $section !== 'noticia' && !$categories) $errors[] = 'El post necesita una product_cat.';
        if ($id) {
            $original = get_post($id);
            if (!$original instanceof WP_Post || $original->post_status !== 'draft'
                || SEO_Editor_Core::kind($original) !== $kind) {
                $errors[] = 'El ID no corresponde a un borrador del mismo tipo.';
            } else {
                if (self::section($original) !== $section) $errors[] = 'No se permite modificar la sección mediante CSV en borradores existentes.';
                $revision = (string)($row['revision_hash'] ?? '');
                if ($revision === '' || !hash_equals(SEO_Editor_Core::fingerprint($original), $revision)) {
                    $errors[] = 'El borrador cambió desde la exportación. Vuelve a exportar.';
                }
            }
        } else {
            if (trim((string)($row['revision_hash'] ?? '')) !== '') $errors[] = 'El hash debe quedar vacío en propuestas externas nuevas.';
            if ($kind === 'post' && !in_array($section,array('guia','comparativa','noticia'),true)) $errors[] = 'Sección de nuevo post no válida.';
            if ($kind === 'landing' && $section !== 'landing') $errors[] = 'Una landing nueva debe usar la sección landing.';
            if (!$errors && self::existing_title($kind,$title)) $errors[] = 'Ya existe una URL con el mismo título. Revisa antes de crear otra.';
        }
        $diff = array();
        if ($original instanceof WP_Post && !$errors) {
            if ((string)$original->post_title !== $title) $diff[] = 'Título';
            if ((string)$original->post_excerpt !== $excerpt) $diff[] = 'Extracto';
            if ((string)$original->post_content !== $content) $diff[] = 'Contenido';
            if ((string)get_post_meta($id,'_seo_editor_notes',true) !== $note) $diff[] = 'Nota';
            $current = SEO_Editor_Core::category_ids($original);
            sort($current);
            $wanted = $categories; sort($wanted);
            if ($current !== $wanted) $diff[] = 'Categorías';
        }
        return array(
            'valid'=>!$errors,
            'errors'=>$errors,
            'operation'=>$id ? 'Actualizar borrador #' . $id : 'Crear borrador externo',
            'diff'=>$diff,
            'id'=>$id,
            'kind'=>$kind,
            'section'=>$section,
            'title'=>$title,
            'excerpt'=>$excerpt,
            'content'=>$content,
            'note'=>$note,
            'categories'=>$categories,
            'original'=>$original,
        );
    }

    public static function handle_preview() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        check_admin_referer('seo_editor_csv_preview');
        $rows = self::parse_upload($_FILES['editor_csv'] ?? array());
        if (is_wp_error($rows)) self::redirect(array('editor_error'=>$rows->get_error_message()));
        if (!$rows) self::redirect(array('editor_error'=>'El CSV está vacío.'));
        $token = bin2hex(random_bytes(10));
        set_transient(self::token_key($token), $rows, 10 * MINUTE_IN_SECONDS);
        self::redirect(array('preview_token'=>$token));
    }

    private static function update_existing($prepared) {
        $post = $prepared['original'];
        if (!$post instanceof WP_Post) return new WP_Error('editor_missing', 'La entrada ya no existe.');
        if (get_post_status($post->ID) !== 'draft') return new WP_Error('editor_changed', 'El borrador cambió de estado.');
        $old_cats = SEO_Editor_Core::category_ids($post);
        $cat_api = $prepared['kind'] === 'post'
            ? 'seo_post_editor_replace_product_cat_relations'
            : 'seo_page_editor_replace_landing_categories';
        if (!function_exists($cat_api)) return new WP_Error('editor_api', 'No está disponible la API de relaciones comerciales.');
        // Las relaciones se validan antes y se intentan restaurar si falla el guardado.
        $relation = call_user_func($cat_api, $post->ID, $prepared['categories']);
        if (is_wp_error($relation) || $relation === false) {
            return is_wp_error($relation) ? $relation : new WP_Error('editor_relation', 'No se pudieron actualizar las categorías.');
        }
        $updated = wp_update_post(wp_slash(array(
            'ID'=>$post->ID,
            'post_title'=>$prepared['title'],
            'post_excerpt'=>$prepared['excerpt'],
            'post_content'=>$prepared['content'],
        )), true);
        if (is_wp_error($updated) || !absint($updated)) {
            call_user_func($cat_api, $post->ID, $old_cats);
            return is_wp_error($updated) ? $updated : new WP_Error('editor_update', 'No se pudo guardar el contenido.');
        }
        update_post_meta($post->ID,'_seo_editor_notes',$prepared['note']);
        return $post->ID;
    }

    public static function handle_apply() {
        if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        $token = sanitize_text_field(wp_unslash($_POST['preview_token'] ?? ''));
        check_admin_referer('seo_editor_csv_apply_' . $token);
        if (empty($_POST['confirm_csv'])) self::redirect(array('editor_error'=>'Confirma expresamente la importación.'));
        $rows = self::preview($token);
        if (!$rows) self::redirect(array('editor_error'=>'La vista previa ha caducado. Vuelve a subir el CSV.'));
        // Todo o nada en la validación: no aplicar un fichero con filas inválidas.
        $prepared = array();
        foreach ($rows as $row) {
            $item = self::review_row($row);
            if (!$item['valid']) {
                self::redirect(array('preview_token'=>$token,'editor_error'=>'La importación contiene errores. No se ha aplicado ningún cambio.'));
            }
            $prepared[] = $item;
        }

        $created = 0; $updated = 0; $errors = array();
        foreach ($prepared as $i=>$item) {
            if ($item['id']) {
                if (!$item['diff']) continue;
                $out = self::update_existing($item);
                if (is_wp_error($out)) $errors[] = 'Fila '.($i+2).': '.$out->get_error_message();
                else $updated++;
            } else {
                $row = array(
                    'tipo'=>$item['kind'], 'seccion'=>$item['section'],
                    'titulo'=>$item['title'], 'extracto'=>$item['excerpt'],
                    'contenido'=>$item['content'], 'categoria_ids'=>implode(',',$item['categories']),
                );
                $out = SEO_Editor_Core::create_external($row);
                if (is_wp_error($out)) $errors[] = 'Fila '.($i+2).': '.$out->get_error_message();
                else {
                    update_post_meta(absint($out),'_seo_editor_notes',$item['note']);
                    $created++;
                }
            }
        }
        delete_transient(self::token_key($token));
        self::redirect(array(
            'editor_created'=>$created, 'editor_updated'=>$updated, 'editor_failures'=>count($errors),
            'editor_error'=>$errors ? implode(' | ',array_slice($errors,0,2)) : '',
        ));
    }
}

SEO_Editor_Exchange::init();
