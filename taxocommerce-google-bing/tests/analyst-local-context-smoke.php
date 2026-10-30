<?php
/**
 * Integracion de Analista con snapshots locales de Auditor y Merchant.
 * No necesita WordPress real ni permite peticiones externas.
 */
namespace TaxoCommerce\Auditor {
    final class Runner {
        public static function report($scope) {
            return $GLOBALS['analyst_test_auditor'][$scope] ?? array();
        }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('DAY_IN_SECONDS', 86400);
    function sanitize_key($value) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
    }
    function absint($number) { return abs((int) $number); }
    function number_format_i18n($value, $decimals = 0) {
        return number_format((float) $value, $decimals, ',', '.');
    }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function esc_url($url) { return esc_html($url); }
    function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
    function wp_remote_get() { throw new \RuntimeException('No external GET allowed'); }
    function wp_remote_post() { throw new \RuntimeException('No external POST allowed'); }

    final class TaxoCommerce_Google_Bing_Merchant {
        public static function snapshots() {
            return $GLOBALS['analyst_test_merchant'] ?? array();
        }
    }

    function assert_analyst($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }

    require dirname(__DIR__) . '/includes/analysis/analista-contexto.php';

    assert_analyst(
        taxocommerce_google_bing_analyst_context_fresh(gmdate('Y-m-d H:i:s'), true),
        'A recent GMT Auditor snapshot must be fresh'
    );
    assert_analyst(
        !taxocommerce_google_bing_analyst_context_fresh(gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS), true),
        'Expired Auditor report cannot contribute evidence'
    );
    assert_analyst(
        taxocommerce_google_bing_analyst_context_entity_key('product_cat', 9) === 'category:9',
        'Category ID normalization failed'
    );
    $GLOBALS['analyst_test_auditor'] = array(
        'products'=>array(
            'completed_gmt'=>gmdate('Y-m-d H:i:s'),
            'finding_count'=>2,
            'findings_sample'=>array(
                array('entity_type'=>'product','entity_id'=>123,'title'=>'Taladro',
                    'priority'=>'P1','priority_score'=>540,'code'=>'product_missing_title',
                    'recommendation'=>'Revisar el título de producto','status'=>'REVIEW_REQUIRED'),
                array('entity_type'=>'product','entity_id'=>124,'title'=>'Sierra',
                    'priority'=>'P4','priority_score'=>210,'code'=>'minor_issue'),
            ),
        ),
        'categories'=>array(
            'completed_gmt'=>gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS),
            'finding_count'=>300,
            'findings_sample'=>array(
                array('entity_type'=>'product_cat','entity_id'=>9,'priority'=>'P1','priority_score'=>540),
            ),
        ),
    );
    $GLOBALS['analyst_test_merchant'] = array(
        'prices'=>array(
            'country'=>'ES', 'updated_at'=>gmdate('c'), 'complete'=>true,
            'rows'=>array(
                'wc-123'=>array('product_id'=>123,'title'=>'Taladro','price'=>125.0,'benchmark'=>100.0,'currency'=>'EUR'),
                'wc-124'=>array('product_id'=>124,'title'=>'Sierra','price'=>0,'benchmark'=>100.0,'currency'=>'EUR'),
                'foreign'=>array('product_id'=>125,'title'=>'Foreign','price'=>120.0,'benchmark'=>100.0,'currency'=>'USD'),
            ),
        ),
        'popular'=>array(),
    );

    $context = taxocommerce_google_bing_analyst_local_context();
    assert_analyst($context['auditor']['state'] === 'partial', 'Auditor must report partial coverage');
    assert_analyst($context['auditor']['scopes'] === 1, 'Only fresh reports count');
    assert_analyst($context['auditor']['findings'] === 2, 'Expired counts must not accumulate');
    assert_analyst(count($context['auditor']['rows']) === 1, 'Only P1/P2 rows are allowed');
    assert_analyst($context['merchant']['prices'] === 3, 'Merchant row count must be sourced from snapshot');
    assert_analyst(count($context['merchant']['rows']) === 1, 'Only valid EUR price gaps are highlighted');
    $plan = array(
        array('entity'=>array('type'=>'product','id'=>123),'priority'=>87,
            'work_bucket'=>'HACER_DESPUES','task_decision'=>array('work_bucket'=>'HACER_DESPUES','execution_ready'=>false)),
        array('entity'=>array('type'=>'product','id'=>456),'priority'=>70,
            'work_bucket'=>'VIGILAR','task_decision'=>array('work_bucket'=>'VIGILAR','execution_ready'=>false)),
    );
    $decorated = taxocommerce_google_bing_analyst_context_attach_plan($plan, $context);
    assert_analyst(count($decorated[0]['local_context_evidence']) === 2, 'Two independent context notes expected');
    assert_analyst(!isset($decorated[1]['local_context_evidence']), 'Never attach unrelated entities');
    foreach ($plan as $i=>$old) {
        foreach (array('priority','work_bucket','task_decision') as $key) {
            assert_analyst($decorated[$i][$key] === $old[$key], 'Must not change decision gate: ' . $key);
        }
    }
    ob_start();
    taxocommerce_google_bing_analyst_render_local_context($context);
    $html = ob_get_clean();
    assert_analyst(strpos($html,'Revisar el título de producto') !== false, 'Render Audit recommendations');
    assert_analyst(strpos($html,'benchmark') !== false, 'Render Merchant benchmark caveat');

    $GLOBALS['analyst_test_auditor'] = array();
    $GLOBALS['analyst_test_merchant'] = array(
        'prices'=>array('country'=>'ES','updated_at'=>gmdate('c', time()-31*DAY_IN_SECONDS),
            'rows'=>array('wc-123'=>array('product_id'=>123,'benchmark'=>100.0,'price'=>200.0,'currency'=>'EUR'))),
    );
    $expired = taxocommerce_google_bing_analyst_local_context();
    assert_analyst($expired['auditor']['scopes'] === 0, 'No recent Auditor reports');
    assert_analyst($expired['merchant']['state'] === 'stale', 'Merchant snapshot should be flagged as stale');
    assert_analyst($expired['merchant']['prices'] === null, 'Stale Merchant rows are not live prices');
    assert_analyst(empty($expired['merchant']['rows']), 'Stale evidence cannot reach the UI');
    echo "Analista Auditor/Merchant read-only integration smoke: OK\n";
}
