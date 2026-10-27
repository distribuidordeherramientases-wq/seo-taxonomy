<?php
/**
 * Deterministic status regression, without WordPress boot or external HTTP.
 * Run: php taxocommerce-google-bing/tests/connections-status-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
require dirname( __DIR__ ) . '/includes/class-taxocommerce-connections-status.php';

$now = 1700000000;
$verified = array( 'mode' => 'test', 'ready' => true, 'sig' => 'identity-A' );
$ok = array( 'ok' => 1, 'checked_at' => $now - 3600, 'sig' => 'identity-A' );
$bad = array( 'ok' => 0, 'checked_at' => $now - 3600, 'sig' => 'identity-A' );
$cases = array(
    array( $verified, array(), 'configured' ),
    array( $verified, $ok, 'connected' ),
    array( $verified, $bad, 'error' ),
    array( $verified, array( 'ok' => 1, 'checked_at' => $now - 8 * DAY_IN_SECONDS, 'sig' => 'identity-A' ), 'configured' ),
    array( $verified, array( 'ok' => 1, 'checked_at' => $now - 100, 'sig' => 'identity-B' ), 'configured' ),
    array( array( 'mode' => 'test', 'ready' => false, 'sig' => 'identity-A' ), $ok, 'pending' ),
    array( array( 'mode' => 'test', 'ready' => true, 'disabled' => true, 'sig' => 'identity-A' ), $ok, 'disabled' ),
    array( array( 'mode' => 'local', 'ready' => true, 'sig' => 'identity-A' ), $ok, 'configured' ),
    array( array( 'mode' => 'local', 'ready' => false, 'sig' => 'identity-A' ), array(), 'pending' ),
    array( array( 'mode' => 'public', 'ready' => true, 'sig' => '' ), array(), 'public' ),
);
foreach ( $cases as $i => $case ) {
    $actual = TaxoCommerce_Connections_Status::state( $case[0], $case[1], $now );
    if ( $case[2] !== $actual ) {
        fwrite( STDERR, "FAIL connection state " . ( $i + 1 ) . ": expected " . $case[2] . ", received " . $actual . "\n" );
        exit( 1 );
    }
}
echo "Connections status OK: unverified, verified, error, stale, changed, pending, disabled, local and public.\n";
