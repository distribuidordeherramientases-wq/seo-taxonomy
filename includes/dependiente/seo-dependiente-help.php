<?php

defined('ABSPATH') || exit;

/**
 * Escalado a asistencia humana para Dependiente.
 *
 * El correo del cliente se guarda separado del log anonimo de busquedas. El
 * contexto de navegacion se reconstruye a partir del search_uuid y del hash de
 * sesion ya existente, sin almacenar IP ni convertir el correo en identificador
 * de aprendizaje.
 */
final class SEO_Dependiente_Help {
    const TABLE_VERSION = '2026-08-31.1';
    const RATE_LIMIT = 3;
    const RATE_WINDOW = HOUR_IN_SECONDS;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_help_requests';
    }

    public static function install() {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_uuid CHAR(36) NOT NULL,
            search_uuid CHAR(36) NULL,
            session_hash CHAR(64) NULL,
            customer_email VARCHAR(254) NOT NULL,
            customer_note TEXT NULL,
            query_snapshot VARCHAR(500) NULL,
            page_url TEXT NULL,
            context_json LONGTEXT NULL,
            mail_to VARCHAR(254) NOT NULL,
            mail_sent TINYINT(1) NOT NULL DEFAULT 0,
            mail_error VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY request_uuid (request_uuid),
            KEY idx_search_uuid (search_uuid),
            KEY idx_session_created (session_hash, created_at),
            KEY idx_status_created (status, created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('seo_dependiente_help_table_version', self::TABLE_VERSION, false);
    }

    public static function table_exists() {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::table_exists(self::table());
        }
        global $wpdb;
        $table = self::table();
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    public static function ensure_ready() {
        $version = (string) get_option('seo_dependiente_help_table_version', '');
        if (!self::table_exists() || self::TABLE_VERSION !== $version) {
            self::install();
        }
        return self::table_exists();
    }

    /**
     * Crea la solicitud, envia el correo y conserva una copia durable del
     * contexto por si el equipo necesita auditar el caso despues.
     */
    public static function submit($data) {
        global $wpdb;

        // Honeypot: los navegadores normales nunca rellenan este campo.
        if ('' !== trim((string) ($data['website'] ?? ''))) {
            return array(
                'ok'         => true,
                'request_id' => '',
                'message'    => 'Solicitud recibida.',
            );
        }

        if (!self::ensure_ready()) {
            return new WP_Error('seo_dependiente_help_unavailable', 'La asistencia no esta disponible en este momento.', array('status' => 503));
        }

        $email = sanitize_email((string) ($data['email'] ?? ''));
        if (!$email || !is_email($email)) {
            return new WP_Error('seo_dependiente_help_email', 'Escribe un correo valido para poder responderte.', array('status' => 400));
        }

        $note = sanitize_textarea_field((string) ($data['note'] ?? ''));
        $note = self::substr($note, 1500);
        $query = sanitize_text_field((string) ($data['query'] ?? ''));
        $query = self::substr($query, 500);
        $mode = sanitize_key((string) ($data['mode'] ?? 'need')) ?: 'need';
        $context_label = sanitize_text_field((string) ($data['context_label'] ?? ''));
        $context_label = self::substr($context_label, 255);
        $page_url = esc_url_raw((string) ($data['page_url'] ?? ''));
        $page_url = self::substr($page_url, 1500);
        $search_uuid = sanitize_text_field((string) ($data['search_id'] ?? ''));
        $search_uuid = preg_match('/^[a-f0-9-]{36}$/i', $search_uuid) ? $search_uuid : '';

        $search = null;
        $browser_session_hash = self::current_session_hash();
        $session_hash = $browser_session_hash;
        $route_context = array('steps' => array());
        if (class_exists('SEO_Dependiente_Search_Log')) {
            if ($search_uuid) {
                $search = SEO_Dependiente_Search_Log::get_search($search_uuid);
                // Si podemos identificar la sesion anonima actual, no aceptamos
                // un search_uuid perteneciente a otra sesion. El UUID no expone
                // datos al cliente, pero esta comprobacion mantiene el contexto aislado.
                if ($search && $browser_session_hash && !empty($search['session_hash'])
                    && !hash_equals((string) $search['session_hash'], $browser_session_hash)) {
                    $search = null;
                    $search_uuid = '';
                }
            }

            // Si el ultimo gesto del cliente no genero search_id (por ejemplo,
            // navegacion visual), recuperamos su busqueda anonima mas reciente.
            if (!$search && $browser_session_hash && SEO_Dependiente_Search_Log::table_exists()) {
                $latest_uuid = (string) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT search_uuid FROM ' . SEO_Dependiente_Search_Log::table()
                        . ' WHERE session_hash = %s AND created_at >= DATE_SUB(%s, INTERVAL 2 HOUR)'
                        . ' ORDER BY id DESC LIMIT 1',
                        $browser_session_hash,
                        current_time('mysql')
                    )
                );
                if ($latest_uuid) {
                    $search_uuid = $latest_uuid;
                    $search = SEO_Dependiente_Search_Log::get_search($search_uuid);
                }
            }

            if ($search) {
                $session_hash = (string) ($search['session_hash'] ?? $browser_session_hash);
                if (method_exists('SEO_Dependiente_Search_Log', 'route_context')) {
                    $route_context = SEO_Dependiente_Search_Log::route_context($search_uuid, 12);
                }
                if (!$query) {
                    $query = self::substr(sanitize_text_field((string) ($search['query_original'] ?? '')), 500);
                }
            }
        }

        if (!self::rate_limit_allowed($email, $session_hash)) {
            return new WP_Error('seo_dependiente_help_rate_limit', 'Has enviado varias solicitudes seguidas. Espera un poco antes de volver a intentarlo.', array('status' => 429));
        }

        $recipient = self::recipient();
        if (!$recipient) {
            return new WP_Error('seo_dependiente_help_recipient', 'No hay un correo de asistencia configurado.', array('status' => 503));
        }

        $frontend_context = array(
            'mode'          => $mode,
            'context_label' => $context_label,
            'filters'       => self::sanitize_context_value($data['filters'] ?? array(), 0),
            'semantic_hint' => self::sanitize_context_value($data['semantic_hint'] ?? array(), 0),
            'orderby'       => sanitize_key((string) ($data['orderby'] ?? 'relevance')),
            'compare_ids'   => array_values(array_slice(array_unique(array_filter(array_map('absint', (array) ($data['compare_ids'] ?? array())))), 0, 4)),
        );

        $context = array(
            'query_snapshot' => $query,
            'page_url'       => $page_url,
            'frontend'       => $frontend_context,
            'route'          => is_array($route_context) ? $route_context : array('steps' => array()),
        );

        $request_uuid = wp_generate_uuid4();
        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'request_uuid'   => $request_uuid,
                'search_uuid'    => $search_uuid ?: null,
                'session_hash'   => $session_hash ?: null,
                'customer_email' => $email,
                'customer_note'  => $note ?: null,
                'query_snapshot' => $query ?: null,
                'page_url'       => $page_url ?: null,
                'context_json'   => wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mail_to'        => $recipient,
                'mail_sent'      => 0,
                'status'         => 'sending',
                'created_at'     => $now,
                'updated_at'     => $now,
            )
        );
        if (false === $inserted) {
            return new WP_Error('seo_dependiente_help_store', 'No se ha podido guardar la solicitud.', array('status' => 500));
        }

        self::consume_rate_limit($email, $session_hash);

        $subject_query = $query ?: 'consulta sin resultados';
        $subject = '[Dependiente] Ayuda solicitada: ' . self::substr($subject_query, 90);
        $body = self::format_email($request_uuid, $email, $note, $query, $page_url, $context);
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $email,
        );

        $sent = (bool) wp_mail($recipient, $subject, $body, $headers);
        $wpdb->update(
            self::table(),
            array(
                'mail_sent'  => $sent ? 1 : 0,
                'mail_error' => $sent ? null : 'wp_mail_returned_false',
                'status'     => $sent ? 'new' : 'mail_failed',
                'updated_at' => current_time('mysql'),
            ),
            array('request_uuid' => $request_uuid)
        );

        if ($search_uuid && class_exists('SEO_Dependiente_Search_Log')) {
            SEO_Dependiente_Search_Log::record_feedback($search_uuid, 'help_request', array(
                'request_uuid' => $request_uuid,
                'has_note'     => $note ? 1 : 0,
                'mail_sent'    => $sent ? 1 : 0,
            ));
        }

        if (!$sent) {
            return new WP_Error('seo_dependiente_help_mail', 'La solicitud se ha guardado, pero no he podido enviar el correo ahora. Intentalo de nuevo en unos minutos.', array('status' => 503));
        }

        return array(
            'ok'         => true,
            'request_id' => $request_uuid,
            'message'    => 'Solicitud enviada. Revisaremos esta busqueda con todo su contexto y te responderemos por correo.',
        );
    }

    private static function recipient() {
        $configured = class_exists('SEO_Dependiente_Plugin')
            ? sanitize_email((string) SEO_Dependiente_Plugin::option('help_email', ''))
            : '';
        if ($configured && is_email($configured)) {
            return $configured;
        }
        $admin = sanitize_email((string) get_option('admin_email', ''));
        return ($admin && is_email($admin)) ? $admin : '';
    }

    private static function current_session_hash() {
        $session_id = '';
        if (!empty($_COOKIE['seo_dependiente_sid'])) {
            $session_id = sanitize_text_field(wp_unslash((string) $_COOKIE['seo_dependiente_sid']));
        } elseif (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $wc_customer_id = (string) WC()->session->get_customer_id();
            if ('' !== $wc_customer_id) {
                $session_id = 'wc:' . $wc_customer_id;
            }
        }
        $session_id = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $session_id);
        if (!$session_id) {
            return '';
        }
        $session_id = self::substr($session_id, 100);
        return hash_hmac('sha256', $session_id, wp_salt('nonce'));
    }

    private static function rate_limit_key($email, $session_hash) {
        $identity = strtolower((string) $email) . '|' . (string) $session_hash;
        return 'seo_dep_help_' . substr(hash_hmac('sha256', $identity, wp_salt('nonce')), 0, 40);
    }

    private static function rate_limit_allowed($email, $session_hash) {
        $count = (int) get_transient(self::rate_limit_key($email, $session_hash));
        return $count < self::RATE_LIMIT;
    }

    private static function consume_rate_limit($email, $session_hash) {
        $key = self::rate_limit_key($email, $session_hash);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, self::RATE_WINDOW);
    }

    private static function format_email($request_uuid, $email, $note, $query, $page_url, $context) {
        $lines = array();
        $lines[] = 'SOLICITUD DE AYUDA - DEPENDIENTE';
        $lines[] = str_repeat('=', 42);
        $lines[] = 'Codigo: ' . $request_uuid;
        $lines[] = 'Fecha: ' . current_time('mysql');
        $lines[] = 'Correo cliente: ' . $email;
        $lines[] = 'Consulta actual: ' . ($query ?: '(sin consulta escrita)');
        if ($page_url) {
            $lines[] = 'Pagina: ' . $page_url;
        }
        if ($note) {
            $lines[] = '';
            $lines[] = 'DETALLE DEL CLIENTE';
            $lines[] = $note;
        }

        $frontend = is_array($context['frontend'] ?? null) ? $context['frontend'] : array();
        $lines[] = '';
        $lines[] = 'ESTADO DEL FRONTEND AL PEDIR AYUDA';
        $lines[] = '- Modo: ' . ((string) ($frontend['mode'] ?? '') ?: 'need');
        $lines[] = '- Contexto visual: ' . ((string) ($frontend['context_label'] ?? '') ?: '(ninguno)');
        $lines[] = '- Orden: ' . ((string) ($frontend['orderby'] ?? '') ?: 'relevance');
        $filters = $frontend['filters'] ?? array();
        if ($filters) {
            $lines[] = '- Filtros activos: ' . self::compact_json($filters);
        }
        $hint = $frontend['semantic_hint'] ?? array();
        if ($hint) {
            $lines[] = '- Aclaracion activa: ' . self::compact_json($hint);
        }
        if (!empty($frontend['compare_ids'])) {
            $lines[] = '- Productos en comparador: ' . implode(', ', array_map('absint', (array) $frontend['compare_ids']));
        }

        $route = is_array($context['route'] ?? null) ? $context['route'] : array();
        $steps = array_values((array) ($route['steps'] ?? array()));
        $lines[] = '';
        $lines[] = 'RUTA DE BUSQUEDA DE DEPENDIENTE (orden cronologico)';
        $lines[] = str_repeat('-', 52);
        if (!$steps) {
            $lines[] = 'No hay un search_id previo. El cliente pudo pedir ayuda antes de ejecutar una busqueda.';
        }

        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $lines[] = '';
            $lines[] = 'PASO ' . ($index + 1) . ' - ' . ((string) ($step['created_at'] ?? '') ?: 'sin fecha');
            $lines[] = 'Search ID: ' . (string) ($step['search_uuid'] ?? '');
            $lines[] = 'Consulta: ' . (string) ($step['query_original'] ?? '');
            $lines[] = 'Tipo / modo: ' . (string) ($step['request_kind'] ?? '') . ' / ' . (string) ($step['mode'] ?? '');

            $concepts = array();
            foreach (array('intent' => 'intencion', 'object' => 'objeto', 'context' => 'contexto', 'state' => 'estado') as $key => $label) {
                $value = trim((string) ($step[$key] ?? ''));
                if ($value) {
                    $concepts[] = $label . '=' . $value;
                }
            }
            $lines[] = 'Interpretacion: ' . ($concepts ? implode(' | ', $concepts) : '(sin conceptos firmes)');

            $unresolved = array_values((array) ($step['unresolved_terms'] ?? array()));
            if ($unresolved) {
                $lines[] = 'Terminos no resueltos: ' . implode(', ', array_slice(array_map('strval', $unresolved), 0, 12));
            }
            $lines[] = 'Estrategia: ' . (string) ($step['search_strategy'] ?? '')
                . ' | candidatos=' . absint($step['candidate_count'] ?? 0)
                . ' | resultados=' . absint($step['result_count'] ?? 0);

            $strategy_detail = is_array($step['strategy_detail'] ?? null) ? $step['strategy_detail'] : array();
            $request_context = is_array($strategy_detail['request_context'] ?? null) ? $strategy_detail['request_context'] : array();
            if ($request_context) {
                $lines[] = 'Peticion: pagina=' . absint($request_context['page'] ?? 1)
                    . ' | orden=' . ((string) ($request_context['orderby'] ?? '') ?: 'relevance');
                if (!empty($request_context['filters'])) {
                    $lines[] = 'Filtros en este paso: ' . self::compact_json($request_context['filters']);
                }
                if (!empty($request_context['semantic_hint'])) {
                    $lines[] = 'Pista semantica en este paso: ' . self::compact_json($request_context['semantic_hint']);
                }
            }

            $semantic = is_array($step['semantic'] ?? null) ? $step['semantic'] : array();
            $routes = array_values((array) ($semantic['routes'] ?? array()));
            if ($routes) {
                $lines[] = 'Rutas semanticas activadas:';
                foreach (array_slice($routes, 0, 12) as $route_item) {
                    if (!is_array($route_item)) {
                        continue;
                    }
                    $target = trim((string) ($route_item['target_group'] ?? '') . ':' . (string) ($route_item['target_slug'] ?? ''), ':');
                    $role = (string) ($route_item['result_role'] ?? '');
                    $weight = (int) ($route_item['weight'] ?? 0);
                    $lines[] = '  - ' . ($target ?: '(sin destino)') . ($role ? ' -> ' . $role : '') . ($weight ? ' [peso ' . $weight . ']' : '');
                }
            }

            $clarification = is_array($step['clarification'] ?? null) ? $step['clarification'] : array();
            if (!empty($clarification['question'])) {
                $lines[] = 'Aclaracion mostrada: ' . (string) $clarification['question'];
            }
            if (!empty($clarification['selected_value'])) {
                $lines[] = 'Aclaracion elegida: ' . trim((string) ($clarification['selected_role'] ?? '') . '=' . (string) $clarification['selected_value'], '=');
            }

            $interactions = array_values((array) ($step['interaction_events'] ?? array()));
            if ($interactions) {
                $lines[] = 'Interacciones:';
                foreach (array_slice($interactions, -10) as $event) {
                    if (!is_array($event)) {
                        continue;
                    }
                    $summary = (string) ($event['type'] ?? 'evento');
                    if (!empty($event['product_id'])) {
                        $summary .= ' producto=' . absint($event['product_id']);
                    }
                    if (!empty($event['role']) || !empty($event['value'])) {
                        $summary .= ' ' . trim((string) ($event['role'] ?? '') . '=' . (string) ($event['value'] ?? ''), '=');
                    }
                    $lines[] = '  - ' . $summary;
                }
            }

            $top_results = array_values((array) ($step['top_results'] ?? array()));
            if ($top_results) {
                $lines[] = 'Primeros resultados mostrados:';
                foreach (array_slice($top_results, 0, 5) as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    $product_id = absint($result['id'] ?? 0);
                    $result_line = '  - #' . absint($result['position'] ?? 0) . ' ' . (string) ($result['title'] ?? '');
                    if ($product_id) {
                        $result_line .= ' [ID ' . $product_id . ']';
                    }
                    if (isset($result['score'])) {
                        $result_line .= ' score=' . (string) $result['score'];
                    }
                    $lines[] = $result_line;
                    if ($product_id) {
                        $url = get_permalink($product_id);
                        if ($url) {
                            $lines[] = '    ' . $url;
                        }
                    }
                    $reasons = array_values((array) ($result['reasons'] ?? array()));
                    $categories = array_values((array) ($result['categories'] ?? array()));
                    if ($categories) {
                        $lines[] = '    categorias: ' . implode(' | ', array_slice(array_map('strval', $categories), 0, 4));
                    }
                    if ($reasons) {
                        $lines[] = '    motivos: ' . implode(' | ', array_slice(array_map('strval', $reasons), 0, 5));
                    }
                }
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = 'Responde directamente a este correo: Reply-To ya apunta al cliente.';
        $lines[] = 'El correo del cliente no se incorpora al log anonimo de aprendizaje; queda separado en la solicitud de asistencia.';

        return implode("\n", $lines);
    }

    private static function sanitize_context_value($value, $depth) {
        if ($depth > 4) {
            return null;
        }
        if (is_array($value)) {
            $out = array();
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 80) {
                    break;
                }
                $clean_key = is_string($key) ? sanitize_key($key) : $key;
                $out[$clean_key] = self::sanitize_context_value($item, $depth + 1);
                $count++;
            }
            return $out;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }
        return self::substr(sanitize_text_field((string) $value), 500);
    }

    private static function compact_json($value) {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::substr((string) $json, 2000);
    }

    private static function substr($value, $length) {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
