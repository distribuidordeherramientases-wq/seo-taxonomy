<?php
/**
 * CSV transfer for ordinary business/association expense documents.
 *
 * Imports into the existing Accounting Expenses register as unapproved manual
 * drafts, preserving the existing encrypted-PDF attachment interface.
 * Neither an import nor an export posts cash or applies a VAT deduction.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || exit;

final class TCA_Expense_CSV {
    const MAX_BYTES = 2097152; // 2 MiB.
    const MAX_ROWS = 500;
    const MAX_EXPORT = 20000;

    public static function columns() {
        return array(
            'date', 'document_no', 'issuer', 'tax_id', 'concept', 'base', 'vat',
            'deductible_vat_pct', 'vat_treatment', 'scope', 'notes',
        );
    }

    /**
     * Validate one CSV row without changing any existing ledger entry.
     *
     * Imported treatment and percentage are proposals only: every entry is
     * saved as a draft and must be reviewed before it affects the VAT summary.
     */
    public static function parse_row( $values, $currency ) {
        if ( count( $values ) !== count( self::columns() ) ) {
            return null;
        }
        $raw = array_combine( self::columns(), $values );
        $date = trim( (string) $raw['date'] );
        $document = sanitize_text_field( (string) $raw['document_no'] );
        $issuer = sanitize_text_field( (string) $raw['issuer'] );
        $tax_id = sanitize_text_field( (string) $raw['tax_id'] );
        $concept = sanitize_textarea_field( (string) $raw['concept'] );
        $notes = sanitize_textarea_field( (string) $raw['notes'] );
        $base = tca_decimal( str_replace( ',', '.', trim( (string) $raw['base'] ) ) );
        $vat = tca_decimal( str_replace( ',', '.', trim( (string) $raw['vat'] ) ) );
        $percentage = tca_decimal( str_replace( ',', '.', trim( (string) $raw['deductible_vat_pct'] ) ) );
        $treatment = sanitize_key( $raw['vat_treatment'] );
        $scope = sanitize_key( $raw['scope'] );

        if ( ! tca_valid_date( $date ) || '' === trim( $document ) ||
            '' === trim( $issuer ) || '' === trim( $concept ) ||
            strlen( $document ) > 100 || strlen( $issuer ) > 191 ||
            strlen( $tax_id ) > 48 || strlen( $concept ) > 1000 ||
            strlen( $notes ) > 5000 ||
            null === $base || $base <= 0 || null === $vat || $vat < 0 ||
            null === $percentage || $percentage < 0 || $percentage > 100 ||
            ! in_array( $treatment, array( 'review', 'domestic', 'intra', 'reverse', 'exempt' ), true ) ||
            ! in_array( $scope, array( 'review', 'association', 'commercial', 'shared' ), true ) ) {
            return null;
        }

        return array(
            'source' => 'manual',
            'kind' => 'expense',
            'fiscal_date' => $date,
            'document_no' => $document,
            'party' => $issuer,
            'tax_id' => $tax_id,
            'concept' => $concept,
            'amount_base' => $base,
            'amount_tax' => $vat,
            'amount_total' => round( $base + $vat, 2 ),
            'deductible_pct' => $percentage,
            'currency' => $currency,
            'treatment' => $treatment,
            'status' => 'draft',
            'notes' => '[scope=' . $scope . '] ' . $notes,
        );
    }

    public static function split_notes( $notes ) {
        $notes = (string) $notes;
        if ( preg_match( '/^\[scope=(review|association|commercial|shared)\]\s*/', $notes, $matches ) ) {
            return array( $matches[1], substr( $notes, strlen( $matches[0] ) ) );
        }
        return array( 'review', $notes );
    }

    /**
     * Produce a fixed schema, safe to open with Excel or LibreOffice.
     */
    public static function export_values( $row ) {
        list( $scope, $notes ) = self::split_notes( $row->notes );
        return array(
            $row->fiscal_date,
            tca_csv_guard( $row->document_no ),
            tca_csv_guard( $row->party ),
            tca_csv_guard( $row->tax_id ),
            tca_csv_guard( $row->concept ),
            (string) $row->amount_base,
            (string) $row->amount_tax,
            (string) $row->deductible_pct,
            $row->treatment,
            $scope,
            tca_csv_guard( $notes ),
        );
    }

    private static function redirect( $code, $created = 0, $skipped = 0 ) {
        wp_safe_redirect( tca_admin_url( 'treasury', array(
            'notice' => $code,
            'created' => absint( $created ),
            'skipped' => absint( $skipped ),
        ) ) );
        exit;
    }

    /**
     * Process a capped CSV upload. Validate the entire input before writing.
     */
    public static function import() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'taxocommerce-accounting' ) );
        }
        check_admin_referer( 'tca_expense_csv_import' );

        // The authenticated administrator's uploaded file metadata is validated below.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; PHP's temporary uploaded path cannot be sanitized as normal form text.
        $file = isset( $_FILES['expense_csv'] ) && is_array( $_FILES['expense_csv'] ) ? $_FILES['expense_csv'] : array();
        if ( ! isset( $file['error'], $file['size'], $file['name'], $file['tmp_name'] ) ||
            is_array( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ||
            (int) $file['size'] < 1 || (int) $file['size'] > self::MAX_BYTES ||
            ! is_string( $file['name'] ) || ! preg_match( '/\.csv$/i', $file['name'] ) ||
            ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            self::redirect( 'expense_csv_upload' );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read the verified temporary CSV upload or open the CSV download stream; WP_Filesystem cannot access PHP output streams.
        $handle = fopen( $file['tmp_name'], 'rb' );
        if ( false === $handle ) {
            self::redirect( 'expense_csv_upload' );
        }
        $first = fgets( $handle, 8192 );
        if ( false === $first ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
            fclose( $handle );
            self::redirect( 'expense_csv_headers' );
        }
        $delimiter = substr_count( $first, ';' ) >= count( self::columns() ) - 1 ? ';' : ',';
        rewind( $handle );
        $header = fgetcsv( $handle, 65536, $delimiter, '"', '' );
        if ( ! is_array( $header ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
            fclose( $handle );
            self::redirect( 'expense_csv_headers' );
        }
        $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
        if ( self::columns() !== array_map( 'trim', $header ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
            fclose( $handle );
            self::redirect( 'expense_csv_headers' );
        }

        $entries = array();
        $seen = array();
        $skipped = 0;
        $count = 0;
        $line = 1;
        $currency = tca_settings()['currency'];
        while ( false !== ( $fields = fgetcsv( $handle, 65536, $delimiter, '"', '' ) ) ) {
            ++$line;
            if ( array( null ) === $fields || array( '' ) === $fields ) {
                continue;
            }
            ++$count;
            if ( $count > self::MAX_ROWS ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
                fclose( $handle );
                self::redirect( 'expense_csv_limit' );
            }
            $entry = self::parse_row( $fields, $currency );
            if ( null === $entry ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
                fclose( $handle );
                self::redirect( 'expense_csv_invalid' );
            }
            $identity = hash( 'sha256', $entry['fiscal_date'] . '|' . $entry['document_no'] . '|' . $entry['party'] );
            if ( isset( $seen[ $identity ] ) ) {
                ++$skipped;
                continue;
            }
            $seen[ $identity ] = true;
            $entries[] = $entry;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
        fclose( $handle );
        if ( 0 === $count ) {
            self::redirect( 'expense_csv_empty' );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own fiscal ledger transaction, no external changes.
        $wpdb->query( 'START TRANSACTION' );
        $created = 0;
        foreach ( $entries as $entry ) {
            // Check all expense/purchase document types to avoid duplicating already recorded receipts.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Latest ledger identity must be checked for each write.
            $existing = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE kind IN (%s, %s, %s, %s) AND fiscal_date = %s AND document_no = %s AND party = %s LIMIT 1',
                tca_table(), 'expense', 'purchase', 'asset', 'intra',
                $entry['fiscal_date'], $entry['document_no'], $entry['party']
            ) );
            if ( $existing ) {
                ++$skipped;
                continue;
            }
            if ( ! tca_insert( $entry ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back batch if its insertion failed.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'expense_csv_error' );
            }
            ++$created;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete owned ledger batch.
        $wpdb->query( 'COMMIT' );
        self::redirect( 'expense_csv_done', $created, $skipped );
    }

    private static function send_csv( $filename ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read the verified temporary CSV upload or open the CSV download stream; WP_Filesystem cannot access PHP output streams.
        $stream = fopen( 'php://output', 'wb' );
        if ( false === $stream ) {
            exit;
        }
        // UTF-8 BOM ensures Spanish Excel recognises accents.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write the UTF-8 BOM to the CSV download stream.
        fwrite( $stream, "\xEF\xBB\xBF" );
        return $stream;
    }

    public static function download_template() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'taxocommerce-accounting' ) );
        }
        check_admin_referer( 'tca_expense_csv_template' );
        $out = self::send_csv( 'accounting-expense-template.csv' );
        fputcsv( $out, self::columns(), ';', '"', '' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
        fclose( $out );
        exit;
    }

    public static function download_export() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'taxocommerce-accounting' ) );
        }
        check_admin_referer( 'tca_expense_csv_export' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
        $year = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2000 || $year > 2100 ) {
            wp_die( esc_html__( 'Invalid year.', 'taxocommerce-accounting' ) );
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read only plugin's own ledger, capped.
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE kind = %s AND fiscal_date BETWEEN %s AND %s ORDER BY fiscal_date ASC, id ASC LIMIT %d',
            tca_table(), 'expense', $year . '-01-01', $year . '-12-31', self::MAX_EXPORT + 1
        ) );
        if ( count( $rows ) > self::MAX_EXPORT ) {
            wp_die( esc_html__( 'Export limit exceeded; use a narrower reporting period.', 'taxocommerce-accounting' ) );
        }
        $out = self::send_csv( 'accounting-expenses-' . $year . '.csv' );
        fputcsv( $out, self::columns(), ';', '"', '' );
        foreach ( $rows as $row ) {
            fputcsv( $out, self::export_values( $row ), ';', '"', '' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV input or output stream.
        fclose( $out );
        exit;
    }

    public static function render( $year ) {
        $template = wp_nonce_url(
            add_query_arg( array( 'action' => 'tca_expense_csv_template' ), admin_url( 'admin-post.php' ) ),
            'tca_expense_csv_template'
        );
        $export = wp_nonce_url(
            add_query_arg( array( 'action' => 'tca_expense_csv_export', 'year' => $year ), admin_url( 'admin-post.php' ) ),
            'tca_expense_csv_export'
        );
        echo '<hr><h3>' . esc_html__( 'Expense documents: CSV import and export', 'taxocommerce-accounting' ) . '</h3>';
        echo '<p>' . esc_html__( 'For domains, hosting, digital certificates, administrative fees, postal services, software and other association or business expenses. Imports go to Accounting > Expenses as unapproved drafts. They do not change bank/cash balances or automatically create a tax deduction.', 'taxocommerce-accounting' ) . '</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url( $template ) . '">' . esc_html__( 'Download blank CSV template', 'taxocommerce-accounting' ) . '</a> ';
        echo '<a class="button button-secondary" href="' . esc_url( $export ) . '">' . esc_html( sprintf(
            /* translators: %d: fiscal year. */
            __( 'Export expense CSV for %d', 'taxocommerce-accounting' ), $year
        ) ) . '</a></p>';
        echo '<p class="description">' . esc_html__( 'Use UTF-8 CSV with semicolon or comma separators. Keep the template column names unchanged. Dates: YYYY-MM-DD; values: base and VAT in EUR; deductible_vat_pct is the percentage of VAT deductible, not the VAT rate (0 until verified). vat_treatment: review, domestic, intra, reverse, exempt. scope: review, association, commercial, shared.', 'taxocommerce-accounting' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
        wp_nonce_field( 'tca_expense_csv_import' );
        echo '<input type="hidden" name="action" value="tca_expense_csv_import">';
        echo '<p><label for="tca-expense-csv">' . esc_html__( 'Expense CSV file', 'taxocommerce-accounting' ) . '</label> ';
        echo '<input id="tca-expense-csv" type="file" name="expense_csv" accept=".csv,text/csv" required> ';
        submit_button( __( 'Import expense drafts', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
        echo '</p></form><p class="description">' . esc_html__( 'Maximum 500 rows / 2 MB per import. Repeated documents are skipped. Attach original PDF receipts individually in Expenses, verify the legal tax treatment, then validate each entry.', 'taxocommerce-accounting' ) . '</p>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only outcome of the verified admin-post importer.
        $notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
        if ( 'expense_csv_done' === $notice ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counters for the visible result.
            $created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counters for the visible result.
            $skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
            echo '<p><strong>' . esc_html( sprintf(
                /* translators: 1: number of created expense drafts, 2: number of skipped duplicate documents. */
                __( 'Imported: %1$d drafts. Existing/duplicate documents skipped: %2$d.', 'taxocommerce-accounting' ),
                $created, $skipped
            ) ) . '</strong></p>';
        }
    }
}

add_action( 'admin_post_tca_expense_csv_import', array( 'TCA_Expense_CSV', 'import' ) );
add_action( 'admin_post_tca_expense_csv_template', array( 'TCA_Expense_CSV', 'download_template' ) );
add_action( 'admin_post_tca_expense_csv_export', array( 'TCA_Expense_CSV', 'download_export' ) );
