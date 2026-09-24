<?php
/**
 * Solucionador - normalizacion semantica conservadora.
 *
 * No sustituye al Interprete. Cuando Dependiente ya ha identificado intent,
 * object/context/state, esas senales son la fuente preferente. Las heuristicas
 * solo sirven para fuentes editoriales que no traen estructura (Analista,
 * Comentarista, Auditor y cobertura de posts).
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Normalizer {
    private static function action_map() {
        return array(
            'reparar' => array('reparar','reparacion','arreglar','solucionar','restaurar'),
            'quitar' => array('quitar','sacar','extraer','extraccion','retirar','desmontar'),
            'aflojar' => array('aflojar','desapretar'),
            'apretar' => array('apretar','ajustar'),
            'perforar' => array('perforar','perforacion','agujerear','taladrar','taladrado'),
            'cortar' => array('cortar','corte','seccionar','recortar'),
            'lijar' => array('lijar','lijado','desbastar','pulir','pulido'),
            'medir' => array('medir','medicion','comprobar','comprobacion','verificar','diagnosticar','diagnostico','testear'),
            'detectar' => array('detectar','deteccion','localizar','localizacion','encontrar'),
            'soldar' => array('soldar','soldadura','desoldar'),
            'limpiar' => array('limpiar','limpieza','desatascar','desatasco','desobstruir'),
            'pintar' => array('pintar','pintura','repintar'),
            'montar' => array('montar','montaje','instalar','instalacion','colocar'),
            'sustituir' => array('sustituir','sustitucion','cambiar','cambio','reemplazar','reemplazo'),
            'cargar' => array('cargar','carga','recargar','recarga'),
            'arrancar' => array('arrancar','arranque','encender','poner en marcha'),
            'elevar' => array('elevar','elevacion','levantar','alzar'),
            'elegir' => array('elegir','eleccion','seleccionar','seleccion'),
            'usar' => array('usar','utilizar','uso'),
            'resolver' => array('resolver'),
        );
    }

    private static function conditions() {
        return array(
            'atascado' => array('atascado','atascada','bloqueado','bloqueada','agarrotado','agarrotada'),
            'roto' => array('roto','rota','partido','partida','fracturado','fracturada'),
            'oxidado' => array('oxidado','oxidada','corroido','corroida'),
            'desgastado' => array('desgastado','desgastada','pasado','pasada','barrido','barrida'),
            'fuga' => array('fuga','fugas','pierde','gotea','goteo'),
            'no_funciona' => array('no funciona','no va','no responde','ha dejado de funcionar'),
            'no_arranca' => array('no arranca','no enciende'),
            'no_carga' => array('no carga','no recarga'),
            'sin_fuerza' => array('sin fuerza','no tiene fuerza','se queda corto','se queda corta'),
            'ruido' => array('hace ruido','mucho ruido','ruidoso','ruidosa'),
            'gira_en_vacio' => array('gira en vacio','gira pero no sale','da vueltas pero no sale','gira loco','gira loca'),
        );
    }

    private static function problem_objects() {
        return array('fuga','atasco','pinchazo','averia');
    }

    private static function context_terms() {
        // Contextos/materiales. Pared, muro, techo, suelo y tuberia se dejan como
        // posibles objetos para no producir perfiles como object=madera/context=pared.
        return array(
            'hormigon','cemento','metal','madera','acero','aluminio','vidrio','cristal',
            'coche','moto','vehiculo','agua','jardin','piscina','bateria','motor','electronica'
        );
    }

    private static function stopwords() {
        return array_flip(array(
            'a','al','algo','como','con','contra','cual','cuales','de','del','desde','el','ella','en','es','esta','este','esto',
            'hacer','ha','hay','la','las','lo','los','me','mi','mis','necesito','no','para','por','porque','que','quiero','se','sin',
            'su','sus','tengo','tiene','un','una','unos','unas','y','ya','muy','mas','puedo','podria','debo','deberia','segun'
        ));
    }

    private static function generic_object_words() {
        return array_flip(array(
            'antes','despues','primero','primera','segundo','segunda','datos','dato','detalle','detalles','idea','clave','regla','punto',
            'cuando','donde','como','porque','debes','debe','puede','puedo','necesitas','necesita','beneficios','beneficio','claro',
            'forma','formas','manera','maneras','pasos','paso','proceso','procesos','opcion','opciones','cosas','cosa','tema','temas',
            'revisar','comprar','elegir','herramientas','herramienta','equipo','equipos','sistema','sistemas',
            'positivo','negativo','mixto','resumen','editorial','literal','comentario','comentarios','resena','resenas',
            'opinion','opiniones','valoracion','valoraciones','conclusion','conclusiones','recomendacion','recomendaciones',
            'ventaja','ventajas','desventaja','desventajas','experiencia','experiencias'
        ));
    }

    public static function normalize($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]+/', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));
        return (string) $text;
    }

    private static function normalize_term($text) {
        $text = self::normalize($text);
        $text = str_replace(array('-', '_'), ' ', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));
        return (string) $text;
    }

    private static function contains_phrase($normalized, $phrase) {
        $phrase = self::normalize($phrase);
        if ($phrase === '') return false;
        return (bool) preg_match('/(^|\s)' . preg_quote($phrase, '/') . '(\s|$)/u', $normalized);
    }

    private static function canonical_singular($term) {
        $term = self::normalize_term($term);
        $map = array(
            'fugas'=>'fuga','paredes'=>'pared','muros'=>'muro','techos'=>'techo','suelos'=>'suelo','tuberias'=>'tuberia',
            'tornillos'=>'tornillo','tuercas'=>'tuerca','brocas'=>'broca','baterias'=>'bateria','motores'=>'motor','coches'=>'coche',
            'motos'=>'moto','vehiculos'=>'vehiculo','compresores'=>'compresor','bombas'=>'bomba','neumaticos'=>'neumatico',
            'rodamientos'=>'rodamiento','cables'=>'cable','enchufes'=>'enchufe','grifos'=>'grifo','puertas'=>'puerta','ruedas'=>'rueda'
        );
        return $map[$term] ?? $term;
    }

    public static function canonical_token($token) {
        $token = self::canonical_singular($token);
        $map = array(
            'deteccion'=>'detectar','detectar'=>'detectar','localizacion'=>'detectar','localizar'=>'detectar',
            'perforacion'=>'perforar','taladrado'=>'perforar','taladrar'=>'perforar','agujerear'=>'perforar',
            'reparacion'=>'reparar','arreglar'=>'reparar','extraccion'=>'quitar','extraer'=>'quitar','sacar'=>'quitar',
            'instalacion'=>'montar','montaje'=>'montar','instalar'=>'montar','sustitucion'=>'sustituir','reemplazo'=>'sustituir',
            'reemplazar'=>'sustituir','medicion'=>'medir','comprobacion'=>'medir','diagnostico'=>'medir','lijado'=>'lijar',
            'soldadura'=>'soldar','elevacion'=>'elevar','eleccion'=>'elegir','seleccion'=>'elegir'
        );
        return $map[$token] ?? $token;
    }

    public static function is_solution_signal($text, $intent = '', $state = '') {
        $intent_n = self::normalize_term($intent);
        $state_n = self::normalize_term($state);
        if ($state_n !== '') return true;
        if (preg_match('/proble|repair|solucion|need|neces|aver|manten|diagn|compat|replace|sustit|repuesto|procedure|how_to/u', $intent_n)) return true;
        $n = self::normalize($text);
        if ($n === '') return false;
        if (strpos($n, 'sacar en claro') !== false) return false;
        foreach (self::conditions() as $variants) {
            foreach ($variants as $variant) {
                if (self::contains_phrase($n, $variant)) return true;
            }
        }
        foreach (self::action_map() as $variants) {
            foreach ($variants as $variant) {
                if (self::contains_phrase($n, $variant)) return true;
            }
        }
        return (bool) preg_match('/\b(como|que hago|necesito|quiero|problema|averia)\b/u', $n);
    }

    private static function strip_editorial_prefix($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^\s*\[[^\]]*(resumen|editorial|cita)[^\]]*\]\s*/iu', '', $text);
        $text = preg_replace('/^\s*(positivo(?:\s+con\s+matiz|\s+con\s+limite\s+de\s+uso)?|negativo|mixto|resumen(?:\s+editorial)?|valoracion|opinion)\s*:\s*/iu', '', $text);
        return trim((string) $text);
    }

    private static function explicit_problem_statement($text) {
        $n = self::normalize($text);
        if ($n === '') return false;
        return (bool) preg_match('/\b(no funciona|no carga|no arranca|no responde|falla|fallo|fallos|problema|problemas|limitad[oa]s?|insuficiente|insuficientes|falta|faltan|echa en falta|dificultad|dificultades|lento|lenta|lentamente|oscilacion|oscilaciones|pierde|gotea|atasca|atascado|bloquea|bloqueado|se rompe|roto|ruido|ruidoso|autonomia limitada|poca autonomia)\b/u', $n);
    }

    /**
     * Comentarista no es una fuente de preguntas por defecto.
     * - Una pregunta explicita puede originar una propuesta.
     * - Un problema/limitacion narrado en una review solo refuerza un tema ya
     *   detectado por cliente/Analista/Auditor.
     * - Valoraciones positivas/editoriales no generan temas.
     */
    public static function extract_comentarista_signals($text, $limit = 4) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') return array();
        $parts = preg_split('/(?<=[\.\?\!])\s+|[\r\n]+/u', $text);
        $out = array();
        foreach ((array) $parts as $part) {
            $part = self::strip_editorial_prefix($part);
            if ($part === '') continue;
            $len = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
            if ($len < 12 || $len > 360) continue;

            $is_question = strpos($part, '?') !== false;
            $is_problem = self::explicit_problem_statement($part);
            if (!$is_question && !$is_problem) continue;

            // Preguntas reales pueden originar. Las afirmaciones de review son
            // evidencia secundaria para no fabricar posts desde sentimiento.
            $out[] = array(
                'text' => $part,
                'proposal_role' => $is_question ? 'origin' : 'reinforcement',
                'kind' => $is_question ? 'question' : 'problem_statement',
            );
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public static function editorial_type($title, $content = '') {
        $n = self::normalize($title);
        if ($n === '') return 'informational';

        if (preg_match('/\b(multa|multas|dgt|normativa|obligacion|sancion|sanciones|ley|legal)\b/u', $n)) return 'legal';
        if (preg_match('/\b(comparativa|comparar|vs|versus)\b/u', $n)) return 'comparison';
        if (preg_match('/\b(guia de compra|cual elegir|como elegir|que elegir|mejor modelo|mejores modelos|antes de comprar)\b/u', $n)) return 'buying_guide';
        if (preg_match('/^(como|que hacer si|que hacer cuando)\b/u', $n)) return 'how_to';
        if (preg_match('/\b(error|errores|problema|problemas|averia|averias|no funciona|no arranca|no carga|fuga|fugas|atascado|atascada|roto|rota)\b/u', $n)) return 'problem';

        return 'informational';
    }

    public static function coverage_eligible_editorial_type($type) {
        return in_array(sanitize_key((string) $type), array('how_to','problem','buying_guide','solution'), true);
    }

    public static function extract_problem_sentences($text, $limit = 3) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') return array();
        $parts = preg_split('/(?<=[\.\?\!])\s+|[\r\n]+/u', $text);
        $out = array();
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($part === '') continue;
            $len = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
            if ($len < 12 || $len > 360) continue;
            if (strpos($part, '?') !== false || self::is_solution_signal($part)) {
                $out[] = $part;
                if (count($out) >= $limit) break;
            }
        }
        return array_values(array_unique($out));
    }

    private static function detect_action($normalized) {
        foreach (self::action_map() as $canonical => $variants) {
            foreach ($variants as $variant) {
                if (self::contains_phrase($normalized, $variant)) return $canonical;
            }
        }
        return '';
    }

    private static function detect_condition($normalized) {
        foreach (self::conditions() as $canonical => $variants) {
            foreach ($variants as $variant) {
                // Limites de palabra: "desbloqueada" ya no activa "bloqueada".
                if (self::contains_phrase($normalized, $variant)) return $canonical;
            }
        }
        return '';
    }

    private static function detect_context($normalized) {
        foreach (self::context_terms() as $term) {
            if (self::contains_phrase($normalized, $term)) return self::canonical_singular($term);
        }
        return '';
    }

    private static function action_words() {
        $out = array();
        foreach (self::action_map() as $canonical => $variants) {
            $out[self::normalize($canonical)] = true;
            foreach ($variants as $v) {
                foreach (preg_split('/\s+/u', self::normalize($v)) as $w) $out[$w] = true;
            }
        }
        return $out;
    }

    private static function condition_words() {
        $out = array();
        foreach (self::conditions() as $canonical => $variants) {
            $out[self::normalize($canonical)] = true;
            foreach ($variants as $v) {
                foreach (preg_split('/\s+/u', self::normalize($v)) as $w) $out[$w] = true;
            }
        }
        return $out;
    }

    private static function detect_object($normalized, $action, $condition, $context) {
        // Patrones de alta precision para problemas/acciones frecuentes.
        if ($action === 'detectar' && preg_match('/\bfugas?\b/u', $normalized)) return 'fuga';
        if ($action === 'perforar') {
            foreach (array('pared','muro','techo','suelo','viga','azulejo','baldosa','chapa','tubo','tuberia','pieza') as $target) {
                if (self::contains_phrase($normalized, $target)) return $target;
            }
            if ($context !== '') return $context;
        }
        if (in_array($action, array('cortar','lijar','soldar','pintar'), true)) {
            foreach (array('tubo','tuberia','barra','chapa','perfil','pieza','pared','muro','techo','suelo','puerta') as $target) {
                if (self::contains_phrase($normalized, $target)) return $target;
            }
            if ($context !== '') return $context;
        }
        if ($action === 'reparar') {
            foreach (array('grifo','tuberia','motor','coche','moto','bomba','compresor','puerta','rueda','neumatico','bateria') as $target) {
                if (self::contains_phrase($normalized, $target)) return $target;
            }
        }
        if (in_array($condition, array('atascado','oxidado','desgastado','gira_en_vacio','roto'), true)) {
            $cond_positions = array();
            foreach (self::conditions()[$condition] ?? array() as $variant) {
                $p = strpos(' ' . $normalized . ' ', ' ' . self::normalize($variant) . ' ');
                if ($p !== false) $cond_positions[] = $p;
            }
            foreach (array('tornillo','tuerca','perno','rodamiento','cojinete','grifo','puerta','rueda','broca','tubo','tuberia') as $target) {
                if (self::contains_phrase($normalized, $target)) return $target;
            }
        }

        $tokens = preg_split('/\s+/u', $normalized);
        $stop = self::stopwords();
        $generic = self::generic_object_words();
        $blocked = array_flip(array_filter(array($action, $context)));
        $action_words = self::action_words();
        $condition_words = self::condition_words();
        $problem_objects = array_flip(self::problem_objects());

        // Si el problema es el sustantivo principal (p.ej. "deteccion de fugas"),
        // se conserva como objeto en vez de descartarlo como estado.
        foreach ((array) $tokens as $token) {
            $token = self::canonical_singular($token);
            if (isset($problem_objects[$token]) && in_array($action, array('detectar','resolver','reparar'), true)) return $token;
        }

        $candidates = array();
        foreach ((array) $tokens as $token) {
            $token = self::canonical_singular(trim((string) $token));
            if ($token === '' || isset($stop[$token]) || isset($generic[$token]) || isset($blocked[$token]) || isset($action_words[$token]) || isset($condition_words[$token])) continue;
            if (is_numeric($token) || strlen($token) < 3) continue;
            $candidates[] = $token;
        }
        return $candidates ? (string) $candidates[0] : '';
    }

    private static function detect_intent($normalized, $action, $condition, $object, $hint = '') {
        $hint = sanitize_key((string) $hint);
        if ($hint !== '' && !in_array($hint, array('linguistic_bridge','search','need'), true)) return $hint;
        if ($condition !== '' || in_array($object, self::problem_objects(), true)) return 'problem';
        if (preg_match('/\b(como|pasos|procedimiento)\b/u', $normalized)) return 'procedure';
        if ($action !== '') return 'need';
        return 'question';
    }

    private static function key_intent($intent) {
        $intent = sanitize_key((string) $intent);
        if ($intent === 'problem') return 'problem';
        if (preg_match('/compat/', $intent)) return 'compatibility';
        if (preg_match('/compar|choice|decision/', $intent)) return 'decision';
        return 'task';
    }

    public static function profile($text, array $hints = array()) {
        $normalized = self::normalize($text);
        if ($normalized === '') return array();

        $action = self::canonical_token(self::normalize_term($hints['action'] ?? ''));
        if ($action === '') $action = self::detect_action($normalized);

        $condition = self::normalize_term($hints['state'] ?? $hints['condition'] ?? '');
        if ($condition === '') $condition = self::detect_condition($normalized);
        $condition = str_replace(' ', '_', $condition);

        $context = self::canonical_singular(self::normalize_term($hints['context'] ?? ''));
        if ($context === '') $context = self::detect_context($normalized);

        $object = self::canonical_singular(self::normalize_term($hints['object'] ?? ''));
        if ($object === '' || isset(self::generic_object_words()[$object])) {
            $object = self::detect_object($normalized, $action, $condition, $context);
        }
        if ($object === '' && $context !== '' && in_array($action, array('perforar','cortar','lijar','soldar','pintar'), true)) {
            $object = $context;
        }
        if ($object !== '' && $object === $context) $context = '';

        // "fuga" puede ser objeto o estado. Si es el objeto principal, no se
        // duplica como condition=fuga.
        if ($object === 'fuga' && $condition === 'fuga') $condition = '';

        $intent = self::detect_intent($normalized, $action, $condition, $object, $hints['intent'] ?? '');
        if ($action === '' && $condition !== '') $action = 'resolver';

        if ($object === '' || ($action === '' && $condition === '')) return array();

        $parts = array(self::key_intent($intent), $action ?: 'resolver', $object, $condition ?: 'general', $context ?: 'general');
        $parts = array_map(static function($v){ return sanitize_title((string) $v); }, $parts);
        $key = implode('|', $parts);
        if (strlen($key) > 191) $key = substr($key, 0, 191);

        $confidence = 0.58;
        if (!empty($hints['object'])) $confidence += 0.14;
        if (!empty($hints['state']) || !empty($hints['condition'])) $confidence += 0.10;
        if (!empty($hints['context'])) $confidence += 0.05;
        if ($action !== '' && $action !== 'resolver') $confidence += 0.08;
        if (isset(self::generic_object_words()[$object])) $confidence -= 0.20;
        $confidence = min(0.98, max(0.20, $confidence));

        return array(
            'normalized' => $normalized,
            'intent' => $intent,
            'key_intent' => self::key_intent($intent),
            'action' => $action,
            'object' => $object,
            'condition' => $condition,
            'context' => $context,
            'canonical_key' => $key,
            'confidence' => $confidence,
        );
    }

    public static function is_weak_profile(array $profile) {
        $object = self::normalize_term($profile['object'] ?? '');
        if ($object === '' || isset(self::generic_object_words()[$object])) return true;
        if ((float) ($profile['confidence'] ?? 0) < 0.50) return true;
        return false;
    }

    private static function human($term) {
        $term = trim(str_replace('_', ' ', (string) $term));
        $map = array(
            'hormigon' => 'hormigón','tuberia' => 'tubería','vehiculo' => 'vehículo','jardin' => 'jardín',
            'bateria' => 'batería','deteccion'=>'detección','gira en vacio' => 'gira en vacío','neumatico'=>'neumático'
        );
        return $map[$term] ?? $term;
    }

    private static function feminine($word) {
        $word = self::normalize($word);
        if ($word === '') return false;
        if (preg_match('/(a|dad|tad|cion|sion|umbre)$/', $word)) return true;
        return in_array($word, array('pared','moto','mano','fuga','tuberia','bateria'), true);
    }

    private static function with_article($term) {
        $term = self::human($term);
        if ($term === '') return '';
        $first = preg_split('/\s+/u', self::normalize($term));
        $first = $first ? (string) $first[0] : $term;
        if (in_array($first, array('hormigon','cemento','metal','madera','acero','aluminio','vidrio','cristal'), true)) return $term;
        return (self::feminine($first) ? 'una ' : 'un ') . $term;
    }

    private static function agree_condition($condition, $object) {
        $condition = self::human($condition);
        if ($condition === '') return '';
        if (!self::feminine($object)) return $condition;
        $parts = preg_split('/\s+/u', $condition);
        if (!$parts) return $condition;
        $first = (string) $parts[0];
        if (preg_match('/o$/', $first)) $parts[0] = preg_replace('/o$/', 'a', $first);
        return implode(' ', $parts);
    }

    private static function problem_phrase($object_raw, $condition_raw) {
        $object = self::with_article($object_raw);
        $condition = self::normalize_term($condition_raw);
        if ($condition === '') return $object;
        if ($condition === 'fuga') return $object . ' tiene una fuga';
        if ($condition === 'no funciona') return $object . ' no funciona';
        if ($condition === 'no arranca') return $object . ' no arranca';
        if ($condition === 'no carga') return $object . ' no carga';
        if ($condition === 'sin fuerza') return $object . ' no tiene fuerza';
        if ($condition === 'ruido') return $object . ' hace ruido';
        if ($condition === 'gira en vacio') return $object . ' gira en vacío';
        return $object . ' está ' . self::agree_condition($condition, $object_raw);
    }

    public static function suggested_title(array $profile) {
        $object_raw = trim((string) ($profile['object'] ?? ''));
        $action = trim((string) ($profile['action'] ?? ''));
        $condition_raw = str_replace('_', ' ', trim((string) ($profile['condition'] ?? '')));
        $context_raw = trim((string) ($profile['context'] ?? ''));
        if ($object_raw === '') return '';

        $object = self::with_article($object_raw);
        $condition = self::agree_condition($condition_raw, $object_raw);
        $context = self::human($context_raw);

        if ($action === 'detectar') {
            $target = $object;
            if ($context !== '' && self::normalize($context) !== self::normalize($object_raw)) $target .= ' de ' . $context;
            return 'Cómo detectar ' . $target . ': señales, pruebas y herramientas';
        }
        if ($action === 'resolver' && $condition_raw !== '') {
            return 'Qué hacer si ' . self::problem_phrase($object_raw, $condition_raw) . ': causas, solución y herramientas';
        }
        if ($action === 'reparar') {
            return 'Cómo reparar ' . $object . ($condition ? ' cuando está ' . $condition : '') . ': qué comprobar y qué herramientas necesitas';
        }
        if ($action === 'perforar') {
            $target = $object;
            if ($context !== '' && self::normalize($context) !== self::normalize($object_raw)) $target .= ' de ' . $context;
            return 'Cómo perforar ' . $target . ': qué herramienta usar y cómo hacerlo';
        }
        if ($action === 'quitar' || $action === 'aflojar') {
            return 'Cómo ' . $action . ' ' . $object . ($condition ? ' ' . $condition : '') . ': métodos y herramientas';
        }
        if ($action === 'elegir') {
            return 'Cómo elegir ' . $object . ($context ? ' para ' . $context : '') . ': qué debes comparar';
        }
        $verb = $action !== '' && $action !== 'resolver' ? $action : 'solucionar el problema de';
        return 'Cómo ' . $verb . ' ' . $object . ($condition ? ' ' . $condition : '') . ': pasos y herramientas necesarias';
    }

    public static function tokens($text) {
        $n = self::normalize($text);
        $stop = self::stopwords();
        $out = array();
        foreach (preg_split('/\s+/u', $n) as $token) {
            if ($token === '' || isset($stop[$token]) || strlen($token) < 3) continue;
            $token = self::canonical_token($token);
            if ($token === '' || isset(self::generic_object_words()[$token])) continue;
            $out[$token] = true;
        }
        return array_keys($out);
    }

    public static function similarity($a, $b) {
        $ta = array_flip(self::tokens($a));
        $tb = array_flip(self::tokens($b));
        if (!$ta || !$tb) return 0.0;
        $intersection = count(array_intersect_key($ta, $tb));
        $union = count($ta + $tb);
        return $union > 0 ? $intersection / $union : 0.0;
    }
}
