<?php
/**
 * Fuentes locales y externas del Clasificador.
 *
 * La red nunca se consulta durante el render normal. Si existe una URL de
 * producto/proveedor, se encola una lectura segura en segundo plano y se usa
 * exclusivamente el contexto ya cacheado. No se siguen enlaces internos ni se
 * realiza una búsqueda general en Internet.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_truncate_text')) {
    function seo_classifier_truncate_text($text, $limit = 24000) {
        $text = preg_replace('/\s+/u', ' ', trim((string) $text));
        $limit = max(100, (int) $limit);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }
}

if (!function_exists('seo_classifier_external_sources_enabled')) {
    function seo_classifier_external_sources_enabled() {
        return (bool) apply_filters('seo_classifier_external_sources_enabled', true);
    }
}

if (!function_exists('seo_classifier_external_cache_ttl')) {
    function seo_classifier_external_cache_ttl() {
        return max(HOUR_IN_SECONDS, (int) apply_filters('seo_classifier_external_cache_ttl', 7 * DAY_IN_SECONDS));
    }
}

if (!function_exists('seo_classifier_external_error_ttl')) {
    function seo_classifier_external_error_ttl() {
        return max(15 * MINUTE_IN_SECONDS, (int) apply_filters('seo_classifier_external_error_ttl', 12 * HOUR_IN_SECONDS));
    }
}

if (!function_exists('seo_classifier_external_cache_key')) {
    function seo_classifier_external_cache_key($url, $product_id = 0) {
        // La relevancia se calcula respecto al producto. Incluir su ID evita que
        // una URL compartida reutilice la valoración de otro producto distinto.
        return 'seo_cl_ext_v2_' . md5(absint($product_id) . '|' . (string) $url);
    }
}

if (!function_exists('seo_classifier_is_http_url')) {
    function seo_classifier_is_http_url($url) {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '') return false;
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && (bool) wp_http_validate_url($url);
    }
}

if (!function_exists('seo_classifier_array_to_text')) {
    function seo_classifier_array_to_text($value, $depth = 0, &$count = 0) {
        if ($depth > 5 || $count > 350) return '';
        if (is_scalar($value)) {
            $count++;
            return trim((string) $value);
        }
        if (!is_array($value) && !is_object($value)) return '';
        $parts = [];
        foreach ((array) $value as $key => $child) {
            if ($count > 350) break;
            $text = seo_classifier_array_to_text($child, $depth + 1, $count);
            if ($text === '') continue;
            if (is_string($key) && !ctype_digit($key)) $parts[] = trim((string) $key) . ': ' . $text;
            else $parts[] = $text;
        }
        return implode(' ', $parts);
    }
}

if (!function_exists('seo_classifier_product_brand_names')) {
    function seo_classifier_product_brand_names($product_id) {
        $product_id = absint($product_id);
        $taxonomies = ['product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand'];
        if (function_exists('seo_product_brand_taxonomy')) {
            $candidate = sanitize_key((string) seo_product_brand_taxonomy());
            if ($candidate !== '') array_unshift($taxonomies, $candidate);
        }
        $names = [];
        foreach (array_values(array_unique($taxonomies)) as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) continue;
            $values = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
            if (is_wp_error($values)) continue;
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value !== '') $names[] = $value;
            }
        }
        foreach (['_seo_marca_proveedor', '_seo_fabricante'] as $meta_key) {
            $value = trim((string) get_post_meta($product_id, $meta_key, true));
            if ($value !== '') $names[] = $value;
        }
        return array_values(array_unique($names));
    }
}

if (!function_exists('seo_classifier_supplier_record')) {
    /**
     * Recupera la fila de catálogo de proveedor enlazada al producto.
     */
    function seo_classifier_supplier_record($product_id) {
        static $cache = [];
        $product_id = absint($product_id);
        if ($product_id < 1) return [];
        if (array_key_exists($product_id, $cache)) return $cache[$product_id];

        global $wpdb;
        $table = function_exists('seo_proveedores_tabla_productos')
            ? (string) seo_proveedores_tabla_productos()
            : $wpdb->prefix . 'seo_proveedores_productos';
        if (!function_exists('seo_classifier_table_exists') || !seo_classifier_table_exists($table)) {
            return $cache[$product_id] = [];
        }

        $catalog_id = absint(get_post_meta($product_id, '_seo_proveedor_catalogo_id', true));
        if ($catalog_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d LIMIT 1",
                $catalog_id
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE object_id=%d ORDER BY actualizado DESC,id DESC LIMIT 1",
                $product_id
            ), ARRAY_A);
        }
        return $cache[$product_id] = is_array($row) ? $row : [];
    }
}

if (!function_exists('seo_classifier_product_source_data')) {
    function seo_classifier_product_source_data($product_id) {
        static $cache = [];
        $product_id = absint($product_id);
        if ($product_id < 1) return [];
        if (isset($cache[$product_id])) return $cache[$product_id];

        $row = seo_classifier_supplier_record($product_id);
        $brand_names = seo_classifier_product_brand_names($product_id);
        $meta = [
            'manufacturer' => trim((string) get_post_meta($product_id, '_seo_fabricante', true)),
            'provider' => trim((string) get_post_meta($product_id, '_seo_proveedor', true)),
            'provider_brand' => trim((string) get_post_meta($product_id, '_seo_marca_proveedor', true)),
            'provider_external_id' => trim((string) get_post_meta($product_id, '_seo_proveedor_id_externo', true)),
            'provider_url_origin' => trim((string) get_post_meta($product_id, '_seo_proveedor_url_origen', true)),
            'provider_url_canonical' => trim((string) get_post_meta($product_id, '_seo_proveedor_url_canonica', true)),
            'manufacturer_url' => trim((string) get_post_meta($product_id, '_seo_fabricante_url', true)),
            'manufacturer_url_alt' => trim((string) get_post_meta($product_id, '_seo_url_fabricante', true)),
            'external_product_url' => trim((string) get_post_meta($product_id, '_product_url', true)),
        ];

        $raw_json_text = '';
        if (!empty($row['raw_json'])) {
            $decoded = json_decode((string) $row['raw_json'], true);
            if (is_array($decoded)) {
                $count = 0;
                $raw_json_text = seo_classifier_array_to_text($decoded, 0, $count);
            }
        }

        $data = [
            'manufacturer' => $meta['manufacturer'] ?: trim((string) ($row['marca'] ?? '')),
            'provider' => $meta['provider'] ?: trim((string) ($row['proveedor'] ?? '')),
            'brand_names' => array_values(array_unique(array_filter(array_merge(
                $brand_names,
                [$meta['provider_brand'], trim((string) ($row['marca'] ?? ''))]
            ), 'strlen'))),
            'mpn' => trim((string) ($row['mpn'] ?? '')),
            'supplier_sku' => trim((string) ($row['sku'] ?? '')),
            'supplier_category' => trim((string) ($row['categoria_proveedor'] ?? '')),
            'supplier_name' => trim((string) ($row['nombre'] ?? '')),
            'supplier_description' => seo_classifier_truncate_text(wp_strip_all_tags((string) ($row['descripcion'] ?? '')), 18000),
            'supplier_raw_text' => seo_classifier_truncate_text($raw_json_text, 12000),
            'urls' => [],
        ];

        $url_candidates = [
            ['url'=>$meta['manufacturer_url'], 'kind'=>'manufacturer', 'priority'=>110],
            ['url'=>$meta['manufacturer_url_alt'], 'kind'=>'manufacturer', 'priority'=>109],
            ['url'=>(string)($row['url_fabricante'] ?? ''), 'kind'=>'manufacturer', 'priority'=>108],
            ['url'=>$meta['provider_url_canonical'], 'kind'=>'product_canonical', 'priority'=>100],
            ['url'=>$meta['provider_url_origin'], 'kind'=>'product_origin', 'priority'=>95],
            ['url'=>(string)($row['url_canonica'] ?? ''), 'kind'=>'supplier_canonical', 'priority'=>90],
            ['url'=>(string)($row['url_origen'] ?? ''), 'kind'=>'supplier_origin', 'priority'=>85],
            ['url'=>$meta['external_product_url'], 'kind'=>'woocommerce_external', 'priority'=>75],
        ];
        $seen = [];
        foreach ($url_candidates as $candidate) {
            $url = esc_url_raw(trim((string) ($candidate['url'] ?? '')));
            if (!seo_classifier_is_http_url($url) || isset($seen[$url])) continue;
            $seen[$url] = true;
            $data['urls'][] = ['url'=>$url, 'kind'=>$candidate['kind'], 'priority'=>(int)$candidate['priority']];
        }
        usort($data['urls'], static function($a, $b) {
            return (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0);
        });

        $data = apply_filters('seo_classifier_product_source_data', $data, $product_id, $row);
        return $cache[$product_id] = is_array($data) ? $data : [];
    }
}

if (!function_exists('seo_classifier_external_source_urls')) {
    function seo_classifier_external_source_urls($product_id, array $source_data = []) {
        $product_id = absint($product_id);
        if (!$source_data) $source_data = seo_classifier_product_source_data($product_id);
        $urls = (array) ($source_data['urls'] ?? []);
        $urls = apply_filters('seo_classifier_external_source_urls', $urls, $product_id, $source_data);
        $out = [];
        $seen = [];
        foreach ((array) $urls as $row) {
            if (is_string($row)) $row = ['url'=>$row, 'kind'=>'custom', 'priority'=>50];
            $url = esc_url_raw(trim((string) ($row['url'] ?? '')));
            if (!seo_classifier_is_http_url($url) || isset($seen[$url])) continue;
            if (!(bool) apply_filters('seo_classifier_external_fetch_allowed', true, $url, $product_id, $row)) continue;
            $seen[$url] = true;
            $out[] = [
                'url'=>$url,
                'kind'=>sanitize_key((string)($row['kind'] ?? 'external')),
                'priority'=>(int)($row['priority'] ?? 0),
            ];
            if (count($out) >= (int) apply_filters('seo_classifier_external_max_urls', 2, $product_id)) break;
        }
        return $out;
    }
}

if (!function_exists('seo_classifier_external_cache_get')) {
    function seo_classifier_external_cache_get($url, $product_id = 0) {
        $cached = get_transient(seo_classifier_external_cache_key($url, $product_id));
        return is_array($cached) ? $cached : null;
    }
}

if (!function_exists('seo_classifier_external_cache_set')) {
    function seo_classifier_external_cache_set($url, array $value, $product_id = 0) {
        $product_id = absint($product_id ?: ($value['product_id'] ?? 0));
        $ttl = !empty($value['ok']) ? seo_classifier_external_cache_ttl() : seo_classifier_external_error_ttl();
        return set_transient(seo_classifier_external_cache_key($url, $product_id), $value, $ttl);
    }
}

if (!function_exists('seo_classifier_jsonld_collect_products')) {
    function seo_classifier_jsonld_collect_products($node, array &$products, $depth = 0) {
        if ($depth > 10 || (!is_array($node) && !is_object($node))) return;
        $node = (array) $node;
        $types = isset($node['@type']) ? (array) $node['@type'] : [];
        foreach ($types as $type) {
            if (strcasecmp(trim((string) $type), 'Product') === 0) {
                $products[] = $node;
                break;
            }
        }
        foreach ($node as $child) {
            if (is_array($child) || is_object($child)) seo_classifier_jsonld_collect_products($child, $products, $depth + 1);
        }
    }
}

if (!function_exists('seo_classifier_jsonld_product_text')) {
    function seo_classifier_jsonld_product_text(array $product) {
        $parts = [];
        $add = static function($label, $value) use (&$parts) {
            if (is_array($value) || is_object($value)) {
                $count = 0;
                $value = seo_classifier_array_to_text($value, 0, $count);
            }
            $value = trim(wp_strip_all_tags((string) $value));
            if ($value !== '') $parts[] = trim((string) $label) . ': ' . $value;
        };
        foreach (['name'=>'Nombre','description'=>'Descripción','sku'=>'SKU','mpn'=>'MPN','model'=>'Modelo','category'=>'Categoría','color'=>'Color','material'=>'Material','size'=>'Tamaño'] as $key=>$label) {
            if (array_key_exists($key, $product)) $add($label, $product[$key]);
        }
        if (!empty($product['brand'])) {
            $brand = $product['brand'];
            if (is_array($brand) && isset($brand['name'])) $brand = $brand['name'];
            $add('Marca', $brand);
        }
        foreach ((array) ($product['additionalProperty'] ?? []) as $property) {
            $property = (array) $property;
            $name = trim((string) ($property['name'] ?? $property['propertyID'] ?? 'Propiedad'));
            $value = $property['value'] ?? '';
            $unit = trim((string) ($property['unitText'] ?? $property['unitCode'] ?? ''));
            if ($unit !== '' && is_scalar($value)) $value = trim((string)$value) . ' ' . $unit;
            $add($name, $value);
        }
        return seo_classifier_truncate_text(implode(' ', $parts), 16000);
    }
}

if (!function_exists('seo_classifier_external_parse_html')) {
    function seo_classifier_external_parse_html($html, $url = '') {
        $html = (string) $html;
        $structured_parts = [];
        $meta_parts = [];
        $visible = '';

        // Extracción independiente de DOMDocument para instalaciones PHP mínimas.
        if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $jsonld_matches)) {
            foreach ((array)($jsonld_matches[1] ?? []) as $jsonld) {
                $decoded = json_decode(html_entity_decode(trim((string)$jsonld), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (!is_array($decoded)) continue;
                $products = [];
                seo_classifier_jsonld_collect_products($decoded, $products);
                foreach ($products as $product) {
                    $text = seo_classifier_jsonld_product_text((array)$product);
                    if ($text !== '') $structured_parts[] = $text;
                }
            }
        }

        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($loaded) {
                $xpath = new DOMXPath($doc);
                foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
                    $decoded = json_decode(trim((string) $script->textContent), true);
                    if (!is_array($decoded)) continue;
                    $products = [];
                    seo_classifier_jsonld_collect_products($decoded, $products);
                    foreach ($products as $product) {
                        $text = seo_classifier_jsonld_product_text((array) $product);
                        if ($text !== '') $structured_parts[] = $text;
                    }
                }

                foreach ($xpath->query('//title') as $node) {
                    $value = trim((string) $node->textContent);
                    if ($value !== '') $meta_parts[] = 'Título de fuente: ' . $value;
                }
                foreach ($xpath->query('//meta[@content]') as $node) {
                    $name = strtolower(trim((string) ($node->getAttribute('name') ?: $node->getAttribute('property'))));
                    if (!in_array($name, ['description','keywords','og:title','og:description','twitter:title','twitter:description'], true)) continue;
                    $value = trim((string) $node->getAttribute('content'));
                    if ($value !== '') $meta_parts[] = $name . ': ' . $value;
                }

                foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer') as $node) {
                    if ($node->parentNode) $node->parentNode->removeChild($node);
                }
                $body = $xpath->query('//body')->item(0);
                $visible = $body ? (string) $body->textContent : (string) $doc->textContent;
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$meta_parts) {
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $title_match)) {
                $title = trim(wp_strip_all_tags(html_entity_decode((string)$title_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($title !== '') $meta_parts[] = 'Título de fuente: ' . $title;
            }
            if (preg_match_all('#<meta\s+[^>]*>#is', $html, $meta_tags)) {
                foreach ((array)($meta_tags[0] ?? []) as $tag) {
                    $name = '';
                    $content = '';
                    if (preg_match('/(?:name|property)=["\']([^"\']+)["\']/i', $tag, $name_match)) $name = strtolower(trim((string)$name_match[1]));
                    if (preg_match('/content=["\']([^"\']*)["\']/i', $tag, $content_match)) $content = trim(html_entity_decode((string)$content_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($content !== '' && in_array($name, ['description','keywords','og:title','og:description','twitter:title','twitter:description'], true)) {
                        $meta_parts[] = $name . ': ' . $content;
                    }
                }
            }
        }

        if ($visible === '') {
            $visible = wp_strip_all_tags(preg_replace('#<(script|style|noscript|svg|nav|footer)[^>]*>.*?</\1>#is', ' ', $html));
        }

        return [
            'structured_text'=>seo_classifier_truncate_text(implode(' ', array_unique($structured_parts)), 18000),
            'meta_text'=>seo_classifier_truncate_text(implode(' ', array_unique($meta_parts)), 6000),
            'body_text'=>seo_classifier_truncate_text($visible, 22000),
            'source_url'=>esc_url_raw((string) $url),
        ];
    }
}

if (!function_exists('seo_classifier_external_relevance')) {
    /**
     * Comprueba que la página externa parece corresponder al producto solicitado.
     * Los identificadores exactos pesan más que una semejanza genérica de texto.
     */
    function seo_classifier_external_relevance($product_id, array $payload) {
        $product_id = absint($product_id);
        $combined = implode(' ', [
            (string)($payload['structured_text'] ?? ''),
            (string)($payload['meta_text'] ?? ''),
            (string)($payload['body_text'] ?? ''),
        ]);
        $combined_norm = seo_classifier_normalize($combined);
        if ($combined_norm === '') return ['score'=>0.0,'reason'=>'sin contenido comparable'];

        $source = seo_classifier_product_source_data($product_id);
        $identifiers = [
            (string)($source['mpn'] ?? ''),
            (string)($source['supplier_sku'] ?? ''),
            (string)get_post_meta($product_id, '_sku', true),
            (string)get_post_meta($product_id, '_seo_proveedor_id_externo', true),
        ];
        foreach ($identifiers as $identifier) {
            $identifier = seo_classifier_normalize($identifier);
            if (strlen(str_replace([' ','-','_','.','/'], '', $identifier)) < 4) continue;
            if (strpos(' ' . $combined_norm . ' ', ' ' . $identifier . ' ') !== false || strpos(str_replace(' ', '', $combined_norm), str_replace(' ', '', $identifier)) !== false) {
                return ['score'=>1.0,'reason'=>'identificador exacto en la fuente'];
            }
        }

        $post = get_post($product_id);
        $title = $post ? (string)$post->post_title : (string)($source['supplier_name'] ?? '');
        $local = array_values(array_unique(seo_classifier_concept_sequence($title)));
        $remote = array_fill_keys(array_unique(seo_classifier_concept_sequence($combined)), true);
        $matched = 0;
        foreach ($local as $token) if (isset($remote[$token])) $matched++;
        $coverage = $matched / max(1, count($local));
        $score = 0.0;
        if ($matched >= 4 && $coverage >= 0.55) $score = 0.90;
        elseif ($matched >= 3 && $coverage >= 0.40) $score = 0.78;
        elseif ($matched >= 2 && $coverage >= 0.25) $score = 0.60;
        elseif ($matched >= 1) $score = 0.32;
        $result = ['score'=>round($score,4),'reason'=>'coincidencia de título: ' . $matched . '/' . count($local)];
        return apply_filters('seo_classifier_external_relevance', $result, $product_id, $payload, $source);
    }
}

if (!function_exists('seo_classifier_external_fetch_url')) {
    function seo_classifier_external_fetch_url($url, $product_id = 0) {
        $url = esc_url_raw((string) $url);
        $product_id = absint($product_id);
        $base = [
            'ok'=>false,
            'url'=>$url,
            'product_id'=>$product_id,
            'fetched_at'=>current_time('mysql'),
            'structured_text'=>'',
            'meta_text'=>'',
            'body_text'=>'',
            'relevance'=>0.0,
            'relevance_reason'=>'',
            'error'=>'',
        ];
        if (!seo_classifier_external_sources_enabled() || !seo_classifier_is_http_url($url)) {
            $base['error'] = 'URL externa no permitida o no válida.';
            return $base;
        }

        $response = wp_safe_remote_get($url, [
            'timeout'=>8,
            'redirection'=>3,
            'limit_response_size'=>1024 * 1024,
            'headers'=>[
                'Accept'=>'text/html,application/ld+json,application/json;q=0.9,*/*;q=0.5',
                'Accept-Language'=>'es-ES,es;q=0.9,en;q=0.5',
            ],
            'user-agent'=>'SEO-Taxonomy-Classifier/' . (function_exists('seo_classifier_version') ? seo_classifier_version() : '2') . '; ' . home_url('/'),
        ]);
        if (is_wp_error($response)) {
            $base['error'] = $response->get_error_message();
            return $base;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $base['error'] = 'Respuesta HTTP ' . $code . '.';
            return $base;
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            $base['error'] = 'La fuente externa no devolvió contenido.';
            return $base;
        }
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (strpos($content_type, 'json') !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $count = 0;
                $base['structured_text'] = seo_classifier_truncate_text(seo_classifier_array_to_text($decoded, 0, $count), 22000);
            }
        } else {
            $parsed = seo_classifier_external_parse_html($body, $url);
            $base = array_merge($base, $parsed);
        }
        $has_content = ($base['structured_text'] !== '' || $base['meta_text'] !== '' || $base['body_text'] !== '');
        if ($has_content) {
            $relevance = seo_classifier_external_relevance($product_id, $base);
            $base['relevance'] = (float)($relevance['score'] ?? 0.0);
            $base['relevance_reason'] = (string)($relevance['reason'] ?? '');
        }
        $minimum_relevance = max(0.0, min(1.0, (float)apply_filters('seo_classifier_external_min_relevance', 0.45, $product_id, $url)));
        $base['ok'] = $has_content && $base['relevance'] >= $minimum_relevance;
        if (!$has_content) $base['error'] = 'No se encontró información de producto utilizable.';
        elseif (!$base['ok']) $base['error'] = 'La fuente no parece corresponder al producto (' . round($base['relevance'] * 100) . '%).';
        return $base;
    }
}

if (!function_exists('seo_classifier_refresh_external_context')) {
    function seo_classifier_refresh_external_context($product_id) {
        $product_id = absint($product_id);
        $sources = seo_classifier_external_source_urls($product_id);
        $results = [];
        foreach ($sources as $source) {
            $url = (string) ($source['url'] ?? '');
            if ($url === '') continue;
            $result = seo_classifier_external_fetch_url($url, $product_id);
            $result['kind'] = (string) ($source['kind'] ?? 'external');
            seo_classifier_external_cache_set($url, $result, $product_id);
            $results[] = $result;
            if (!empty($result['ok'])) break; // Una ficha fiable es suficiente por ejecución.
        }
        do_action('seo_classifier_external_context_refreshed', $product_id, $results);
        return $results;
    }
}

if (!function_exists('seo_classifier_external_queue_option')) {
    function seo_classifier_external_queue_option() {
        return 'seo_classifier_external_queue_v2';
    }
}

if (!function_exists('seo_classifier_queue_external_context')) {
    function seo_classifier_queue_external_context($product_id) {
        $product_id = absint($product_id);
        if ($product_id < 1 || !seo_classifier_external_sources_enabled()) return false;
        if (!seo_classifier_external_source_urls($product_id)) return false;

        $lock_key = 'seo_cl_ext_q_' . $product_id;
        if (get_transient($lock_key)) return false;
        set_transient($lock_key, 1, 30 * MINUTE_IN_SECONDS);

        $option = seo_classifier_external_queue_option();
        $queue = get_option($option, []);
        if (!is_array($queue)) $queue = [];
        $queue = array_values(array_unique(array_filter(array_map('absint', $queue))));
        if (!in_array($product_id, $queue, true)) $queue[] = $product_id;
        $max = max(50, (int) apply_filters('seo_classifier_external_queue_max', 2000));
        if (count($queue) > $max) $queue = array_slice($queue, -$max);
        update_option($option, $queue, false);

        if (!wp_next_scheduled('seo_classifier_run_external_queue')) {
            wp_schedule_single_event(time() + 10, 'seo_classifier_run_external_queue');
        }
        return true;
    }
}

if (!function_exists('seo_classifier_run_external_queue')) {
    function seo_classifier_run_external_queue() {
        if (get_transient('seo_classifier_external_queue_lock')) return;
        set_transient('seo_classifier_external_queue_lock', 1, 2 * MINUTE_IN_SECONDS);

        $option = seo_classifier_external_queue_option();
        $queue = get_option($option, []);
        if (!is_array($queue)) $queue = [];
        $queue = array_values(array_unique(array_filter(array_map('absint', $queue))));
        $batch_size = max(1, min(5, (int) apply_filters('seo_classifier_external_queue_batch_size', 2)));
        $batch = array_splice($queue, 0, $batch_size);
        update_option($option, $queue, false);

        foreach ($batch as $product_id) {
            seo_classifier_refresh_external_context($product_id);
        }
        delete_transient('seo_classifier_external_queue_lock');

        if ($queue && !wp_next_scheduled('seo_classifier_run_external_queue')) {
            wp_schedule_single_event(time() + 45, 'seo_classifier_run_external_queue');
        }
    }
    add_action('seo_classifier_run_external_queue', 'seo_classifier_run_external_queue');
}

if (!function_exists('seo_classifier_get_external_context')) {
    function seo_classifier_get_external_context($product_id, $schedule = true) {
        $product_id = absint($product_id);
        $sources = seo_classifier_external_source_urls($product_id);
        if (!$sources || !seo_classifier_external_sources_enabled()) {
            return ['status'=>'unavailable','ready'=>false,'queued'=>false,'sources'=>[],'structured_text'=>'','meta_text'=>'','body_text'=>''];
        }

        $ready = [];
        $errors = [];
        foreach ($sources as $source) {
            $cached = seo_classifier_external_cache_get((string) $source['url'], $product_id);
            if (!is_array($cached)) continue;
            $cached['kind'] = (string) ($source['kind'] ?? 'external');
            if (!empty($cached['ok'])) $ready[] = $cached;
            elseif (!empty($cached['error'])) $errors[] = $cached;
        }

        $queued = false;
        // Un error cacheado aplica su propio TTL. No se reintenta en cada visita.
        if (!$ready && !$errors && $schedule) $queued = seo_classifier_queue_external_context($product_id);
        $structured = [];
        $meta = [];
        $body = [];
        $relevance_scores = [];
        foreach ($ready as $row) {
            if (!empty($row['structured_text'])) $structured[] = (string) $row['structured_text'];
            if (!empty($row['meta_text'])) $meta[] = (string) $row['meta_text'];
            if (!empty($row['body_text'])) $body[] = (string) $row['body_text'];
            if (isset($row['relevance'])) $relevance_scores[] = (float)$row['relevance'];
        }

        return [
            'status'=>$ready ? 'ready' : ($queued ? 'pending' : ($errors ? 'error' : 'missing')),
            'ready'=>!empty($ready),
            'queued'=>$queued,
            'sources'=>$sources,
            'cached'=>$ready,
            'errors'=>$errors,
            'relevance'=>$relevance_scores ? max($relevance_scores) : 0.0,
            'structured_text'=>seo_classifier_truncate_text(implode(' ', $structured), 22000),
            'meta_text'=>seo_classifier_truncate_text(implode(' ', $meta), 8000),
            'body_text'=>seo_classifier_truncate_text(implode(' ', $body), 22000),
        ];
    }
}
