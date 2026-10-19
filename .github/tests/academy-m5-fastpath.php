<?php
/**
 * Lightweight regression test for the M5 structural evaluator.
 * Run: php .github/tests/academy-m5-fastpath.php
 * This file is excluded from the distributed WordPress.org plugin ZIP.
 */

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function add_action() {}
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return strip_tags((string) $value); }
function esc_sql($value) { return (string) $value; }
function current_time($type) { return '2026-10-19 21:00:00'; }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }

final class SEO_Dependiente_Index {
    public static $tables_ready = true;
    public static function table_exists($table = '') { return self::$tables_ready; }
}

final class AcademyTestDb {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $last_error = '';
    public $insert_id = 0;
    public $existing = 0;
    public $rows = array();
    public $inserted = array();
    public $bindings = array();
    public $queries = 0;

    public function prepare($sql, ...$parameters) {
        if (1 === count($parameters) && is_array($parameters[0])) {
            $parameters = $parameters[0];
        }
        $this->bindings[] = $parameters;
        return $sql;
    }
    public function get_var($sql) { return $this->existing; }
    public function get_results($sql, $output = '') {
        $this->queries++;
        return $this->rows;
    }
    public function insert($table, $data) {
        $this->inserted[] = $data;
        return ++$this->insert_id;
    }
}

require_once dirname(__DIR__, 2) . '/includes/dependiente/entrenador/seo-dependiente-entrenador.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function run_case($expected, $rows, $existing = 0, $tables_ready = true) {
    global $wpdb;
    $wpdb = new AcademyTestDb();
    $wpdb->rows = $rows;
    $wpdb->existing = $existing;
    SEO_Dependiente_Index::$tables_ready = $tables_ready;
    $question = array(
        'id' => 77,
        'lesson_key' => 'update_20261019_testing',
        'module_no' => 5,
        'source_type' => 'editorial',
        'question_type' => 'editorial_semantic',
        'mode' => 'need',
        'question' => '¿Qué contenido tienes relacionado con un concepto?',
        'expected_json' => json_encode($expected),
    );
    $returned = SEO_Dependiente_Entrenador::update_run_editorial_question($question, 'mock-batch');
    return array($wpdb, $returned);
}

$legacy = array(
    'kind' => 'content',
    'labels' => array('Taladro', 'Madera'),
    'acceptable_related' => array(array('id' => 42, 'type' => 'post')),
);
$matching = array(
    array('ID' => 42, 'post_type' => 'post', 'vocabulary_id' => 11, 'label' => 'Taladro'),
    array('ID' => 42, 'post_type' => 'post', 'vocabulary_id' => 12, 'label' => 'Madera'),
);
list($db, $id) = run_case($legacy, $matching);
check(1 === $id, 'legacy M5 must persist a terminal run');
check(1 === $db->queries, 'legacy M5 must query canonical relations once, never the full search');
check('pass_top1' === $db->inserted[0]['evaluation_status'], 'valid editorial routes must pass');
check('editorial_structure_check' === $db->inserted[0]['search_strategy'], 'M5 must identify the structural path');
check(42 === $db->bindings[1][0], 'expected post ID must be bound as a prepared SQL parameter');

list($db) = run_case($legacy, array($matching[0]));
check('fail' === $db->inserted[0]['evaluation_status'], 'missing a required route must fail');

list($db) = run_case($legacy, array(
    array('ID' => 42, 'post_type' => 'page', 'vocabulary_id' => 11, 'label' => 'Taladro'),
    array('ID' => 42, 'post_type' => 'page', 'vocabulary_id' => 12, 'label' => 'Madera'),
));
check('fail' === $db->inserted[0]['evaluation_status'], 'unexpected post type must not pass');

$exact = $legacy;
$exact['vocabulary_ids'] = array(11, 12);
list($db) = run_case($exact, array(
    array('ID' => 42, 'post_type' => 'post', 'vocabulary_id' => 11, 'label' => 'Updated one'),
    array('ID' => 42, 'post_type' => 'post', 'vocabulary_id' => 12, 'label' => 'Updated two'),
));
check('pass_top1' === $db->inserted[0]['evaluation_status'], 'new questions must use exact canonical term IDs');

list($db, $id) = run_case($legacy, $matching, 91);
check(91 === $id && !$db->inserted && 0 === $db->queries, 'terminal questions must never be reevaluated');

list($db) = run_case($legacy, $matching, 0, false);
check('error' === $db->inserted[0]['status'], 'missing canonical tables must be a technical error');
check('error' === $db->inserted[0]['evaluation_status'], 'missing tables must never be counted as a pass');

fwrite(STDOUT, "OK: 6 Academy M5 regression cases passed.\n");
