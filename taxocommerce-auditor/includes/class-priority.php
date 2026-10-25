<?php
/**
 * Port of the Auditor P1-P5 plan-of-work taxonomy.
 *
 * Severity, priority and execution authorization are separate concerns.
 * This module never performs the suggested correction.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Priority {
    const BASE = array( 'P1' => 500, 'P2' => 400, 'P3' => 300, 'P4' => 200, 'P5' => 100 );
    const SEVERITY_WEIGHT = array( 'critical' => 40, 'high' => 30, 'medium' => 20, 'low' => 10, 'info' => 0 );

    public static function decorate( $finding ) {
        $code = sanitize_key( (string) ( $finding['code'] ?? '' ) );
        $severity = sanitize_key( (string) ( $finding['severity'] ?? 'info' ) );
        if ( ! isset( self::SEVERITY_WEIGHT[ $severity ] ) ) {
            $severity = 'info';
        }
        $finding['severity'] = $severity;
        $finding['code'] = $code;
        $type = sanitize_key( (string) ( $finding['entity_type'] ?? '' ) );
        $id = (string) ( $finding['entity_id'] ?? '' );

        $critical_integrity = array(
            'faq_invalid_owner_type', 'faq_orphan_owner', 'faq_empty_question',
            'product_missing_title', 'editorial_missing_title',
            'product_without_category', 'product_missing_slug',
            'editorial_missing_slug', 'page_missing_native_parent',
        );
        $source_verification = array(
            'product_title_excerpt_drift', 'product_description_identity_drift',
            'product_description_alignment_weak', 'product_attribute_title_conflict',
            'attribute_value_malformed', 'product_tag_identity_drift',
            'category_excerpt_identity_drift', 'category_description_identity_drift',
            'category_description_alignment_weak', 'faq_owner_semantic_drift',
            'product_seo_title_drift', 'product_seo_description_drift',
            'source_duplicate_sku', 'source_duplicate_identifier',
        );
        $enrich = array(
            'product_missing_excerpt', 'product_missing_description',
            'product_without_native_vocabulary', 'category_missing_description',
            'category_missing_excerpt', 'category_without_native_vocabulary',
            'editorial_thin_content', 'editorial_without_native_vocabulary',
            'faq_thin_answer',
        );
        $migrate = array( 'faq_legacy_owner_active', 'faq_migration_candidate' );

        $priority = 'P5';
        $action_class = 'INFORMATIVO';
        $status = 'NO_ACTION';
        $needs_source = in_array( $code, $source_verification, true );

        if ( in_array( $code, $critical_integrity, true ) ) {
            $priority = 'P1';
            $action_class = 'CORREGIR_AHORA';
            $status = 'REVIEW_REQUIRED';
        } elseif ( $needs_source ) {
            $priority = 'P1';
            $action_class = 'VERIFICAR_AHORA';
            $status = 'NEEDS_SOURCE_VERIFICATION';
        } elseif ( in_array( $code, $enrich, true ) ) {
            $priority = 'P2';
            $action_class = 'ESPERAR_ENRIQUECIMIENTO';
            $status = 'WAITING_FOR_ENRICHMENT';
        } elseif ( in_array( $code, $migrate, true ) ) {
            $priority = 'P3';
            $action_class = 'MIGRAR_A_SOLUCIONADOR';
            $status = 'MIGRATION_PENDING';
        } elseif ( in_array( $severity, array( 'critical', 'high' ), true ) ) {
            $priority = 'P1';
            $action_class = 'REVISAR';
            $status = 'REVIEW_REQUIRED';
        } elseif ( 'medium' === $severity || 'low' === $severity ) {
            $priority = 'P4';
            $action_class = 'REVISAR';
            $status = 'REVIEW_REQUIRED';
        }

        // High scores sort only within priority. They do not authorize edits.
        $finding['task_id'] = 'tc-aud-' . substr( hash( 'sha256', $code . '|' . $type . '|' . $id ), 0, 20 );
        $finding['priority'] = $priority;
        $finding['priority_score'] = self::BASE[ $priority ] + self::SEVERITY_WEIGHT[ $severity ];
        $finding['action_class'] = $action_class;
        $finding['status'] = $status;
        $finding['ready_now'] = in_array( $status, array( 'REVIEW_REQUIRED', 'READY_FOR_EDITOR' ), true );
        $finding['requires_source_check'] = $needs_source;
        $finding['depends_on'] = $needs_source ? array( 'verificar_ficha_fabricante_o_proveedor' ) : array();

        return $finding;
    }
}
