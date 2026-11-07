<?php
/**
 * Verify four independent TaxoCommerce modules do not translate their Core
 * metadata before init. Run once per slug, in independent PHP processes.
 *
 * php .github/tests/early-i18n-core-registration-smoke.php vocabulary
 *
 * @package TaxoCommerce
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cases = array(
	'vocabulary' => array( 'vocabulary', '0.3.11' ),
	'auditor'    => array( 'auditor', '0.3.7' ),
	'database'   => array( 'database', '0.4.10' ),
	'scrappers'  => array( 'scrappers', '0.1.10' ),
);
$slug = isset( $argv[1] ) ? $argv[1] : '';
if ( ! isset( $cases[ $slug ] ) ) {
	fwrite( STDERR, "Supply vocabulary, auditor, database or scrappers\n" );
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
$GLOBALS['tc_i18n_test_slug']   = $slug;
$GLOBALS['tc_i18n_init_fired'] = false;
$GLOBALS['tc_i18n_hooks']      = array();
$GLOBALS['tc_i18n_calls']      = array();

function did_action( $hook ) {
	return 'init' === $hook && $GLOBALS['tc_i18n_init_fired'] ? 1 : 0;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['tc_i18n_hooks'][ $hook ][ $priority ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return true;
}
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
function plugin_dir_url( $path ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $path ) ) . '/'; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function __( $text, $domain = '' ) {
	if ( 'taxocommerce-' . $GLOBALS['tc_i18n_test_slug'] === $domain && ! did_action( 'init' ) ) {
		throw new RuntimeException( 'Premature gettext: ' . $domain . ' / ' . $text );
	}
	return 'localised: ' . $text;
}

class TC_I18n_Fake_Core {
	public $modules = array();
	public $calls   = 0;
	public function register_module( $id, $details ) {
		if ( isset( $this->modules[ $id ] ) ) {
			return false;
		}
		$this->modules[ $id ] = $details;
		++$this->calls;
		return true;
	}
}

if ( 'scrappers' === $slug ) {
	// Scrappers reuses optional legacy classes. Avoid loading their unrelated
	// implementations while testing only the standalone registration contract.
	class SEO_Ojeador_Admin {}
	class SEO_Ingeniero {}
}

$root = dirname( __DIR__, 2 );
require $root . '/taxocommerce-' . $slug . '/taxocommerce-' . $slug . '.php';
$callback = array(
	'vocabulary' => 'taxocommerce_vocabulary_register_with_core',
	'auditor'    => array( 'TaxoCommerce\Auditor\Plugin', 'register_core_module' ),
	'database'   => array( 'TaxoCommerce_Database', 'register_core' ),
	'scrappers'  => array( 'TaxoCommerce_Scrappers', 'register_core' ),
);
$id   = $cases[ $slug ][0];
$core = new TC_I18n_Fake_Core();
call_user_func( $callback[ $slug ], $core );
if ( isset( $core->modules[ $id ] ) ) {
	throw new RuntimeException( 'Registration must wait until init: ' . $slug );
}

$GLOBALS['tc_i18n_init_fired'] = true;
// The actual local catalog runs on init priority 10. This stub translates
// correctly once init has started, and executes only the deferred callbacks.
$deferred = isset( $GLOBALS['tc_i18n_hooks']['init'][11] )
	? $GLOBALS['tc_i18n_hooks']['init'][11] : array();
if ( 1 !== count( $deferred ) ) {
	throw new RuntimeException( 'Expected exactly one deferred init callback for ' . $slug );
}
foreach ( $deferred as $callback_at_init ) {
	call_user_func( $callback_at_init );
}
if ( ! isset( $core->modules[ $id ] ) || 1 !== $core->calls ) {
	throw new RuntimeException( 'Missing or duplicate module after init: ' . $slug );
}
$metadata = $core->modules[ $id ];
if ( $cases[ $slug ][1] !== $metadata['version'] ||
	0 !== strpos( $metadata['description'], 'localised: ' ) ||
	'' === (string) $metadata['admin_url'] ) {
	throw new RuntimeException( 'Module version, translation or admin URL changed: ' . $slug );
}
if ( 'scrappers' === $slug && 0 !== strpos( $metadata['name'], 'localised: ' ) ) {
	throw new RuntimeException( 'Scrappers title not translated after init' );
}

// Core may also load after init. In that case registration must be immediate.
$later = new TC_I18n_Fake_Core();
call_user_func( $callback[ $slug ], $later );
if ( ! isset( $later->modules[ $id ] ) || 1 !== $later->calls ) {
	throw new RuntimeException( 'Late Core registration failed: ' . $slug );
}
echo 'PASS: ' . $slug . " defers gettext until init without losing Core module\n";
