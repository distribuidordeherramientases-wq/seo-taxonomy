<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vista y lógica de administración para el motor de redirecciones SEO.
 *
 * Incluye:
 * - Normalización coherente de origen/destino.
 * - Prevención de duplicados y ciclos al guardar.
 * - KPIs de uso.
 * - Diagnóstico estructural y heurístico.
 * - Filtros y paginación.
 */

if (!function_exists('seo_redirects_admin_same_host')) {
    function seo_redirects_admin_same_host($host_a, $host_b) {
        $host_a = strtolower((string) $host_a);
        $host_b = strtolower((string) $host_b);

        $host_a = preg_replace('/^www\./', '', $host_a);
        $host_b = preg_replace('/^www\./', '', $host_b);

        return $host_a !== '' && $host_a === $host_b;
    }
}

if (!function_exists('seo_redirects_admin_normalize_origin')) {
    function seo_redirects_admin_normalize_origin($url, &$error = '') {
        $error = '';
        $url = trim(wp_unslash((string) $url));

        if ($url === '') {
            $error = 'La URL de origen está vacía.';
            return '';
        }

        // El motor runtime elimina la query antes de buscar la regla.
        if (strpos($url, '?') !== false) {
            $error = 'La URL de origen no puede contener query string porque el motor actual la elimina antes de buscar el redirect.';
            return '';
        }

        // Los fragmentos nunca llegan al servidor.
        if (strpos($url, '#') !== false) {
            $url = strtok($url, '#');
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        if (preg_match('#^https?://#i', $url)) {
            $host = wp_parse_url($url, PHP_URL_HOST);

            if (!seo_redirects_admin_same_host($host, $home_host)) {
                $error = 'La URL de origen debe pertenecer a este sitio.';
                return '';
            }

            $path = wp_parse_url($url, PHP_URL_PATH);
            $url = is_string($path) && $path !== '' ? $path : '/';
        }

        $url = preg_replace('#/+#', '/', $url);
        $url = '/' . ltrim($url, '/');

        // Formato canónico: sin slash final, excepto la raíz.
        if ($url !== '/') {
            $url = rtrim($url, '/');
        }

        return $url;
    }
}

if (!function_exists('seo_redirects_admin_normalize_target')) {
    function seo_redirects_admin_normalize_target($url, &$error = '') {
        $error = '';
        $url = trim(wp_unslash((string) $url));

        if ($url === '') {
            $error = 'La URL de destino está vacía.';
            return '';
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        // Destino absoluto.
        if (preg_match('#^https?://#i', $url)) {
            $host = wp_parse_url($url, PHP_URL_HOST);

            // Si es interno, lo guardamos como ruta relativa.
            if (seo_redirects_admin_same_host($host, $home_host)) {
                $path = wp_parse_url($url, PHP_URL_PATH);
                $query = wp_parse_url($url, PHP_URL_QUERY);
                $fragment = wp_parse_url($url, PHP_URL_FRAGMENT);

                $path = is_string($path) && $path !== '' ? $path : '/';
                $path = preg_replace('#/+#', '/', $path);
                $path = '/' . ltrim($path, '/');

                if ($path !== '/') {
                    $path = rtrim($path, '/');
                }

                if (is_string($query) && $query !== '') {
                    $path .= '?' . $query;
                }

                if (is_string($fragment) && $fragment !== '') {
                    $path .= '#' . $fragment;
                }

                return $path;
            }

            $sanitized = esc_url_raw($url, array('http', 'https'));

            if ($sanitized === '') {
                $error = 'La URL de destino externa no es válida.';
                return '';
            }

            return $sanitized;
        }

        // Destino interno relativo: conservamos query/fragment.
        $fragment = '';
        if (strpos($url, '#') !== false) {
            list($url, $fragment) = array_pad(explode('#', $url, 2), 2, '');
        }

        $query = '';
        if (strpos($url, '?') !== false) {
            list($url, $query) = array_pad(explode('?', $url, 2), 2, '');
        }

        $url = preg_replace('#/+#', '/', $url);
        $url = '/' . ltrim($url, '/');

        if ($url !== '/') {
            $url = rtrim($url, '/');
        }

        if ($query !== '') {
            $url .= '?' . $query;
        }

        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }

        return $url;
    }
}

if (!function_exists('seo_redirects_admin_effective_path')) {
    function seo_redirects_admin_effective_path($url) {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        if (preg_match('#^https?://#i', $url)) {
            $host = wp_parse_url($url, PHP_URL_HOST);

            if (!seo_redirects_admin_same_host($host, $home_host)) {
                return null;
            }

            $path = wp_parse_url($url, PHP_URL_PATH);
            $url = is_string($path) && $path !== '' ? $path : '/';
        } else {
            $url = strtok($url, '#');
            $url = strtok($url, '?');
        }

        $url = preg_replace('#/+#', '/', $url);
        $url = '/' . ltrim($url, '/');

        if ($url !== '/') {
            $url = rtrim($url, '/');
        }

        return $url;
    }
}

if (!function_exists('seo_redirects_admin_product_tokens')) {
    function seo_redirects_admin_product_tokens($path) {
        $path = (string) $path;

        if (strpos($path, '/producto/') !== 0) {
            return array();
        }

        $slug = trim(substr($path, strlen('/producto/')), '/');
        $slug = rawurldecode($slug);
        $slug = remove_accents($slug);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', ' ', $slug);

        $stop = array(
            'vevor', 'producto', 'para', 'con', 'sin', 'del', 'las', 'los',
            'una', 'uno', 'unos', 'unas', 'que', 'por', 'como', 'desde',
            'hasta', 'este', 'esta', 'estos', 'estas', 'kit', 'juego',
            'pieza', 'piezas', 'pcs', 'uds', 'unidad', 'unidades'
        );

        $tokens = preg_split('/\s+/', trim($slug));
        $result = array();

        foreach ($tokens as $token) {
            if ($token === '' || strlen($token) < 4) {
                continue;
            }

            if (in_array($token, $stop, true)) {
                continue;
            }

            // Ignorar tokens puramente numéricos.
            if (ctype_digit($token)) {
                continue;
            }

            $result[$token] = true;
        }

        return array_keys($result);
    }
}

if (!function_exists('seo_redirects_admin_semantic_similarity')) {
    function seo_redirects_admin_semantic_similarity($origin_path, $target_path) {
        $a = seo_redirects_admin_product_tokens($origin_path);
        $b = seo_redirects_admin_product_tokens($target_path);

        if (count($a) < 2 || count($b) < 2) {
            return null;
        }

        $intersection = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));

        if (empty($union)) {
            return null;
        }

        return array(
            'score' => count($intersection) / count($union),
            'shared' => count($intersection),
            'origin_tokens' => count($a),
            'target_tokens' => count($b),
        );
    }
}

if (!function_exists('seo_redirects_admin_existing_rows')) {
    function seo_redirects_admin_existing_rows($table_redirects, $wpdb) {
        return $wpdb->get_results("SELECT id, origin_url, target_url, status_code, hits, last_hit FROM {$table_redirects} ORDER BY id DESC");
    }
}

if (!function_exists('seo_redirects_admin_find_duplicate_origin')) {
    function seo_redirects_admin_find_duplicate_origin($rows, $origin_url, $exclude_id = 0) {
        $effective = seo_redirects_admin_effective_path($origin_url);

        if ($effective === null) {
            return null;
        }

        foreach ($rows as $row) {
            if ((int) $row->id === (int) $exclude_id) {
                continue;
            }

            if (seo_redirects_admin_effective_path($row->origin_url) === $effective) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists('seo_redirects_admin_would_create_cycle')) {
    function seo_redirects_admin_would_create_cycle($rows, $origin_url, $target_url, $exclude_id = 0) {
        $origin = seo_redirects_admin_effective_path($origin_url);
        $target = seo_redirects_admin_effective_path($target_url);

        if ($origin === null || $target === null) {
            return false;
        }

        $map = array();

        foreach ($rows as $row) {
            if ((int) $row->id === (int) $exclude_id) {
                continue;
            }

            $row_origin = seo_redirects_admin_effective_path($row->origin_url);
            $row_target = seo_redirects_admin_effective_path($row->target_url);

            if ($row_origin !== null && $row_target !== null && !isset($map[$row_origin])) {
                $map[$row_origin] = $row_target;
            }
        }

        $map[$origin] = $target;

        $seen = array();
        $current = $origin;
        $max = count($map) + 2;

        for ($i = 0; $i < $max; $i++) {
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;

            if (!isset($map[$current])) {
                return false;
            }

            $current = $map[$current];
        }

        return true;
    }
}

if (!function_exists('seo_redirects_admin_build_diagnostics')) {
    function seo_redirects_admin_build_diagnostics($rows) {
        $by_origin = array();
        $target_to_ids = array();
        $meta = array();
        $map = array();

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $origin = seo_redirects_admin_effective_path($row->origin_url);
            $target = seo_redirects_admin_effective_path($row->target_url);

            $meta[$id] = array(
                'origin' => $origin,
                'target' => $target,
            );

            if ($origin !== null) {
                if (!isset($by_origin[$origin])) {
                    $by_origin[$origin] = array();
                }
                $by_origin[$origin][] = $id;
            }

            if ($target !== null) {
                if (!isset($target_to_ids[$target])) {
                    $target_to_ids[$target] = array();
                }
                $target_to_ids[$target][] = $id;
            }
        }

        // Mapa solo para orígenes no ambiguos.
        foreach ($by_origin as $origin => $ids) {
            if (count($ids) !== 1) {
                continue;
            }

            $id = $ids[0];
            if ($meta[$id]['target'] !== null) {
                $map[$origin] = $meta[$id]['target'];
            }
        }

        // Detectar nodos que forman parte de ciclos.
        $cycle_nodes = array();
        $globally_done = array();

        foreach (array_keys($map) as $start) {
            if (isset($globally_done[$start])) {
                continue;
            }

            $path = array();
            $positions = array();
            $current = $start;

            while (isset($map[$current])) {
                if (isset($positions[$current])) {
                    $cycle_start = $positions[$current];
                    $cycle_slice = array_slice($path, $cycle_start);

                    foreach ($cycle_slice as $node) {
                        $cycle_nodes[$node] = true;
                    }
                    break;
                }

                if (isset($globally_done[$current])) {
                    break;
                }

                $positions[$current] = count($path);
                $path[] = $current;
                $current = $map[$current];
            }

            foreach ($path as $node) {
                $globally_done[$node] = true;
            }
        }

        $diagnostics = array();
        $stats = array(
            'total' => count($rows),
            'with_hits' => 0,
            'without_hits' => 0,
            'hits_total' => 0,
            'last_24h' => 0,
            'last_7d' => 0,
            'last_30d' => 0,
            'structural' => 0,
            'suspicious' => 0,
            'chains' => 0,
            'cycles' => 0,
            'duplicates' => 0,
            'external_targets' => 0,
        );

        $now = current_time('timestamp');
        $tz = wp_timezone();

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $origin = $meta[$id]['origin'];
            $target = $meta[$id]['target'];
            $issues = array();
            $severity = 'ok';
            $structural = false;
            $suspicious = false;
            $chain_hops = 0;
            $in_cycle = false;

            $hits = (int) $row->hits;
            $stats['hits_total'] += $hits;

            if ($hits > 0) {
                $stats['with_hits']++;
            } else {
                $stats['without_hits']++;
            }

            if (!empty($row->last_hit)) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row->last_hit, $tz);

                if ($dt instanceof DateTimeImmutable) {
                    $age = $now - $dt->getTimestamp();

                    if ($age >= 0 && $age <= DAY_IN_SECONDS) {
                        $stats['last_24h']++;
                    }
                    if ($age >= 0 && $age <= 7 * DAY_IN_SECONDS) {
                        $stats['last_7d']++;
                    }
                    if ($age >= 0 && $age <= 30 * DAY_IN_SECONDS) {
                        $stats['last_30d']++;
                    }
                }
            }

            if (preg_match('#^https?://#i', (string) $row->origin_url)) {
                $issues[] = array('level' => 'error', 'text' => 'Origen absoluto: el motor trabaja con REQUEST_URI.');
                $structural = true;
            }

            if (strpos((string) $row->origin_url, '?') !== false) {
                $issues[] = array('level' => 'error', 'text' => 'Query en origen: el motor actual elimina la query antes del lookup.');
                $structural = true;
            }

            if ($origin === null) {
                $issues[] = array('level' => 'error', 'text' => 'Origen no interpretable como ruta interna.');
                $structural = true;
            }

            if ($origin !== null && isset($by_origin[$origin]) && count($by_origin[$origin]) > 1) {
                $issues[] = array('level' => 'error', 'text' => 'Origen efectivo duplicado en ' . count($by_origin[$origin]) . ' reglas.');
                $structural = true;
                $stats['duplicates']++;
            }

            if ($origin !== null && $target !== null && $origin === $target) {
                $issues[] = array('level' => 'error', 'text' => 'Autoredirección: origen y destino efectivos son iguales.');
                $structural = true;
            }

            if (!in_array((int) $row->status_code, array(301, 302), true)) {
                $issues[] = array('level' => 'warning', 'text' => 'Código HTTP fuera de los tipos gestionados por esta pantalla.');
            }

            if (preg_match('#^https?://#i', (string) $row->target_url)) {
                $target_host = wp_parse_url($row->target_url, PHP_URL_HOST);
                $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

                if (seo_redirects_admin_same_host($target_host, $home_host)) {
                    $issues[] = array('level' => 'warning', 'text' => 'Destino interno absoluto: conviene guardarlo como ruta relativa.');
                } else {
                    $stats['external_targets']++;
                    $issues[] = array('level' => 'info', 'text' => 'Destino externo.');
                }
            }

            if ($origin !== null && isset($cycle_nodes[$origin])) {
                $in_cycle = true;
                $structural = true;
                $issues[] = array('level' => 'error', 'text' => 'La regla forma parte de un ciclo de redirección.');
            }

            // Longitud de cadena desde este origen.
            if ($origin !== null && isset($map[$origin]) && !$in_cycle) {
                $visited = array();
                $current = $origin;

                while (isset($map[$current]) && !isset($visited[$current]) && $chain_hops < 20) {
                    $visited[$current] = true;
                    $chain_hops++;
                    $current = $map[$current];
                }

                if ($chain_hops > 1) {
                    $issues[] = array('level' => 'warning', 'text' => 'Cadena de ' . $chain_hops . ' saltos. Conviene apuntar directamente al destino final.');
                }
            }

            // Heurística semántica solo entre productos.
            if ($origin !== null && $target !== null) {
                $similarity = seo_redirects_admin_semantic_similarity($origin, $target);

                $low_similarity = false;

                if (is_array($similarity)
                    && ($similarity['shared'] === 0 || $similarity['score'] < 0.12)) {
                    $low_similarity = true;
                    $suspicious = true;

                    $issues[] = array(
                        'level' => 'warning',
                        'text' => 'Destino sospechoso (heurístico): afinidad muy baja entre los términos del producto de origen y destino.'
                    );
                }

                // Muchos orígenes hacia un mismo producto solo se señalan cuando
                // además la afinidad semántica de esta regla es muy baja.
                if ($low_similarity
                    && strpos($target, '/producto/') === 0
                    && isset($target_to_ids[$target])
                    && count($target_to_ids[$target]) >= 5) {
                    $issues[] = array(
                        'level' => 'warning',
                        'text' => 'Patrón masivo sospechoso: ' . count($target_to_ids[$target]) . ' reglas apuntan al mismo producto.'
                    );
                }
            }

            if ($hits === 0) {
                $issues[] = array('level' => 'info', 'text' => 'Sin actividad registrada.');
            }

            if ($structural) {
                $stats['structural']++;
            }
            if ($suspicious) {
                $stats['suspicious']++;
            }
            if ($chain_hops > 1) {
                $stats['chains']++;
            }
            if ($in_cycle) {
                $stats['cycles']++;
            }

            foreach ($issues as $issue) {
                if ($issue['level'] === 'error') {
                    $severity = 'error';
                    break;
                }
                if ($issue['level'] === 'warning' && $severity !== 'error') {
                    $severity = 'warning';
                }
                if ($issue['level'] === 'info' && $severity === 'ok') {
                    $severity = 'info';
                }
            }

            $diagnostics[$id] = array(
                'issues' => $issues,
                'severity' => $severity,
                'structural' => $structural,
                'suspicious' => $suspicious,
                'chain_hops' => $chain_hops,
                'cycle' => $in_cycle,
                'origin_effective' => $origin,
                'target_effective' => $target,
            );
        }

        return array(
            'diagnostics' => $diagnostics,
            'stats' => $stats,
        );
    }
}

if (!function_exists('seo_redirects_admin_issue_badge')) {
    function seo_redirects_admin_issue_badge($diagnostic) {
        if (!empty($diagnostic['cycle'])) {
            return array('Ciclo', 'seo-rd-badge-error');
        }

        if (!empty($diagnostic['structural'])) {
            return array('Incorrecto', 'seo-rd-badge-error');
        }

        if (!empty($diagnostic['suspicious'])) {
            return array('Sospechoso', 'seo-rd-badge-warning');
        }

        if (!empty($diagnostic['chain_hops']) && $diagnostic['chain_hops'] > 1) {
            return array('Cadena', 'seo-rd-badge-warning');
        }

        return array('Correcto', 'seo-rd-badge-ok');
    }
}

function seo_menu_manager_redirects_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para gestionar redirecciones.', 'seo-menu-manager'));
    }

    global $wpdb;
    $table_redirects = $wpdb->prefix . 'seo_redirects';

    $message = '';
    $message_class = 'updated';

    $existing_rows = seo_redirects_admin_existing_rows($table_redirects, $wpdb);

    // =========================
    // ACCIONES POST
    // =========================
    if (isset($_POST['action_seo_redirect'])) {
        $action = sanitize_key(wp_unslash($_POST['action_seo_redirect']));

        // =========================
        // ADD
        // =========================
        if ($action === 'add' && check_admin_referer('seo_add_redirect_nonce')) {
            $origin_error = '';
            $target_error = '';

            $origin_url = seo_redirects_admin_normalize_origin($_POST['origin_url'] ?? '', $origin_error);
            $target_url = seo_redirects_admin_normalize_target($_POST['target_url'] ?? '', $target_error);
            $status_code = (int) ($_POST['status_code'] ?? 301);

            if (!in_array($status_code, array(301, 302), true)) {
                $status_code = 301;
            }

            if ($origin_error !== '' || $target_error !== '') {
                $message = trim($origin_error . ' ' . $target_error);
                $message_class = 'error';
            } elseif ($origin_url === '' || $target_url === '') {
                $message = __('Por favor, rellena tanto la URL de origen como la de destino.', 'seo-menu-manager');
                $message_class = 'error';
            } elseif (seo_redirects_admin_effective_path($origin_url) === seo_redirects_admin_effective_path($target_url)) {
                $message = __('No se puede crear una redirección hacia la misma URL.', 'seo-menu-manager');
                $message_class = 'error';
            } elseif (($duplicate = seo_redirects_admin_find_duplicate_origin($existing_rows, $origin_url)) !== null) {
                $message = sprintf(
                    __('Ya existe una redirección con el mismo origen efectivo (ID %d). Edítala en lugar de crear otra.', 'seo-menu-manager'),
                    (int) $duplicate->id
                );
                $message_class = 'error';
            } elseif (seo_redirects_admin_would_create_cycle($existing_rows, $origin_url, $target_url)) {
                $message = __('La nueva regla crearía un ciclo de redirección. No se ha guardado.', 'seo-menu-manager');
                $message_class = 'error';
            } else {
                $inserted = $wpdb->insert(
                    $table_redirects,
                    array(
                        'origin_url'  => $origin_url,
                        'target_url'  => $target_url,
                        'status_code' => $status_code,
                        'hits'        => 0,
                        'last_hit'    => null,
                    ),
                    array('%s', '%s', '%d', '%d', '%s')
                );

                if ($inserted === false) {
                    $message = __('Error al insertar la redirección.', 'seo-menu-manager');
                    $message_class = 'error';
                } else {
                    $message = __('Redirección añadida correctamente.', 'seo-menu-manager');
                    $existing_rows = seo_redirects_admin_existing_rows($table_redirects, $wpdb);
                }
            }
        }

        // =========================
        // UPDATE
        // =========================
        if ($action === 'update' && isset($_POST['redirect_id'])) {
            $id_to_update = absint($_POST['redirect_id']);

            if (check_admin_referer(
                'seo_update_redirect_nonce_' . $id_to_update,
                'seo_update_redirect_nonce_' . $id_to_update
            )) {
                $origin_error = '';
                $target_error = '';

                $origin_url = seo_redirects_admin_normalize_origin($_POST['origin_url'] ?? '', $origin_error);
                $target_url = seo_redirects_admin_normalize_target($_POST['target_url'] ?? '', $target_error);
                $status_code = (int) ($_POST['status_code'] ?? 301);

                if (!in_array($status_code, array(301, 302), true)) {
                    $status_code = 301;
                }

                if ($origin_error !== '' || $target_error !== '') {
                    $message = trim($origin_error . ' ' . $target_error);
                    $message_class = 'error';
                } elseif ($origin_url === '' || $target_url === '') {
                    $message = __('Origen y destino son obligatorios.', 'seo-menu-manager');
                    $message_class = 'error';
                } elseif (seo_redirects_admin_effective_path($origin_url) === seo_redirects_admin_effective_path($target_url)) {
                    $message = __('No se puede redirigir una URL hacia sí misma.', 'seo-menu-manager');
                    $message_class = 'error';
                } elseif (($duplicate = seo_redirects_admin_find_duplicate_origin($existing_rows, $origin_url, $id_to_update)) !== null) {
                    $message = sprintf(
                        __('El origen entra en conflicto con la redirección ID %d.', 'seo-menu-manager'),
                        (int) $duplicate->id
                    );
                    $message_class = 'error';
                } elseif (seo_redirects_admin_would_create_cycle($existing_rows, $origin_url, $target_url, $id_to_update)) {
                    $message = __('El cambio crearía un ciclo de redirección. No se ha guardado.', 'seo-menu-manager');
                    $message_class = 'error';
                } else {
                    $updated = $wpdb->update(
                        $table_redirects,
                        array(
                            'origin_url'  => $origin_url,
                            'target_url'  => $target_url,
                            'status_code' => $status_code,
                        ),
                        array('id' => $id_to_update),
                        array('%s', '%s', '%d'),
                        array('%d')
                    );

                    if ($updated === false) {
                        $message = __('Error al actualizar la redirección.', 'seo-menu-manager');
                        $message_class = 'error';
                    } else {
                        $message = __('Redirección actualizada correctamente.', 'seo-menu-manager');
                        $existing_rows = seo_redirects_admin_existing_rows($table_redirects, $wpdb);
                    }
                }
            }
        }
    }

    // =========================
    // DELETE
    // =========================
    if (isset($_GET['action'], $_GET['id']) && sanitize_key(wp_unslash($_GET['action'])) === 'delete') {
        $id_to_delete = absint($_GET['id']);

        if (check_admin_referer('seo_delete_redirect_nonce_' . $id_to_delete)) {
            $wpdb->delete($table_redirects, array('id' => $id_to_delete), array('%d'));
            $message = __('Redirección eliminada correctamente.', 'seo-menu-manager');
            $existing_rows = seo_redirects_admin_existing_rows($table_redirects, $wpdb);
        }
    }

    // =========================
    // DIAGNÓSTICO + KPIs
    // =========================
    $analysis = seo_redirects_admin_build_diagnostics($existing_rows);
    $diagnostics = $analysis['diagnostics'];
    $stats = $analysis['stats'];

    $filter = isset($_GET['redirect_filter']) ? sanitize_key(wp_unslash($_GET['redirect_filter'])) : 'all';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    $allowed_filters = array(
        'all',
        'active',
        'no_hits',
        'recent_7',
        'structural',
        'suspicious',
        'chains',
        'cycles',
    );

    if (!in_array($filter, $allowed_filters, true)) {
        $filter = 'all';
    }

    $filtered_rows = array();

    foreach ($existing_rows as $row) {
        $id = (int) $row->id;
        $diag = $diagnostics[$id];

        $match = true;

        if ($filter === 'active') {
            $match = ((int) $row->hits > 0);
        } elseif ($filter === 'no_hits') {
            $match = ((int) $row->hits === 0);
        } elseif ($filter === 'recent_7') {
            $match = false;

            if (!empty($row->last_hit)) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row->last_hit, wp_timezone());
                if ($dt instanceof DateTimeImmutable) {
                    $age = current_time('timestamp') - $dt->getTimestamp();
                    $match = ($age >= 0 && $age <= 7 * DAY_IN_SECONDS);
                }
            }
        } elseif ($filter === 'structural') {
            $match = !empty($diag['structural']);
        } elseif ($filter === 'suspicious') {
            $match = !empty($diag['suspicious']);
        } elseif ($filter === 'chains') {
            $match = !empty($diag['chain_hops']) && $diag['chain_hops'] > 1;
        } elseif ($filter === 'cycles') {
            $match = !empty($diag['cycle']);
        }

        if ($match && $search !== '') {
            $haystack = strtolower(
                (string) $row->id . ' ' .
                (string) $row->origin_url . ' ' .
                (string) $row->target_url
            );
            $match = (strpos($haystack, strtolower($search)) !== false);
        }

        if ($match) {
            $filtered_rows[] = $row;
        }
    }

    // Priorizar problemas con tráfico en el bloque de revisión.
    $review_rows = array_filter($existing_rows, function ($row) use ($diagnostics) {
        $diag = $diagnostics[(int) $row->id];
        return !empty($diag['structural']) || !empty($diag['suspicious']) || (!empty($diag['chain_hops']) && $diag['chain_hops'] > 1);
    });

    usort($review_rows, function ($a, $b) use ($diagnostics) {
        $da = $diagnostics[(int) $a->id];
        $db = $diagnostics[(int) $b->id];

        $score_a = (!empty($da['structural']) ? 1000 : 0)
            + (!empty($da['suspicious']) ? 500 : 0)
            + ((int) $a->hits * 10);

        $score_b = (!empty($db['structural']) ? 1000 : 0)
            + (!empty($db['suspicious']) ? 500 : 0)
            + ((int) $b->hits * 10);

        return $score_b <=> $score_a;
    });

    $review_rows = array_slice($review_rows, 0, 12);

    // Paginación.
    $per_page = 100;
    $current_page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
    $total_filtered = count($filtered_rows);
    $total_pages = max(1, (int) ceil($total_filtered / $per_page));

    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }

    $paged_rows = array_slice($filtered_rows, ($current_page - 1) * $per_page, $per_page);

    $filter_counts = array(
        'all' => $stats['total'],
        'active' => $stats['with_hits'],
        'no_hits' => $stats['without_hits'],
        'recent_7' => $stats['last_7d'],
        'structural' => $stats['structural'],
        'suspicious' => $stats['suspicious'],
        'chains' => $stats['chains'],
        'cycles' => $stats['cycles'],
    );

    $filters_ui = array(
        'all' => 'Todos',
        'active' => 'Con actividad',
        'no_hits' => 'Sin uso',
        'recent_7' => 'Usados 7 días',
        'structural' => 'Incorrectos',
        'suspicious' => 'Sospechosos',
        'chains' => 'Cadenas',
        'cycles' => 'Ciclos',
    );

    ?>
    <div class="wrap seo-rd-wrap">
        <h1 class="wp-heading-inline">Gestor de Redirecciones SEO</h1>
        <hr class="wp-header-end">

        <style>
            .seo-rd-kpis {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
                gap: 10px;
                margin: 18px 0;
            }
            .seo-rd-kpi {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 14px;
                min-height: 74px;
                box-sizing: border-box;
            }
            .seo-rd-kpi strong {
                display: block;
                font-size: 24px;
                line-height: 1.1;
                margin-bottom: 5px;
            }
            .seo-rd-kpi span {
                color: #646970;
                font-size: 12px;
            }
            .seo-rd-kpi.is-warning {
                border-left: 4px solid #dba617;
            }
            .seo-rd-kpi.is-error {
                border-left: 4px solid #d63638;
            }
            .seo-rd-kpi.is-ok {
                border-left: 4px solid #00a32a;
            }
            .seo-rd-grid {
                display: grid;
                grid-template-columns: minmax(260px, 30%) 1fr;
                gap: 16px;
                align-items: start;
                margin-top: 16px;
            }
            .seo-rd-card {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 16px;
            }
            .seo-rd-card h2 {
                margin-top: 0;
            }
            .seo-rd-review {
                margin: 12px 0 18px;
                background: #fff;
                border: 1px solid #dcdcde;
            }
            .seo-rd-review h2 {
                padding: 12px 14px;
                margin: 0;
                border-bottom: 1px solid #dcdcde;
            }
            .seo-rd-review-item {
                padding: 10px 14px;
                border-bottom: 1px solid #f0f0f1;
                display: grid;
                grid-template-columns: 70px 1fr 90px;
                gap: 10px;
                align-items: start;
            }
            .seo-rd-review-item:last-child {
                border-bottom: 0;
            }
            .seo-rd-review-item code {
                word-break: break-word;
            }
            .seo-rd-badge {
                display: inline-block;
                padding: 2px 7px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                white-space: nowrap;
            }
            .seo-rd-badge-ok {
                background: #edfaef;
                color: #006b1b;
            }
            .seo-rd-badge-warning {
                background: #fff8e5;
                color: #7a4d00;
            }
            .seo-rd-badge-error {
                background: #fcf0f1;
                color: #b32d2e;
            }
            .seo-rd-badge-info {
                background: #f0f6fc;
                color: #135e96;
            }
            .seo-rd-diag {
                margin-top: 5px;
                font-size: 11px;
                line-height: 1.35;
            }
            .seo-rd-diag div {
                margin-bottom: 3px;
            }
            .seo-rd-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin: 0 0 12px;
            }
            .seo-rd-filters a {
                text-decoration: none;
            }
            .seo-rd-search {
                display: flex;
                gap: 6px;
                margin-bottom: 12px;
            }
            .seo-rd-table code {
                display: block;
                white-space: normal;
                word-break: break-word;
                font-size: 11px;
                line-height: 1.35;
            }
            .seo-rd-table .column-id {
                width: 60px;
            }
            .seo-rd-table .column-type {
                width: 58px;
                text-align: center;
            }
            .seo-rd-table .column-hits {
                width: 70px;
                text-align: center;
            }
            .seo-rd-table .column-last {
                width: 120px;
            }
            .seo-rd-table .column-status {
                width: 145px;
            }
            .seo-rd-table .column-actions {
                width: 150px;
                text-align: right;
            }
            .seo-rd-row-error {
                background: #fff7f7;
            }
            .seo-rd-row-warning {
                background: #fffdf5;
            }
            .seo-rd-actions {
                display: flex;
                gap: 6px;
                justify-content: flex-end;
                flex-wrap: wrap;
            }
            @media (max-width: 1100px) {
                .seo-rd-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <?php if (!empty($message)): ?>
            <div class="<?php echo esc_attr($message_class); ?> notice is-dismissible">
                <p><strong><?php echo esc_html($message); ?></strong></p>
            </div>
        <?php endif; ?>

        <div class="seo-rd-kpis">
            <div class="seo-rd-kpi is-ok">
                <strong><?php echo number_format_i18n($stats['total']); ?></strong>
                <span>Redirects activos</span>
            </div>
            <div class="seo-rd-kpi">
                <strong><?php echo number_format_i18n($stats['with_hits']); ?></strong>
                <span>Con actividad</span>
            </div>
            <div class="seo-rd-kpi">
                <strong><?php echo number_format_i18n($stats['without_hits']); ?></strong>
                <span>Sin uso registrado</span>
            </div>
            <div class="seo-rd-kpi">
                <strong><?php echo number_format_i18n($stats['hits_total']); ?></strong>
                <span>Hits acumulados</span>
            </div>
            <div class="seo-rd-kpi">
                <strong><?php echo number_format_i18n($stats['last_24h']); ?></strong>
                <span>Usados en 24 h</span>
            </div>
            <div class="seo-rd-kpi">
                <strong><?php echo number_format_i18n($stats['last_7d']); ?></strong>
                <span>Usados en 7 días</span>
            </div>
            <div class="seo-rd-kpi">
                <strong><?php echo number_format_i18n($stats['last_30d']); ?></strong>
                <span>Usados en 30 días</span>
            </div>
            <div class="seo-rd-kpi <?php echo $stats['structural'] ? 'is-error' : 'is-ok'; ?>">
                <strong><?php echo number_format_i18n($stats['structural']); ?></strong>
                <span>Incorrectos estructurales</span>
            </div>
            <div class="seo-rd-kpi <?php echo $stats['suspicious'] ? 'is-warning' : 'is-ok'; ?>">
                <strong><?php echo number_format_i18n($stats['suspicious']); ?></strong>
                <span>Sospechosos (heurístico)</span>
            </div>
            <div class="seo-rd-kpi <?php echo $stats['chains'] ? 'is-warning' : 'is-ok'; ?>">
                <strong><?php echo number_format_i18n($stats['chains']); ?></strong>
                <span>Cadenas</span>
            </div>
            <div class="seo-rd-kpi <?php echo $stats['cycles'] ? 'is-error' : 'is-ok'; ?>">
                <strong><?php echo number_format_i18n($stats['cycles']); ?></strong>
                <span>Ciclos</span>
            </div>
        </div>

        <?php if (!empty($review_rows)): ?>
            <div class="seo-rd-review">
                <h2>Requieren revisión</h2>
                <?php foreach ($review_rows as $review_row): ?>
                    <?php
                    $diag = $diagnostics[(int) $review_row->id];
                    list($badge_text, $badge_class) = seo_redirects_admin_issue_badge($diag);
                    ?>
                    <div class="seo-rd-review-item">
                        <div>
                            <strong>#<?php echo esc_html($review_row->id); ?></strong><br>
                            <span class="seo-rd-badge <?php echo esc_attr($badge_class); ?>">
                                <?php echo esc_html($badge_text); ?>
                            </span>
                        </div>
                        <div>
                            <code><?php echo esc_html($review_row->origin_url); ?></code>
                            <div style="margin:3px 0;">→</div>
                            <code><?php echo esc_html($review_row->target_url); ?></code>
                            <div class="seo-rd-diag">
                                <?php foreach (array_slice($diag['issues'], 0, 3) as $issue): ?>
                                    <div><?php echo esc_html($issue['text']); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <strong><?php echo number_format_i18n((int) $review_row->hits); ?></strong><br>
                            <span style="color:#646970;">hits</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="seo-rd-grid">
            <div class="seo-rd-card">
                <h2>Añadir nueva redirección</h2>

                <p style="color:#646970;">
                    El origen se guarda siempre como ruta interna normalizada. Las queries en origen se bloquean porque el motor runtime no las utiliza.
                </p>

                <form method="post">
                    <?php wp_nonce_field('seo_add_redirect_nonce'); ?>
                    <input type="hidden" name="action_seo_redirect" value="add">

                    <p>
                        <label for="seo-rd-origin"><strong>URL origen</strong></label>
                        <input id="seo-rd-origin" type="text" name="origin_url" class="large-text" required placeholder="/producto/url-antigua">
                    </p>

                    <p>
                        <label for="seo-rd-target"><strong>URL destino</strong></label>
                        <input id="seo-rd-target" type="text" name="target_url" class="large-text" required placeholder="/producto/url-nueva">
                    </p>

                    <p>
                        <label for="seo-rd-status"><strong>Tipo</strong></label>
                        <select id="seo-rd-status" name="status_code">
                            <option value="301">301 permanente</option>
                            <option value="302">302 temporal</option>
                        </select>
                    </p>

                    <p>
                        <input type="submit" class="button button-primary" value="Crear redirección">
                    </p>
                </form>
            </div>

            <div class="seo-rd-card">
                <h2 style="margin-bottom:10px;">
                    Redirecciones
                    <span style="font-weight:normal;color:#646970;">
                        (<?php echo number_format_i18n($total_filtered); ?>)
                    </span>
                </h2>

                <div class="seo-rd-filters">
                    <?php foreach ($filters_ui as $key => $label): ?>
                        <?php
                        $url = add_query_arg(
                            array(
                                'page' => 'seo-menu-redirects',
                                'redirect_filter' => $key,
                            ),
                            admin_url('admin.php')
                        );
                        $button_class = ($filter === $key) ? 'button button-primary' : 'button';
                        ?>
                        <a class="<?php echo esc_attr($button_class); ?>" href="<?php echo esc_url($url); ?>">
                            <?php echo esc_html($label); ?>
                            (<?php echo number_format_i18n($filter_counts[$key]); ?>)
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="get" class="seo-rd-search">
                    <input type="hidden" name="page" value="seo-menu-redirects">
                    <input type="hidden" name="redirect_filter" value="<?php echo esc_attr($filter); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" class="regular-text" placeholder="Buscar ID, origen o destino">
                    <button type="submit" class="button">Buscar</button>
                    <?php if ($search !== ''): ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-menu-redirects', 'redirect_filter' => $filter), admin_url('admin.php'))); ?>">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </form>

                <table class="wp-list-table widefat striped seo-rd-table">
                    <thead>
                        <tr>
                            <th class="column-id">ID</th>
                            <th>Origen</th>
                            <th>Destino</th>
                            <th class="column-status">Diagnóstico</th>
                            <th class="column-type">Tipo</th>
                            <th class="column-hits">Hits</th>
                            <th class="column-last">Último uso</th>
                            <th class="column-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($paged_rows)): ?>
                        <tr>
                            <td colspan="8">No hay redirecciones para este filtro.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paged_rows as $row): ?>
                            <?php
                            $id = (int) $row->id;
                            $diag = $diagnostics[$id];
                            list($badge_text, $badge_class) = seo_redirects_admin_issue_badge($diag);

                            $row_class = '';
                            if ($diag['severity'] === 'error') {
                                $row_class = 'seo-rd-row-error';
                            } elseif ($diag['severity'] === 'warning') {
                                $row_class = 'seo-rd-row-warning';
                            }

                            $delete_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page' => 'seo-menu-redirects',
                                        'action' => 'delete',
                                        'id' => $id,
                                    ),
                                    admin_url('admin.php')
                                ),
                                'seo_delete_redirect_nonce_' . $id
                            );

                            $origin_open = home_url($diag['origin_effective'] ?: '/');

                            if (preg_match('#^https?://#i', (string) $row->target_url)) {
                                $target_open = $row->target_url;
                            } else {
                                $target_open = home_url('/' . ltrim((string) $row->target_url, '/'));
                            }
                            ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td class="column-id">
                                    <strong>#<?php echo esc_html($id); ?></strong>
                                </td>

                                <td>
                                    <code><?php echo esc_html($row->origin_url); ?></code>
                                </td>

                                <td>
                                    <code><?php echo esc_html($row->target_url); ?></code>
                                </td>

                                <td class="column-status">
                                    <span class="seo-rd-badge <?php echo esc_attr($badge_class); ?>">
                                        <?php echo esc_html($badge_text); ?>
                                    </span>

                                    <?php if (!empty($diag['issues'])): ?>
                                        <div class="seo-rd-diag">
                                            <?php foreach ($diag['issues'] as $issue): ?>
                                                <div><?php echo esc_html($issue['text']); ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="column-type">
                                    <?php echo esc_html($row->status_code); ?>
                                </td>

                                <td class="column-hits">
                                    <strong><?php echo number_format_i18n((int) $row->hits); ?></strong>
                                </td>

                                <td class="column-last">
                                    <?php echo !empty($row->last_hit) ? esc_html($row->last_hit) : '—'; ?>
                                </td>

                                <td class="column-actions">
                                    <div class="seo-rd-actions">
                                        <button
                                            type="button"
                                            class="button button-small"
                                            onclick="var el=document.getElementById('edit-redirect-<?php echo esc_attr($id); ?>'); el.style.display=(el.style.display==='none'?'block':'none');">
                                            Editar
                                        </button>

                                        <a class="button button-small" href="<?php echo esc_url($origin_open); ?>" target="_blank" rel="noopener">
                                            Probar
                                        </a>

                                        <a class="button button-small" href="<?php echo esc_url($target_open); ?>" target="_blank" rel="noopener">
                                            Destino
                                        </a>

                                        <a
                                            href="<?php echo esc_url($delete_url); ?>"
                                            onclick="return confirm('¿Eliminar esta redirección?');"
                                            style="color:#b32d2e;">
                                            Borrar
                                        </a>
                                    </div>

                                    <div
                                        id="edit-redirect-<?php echo esc_attr($id); ?>"
                                        style="display:none; margin-top:12px; text-align:left; background:#f6f7f7; padding:10px; border:1px solid #ccd0d4;">

                                        <form method="post">
                                            <?php wp_nonce_field(
                                                'seo_update_redirect_nonce_' . $id,
                                                'seo_update_redirect_nonce_' . $id
                                            ); ?>

                                            <input type="hidden" name="action_seo_redirect" value="update">
                                            <input type="hidden" name="redirect_id" value="<?php echo esc_attr($id); ?>">

                                            <p style="margin:0 0 8px;">
                                                <label style="display:block;font-weight:bold;">URL origen</label>
                                                <input
                                                    type="text"
                                                    name="origin_url"
                                                    class="large-text"
                                                    value="<?php echo esc_attr($row->origin_url); ?>"
                                                    required>
                                            </p>

                                            <p style="margin:0 0 8px;">
                                                <label style="display:block;font-weight:bold;">URL destino</label>
                                                <input
                                                    type="text"
                                                    name="target_url"
                                                    class="large-text"
                                                    value="<?php echo esc_attr($row->target_url); ?>"
                                                    required>
                                            </p>

                                            <p style="margin:0 0 8px;">
                                                <label style="display:block;font-weight:bold;">Tipo</label>
                                                <select name="status_code">
                                                    <option value="301" <?php selected((int) $row->status_code, 301); ?>>301</option>
                                                    <option value="302" <?php selected((int) $row->status_code, 302); ?>>302</option>
                                                </select>
                                            </p>

                                            <p style="margin:0;">
                                                <input type="submit" class="button button-primary" value="Guardar cambios">
                                            </p>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            $base_args = array(
                                'page' => 'seo-menu-redirects',
                                'redirect_filter' => $filter,
                            );

                            if ($search !== '') {
                                $base_args['s'] = $search;
                            }

                            echo wp_kses_post(
                                paginate_links(
                                    array(
                                        'base' => add_query_arg(array_merge($base_args, array('paged' => '%#%')), admin_url('admin.php')),
                                        'format' => '',
                                        'current' => $current_page,
                                        'total' => $total_pages,
                                        'prev_text' => '‹',
                                        'next_text' => '›',
                                    )
                                )
                            );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <p style="margin-top:14px;color:#646970;">
                    “Sospechoso” es una señal heurística, no una orden de borrado. Revisa el destino antes de modificar o eliminar la regla.
                </p>
            </div>
        </div>
    </div>
    <?php
}
