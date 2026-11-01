<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-scrappers.php' );
$ojeador = file_get_contents( $root . '/includes/ojeador/class-ojeador-admin.php' );
$engineer = file_get_contents( $root . '/includes/ingeniero/class-seo-ingeniero-admin.php' );
$shopping = file_get_contents( $root . '/includes/ojeador/class-ojeador-shopping.php' );
// Check every runtime PHP file, not just the four files listed in the old PRO report.
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
);
foreach ( $files as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
        continue;
    }
    $contents = file_get_contents( $file->getPathname() );
    if ( false === $contents ) {
        fwrite( STDERR, 'Cannot read Scrappers PHP file: ' . $file->getPathname() . PHP_EOL );
        exit( 1 );
    }
    if ( false !== strpos( $contents, "'taxocommerce-seo'" ) ||
         false !== strpos( $contents, '"taxocommerce-seo"' ) ) {
        fwrite( STDERR, 'Legacy text domain in ' . $file->getPathname() . PHP_EOL );
        exit( 1 );
    }
}
$posts = file_get_contents( $root . '/includes/ingeniero/class-seo-ingeniero-posts.php' );
$method_pos = strpos( $posts, 'private static function assign_category_vocabulary(' );
$vocabulary_sql = false !== $method_pos ? substr( $posts, $method_pos, 1800 ) : '';
$db = file_get_contents( $root . '/includes/ingeniero/class-seo-ingeniero-db.php' );
$ojeador_db = file_get_contents( $root . '/includes/ojeador/class-ojeador-db.php' );
$manual = file_get_contents( $root . '/includes/class-taxocommerce-scrappers-manual.php' );
$analysis = file_get_contents( $root . '/includes/ojeador/class-ojeador-analysis.php' );
$checks = array(
    strpos( $main, "Plugin Name: TaxoCommerce Scrappers" ) !== false,
    strpos( $main, "'connections' => 'Connections'" ) !== false,
    strpos( $main, "'ojeador'     => 'Ojeador'" ) !== false,
    strpos( $main, "'ingeniero'   => 'Ingeniero'" ) !== false,
    strpos( $main, "SEO_Ojeador_Admin::render_shared_google_connections()" ) !== false,
    strpos( $main, "SEO_Ojeador_Admin::render()" ) !== false,
    strpos( $main, "SEO_Ingeniero_Admin::render()" ) !== false,
    strpos( $shopping, "https://serpapi.com/search.json" ) !== false,
    strpos( $shopping, "https://api.scraperapi.com/structured/google/shopping" ) !== false,
    strpos( $shopping, "https://api.scraperapi.com/structured/google/search" ) !== false,
    strpos( $ojeador, "const PAGE = 'taxocommerce-scrappers'" ) !== false,
    strpos( $ojeador, "'tcs_tab'=>'ojeador'" ) !== false,
    strpos( $engineer, "'tcs_tab' => 'ingeniero'" ) !== false,
    strpos( $db, 'SELECT id FROM %i WHERE term_id=%d' ) !== false,
    strpos( $main, "Version: 0.1.6" ) !== false,
    strpos( $db, 'WordPress.DB.DirectDatabaseQuery.DirectQuery' ) !== false,
    strpos( $ojeador_db, 'UPDATE %i SET status=' ) !== false,
    strpos( $ojeador_db, 'UPDATE {$runs} SET status=' ) === false,
    strpos( $db, 'array_merge(array($table), $ids)' ) !== false,
    // The sixth PRO error was an unescaped SQL table name in this exact method.
    strpos( $vocabulary_sql, '$wpdb->prepare(' ) !== false,
    strpos( $vocabulary_sql, 'FROM %i ov' ) !== false,
    strpos( $vocabulary_sql, 'INNER JOIN %i v' ) !== false,
    strpos( $vocabulary_sql, '$objects,' ) !== false,
    strpos( $vocabulary_sql, '$vocabulary,' ) !== false,
    strpos( $vocabulary_sql, 'absint($term_id)' ) !== false,
    strpos( $vocabulary_sql, 'FROM {$objects}' ) === false,
    strpos( $vocabulary_sql, 'JOIN {$vocabulary}' ) === false,
    substr_count( $manual, 'wp_kses_post( self::link(' ) === 9,
    strpos( $analysis, 'GROUP BY term_id"' ) === false || strpos( $analysis, 'GROUP BY term_id", $table)' ) !== false,
    strpos( $ojeador, 'SELECT * FROM %i WHERE' ) !== false,
);
foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Scrappers smoke ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Scrappers smoke passed.\n";
