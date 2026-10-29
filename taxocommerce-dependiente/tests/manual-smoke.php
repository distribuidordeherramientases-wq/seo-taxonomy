<?php
/** Standalone documentation integration smoke. No WordPress/database/writes needed. */
$root = dirname( __DIR__ );
$entry = file_get_contents( $root . '/taxocommerce-dependiente.php' );
$admin = file_get_contents( $root . '/includes/class-tcd-admin.php' );
$manual = file_get_contents( $root . '/includes/class-tcd-manual.php' );
$style = file_get_contents( $root . '/assets/manual.css' );
$checks = array(
    'Manual class loaded' => strpos( $entry, 'includes/class-tcd-manual.php' ) !== false,
    'Styles booted' => strpos( $entry, 'TCD_Manual::boot()' ) !== false,
    'Admin tab exists' => strpos( $admin, '>User Guide</a>' ) !== false,
    'Tab allowlisted' => strpos( $admin, "'learning-log','auto-test','user-guide'" ) !== false,
    'Both manuals present' => strpos( $manual, 'User Manual' ) !== false && strpos( $manual, 'Technical Manual' ) !== false,
    'Read-only manual' => strpos( $manual, '<form' ) === false && strpos( $manual, 'update_option(' ) === false,
    'Manual capability guard' => strpos( $manual, "current_user_can( 'manage_woocommerce' )" ) !== false,
    'Heading hierarchy and index' => strpos( $manual, '<h2>' ) !== false && strpos( $manual, '<h3>' ) !== false && strpos( $manual, 'tcd-manual__index' ) !== false,
    'Syllabus from code' => strpos( $manual, 'TCD_Sources::syllabus()' ) !== false,
    'Responsive styles' => strpos( $style, '.tcd-manual__layout' ) !== false && strpos( $style, '@media(max-width:600px)' ) !== false,
    'Technical backup scope disclosed' => strpos( $manual, 'seo_interprete_lexicon_evidence' ) !== false,
);
foreach ( $checks as $label => $ok ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL manual: ' . $label . PHP_EOL ); exit( 1 ); }
}
echo "Dependiente User Guide smoke passed.\n";
