<?php
/**
 * Solucionador - normalizacion linguistica ligera.
 *
 * No sustituye al Interprete. Cuando el log trae intent/object/context/state,
 * esas senales son preferentes. Las heuristicas son solo un respaldo.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Normalizer {
    private static function action_map() {
        return array(
            'reparar' => array('reparar','arreglar','solucionar','reponer','restaurar'),
            'quitar' => array('quitar','sacar','extraer','retirar','desmontar'),
            'aflojar' => array('aflojar','desapretar'),
            'apretar' => array('apretar','ajustar'),
            'perforar' => array('perforar','agujerear','taladrar'),
            'cortar' => array('cortar','seccionar','recortar'),
            'lijar' => array('lijar','desbastar','pulir'),
            'medir' => array('medir','comprobar','verificar','diagnosticar','testear'),
            'soldar' => array('soldar','desoldar'),
            'limpiar' => array('limpiar','desatascar','desobstruir'),
            'pintar' => array('pintar','repintar'),
            'montar' => array('montar','instalar','colocar'),
            'sustituir' => array('sustituir','cambiar','reemplazar'),
            'cargar' => array('cargar','recargar'),
            'arrancar' => array('arrancar','encender','poner en marcha'),
            'elevar' => array('elevar','levantar','alzar'),
            'resolver' => array('resolver'),
        );
    }

    private static function conditions() {
        return array(
            'atascado' => array('atascado','atascada','bloqueado','bloqueada','agarrotado','agarrotada'),
            'roto' => array('roto','rota','partido','partida','fracturado','fracturada'),
            'oxidado' => array('oxidado','oxidada','corroido','corroida'),
            'desgastado' => array('desgastado','desgastada','pasado','pasada','barrido','barrida'),
            'fuga' => array('fuga','pierde','gotea','goteo'),
            'no_funciona' => array('no funciona','no va','no responde','ha dejado de funcionar'),
            'no_arranca' => array('no arranca','no enciende'),
            'no_carga' => array('no carga','no recarga'),
            'sin_fuerza' => array('sin fuerza','no tiene fuerza','se queda corto','se queda corta'),
            'ruido' => array('hace ruido','mucho ruido','ruidoso','ruidosa'),
            'gira_en_vacio' => array('gira en vacio','da vueltas pero no sale','gira loco','gira loca'),
        );
    }

    private static function context_terms() {
        return array(
            'hormigon','cemento','metal','madera','acero','aluminio','pared','muro','techo','suelo',
            'coche','moto','vehiculo','tuberia','agua','jardin','piscina','bateria','motor'
        );
    }

    private static function stopwords() {
        return array_flip(array(
            'a','al','algo','como','con','contra','cual','de','del','desde','el','ella','en','es','esta','este','esto',
            'hacer','ha','hay','la','las','lo','los','me','mi','mis','necesito','no','para','por','porque','que','quiero','se','sin',
            'su','sus','tengo','tiene','un','una','unos','unas','y','ya','muy','mas','puedo','podria','debo','deberia'
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

    public static function is_solution_signal($text, $intent = '', $state = '') {
        $intent_n = self::normalize_term($intent);
        $state_n = self::normalize_term($state);
        if ($state_n !== '') return true;
        if (preg_match('/proble|repair|solucion|need|neces|aver|manten|diagn|compat|replace|sustit|repuesto|procedure/u', $intent_n)) return true;
        $n = self::normalize($text);
        if ($n === '') return false;
        foreach (self::conditions() as $variants) {
            foreach ($variants as $variant) {
                if (strpos($n, self::normalize($variant)) !== false) return true;
            }
        }
        foreach (self::action_map() as $variants) {
            foreach ($variants as $variant) {
                $v = self::normalize($variant);
                if (preg_match('/(^|\s)' . preg_quote($v, '/') . '(\s|$)/u', $n)) return true;
            }
        }
        return (bool) preg_match('/\b(como|que hago|necesito|quiero|problema|averia)\b/u', $n);
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
                $v = self::normalize($variant);
                if (preg_match('/(^|\s)' . preg_quote($v, '/') . '(\s|$)/u', $normalized)) return $canonical;
            }
        }
        return '';
    }

    private static function detect_condition($normalized) {
        foreach (self::conditions() as $canonical => $variants) {
            foreach ($variants as $variant) {
                if (strpos($normalized, self::normalize($variant)) !== false) return $canonical;
            }
        }
        return '';
    }

    private static function detect_context($normalized) {
        foreach (self::context_terms() as $term) {
            $n = self::normalize($term);
            if (preg_match('/(^|\s)' . preg_quote($n, '/') . '(\s|$)/u', $normalized)) return $n;
        }
        return '';
    }

    private static function detect_object($normalized, $action, $condition, $context) {
        $tokens = preg_split('/\s+/u', $normalized);
        $stop = self::stopwords();
        $blocked = array_flip(array_filter(array($action, $condition, $context)));
        $action_words = array();
        foreach (self::action_map() as $canonical => $variants) {
            $action_words[$canonical] = true;
            foreach ($variants as $v) {
                foreach (preg_split('/\s+/u', self::normalize($v)) as $w) $action_words[$w] = true;
            }
        }
        $condition_words = array();
        foreach (self::conditions() as $canonical => $variants) {
            $condition_words[$canonical] = true;
            foreach ($variants as $v) {
                foreach (preg_split('/\s+/u', self::normalize($v)) as $w) $condition_words[$w] = true;
            }
        }
        $candidates = array();
        foreach ((array) $tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || isset($stop[$token]) || isset($blocked[$token]) || isset($action_words[$token]) || isset($condition_words[$token])) continue;
            if (is_numeric($token) || strlen($token) < 3) continue;
            $candidates[] = $token;
        }
        return $candidates ? (string) $candidates[0] : '';
    }

    private static function detect_intent($normalized, $action, $condition, $hint = '') {
        $hint = sanitize_key((string) $hint);
        if ($hint !== '' && !in_array($hint, array('linguistic_bridge','search'), true)) return $hint;
        if ($condition !== '') return 'problem';
        if (preg_match('/\b(como|pasos|procedimiento)\b/u', $normalized)) return 'procedure';
        if ($action !== '') return 'need';
        return 'question';
    }

    public static function profile($text, array $hints = array()) {
        $normalized = self::normalize($text);
        if ($normalized === '') return array();

        $action = self::normalize_term($hints['action'] ?? '');
        if ($action === '') $action = self::detect_action($normalized);

        $condition = self::normalize_term($hints['state'] ?? $hints['condition'] ?? '');
        if ($condition === '') $condition = self::detect_condition($normalized);

        $context = self::normalize_term($hints['context'] ?? '');
        if ($context === '') $context = self::detect_context($normalized);

        $object = self::normalize_term($hints['object'] ?? '');
        if ($object === '') $object = self::detect_object($normalized, $action, $condition, $context);

        $intent = self::detect_intent($normalized, $action, $condition, $hints['intent'] ?? '');
        if ($action === '' && $condition !== '') $action = 'resolver';

        if ($object === '' || ($action === '' && $condition === '')) return array();

        $parts = array($intent ?: 'question', $action ?: 'resolver', $object, $condition ?: 'general', $context ?: 'general');
        $parts = array_map(static function($v){ return sanitize_title((string) $v); }, $parts);
        $key = implode('|', $parts);
        if (strlen($key) > 191) $key = substr($key, 0, 191);

        $confidence = 0.58;
        if (!empty($hints['object'])) $confidence += 0.14;
        if (!empty($hints['state']) || !empty($hints['condition'])) $confidence += 0.10;
        if (!empty($hints['context'])) $confidence += 0.05;
        if ($action !== '' && $action !== 'resolver') $confidence += 0.08;
        $confidence = min(0.98, $confidence);

        return array(
            'normalized' => $normalized,
            'intent' => $intent,
            'action' => $action,
            'object' => $object,
            'condition' => $condition,
            'context' => $context,
            'canonical_key' => $key,
            'confidence' => $confidence,
        );
    }

    private static function human($term) {
        $term = trim(str_replace('_', ' ', (string) $term));
        $map = array(
            'hormigon' => 'hormig&oacute;n',
            'tuberia' => 'tuber&iacute;a',
            'vehiculo' => 'veh&iacute;culo',
            'jardin' => 'jard&iacute;n',
            'bateria' => 'bater&iacute;a',
            'gira en vacio' => 'gira en vac&iacute;o',
        );
        if (isset($map[$term])) $term = html_entity_decode($map[$term], ENT_QUOTES, 'UTF-8');
        return $term;
    }

    private static function feminine($word) {
        $word = self::normalize($word);
        if ($word === '') return false;
        if (preg_match('/(a|dad|tad|cion|sion|umbre)$/', $word)) return true;
        return in_array($word, array('pared','moto','mano'), true);
    }

    private static function with_article($term) {
        $term = self::human($term);
        if ($term === '') return '';
        $first = preg_split('/\s+/u', self::normalize($term));
        $first = $first ? (string) $first[0] : $term;
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

    public static function suggested_title(array $profile) {
        $object_raw = trim((string) ($profile['object'] ?? ''));
        $action = trim((string) ($profile['action'] ?? ''));
        $condition_raw = trim((string) ($profile['condition'] ?? ''));
        $context_raw = trim((string) ($profile['context'] ?? ''));
        if ($object_raw === '') return '';

        $object = self::with_article($object_raw);
        $condition = self::agree_condition($condition_raw, $object_raw);
        $context = self::human($context_raw);

        if ($action === 'resolver' && $condition !== '') {
            return 'Qué hacer si ' . $object . ' está ' . $condition . ': causas, solución y herramientas';
        }
        if ($action === 'reparar') {
            return 'Cómo reparar ' . $object . ($condition ? ' cuando está ' . $condition : '') . ': qué comprobar y qué herramientas necesitas';
        }
        if ($action === 'perforar') {
            $target = $object;
            if ($context !== '' && self::normalize($context) !== self::normalize($object_raw)) {
                $target .= ' de ' . $context;
            }
            return 'Cómo perforar ' . $target . ': qué herramienta usar y cómo hacerlo';
        }
        if ($action === 'quitar' || $action === 'aflojar') {
            return 'Cómo ' . $action . ' ' . $object . ($condition ? ' ' . $condition : '') . ': métodos y herramientas';
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
