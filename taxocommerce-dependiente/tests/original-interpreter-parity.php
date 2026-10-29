<?php
/**
 * Verify the original Interpreter/Linguist has been PORTED, not replaced with
 * a hand-written stopword/ranking heuristic.
 *
 * Run: php taxocommerce-dependiente/tests/original-interpreter-parity.php
 */
$root = dirname(__DIR__);
$legacy = dirname($root) . '/includes/dependiente/interprete';
$copy = $root . '/includes/interpreter';

function original_interpreter_assert($ok, $message) {
    if (!$ok) {
        fwrite(STDERR, 'FAIL original Interpreter parity: ' . $message . PHP_EOL);
        exit(1);
    }
}
$legacy_runtime = file_get_contents($legacy . '/seo-dependiente-interprete.php');
$ported_runtime = file_get_contents($copy . '/class-tcd-interpreter-legacy.php');
$legacy_db = file_get_contents($legacy . '/seo-dependiente-interprete-db.php');
$ported_db = file_get_contents($copy . '/class-tcd-interpreter-lexicon.php');
$legacy_course = file_get_contents($legacy . '/linguista/seo-dependiente-linguista.php');
$ported_course = file_get_contents($copy . '/class-tcd-linguist.php');
$search = file_get_contents($root . '/includes/class-tcd-search.php');
$academy = file_get_contents($root . '/includes/class-tcd-academy.php');
$admin = file_get_contents($root . '/includes/class-tcd-admin.php');

original_interpreter_assert(strpos($ported_runtime, 'final class TCD_Original_Interprete') !== false, 'real original runtime class');
foreach (array('correct_typos','analyze_language','classify_with_linguista',
    'canonical_action_for_concept','safe_learned_lemma','plan_clarification') as $method) {
    original_interpreter_assert(strpos($legacy_runtime, 'function ' . $method) !== false
        && strpos($ported_runtime, 'function ' . $method) !== false, 'original runtime method: ' . $method);
}
foreach (array('matching_rows','fuzzy_matching_rows','add_evidence',
    'resolve_vocabulary_ids','active_rows','refresh_evidence_summary') as $method) {
    original_interpreter_assert(strpos($legacy_db, 'function ' . $method) !== false
        && strpos($ported_db, 'function ' . $method) !== false, 'original lexical DB method: ' . $method);
}
foreach (array('lesson_catalog_language','lesson_actions','lesson_synonyms','lesson_context',
    'lesson_validate_ambiguity','lesson_natural_language','lesson_intent','lesson_exam',
    'lesson9_ready','run_current_lesson_batch','process_control_stop','process_control_resume',
    'process_manager_slice') as $method) {
    original_interpreter_assert(strpos($legacy_course, 'function ' . $method) !== false
        && strpos($ported_course, 'function ' . $method) !== false, 'original Linguist course method: ' . $method);
}
original_interpreter_assert(
    file_get_contents($legacy . '/linguista/data/es-grammar-v2.php') ===
    file_get_contents($copy . '/data/es-grammar-v2.php'), 'same grammar dictionary'
);
original_interpreter_assert(
    file_get_contents($legacy . '/linguista/data/curriculum-v1.php') ===
    file_get_contents($copy . '/data/curriculum-v1.php'), 'same curriculum'
);
original_interpreter_assert(strpos($search, 'TCD_Original_Interprete::interpret(') !== false, 'real original interpreter called in public runtime');
original_interpreter_assert(strpos($academy, 'TCD_Original_Linguista::process_control_start(') !== false, 'original Linguist starts in M11');
original_interpreter_assert(strpos($admin, 'Intérprete original v1.2.0') !== false && strpos($admin, 'M11 · Intérprete original') !== false && strpos($admin, 'nav-tab-wrapper') !== false, 'single training surface');
original_interpreter_assert(strpos($ported_course, "'tcd_original_linguista_worker'") !== false, 'durable background worker');
original_interpreter_assert(strpos($ported_db, "'seo_interprete_lexicon'") !== false, 'retained trained lexicon');
original_interpreter_assert(strpos($ported_db, "'seo_interprete_lexicon_evidence'") !== false, 'retained evidence');
echo "Original Interpreter and Linguist parity guards passed.\n";
