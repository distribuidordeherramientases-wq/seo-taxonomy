<?php

defined( 'ABSPATH' ) || exit;

final class TCV_Schema {
    const DB_VERSION = '0.3.0';

    public static function tables() {
        global $wpdb;
        return array(
            'vocabulary'  => $wpdb->prefix . 'seo_vocabulary',
            'objects'     => $wpdb->prefix . 'seo_object_vocabulary',
            'type_role'   => $wpdb->prefix . 'seo_type_role_map',
            'attributes'  => $wpdb->prefix . 'sql_atributos',
            'terms'       => $wpdb->prefix . 'sql_atributos_terminos',
            'aliases'     => $wpdb->prefix . 'sql_atributos_aliases',
            'product_att' => $wpdb->prefix . 'sql_product_atributos',
            'proposals'   => $wpdb->prefix . 'seo_classifier_proposals',
            'cat_proposals' => $wpdb->prefix . 'seo_classifier_category_proposals',
        );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'taxocommerce_vocabulary_db_version' ) !== self::DB_VERSION ) {
            self::install();
        }
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $cc = $wpdb->get_charset_collate();

        $sql = array();
        $sql[] = "CREATE TABLE {$t['vocabulary']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            semantic_group VARCHAR(100) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            label VARCHAR(255) NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            source VARCHAR(80) NOT NULL DEFAULT 'manual',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY group_slug (semantic_group, slug),
            KEY semantic_group (semantic_group),
            KEY parent_id (parent_id),
            KEY active (active)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['objects']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            vocabulary_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(80) NOT NULL DEFAULT 'manual',
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY object_vocabulary (object_type, object_id, vocabulary_id),
            KEY object_lookup (object_type, object_id, status),
            KEY vocabulary_lookup (vocabulary_id, status),
            KEY source (source)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['type_role']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type_vocabulary_id BIGINT UNSIGNED NOT NULL,
            role_vocabulary_id BIGINT UNSIGNED NOT NULL,
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            source VARCHAR(60) NOT NULL DEFAULT 'manual',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_type_vocabulary (type_vocabulary_id),
            KEY idx_role_vocabulary (role_vocabulary_id),
            KEY idx_active (active)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['attributes']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(100) NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            grupo VARCHAR(100) NULL,
            tipo ENUM('texto','numero','boolean','termino','rango') NOT NULL DEFAULT 'texto',
            unidad_tipo VARCHAR(50) NULL,
            unidad_base VARCHAR(30) NULL,
            multiple TINYINT(1) NOT NULL DEFAULT 0,
            filtrable TINYINT(1) NOT NULL DEFAULT 0,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            seo TINYINT(1) NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['terms']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            atributo_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(150) NOT NULL,
            nombre VARCHAR(191) NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_atributo_termino (atributo_id, slug),
            KEY idx_atributo (atributo_id)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['aliases']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            atributo_id BIGINT UNSIGNED NOT NULL,
            termino_id BIGINT UNSIGNED NULL,
            alias VARCHAR(191) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_alias (alias),
            KEY idx_atributo_alias (atributo_id)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['product_att']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            atributo_id BIGINT UNSIGNED NOT NULL,
            termino_id BIGINT UNSIGNED NULL,
            valor_texto TEXT NULL,
            valor_numero DECIMAL(20,6) NULL,
            valor_numero_max DECIMAL(20,6) NULL,
            unidad VARCHAR(30) NULL,
            valor_original TEXT NULL,
            orden INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_product (product_id),
            KEY idx_atributo (atributo_id),
            KEY idx_termino (termino_id),
            KEY idx_product_atributo (product_id, atributo_id)
        ) {$cc};";
        $sql[] = "CREATE TABLE {$t['proposals']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            semantic_group VARCHAR(80) NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'unresolved',
            vocabulary_id BIGINT UNSIGNED NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            confidence DECIMAL(8,4) NOT NULL DEFAULT 0,
            payload_json LONGTEXT NULL,
            context_hash CHAR(64) NOT NULL DEFAULT '',
            classifier_version VARCHAR(40) NOT NULL DEFAULT '',
            source_job_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_group (product_id, semantic_group),
            KEY state_active (state, active),
            KEY product_active (product_id, active)
        ) {$cc};";

        $sql[] = "CREATE TABLE {$t['cat_proposals']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            term_id BIGINT UNSIGNED NOT NULL,
            semantic_group VARCHAR(80) NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'unresolved',
            vocabulary_id BIGINT UNSIGNED NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            confidence DECIMAL(8,4) NOT NULL DEFAULT 0,
            payload_json LONGTEXT NULL,
            classifier_version VARCHAR(40) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY category_group (term_id, semantic_group),
            KEY state_active (state, active),
            KEY category_active (term_id, active)
        ) {$cc};";

        foreach ( $sql as $statement ) {
            dbDelta( $statement );
        }
        update_option( 'taxocommerce_vocabulary_db_version', self::DB_VERSION, false );
    }
}
