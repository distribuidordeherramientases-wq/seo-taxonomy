<?php

defined('ABSPATH') || exit;

/**
 * Interprete del Dependiente.
 *
 * Mision: convertir la forma natural de hablar del cliente en conceptos que
 * el motor del Dependiente ya sabe buscar. No aprende catalogo ni sustituye a
 * Academia: aprende lenguaje de cliente y lo traduce al lenguaje canonico.
 */
final class SEO_Dependiente_Interprete {
    const VERSION = '0.7.1';

    private static $morphology = null;

    /**
     * Interpreta una consulta de cliente.
     *
     * @param string $query Consulta original.
     * @return array
     */
    public static function interpret($query) {
        $original = self::clean_query($query);
        $normalized = self::normalize($original);

        $language = self::analyze_language($normalized);
        $result = array(
            'version'       => self::VERSION,
            'original'      => $original,
            'normalized'    => $normalized,
            'search_query'  => $original,
            'changed'       => false,
            'confidence'    => 0.0,
            'rule'          => '',
            'reason'        => '',
            'concepts'      => array(),
            'lesson_key'    => '',
            'intent'        => self::detect_intent($normalized),
            'language'      => $language,
        );

        if ('' === $normalized) {
            return $result;
        }

        /*
         * Regresion base: si el cliente ya escribe extractor + objeto,
         * conservamos la ruta literal que sabemos que funciona.
         */
        $extractor_targets = array(
            'rodamiento' => array('rodamiento', 'rodamientos', 'cojinete', 'cojinetes'),
            'polea'      => array('polea', 'poleas'),
            'engranaje'  => array('engranaje', 'engranajes'),
            'rotula'     => array('rotula', 'rotulas'),
        );

        if (self::has_any($normalized, array('extractor', 'extractores'))) {
            foreach ($extractor_targets as $canonical => $variants) {
                if (self::has_any($normalized, $variants)) {
                    return self::rewrite(
                        $result,
                        'extractor ' . self::plural_search_term($canonical),
                        0.99,
                        'literal_extractor_object',
                        'Se conserva extractor y el objeto principal.',
                        array('herramienta' => 'extractor', 'objeto' => $canonical),
                        'i1_subject_action'
                    );
                }
            }
        }

        /*
         * Intencion de extraccion expresada de forma natural.
         * Esta regla queda como regresion critica mientras la tabla linguistica
         * amplia su cobertura.
         */
        $extraction_actions = array(
            'sacar', 'sacarlo', 'sacarla', 'sacarlos', 'sacarlas',
            'extraer', 'extraerlo', 'extraerla', 'extraerlos', 'extraerlas',
            'quitar', 'quitarlo', 'quitarla', 'quitarlos', 'quitarlas',
            'retirar', 'retirarlo', 'retirarla', 'retirarlos', 'retirarlas',
            'desmontar', 'desmontarlo', 'desmontarla', 'desmontarlos', 'desmontarlas',
        );
        if (self::has_any($normalized, $extraction_actions)) {
            foreach ($extractor_targets as $canonical => $variants) {
                if (self::has_any($normalized, $variants)) {
                    return self::rewrite(
                        $result,
                        'extractor ' . self::plural_search_term($canonical),
                        0.98,
                        'natural_extraction_object',
                        'Accion de extraccion mas objeto reconocido.',
                        array('intencion' => 'extraer', 'herramienta' => 'extractor', 'objeto' => $canonical),
                        'i1_subject_action'
                    );
                }
            }
        }

        /*
         * Desambiguacion de compresor de aire frente a compresores mecanicos
         * de muelles/suspension.
         */
        $air_context = array(
            'inflar', 'inflado', 'rueda', 'ruedas', 'neumatico', 'neumaticos',
            'aire', 'neumatica', 'neumaticas',
        );
        $has_air_context = self::has_any($normalized, $air_context)
            || self::contains_phrase($normalized, 'herramienta neumatica')
            || self::contains_phrase($normalized, 'herramientas neumaticas');

        if ($has_air_context && self::has_any($normalized, array('compresor', 'compresores'))) {
            return self::rewrite(
                $result,
                'compresor aire',
                0.98,
                'air_compressor_context',
                'Compresor desambiguado por contexto de inflado o uso neumatico.',
                array('producto' => 'compresor', 'medio' => 'aire'),
                'i1_subject_action'
            );
        }

        if ($has_air_context
            && self::has_any($normalized, array('inflar', 'inflado'))
            && self::has_any($normalized, array('herramienta', 'herramientas', 'neumatica', 'neumaticas'))) {
            return self::rewrite(
                $result,
                'compresor aire',
                0.95,
                'air_need_without_product_name',
                'Necesidad de inflado y herramienta neumatica interpretada como compresor de aire.',
                array('necesidad' => 'aire_comprimido', 'producto' => 'compresor'),
                'i1_subject_action'
            );
        }

        /*
         * Memoria linguistica persistente. Aqui vive el aprendizaje del
         * Interprete: verbo/sinonimo/frase -> concepto canonico de busqueda.
         */
        $lexicon_result = self::interpret_from_lexicon($result, false);
        if (!empty($lexicon_result['changed'])) {
            return $lexicon_result;
        }

        /*
         * Segunda lectura: gramática castellana. Se eliminan palabras funcionales
         * y se convierten formas verbales conocidas a su infinitivo. Solo se usa
         * para consultar conocimiento ya aprendido; nunca crea una relación nueva
         * mientras atiende a un cliente.
         */
        $semantic_query = self::normalize((string) ($language['semantic_query'] ?? ''));
        if ('' !== $semantic_query && $semantic_query !== $normalized) {
            $semantic_result = $result;
            $semantic_result['normalized'] = $semantic_query;
            $semantic_match = self::interpret_from_lexicon($semantic_result, false);
            if (!empty($semantic_match['changed'])) {
                $semantic_match['normalized'] = $normalized;
                $semantic_match['changed'] = self::normalize((string) ($semantic_match['search_query'] ?? '')) !== $normalized;
                $semantic_match['rule'] = sanitize_key('language_' . (string) ($semantic_match['rule'] ?? 'lexicon'));
                $semantic_match['reason'] = 'Análisis lingüístico: ' . (string) ($semantic_match['reason'] ?? 'expresión comprendida.');
                $semantic_match['language'] = $language;
                return $semantic_match;
            }
        }

        // Tras L6, las erratas pequeñas se comparan solo contra expresiones ya
        // aprendidas. Para palabras cortas se limita a una edición; las largas
        // admiten dos (incluye muchas transposiciones), evitando falsos positivos.
        $grammar = self::grammar_settings();
        $fuzzy_distance = !empty($grammar['natural_language']) ? absint($grammar['fuzzy_distance'] ?? 0) : 0;
        if ($fuzzy_distance > 0) {
            $fuzzy_base = $result;
            if ('' !== $semantic_query) {
                $fuzzy_base['normalized'] = $semantic_query;
            }
            $fuzzy_result = self::interpret_from_lexicon($fuzzy_base, true, $fuzzy_distance);
            if (!empty($fuzzy_result['changed'])) {
                $fuzzy_result['normalized'] = $normalized;
                $fuzzy_result['changed'] = self::normalize((string) ($fuzzy_result['search_query'] ?? '')) !== $normalized;
                $fuzzy_result['language'] = $language;
                return $fuzzy_result;
            }
        }

        return $result;
    }

    private static function interpret_from_lexicon($result, $fuzzy = false, $fuzzy_distance = 1) {
        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            return $result;
        }

        $rows = $fuzzy
            ? SEO_Dependiente_Interprete_DB::fuzzy_matching_rows((string) ($result['normalized'] ?? ''), $fuzzy_distance)
            : SEO_Dependiente_Interprete_DB::matching_rows((string) ($result['normalized'] ?? ''));
        if (!$rows) {
            return $result;
        }

        foreach ($rows as $row) {
            $target = self::clean_query((string) ($row['target_search'] ?? ''));
            if ('' === $target) {
                continue;
            }

            $matched_context = isset($row['matched_context']) && is_array($row['matched_context'])
                ? $row['matched_context']
                : array();
            if ($matched_context) {
                $context = self::canonical_context_term((string) reset($matched_context));
                if ('' !== $context && !self::contains_phrase(self::normalize($target), self::normalize($context))) {
                    $target .= ' ' . $context;
                }
            }

            $expression = (string) ($row['expression'] ?? '');
            $canonical = (string) ($row['canonical_term'] ?? $target);
            $relation = sanitize_key((string) ($row['relation_type'] ?? 'synonym'));
            $lesson_key = sanitize_key((string) ($row['lesson_key'] ?? ''));

            return self::rewrite(
                $result,
                $target,
                (float) ($row['confidence'] ?? 0.9),
                ($fuzzy ? 'lexicon_fuzzy_' : 'lexicon_') . $relation,
                ($fuzzy ? 'Coincidencia ortográfica aproximada: ' : 'El Intérprete traduce ') . '"' . $expression . '" al concepto "' . $canonical . '".',
                array(
                    'expresion_cliente' => $expression,
                    'concepto_canonico' => $canonical,
                    'relacion' => $relation,
                ),
                $lesson_key
            );
        }

        return $result;
    }


    /**
     * Diseña, desde el Intérprete, una pregunta corta de desambiguación.
     *
     * El Intérprete no consulta productos ni decide resultados. Recibe únicamente
     * la orientación agregada del conjunto candidato (facetas + recuentos) y elige
     * la pregunta que mejor puede reducir la ambigüedad. El API del Dependiente se
     * limita a ejecutar después el filtro confirmado por el cliente.
     *
     * @param array $context Contexto agregado de la búsqueda.
     * @return array
     */
    public static function plan_clarification($context = array()) {
        $empty = array(
            'should_ask'          => false,
            'question'            => '',
            'role'                => '',
            'reason'              => '',
            'delay_ms'            => 0,
            'step'                => 0,
            'max_steps'           => 2,
            'strategy'            => 'interpreter_information_gain',
            'axis'                => '',
            'estimated_reduction' => 0,
            'options'             => array(),
        );

        $query = self::clean_query((string) ($context['query'] ?? ''));
        $mode = sanitize_key((string) ($context['mode'] ?? 'need'));
        $request_kind = sanitize_key((string) ($context['request_kind'] ?? 'search'));
        $resolved_owner = isset($context['resolved_owner']) && is_array($context['resolved_owner'])
            ? $context['resolved_owner']
            : array();
        $confirmed = self::sanitize_guidance_hints((array) ($context['confirmed_hints'] ?? array()));
        $step = count($confirmed) + 1;

        if ('' === $query || 'compare' === $mode || $step > 2 || in_array($request_kind, array('paginate','compare'), true)) {
            return $empty;
        }
        if (absint($resolved_owner['object_id'] ?? 0) && in_array(absint($resolved_owner['object_type'] ?? 0), array(2,3), true)) {
            return $empty;
        }

        $semantic = isset($context['semantic']) && is_array($context['semantic']) ? $context['semantic'] : array();
        $facets = isset($context['facets']) && is_array($context['facets']) ? $context['facets'] : array();
        $diagnostic = isset($context['diagnostic']) && is_array($context['diagnostic']) ? $context['diagnostic'] : array();
        $interpretation = isset($context['interpretation']) && is_array($context['interpretation']) ? $context['interpretation'] : array();
        $total = max(0, absint($context['total'] ?? 0));
        $strategy = sanitize_key((string) ($diagnostic['strategy'] ?? 'strict'));
        $weak_strategy = in_array($strategy, array('broad_fallback','catalog_fallback','index_unavailable'), true);
        $object = self::semantic_first_value($semantic, 'object');
        $intent = self::semantic_first_value($semantic, 'intent');
        $unresolved = self::semantic_unresolved_values($semantic);
        $language = isset($interpretation['language']) && is_array($interpretation['language']) ? $interpretation['language'] : array();
        $content_tokens = array_values(array_filter((array) ($language['content_tokens'] ?? array())));
        if (!$content_tokens) {
            $content_tokens = array_values(array_filter(explode(' ', self::normalize($query))));
        }

        // El objeto semántico puede ser el destino del trabajo (pared, viga,
        // hormigón...) y no necesariamente el producto que el cliente quiere.
        // Para preguntas comerciales solo damos por conocido el producto cuando el
        // propio Intérprete tiene una señal explícita de producto/herramienta.
        $product_object = self::interpretation_product_object($interpretation);
        $action = self::interpretation_action($interpretation, $query);
        $question_object = $product_object ?: ('' === $action ? $object : '');
        $has_action = '' !== $action || '' !== $intent;

        // Tras una respuesta buena, con pocos candidatos y estrategia sólida no
        // molestamos al cliente con una segunda pregunta innecesaria.
        if ($confirmed && !$weak_strategy && !$unresolved && $total > 0 && $total <= 8) {
            return $empty;
        }
        if (!$confirmed && !$weak_strategy && !$unresolved && $total > 0 && $total <= 3 && count($content_tokens) >= 2) {
            return $empty;
        }

        $answered_axes = array();
        foreach ($confirmed as $hint) {
            $group = sanitize_key((string) ($hint['source_group'] ?? ''));
            $role = sanitize_key((string) ($hint['role'] ?? 'term'));
            $answered_axes[$role . '|' . $group] = true;
            if ($group) {
                $answered_axes['group|' . $group] = true;
            }
        }

        $reason = '';
        $preferred = '';
        $product_family_answered = false;
        foreach (array('category', 'tipo', 'subtipo') as $family_group) {
            if (isset($answered_axes['group|' . $family_group])) {
                $product_family_answered = true;
                break;
            }
        }

        // Una acción no identifica una familia de producto. “Taladrar” puede
        // significar máquina, broca/corona, accesorio, soporte, etc. Una respuesta
        // genérica de ROL (p. ej. “Herramienta”) tampoco identifica todavía el
        // producto: seguimos pidiendo una rama concreta de tipo/subtipo/categoría.
        if (!$product_object && $action && !$product_family_answered) {
            $preferred = 'object';
            $reason = 'action_without_product';
        } elseif (!$object && ($unresolved || $weak_strategy || 0 === $total)) {
            $preferred = 'object';
            $reason = 'missing_object';
        } elseif ('need' === $mode && !$has_action && !isset($answered_axes['intent|intent'])) {
            $preferred = 'intent';
            $reason = 'missing_intent';
        } elseif ($weak_strategy || 0 === $total) {
            $preferred = 'context';
            $reason = 'weak_match';
        } elseif ($total > 8 || count($content_tokens) <= 3) {
            $preferred = 'context';
            $reason = 'broad_result_set';
        } else {
            return $empty;
        }

        $axes = self::clarification_axes($facets, $total, $question_object, $answered_axes, $action, $query);
        $axis = self::choose_clarification_axis($axes, $preferred, 'action_without_product' === $reason);

        // Si falta intención, una aplicación real del catálogo es mejor pregunta
        // que una lista genérica de verbos. Solo caemos al repertorio controlado
        // cuando no existe una división útil en las facetas candidatas.
        if ('intent' === $preferred && '' === $action && (!$axis || !in_array((string) ($axis['kind'] ?? ''), array('application','tag','attribute'), true))) {
            $options = self::controlled_intent_options();
            if (count($options) >= 2) {
                $axis = array(
                    'kind'      => 'intent',
                    'group'     => 'intent',
                    'role'      => 'intent',
                    'question'  => $object
                        ? '¿Qué quieres hacer con “' . $object . '”?' 
                        : '¿Qué quieres conseguir?',
                    'score'     => 0.55,
                    'reduction' => 45,
                    'options'   => $options,
                );
            }
        }

        if (!$axis || empty($axis['options']) || count((array) $axis['options']) < 2) {
            return $empty;
        }

        $options = array_slice(array_values((array) $axis['options']), 0, 4);
        return array(
            'should_ask'          => true,
            'question'            => sanitize_text_field((string) ($axis['question'] ?? '¿Cuál de estas opciones encaja mejor?')),
            'role'                => sanitize_key((string) ($axis['role'] ?? $preferred ?: 'context')),
            'reason'              => $reason ?: 'information_gain',
            // Las preguntas forman parte de la interpretación, no son un mensaje
            // secundario. Se muestran enseguida; una interacción con producto las
            // cancela como hasta ahora.
            'delay_ms'            => $weak_strategy || 0 === $total ? 0 : 350,
            'step'                => $step,
            'max_steps'           => 2,
            'strategy'            => 'interpreter_information_gain',
            'axis'                => sanitize_key((string) ($axis['group'] ?? $axis['kind'] ?? '')),
            'estimated_reduction' => max(0, min(95, absint($axis['reduction'] ?? 0))),
            'options'             => $options,
        );
    }

    /**
     * Genera la consulta limpia que el Intérprete entrega al Dependiente.
     * Las confirmaciones del diálogo se agregan como orientación canónica, sin
     * reemplazar ni reescribir lo que el cliente escribió originalmente.
     */
    public static function refine_search_query($interpretation, $confirmed_hints = array()) {
        $interpretation = is_array($interpretation) ? $interpretation : array();
        $query = self::clean_query((string) ($interpretation['search_query'] ?? $interpretation['original'] ?? ''));
        $language = isset($interpretation['language']) && is_array($interpretation['language']) ? $interpretation['language'] : array();

        // Cuando Lingüista ya ha activado gramática natural, preferimos la versión
        // sin palabras funcionales si el léxico no produjo una reescritura mejor.
        if (empty($interpretation['changed']) && !empty($language['enabled'])) {
            $semantic_query = self::clean_query((string) ($language['semantic_query'] ?? ''));
            if ('' !== $semantic_query) {
                $query = $semantic_query;
            }
        }

        $parts = array();
        if ('' !== $query) {
            $parts[] = $query;
        }
        foreach (self::sanitize_guidance_hints((array) $confirmed_hints) as $hint) {
            $value = self::clean_query((string) ($hint['value'] ?? ''));
            $source = sanitize_key((string) ($hint['source'] ?? ''));

            // Las respuestas que ya se aplican como faceta fuerte del catálogo no
            // deben añadirse además como texto libre. Hacer “consulta + herramienta”
            // o “consulta + categoría” puede empeorar el ranking y duplicar la misma
            // restricción. Conservamos la pista para conversación/aprendizaje y el
            // filtro para Dependiente; solo el texto libre/intent se suma a la query.
            $filtered_catalog_hint = in_array($source, array(
                'catalog_vocabulary', 'catalog_attribute', 'catalog_tag',
                'catalog_category', 'category', 'catalog_brand', 'catalog_orientation'
            ), true);
            if ($filtered_catalog_hint) {
                continue;
            }

            if ('' !== $value && !self::contains_phrase(self::normalize(implode(' ', $parts)), self::normalize($value))) {
                $parts[] = $value;
            }
        }
        return self::clean_query(implode(' ', array_unique($parts)));
    }

    private static function sanitize_guidance_hints($hints) {
        $clean = array();
        foreach ((array) $hints as $hint) {
            if (!is_array($hint)) {
                continue;
            }
            $role = sanitize_key((string) ($hint['role'] ?? 'term'));
            if (!in_array($role, array('intent','object','context','state','term'), true)) {
                continue;
            }
            $value = self::normalize((string) ($hint['value'] ?? ''));
            if ('' === $value) {
                continue;
            }
            $clean[] = array(
                'role'         => $role,
                'value'        => $value,
                'label'        => sanitize_text_field((string) ($hint['label'] ?? $value)),
                'source'       => sanitize_key((string) ($hint['source'] ?? 'interpreter_clarification')) ?: 'interpreter_clarification',
                'source_group' => sanitize_key((string) ($hint['source_group'] ?? '')),
                'source_slug'  => sanitize_title((string) ($hint['source_slug'] ?? '')),
            );
            if (count($clean) >= 2) {
                break;
            }
        }
        return $clean;
    }

    private static function clarification_axes($facets, $total, $object, $answered_axes, $action = '', $query = '') {
        $axes = array();
        $total = max(1, absint($total));
        $action = self::normalize((string) $action);
        $query = self::normalize((string) $query);
        $vocabulary = isset($facets['vocabulary']) && is_array($facets['vocabulary']) ? $facets['vocabulary'] : array();
        $action_question = $action ? '¿Qué necesitas para ' . $action . '?' : '';

        $vocab_defs = array(
            'aplicacion' => array('kind'=>'application','role'=>'context','priority'=>0.18,'question'=>'¿Para qué uso lo necesitas?'),
            'subtipo'    => array('kind'=>'subtype','role'=>'object','priority'=>0.15,'question'=>$object ? '¿Qué tipo de “' . $object . '” encaja mejor?' : ($action_question ?: '¿Qué tipo de producto se acerca más a lo que buscas?')),
            'tipo'       => array('kind'=>'type','role'=>'object','priority'=>0.12,'question'=>$object ? '¿Qué tipo de “' . $object . '” buscas?' : ($action_question ?: '¿A qué tipo de producto o herramienta te refieres?')),
            'plataforma' => array('kind'=>'platform','role'=>'context','priority'=>0.10,'question'=>'¿Con qué sistema o plataforma debe ser compatible?'),
            'rol'        => array('kind'=>'role','role'=>'object','priority'=>0.04,'question'=>$action_question ?: '¿Qué necesitas exactamente: herramienta, accesorio u otro tipo de producto?'),
        );
        foreach ($vocab_defs as $group => $def) {
            if (isset($answered_axes['group|' . $group]) || self::facet_items_match_query((array) ($vocabulary[$group] ?? array()), $query)) {
                continue;
            }
            $axis = self::make_catalog_axis((array) ($vocabulary[$group] ?? array()), $total, array_merge($def, array(
                'group'       => $group,
                'filter_type' => 'vocabulary',
                'source'      => 'catalog_vocabulary',
            )));
            if ($axis) {
                $axes[] = $axis;
            }
        }

        foreach ((array) ($facets['attributes'] ?? array()) as $attribute) {
            $group = sanitize_key((string) ($attribute['key'] ?? ''));
            $label = sanitize_text_field((string) ($attribute['label'] ?? $group));
            if (!$group || isset($answered_axes['group|' . $group]) || self::facet_items_match_query((array) ($attribute['values'] ?? array()), $query)) {
                continue;
            }
            $label_norm = self::normalize($label);
            $question = '¿Qué opción de “' . $label . '” necesitas?';
            $priority = 0.08;
            if (preg_match('/\b(material|superficie|soporte)\b/', $label_norm)) {
                $question = '¿Sobre qué material o superficie lo vas a usar?';
                $priority = 0.24;
            } elseif (preg_match('/\b(ubicacion|estancia|lugar|interior|exterior)\b/', $label_norm)) {
                $question = '¿Dónde lo vas a usar?';
                $priority = 0.22;
            } elseif (preg_match('/\b(uso|aplicacion|trabajo)\b/', $label_norm)) {
                $question = '¿Para qué trabajo lo necesitas?';
                $priority = 0.20;
            } elseif (preg_match('/\b(compatibilidad|compatible|sistema)\b/', $label_norm)) {
                $question = '¿Con qué sistema debe ser compatible?';
                $priority = 0.18;
            }
            $axis = self::make_catalog_axis((array) ($attribute['values'] ?? array()), $total, array(
                'kind'        => 'attribute',
                'role'        => 'context',
                'group'       => $group,
                'filter_type' => 'attributes',
                'source'      => 'catalog_attribute',
                'priority'    => $priority,
                'question'    => $question,
            ));
            if ($axis) {
                $axes[] = $axis;
            }
        }

        if (!isset($answered_axes['group|category']) && !self::facet_items_match_query((array) ($facets['categories'] ?? array()), $query)) {
            $axis = self::make_catalog_axis((array) ($facets['categories'] ?? array()), $total, array(
                'kind'        => 'category',
                'role'        => 'object',
                'group'       => 'category',
                'filter_type' => 'categories',
                'source'      => 'category',
                'priority'    => 0.09,
                'question'    => $object
                    ? '¿Qué familia se parece más al “' . $object . '” que buscas?'
                    : ($action_question ?: '¿A qué familia de producto te refieres?'),
            ));
            if ($axis) {
                $axes[] = $axis;
            }
        }

        if (!isset($answered_axes['group|tag']) && !self::facet_items_match_query((array) ($facets['tags'] ?? array()), $query)) {
            $axis = self::make_catalog_axis((array) ($facets['tags'] ?? array()), $total, array(
                'kind'        => 'tag',
                'role'        => 'context',
                'group'       => 'tag',
                'filter_type' => 'tags',
                'source'      => 'catalog_tag',
                'priority'    => 0.05,
                'question'    => '¿Cuál de estas características encaja mejor con lo que quieres hacer?',
            ));
            if ($axis) {
                $axes[] = $axis;
            }
        }

        return $axes;
    }

    private static function make_catalog_axis($items, $total, $def) {
        $items = array_values(array_filter((array) $items, static function($item) {
            return is_array($item) && !empty($item['slug']) && !empty($item['label']) && absint($item['count'] ?? 0) > 0;
        }));
        if (count($items) < 2) {
            return array();
        }

        usort($items, static function($a, $b) {
            return absint($b['count'] ?? 0) <=> absint($a['count'] ?? 0);
        });
        $items = array_slice($items, 0, 6);
        $top = array_slice($items, 0, 4);
        $counts = array_map(static function($item) { return max(1, absint($item['count'] ?? 0)); }, $top);
        $sum = max(1, array_sum($counts));
        $max_share = max($counts) / $sum;
        $balance = max(0.0, min(1.0, (1.0 - $max_share) / 0.75));
        $coverage = max(0.0, min(1.0, $sum / max(1, absint($total))));
        $diversity = max(0.0, min(1.0, count($top) / 4));
        $priority = (float) ($def['priority'] ?? 0);
        $score = min(1.5, ($coverage * 0.42) + ($balance * 0.36) + ($diversity * 0.22) + $priority);
        $reduction = (int) round(max(0, min(0.95, 1.0 - $max_share)) * 100);

        $options = array();
        foreach ($top as $item) {
            $slug = sanitize_title((string) ($item['slug'] ?? ''));
            $label = sanitize_text_field((string) ($item['label'] ?? $slug));
            if (!$slug || !$label) {
                continue;
            }
            $filter_type = sanitize_key((string) ($def['filter_type'] ?? ''));
            $group = sanitize_key((string) ($def['group'] ?? ''));
            $filter = array();
            if (in_array($filter_type, array('vocabulary','attributes'), true)) {
                $filter = array('type'=>$filter_type, 'group'=>$group, 'slug'=>$slug);
            } elseif (in_array($filter_type, array('categories','tags','brands'), true)) {
                $filter = array('type'=>$filter_type, 'group'=>'','slug'=>$slug);
            }
            $options[] = array(
                'role'         => sanitize_key((string) ($def['role'] ?? 'context')),
                'value'        => $slug,
                'label'        => $label,
                'source'       => sanitize_key((string) ($def['source'] ?? 'catalog_orientation')),
                'source_group' => $group,
                'source_slug'  => $slug,
                'count'        => absint($item['count'] ?? 0),
                'filter'       => $filter,
            );
        }
        if (count($options) < 2) {
            return array();
        }

        return array(
            'kind'      => sanitize_key((string) ($def['kind'] ?? 'context')),
            'group'     => sanitize_key((string) ($def['group'] ?? '')),
            'role'      => sanitize_key((string) ($def['role'] ?? 'context')),
            'question'  => sanitize_text_field((string) ($def['question'] ?? '¿Cuál de estas opciones encaja mejor?')),
            'score'     => $score,
            'reduction' => $reduction,
            'options'   => $options,
        );
    }

    private static function choose_clarification_axis($axes, $preferred, $prefer_concrete_product_branch = false) {
        $axes = array_values(array_filter((array) $axes));
        if (!$axes) {
            return array();
        }
        $preferred = sanitize_key((string) $preferred);

        // Cuando falta saber qué producto quiere el cliente, no dejamos que un eje
        // contextual estadísticamente fuerte (material, uso, etiqueta...) desplace
        // la bifurcación comercial. Si existe al menos un eje de objeto/familia,
        // elegimos dentro de ese subconjunto.
        if ('object' === $preferred) {
            $object_axes = array_values(array_filter($axes, static function($axis) {
                $kind = sanitize_key((string) ($axis['kind'] ?? ''));
                $role = sanitize_key((string) ($axis['role'] ?? ''));
                return 'object' === $role || in_array($kind, array('type','subtype','category','role'), true);
            }));
            if ($object_axes) {
                $axes = $object_axes;
            }

            // Para una acción sin producto (“perforar”, “lijar”, “cortar”...),
            // “Herramienta / Accesorio / Equipamiento” es demasiado genérico como
            // primera bifurcación. Si el catálogo ofrece tipo, subtipo o categoría,
            // preguntamos por esa rama concreta y dejamos ROL solo como fallback.
            if ($prefer_concrete_product_branch) {
                $concrete_axes = array_values(array_filter($axes, static function($axis) {
                    $kind = sanitize_key((string) ($axis['kind'] ?? ''));
                    return in_array($kind, array('type','subtype','category'), true);
                }));
                if ($concrete_axes) {
                    $axes = $concrete_axes;
                }
            }
        }

        foreach ($axes as &$axis) {
            $bonus = 0.0;
            $kind = sanitize_key((string) ($axis['kind'] ?? ''));
            $role = sanitize_key((string) ($axis['role'] ?? ''));
            if ('object' === $preferred && ('object' === $role || in_array($kind, array('type','subtype','category','role'), true))) {
                $bonus = 0.24;
                if ($prefer_concrete_product_branch) {
                    // Primero una familia reconocible por el cliente; el subtipo
                    // queda ligeramente por detrás para no preguntar demasiado fino.
                    if ('type' === $kind) {
                        $bonus += 0.18;
                    } elseif ('category' === $kind) {
                        $bonus += 0.14;
                    } elseif ('subtype' === $kind) {
                        $bonus += 0.10;
                    }
                }
            } elseif ('intent' === $preferred && in_array($kind, array('application','tag','attribute'), true)) {
                $bonus = 0.25;
            } elseif ('context' === $preferred && 'context' === $role) {
                $bonus = 0.18;
            }
            $axis['_rank'] = (float) ($axis['score'] ?? 0) + $bonus;
        }
        unset($axis);
        usort($axes, static function($a, $b) {
            if ((float) ($a['_rank'] ?? 0) === (float) ($b['_rank'] ?? 0)) {
                return 0;
            }
            return (float) ($a['_rank'] ?? 0) < (float) ($b['_rank'] ?? 0) ? 1 : -1;
        });
        $best = $axes[0];
        unset($best['_rank']);
        return $best;
    }

    private static function controlled_intent_options() {
        return array(
            array('role'=>'intent','value'=>'reparar','label'=>'Reparar / arreglar','source'=>'interpreter_intent','source_group'=>'intent','source_slug'=>'reparar','filter'=>array()),
            array('role'=>'intent','value'=>'instalar','label'=>'Instalar / montar','source'=>'interpreter_intent','source_group'=>'intent','source_slug'=>'instalar','filter'=>array()),
            array('role'=>'intent','value'=>'sustituir','label'=>'Cambiar / sustituir','source'=>'interpreter_intent','source_group'=>'intent','source_slug'=>'sustituir','filter'=>array()),
            array('role'=>'intent','value'=>'comprar','label'=>'Comprar / elegir','source'=>'interpreter_intent','source_group'=>'intent','source_slug'=>'comprar','filter'=>array()),
        );
    }

    private static function interpretation_product_object($interpretation) {
        $concepts = isset($interpretation['concepts']) && is_array($interpretation['concepts']) ? $interpretation['concepts'] : array();
        foreach (array('producto','product','herramienta','tool') as $key) {
            if (empty($concepts[$key])) {
                continue;
            }
            $value = is_array($concepts[$key]) ? reset($concepts[$key]) : $concepts[$key];
            $value = self::normalize((string) $value);
            if ('' !== $value) {
                return $value;
            }
        }
        return '';
    }

    private static function interpretation_action($interpretation, $query = '') {
        $concepts = isset($interpretation['concepts']) && is_array($interpretation['concepts']) ? $interpretation['concepts'] : array();
        foreach (array('accion','action','intencion') as $key) {
            if (empty($concepts[$key])) {
                continue;
            }
            $value = is_array($concepts[$key]) ? reset($concepts[$key]) : $concepts[$key];
            $value = self::normalize((string) $value);
            if ('' !== $value) {
                return $value;
            }
        }

        $language = isset($interpretation['language']) && is_array($interpretation['language']) ? $interpretation['language'] : array();
        foreach ((array) ($language['verbs'] ?? array()) as $verb) {
            $lemma = self::normalize((string) ($verb['lemma'] ?? ''));
            if ('' !== $lemma) {
                return $lemma;
            }
        }

        // Antes de L6 puede no estar activa la gramática completa. Solo usamos
        // como respaldo un repertorio corto de acciones inequívocas del dominio;
        // no clasificamos cualquier palabra acabada en -ar/-er/-ir como verbo.
        $domain_actions = array(
            'taladrar','perforar','agujerear','cortar','lijar','pulir','atornillar','desatornillar',
            'apretar','aflojar','serrar','fresar','roscar','remachar','soldar','medir','nivelar',
            'inflar','clavar','grapar','pintar','limpiar','aspirar','demoler','romper','montar',
            'instalar','reparar','cambiar','sustituir','extraer','sacar','quitar','retirar'
        );
        foreach (array_values(array_filter(explode(' ', self::normalize((string) $query)))) as $token) {
            if (in_array($token, $domain_actions, true)) {
                return $token;
            }
        }
        return '';
    }

    private static function facet_items_match_query($items, $query) {
        $query = self::normalize((string) $query);
        if ('' === $query) {
            return false;
        }
        foreach ((array) $items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (array($item['label'] ?? '', str_replace('-', ' ', (string) ($item['slug'] ?? ''))) as $candidate) {
                $candidate = self::normalize((string) $candidate);
                if (strlen($candidate) >= 3 && self::contains_phrase($query, $candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function semantic_first_value($semantic, $role) {
        $values = array_values(array_filter((array) ($semantic['concepts'][$role] ?? array())));
        return $values ? sanitize_text_field((string) $values[0]) : '';
    }

    private static function semantic_unresolved_values($semantic) {
        $out = array();
        foreach ((array) ($semantic['groups'] ?? array()) as $group) {
            if ('term' !== sanitize_key((string) ($group['role'] ?? 'term'))) {
                continue;
            }
            $term = self::normalize((string) ($group['canonical'] ?? ''));
            if ($term && strlen($term) >= 2) {
                $out[$term] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Vista de prueba en STAGING. El entrenamiento real se almacena en el
     * lexico, pero esta pantalla nunca modifica datos al cargarla.
     */
    public static function render_tab() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder al Intérprete.', 'seo-taxonomy'));
        }

        $query = isset($_GET['interpreter_q'])
            ? self::clean_query(wp_unslash((string) $_GET['interpreter_q']))
            : '';
        $test = '' !== $query ? self::interpret($query) : array();
        $stats = class_exists('SEO_Dependiente_Interprete_DB')
            ? SEO_Dependiente_Interprete_DB::stats()
            : array('ready' => false, 'active' => 0, 'lesson_i1' => 0, 'linked_vocabulary' => 0);
        $linguista = class_exists('SEO_Dependiente_Linguista')
            ? SEO_Dependiente_Linguista::process_monitor_payload()
            : array();
        ?>
        <?php if (!empty($_GET['linguista_notice'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['linguista_notice'])))); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['linguista_error'])) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['linguista_error'])))); ?></p></div>
        <?php endif; ?>
        <div class="postbox seo-dependiente-admin__box" style="margin-top:16px; padding:18px;">
            <h2 style="margin-top:0;">Intérprete <small>v<?php echo esc_html(self::VERSION); ?></small></h2>
            <p><strong>Objetivo:</strong> enseñar al Dependiente a entender cómo habla el cliente.</p>
            <p>
                Memoria lingüística: <strong><?php echo !empty($stats['ready']) ? 'lista' : 'no disponible'; ?></strong>
                · expresiones activas: <strong><?php echo esc_html(number_format_i18n((int) ($stats['active'] ?? 0))); ?></strong>
                · I1 sujeto ↔ acción: <strong><?php echo esc_html(number_format_i18n((int) ($stats['lesson_i1'] ?? 0))); ?></strong>
                · enlazadas a Vocabulary: <strong><?php echo esc_html(number_format_i18n((int) ($stats['linked_vocabulary'] ?? 0))); ?></strong>
            </p>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="seo-dependiente">
                <input type="hidden" name="tab" value="interpreter">
                <p>
                    <label for="seo-dependiente-interpreter-q"><strong>Probar una pregunta</strong></label><br>
                    <input id="seo-dependiente-interpreter-q" type="text" class="large-text" name="interpreter_q" value="<?php echo esc_attr($query); ?>" placeholder="Ej.: Tengo que sacar un rodamiento muy agarrado. ¿Qué herramienta necesito?">
                </p>
                <?php submit_button('Interpretar', 'primary', '', false); ?>
            </form>

            <?php if ($test) : ?>
                <hr>
                <p><strong>Original:</strong> <?php echo esc_html((string) $test['original']); ?></p>
                <p><strong>Búsqueda resultante:</strong> <code><?php echo esc_html((string) $test['search_query']); ?></code></p>
                <p><strong>Cambio:</strong> <?php echo !empty($test['changed']) ? 'Sí' : 'No'; ?> · <strong>Confianza:</strong> <?php echo esc_html(number_format_i18n(((float) $test['confidence']) * 100, 0)); ?>%</p>
                <?php if (!empty($test['lesson_key'])) : ?>
                    <p><strong>Lección:</strong> <code><?php echo esc_html((string) $test['lesson_key']); ?></code></p>
                <?php endif; ?>
                <?php if (!empty($test['reason'])) : ?>
                    <p><strong>Motivo:</strong> <?php echo esc_html((string) $test['reason']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="postbox seo-dependiente-admin__box" style="padding:18px;">
            <h2 style="margin-top:0;">Lingüista · formación del Intérprete</h2>
            <p><strong>Objetivo:</strong> enseñar al Intérprete a comprender cómo habla el cliente y entregar al Dependiente una petición clara. Academia enseña catálogo al Dependiente; Lingüista enseña lenguaje al Intérprete.</p>
            <?php if (!$linguista) : ?>
                <div class="notice notice-error inline"><p><strong>Lingüista no está cargado.</strong> Revisa el bootstrap del Intérprete antes de iniciar la formación.</p></div>
            <?php else :
                $ling_state = isset($linguista['state']) && is_array($linguista['state']) ? $linguista['state'] : array();
                $ling_current = isset($linguista['current']) && is_array($linguista['current']) ? $linguista['current'] : array();
                $ling_rows = isset($linguista['lesson_statuses']) && is_array($linguista['lesson_statuses']) ? $linguista['lesson_statuses'] : array();
                $ling_progress = absint($linguista['progress'] ?? 0);
                $ling_status = sanitize_key((string) ($ling_state['status'] ?? 'stopped'));
                $ling_running = !empty($linguista['running']);
                $status_labels = array(
                    'stopped' => 'Preparado',
                    'running' => 'En formación',
                    'paused' => 'Pausado',
                    'error' => 'Error',
                    'completed' => 'Curso completado',
                );
                $status_label = $status_labels[$ling_status] ?? $ling_status;
            ?>
                <p>
                    Estado: <strong><?php echo esc_html($status_label); ?></strong>
                    · progreso: <strong><?php echo esc_html(number_format_i18n($ling_progress)); ?>%</strong>
                    · lote actual: <strong><?php echo esc_html(number_format_i18n(absint($ling_state['batch_size'] ?? 0))); ?></strong>
                    · memoria activa: <strong><?php echo esc_html(number_format_i18n((int) ($stats['active'] ?? 0))); ?></strong>
                    · evidencias: <strong><?php echo esc_html(number_format_i18n((int) ($stats['evidence'] ?? 0))); ?></strong>
                </p>
                <div style="height:10px;background:#dcdcde;border-radius:8px;overflow:hidden;max-width:760px;margin:8px 0 14px;">
                    <div style="height:100%;width:<?php echo esc_attr((string) $ling_progress); ?>%;background:#2271b1;"></div>
                </div>
                <?php if ($ling_current) : ?>
                    <p>Lección actual: <strong>L<?php echo esc_html((string) absint($ling_current['order'] ?? 0)); ?> · <?php echo esc_html((string) ($ling_current['title'] ?? '')); ?></strong></p>
                <?php endif; ?>
                <?php if (!empty($ling_state['last_message'])) : ?>
                    <p class="description"><?php echo esc_html((string) $ling_state['last_message']); ?></p>
                <?php endif; ?>
                <?php if (!empty($ling_state['last_error'])) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html((string) $ling_state['last_error']); ?></p></div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px;">
                    <?php if ($ling_running) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="pause">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button">Pausar formación</button>
                        </form>
                    <?php elseif (in_array($ling_status, array('paused','error'), true)) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="resume">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button button-primary">Reanudar formación</button>
                        </form>
                    <?php elseif ('completed' !== $ling_status) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="start">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button button-primary">Iniciar formación Lingüista</button>
                        </form>
                    <?php endif; ?>
                    <?php if ('completed' === $ling_status) : ?>
                        <a class="button button-primary" href="<?php echo esc_url(SEO_Dependiente_Linguista::export_url('course')); ?>">Descargar evolución completa JSON</a>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Reentrenar Lingüista desde L1? La memoria aprendida no se borra; se vuelve a validar.');">
                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                            <input type="hidden" name="command" value="restart_course">
                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                            <button type="submit" class="button">Reentrenar desde L1</button>
                        </form>
                    <?php endif; ?>
                </div>

                <table class="widefat striped" style="max-width:1100px;">
                    <thead><tr><th>Lección</th><th>Estado</th><th>Progreso</th><th>Aprendido / revisado</th><th>Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($ling_rows as $row) :
                        $row_status = sanitize_key((string) ($row['status'] ?? 'pending'));
                        $row_labels = array('completed'=>'Completada','running'=>'En curso','paused'=>'Pausada','error'=>'Error','pending'=>'Pendiente','locked'=>'Bloqueada');
                    ?>
                        <tr>
                            <td><strong>L<?php echo esc_html((string) absint($row['order'] ?? 0)); ?> · <?php echo esc_html((string) ($row['title'] ?? '')); ?></strong><br><small><?php echo esc_html((string) ($row['goal'] ?? '')); ?></small></td>
                            <td><?php echo esc_html($row_labels[$row_status] ?? $row_status); ?></td>
                            <td><?php echo esc_html(number_format_i18n(absint($row['progress'] ?? 0))); ?>%</td>
                            <td><?php echo esc_html(number_format_i18n(absint($row['learned'] ?? 0))); ?> aprendidas · <?php echo esc_html(number_format_i18n(absint($row['rejected'] ?? 0))); ?> rechazadas · <?php echo esc_html(number_format_i18n(absint($row['processed'] ?? 0))); ?> procesadas</td>
                            <td>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <?php if ('completed' === $row_status) : ?>
                                        <a class="button button-small" href="<?php echo esc_url(SEO_Dependiente_Linguista::export_url('lesson', (string) ($row['key'] ?? ''))); ?>">Descargar JSON</a>
                                    <?php endif; ?>
                                    <?php if (!$ling_running && in_array($row_status, array('completed','paused','error'), true)) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Reentrenar desde esta lección? Los resultados posteriores se invalidarán, pero no se borrará la memoria lingüística ya aprendida.');" style="margin:0;">
                                            <input type="hidden" name="action" value="seo_dependiente_linguista_control">
                                            <input type="hidden" name="command" value="retrain_from">
                                            <input type="hidden" name="lesson_key" value="<?php echo esc_attr((string) ($row['key'] ?? '')); ?>">
                                            <?php wp_nonce_field('seo_dependiente_linguista_control'); ?>
                                            <button type="submit" class="button button-small">Reentrenar desde aquí</button>
                                        </form>
                                    <?php elseif ('completed' !== $row_status) : ?>—<?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="description" style="margin-top:14px;"><strong>Informes:</strong> al completar cada lección queda disponible su JSON con métricas y fotografía de memoria antes/después. Al terminar L8 aparece además el JSON de evolución completa L1 → L8.</p>
                <p class="description"><strong>Worker:</strong> Lingüista solo empieza cuando lo arrancas aquí. Después el gestor le concede ventanas de trabajo y procesa lotes pequeños/adaptativos; nunca lanza toda una lección de golpe.</p>
            <?php endif; ?>
            <p class="description">Regresión base conservada: <code>extractor ↔ extraer/sacar</code>, <code>taladro ↔ taladrar/perforar/agujerear</code> y desambiguación de compresor de aire.</p>
        </div>
        <?php
    }

    private static function grammar_settings() {
        $stored = get_option('seo_dependiente_interprete_grammar', array());
        return is_array($stored) ? $stored : array();
    }

    private static function morphology_settings() {
        if (is_array(self::$morphology)) {
            return self::$morphology;
        }
        $stored = get_option('seo_dependiente_interprete_morphology', array());
        self::$morphology = is_array($stored) ? $stored : array();
        return self::$morphology;
    }

    /**
     * Analiza castellano antes de buscar: reconoce palabras funcionales, formas
     * verbales (incluidas irregulares aprendidas) y construye una versión
     * semántica sin ruido. No modifica datos ni aprende durante la petición.
     */
    private static function analyze_language($normalized) {
        $normalized = self::normalize($normalized);
        $base = array(
            'enabled'        => false,
            'semantic_query' => $normalized,
            'content_tokens' => array_values(array_filter(explode(' ', $normalized))),
            'removed_tokens' => array(),
            'verbs'          => array(),
            'tokens'         => array(),
        );
        if ('' === $normalized) {
            return $base;
        }

        $grammar = self::grammar_settings();
        if (empty($grammar['natural_language'])) {
            return $base;
        }
        $base['enabled'] = true;

        $stopword_map = array();
        foreach ((array) ($grammar['stopword_groups'] ?? array()) as $group => $words) {
            foreach ((array) $words as $word) {
                $word = self::normalize($word);
                if ('' !== $word && false === strpos($word, ' ')) {
                    $stopword_map[$word] = sanitize_key((string) $group);
                }
            }
        }
        $semantic_stopwords = array_flip(array_values(array_filter(array_map(array(__CLASS__, 'normalize'), (array) ($grammar['semantic_stopwords'] ?? array())))));
        $stop_verbs = array_flip(array_values(array_filter(array_map(array(__CLASS__, 'normalize'), (array) ($grammar['stop_verbs'] ?? array())))));
        $irregular = array();
        foreach ((array) ($grammar['irregular_forms'] ?? array()) as $form => $lemma) {
            $form = self::normalize($form);
            $lemma = self::normalize($lemma);
            if ('' !== $form && '' !== $lemma && false === strpos($form, ' ') && false === strpos($lemma, ' ')) {
                $irregular[$form] = $lemma;
            }
        }
        $morphology = self::morphology_settings();
        $forms = isset($morphology['forms']) && is_array($morphology['forms']) ? $morphology['forms'] : array();

        $semantic = array();
        foreach (array_values(array_filter(explode(' ', $normalized))) as $token) {
            $role = isset($stopword_map[$token]) ? $stopword_map[$token] : 'content';
            $lemma = '';
            $verb_source = '';

            if (isset($forms[$token]) && is_string($forms[$token]) && '' !== $forms[$token]) {
                $lemma = self::normalize($forms[$token]);
                $verb_source = 'learned_morphology';
            } elseif (isset($irregular[$token])) {
                $lemma = $irregular[$token];
                $verb_source = 'irregular_dictionary';
            } elseif (preg_match('/(?:ar|er|ir)$/', $token)) {
                $lemma = $token;
                $verb_source = 'infinitive';
            }

            if ('' !== $lemma) {
                $role = isset($stop_verbs[$lemma]) ? 'stop_verb' : 'verb';
                $base['verbs'][] = array('surface' => $token, 'lemma' => $lemma, 'source' => $verb_source);
            }

            $remove = ('stop_verb' === $role) || isset($semantic_stopwords[$token]);
            $base['tokens'][] = array(
                'token'   => $token,
                'role'    => $role,
                'lemma'   => $lemma,
                'removed' => $remove ? 1 : 0,
            );
            if ($remove) {
                $base['removed_tokens'][] = $token;
                continue;
            }
            $semantic[] = '' !== $lemma ? $lemma : $token;
        }

        $semantic = array_values(array_filter($semantic));
        $base['content_tokens'] = $semantic;
        $base['semantic_query'] = implode(' ', $semantic);
        return $base;
    }

    private static function detect_intent($normalized) {
        $normalized = self::normalize($normalized);
        if ('' === $normalized) {
            return 'unknown';
        }
        $grammar = self::grammar_settings();
        if (empty($grammar['intent_detection']) || empty($grammar['intents']) || !is_array($grammar['intents'])) {
            return 'find_product';
        }
        // Las intenciones más específicas se prueban antes que la búsqueda genérica.
        $order = array('compare','compatibility','replacement','accessory','solve_problem','find_product');
        foreach ($order as $intent) {
            foreach ((array) ($grammar['intents'][$intent] ?? array()) as $phrase) {
                if (self::contains_phrase($normalized, self::normalize($phrase))) {
                    return $intent;
                }
            }
        }
        return 'find_product';
    }

    private static function rewrite($result, $search_query, $confidence, $rule, $reason, $concepts = array(), $lesson_key = '') {
        $result['search_query'] = self::clean_query($search_query);
        $result['changed'] = self::normalize((string) $result['search_query']) !== (string) $result['normalized'];
        $result['confidence'] = min(1, max(0, (float) $confidence));
        $result['rule'] = sanitize_key((string) $rule);
        $result['reason'] = (string) $reason;
        $result['concepts'] = is_array($concepts) ? $concepts : array();
        $result['lesson_key'] = sanitize_key((string) $lesson_key);
        return $result;
    }

    private static function clean_query($value) {
        $value = sanitize_text_field((string) $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1000, 'UTF-8');
        }
        return substr($value, 0, 1000);
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function has_any($normalized, $terms) {
        foreach ((array) $terms as $term) {
            if (self::contains_phrase($normalized, self::normalize($term))) {
                return true;
            }
        }
        return false;
    }

    private static function contains_phrase($haystack, $needle) {
        $haystack = trim((string) $haystack);
        $needle = trim((string) $needle);
        if ('' === $haystack || '' === $needle) {
            return false;
        }
        return false !== strpos(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }

    private static function plural_search_term($canonical) {
        $map = array(
            'rodamiento' => 'rodamientos',
            'polea'      => 'poleas',
            'engranaje'  => 'engranajes',
            'rotula'     => 'rotulas',
        );
        return $map[$canonical] ?? $canonical;
    }

    private static function canonical_context_term($term) {
        $term = self::normalize($term);
        $map = array(
            'rodamiento' => 'rodamientos',
            'rodamientos' => 'rodamientos',
            'cojinete' => 'rodamientos',
            'cojinetes' => 'rodamientos',
            'polea' => 'poleas',
            'poleas' => 'poleas',
            'engranaje' => 'engranajes',
            'engranajes' => 'engranajes',
            'rotula' => 'rotulas',
            'rotulas' => 'rotulas',
        );
        return $map[$term] ?? $term;
    }
}
