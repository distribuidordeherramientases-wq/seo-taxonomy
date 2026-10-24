<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module 12: incremental maintenance after the initial L1-L10 course.
 *
 * It updates only changed sources. It never restarts the complete course.
 */
final class TCD_Maintenance {
    const CRON_HOOK = 'tcd_dependiente_maintenance';
    const STATE = 'tcd_maintenance_state';

    public static function boot() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
        add_action( 'woocommerce_new_product', array( __CLASS__, 'product_changed' ), 140, 1 );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'product_changed' ), 140, 1 );
        add_action( 'save_post_product', array( __CLASS__, 'product_saved' ), 1200, 3 );
        add_action( 'transition_post_status', array( __CLASS__, 'post_status_changed' ), 140, 3 );
        add_action( 'created_product_cat', array( __CLASS__, 'category_changed' ), 140, 1 );
        add_action( 'edited_product_cat', array( __CLASS__, 'category_changed' ), 140, 1 );
        add_action( 'save_post_post', array( __CLASS__, 'editorial_saved' ), 1200, 3 );
        add_action( 'save_post_page', array( __CLASS__, 'editorial_saved' ), 1200, 3 );
    }

    public static function activate() {
        $state = self::state();
        $state['status'] = 'active';
        $state['activated_at'] = $state['activated_at'] ?: current_time( 'mysql' );
        $state['updated_at'] = current_time( 'mysql' );
        update_option( self::STATE, $state, false );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, 'twicedaily', self::CRON_HOOK );
        }
        return $state;
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function state() {
        $state = get_option( self::STATE, array() );
        return wp_parse_args( is_array( $state ) ? $state : array(), array(
            'status'=>'idle',
            'activated_at'=>'',
            'last_run'=>'',
            'last_cursor'=>'',
            'products_updated'=>0,
            'editorial_updated'=>0,
            'faqs_updated'=>0,
            'last_error'=>'',
            'updated_at'=>'',
        ) );
    }

    private static function ready() {
        $interpreter = TCD_Intelligence::interpreter_state();
        return 'ready' === (string) ( $interpreter['status'] ?? '' );
    }

    public static function product_changed( $product_id ) {
        if ( ! self::ready() ) return;
        $product_id = absint( $product_id );
        if ( ! $product_id || 'publish' !== get_post_status( $product_id ) ) return;
        foreach ( array( 2, 4, 9 ) as $lesson ) {
            $item = TCD_Sources::product_item( $lesson, $product_id );
            if ( is_array( $item ) ) TCD_Academy::upsert_maintenance_item( $lesson, $item );
        }
        TCD_Intelligence::refresh_product( $product_id );
        self::bump( 'products_updated' );
    }

    public static function product_saved( $post_id, $post = null, $update = false ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        self::product_changed( $post_id );
    }

    public static function post_status_changed( $new_status, $old_status, $post ) {
        if ( ! $post instanceof WP_Post ) return;
        if ( 'product' === $post->post_type ) {
            if ( 'publish' === $new_status ) {
                self::product_changed( $post->ID );
            } elseif ( 'publish' === $old_status ) {
                self::remove_product( $post->ID );
            }
        }
    }

    private static function remove_product( $product_id ) {
        global $wpdb;
        $product_id = absint( $product_id );
        if ( ! $product_id ) return;
        $wpdb->delete( TCD_Knowledge_Transfer::l9_table(), array( 'product_id'=>$product_id ) );
        $knowledge = TCD_Academy::knowledge_table();
        foreach ( array( 2, 4, 9 ) as $lesson ) {
            $wpdb->delete( $knowledge, array( 'lesson'=>$lesson, 'source_type'=>'product', 'source_id'=>$product_id ) );
        }
    }

    public static function category_changed( $term_id ) {
        if ( ! self::ready() ) return;
        $item = TCD_Sources::category_item( absint( $term_id ) );
        if ( ! is_array( $item ) ) return;
        TCD_Academy::upsert_maintenance_item( 1, $item );
        TCD_Intelligence::refresh_category( $term_id );
    }

    public static function editorial_saved( $post_id, $post = null, $update = false ) {
        if ( ! self::ready() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( 'publish' !== get_post_status( $post_id ) ) return;
        $item = TCD_Sources::content_item( absint( $post_id ) );
        if ( ! is_array( $item ) ) return;
        TCD_Academy::upsert_maintenance_item( 5, $item );
        self::bump( 'editorial_updated' );
    }

    public static function run() {
        if ( ! self::ready() ) return;
        $state = self::state();
        $since = $state['last_cursor'] ?: gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $until = current_time( 'mysql', true );
        try {
            self::refresh_recent_posts( $since, $until );
            self::refresh_recent_faqs( $since, $until );
            $state = self::state();
            $state['status'] = 'active';
            $state['last_run'] = current_time( 'mysql' );
            $state['last_cursor'] = $until;
            $state['last_error'] = '';
            $state['updated_at'] = current_time( 'mysql' );
            update_option( self::STATE, $state, false );
        } catch ( Throwable $e ) {
            $state['last_error'] = substr( $e->getMessage(), 0, 400 );
            $state['updated_at'] = current_time( 'mysql' );
            update_option( self::STATE, $state, false );
        }
    }

    private static function refresh_recent_posts( $since, $until ) {
        global $wpdb;
        $ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish'
               AND post_type IN ('product','post','page')
               AND post_modified_gmt>%s AND post_modified_gmt<=%s
             ORDER BY post_modified_gmt ASC,ID ASC LIMIT 300",
            $since, $until
        ) );
        foreach ( $ids as $id ) {
            $post_type = get_post_type( absint( $id ) );
            if ( 'product' === $post_type ) self::product_changed( $id );
            elseif ( in_array( $post_type, array( 'post','page' ), true ) ) self::editorial_saved( $id );
        }
    }

    private static function refresh_recent_faqs( $since, $until ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_faq';
        if ( ! self::table_exists( $table ) ) return;
        $ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE active=1 AND updated_at>%s AND updated_at<=%s
             ORDER BY updated_at ASC,id ASC LIMIT 300",
            $since, $until
        ) );
        foreach ( $ids as $id ) {
            $item = TCD_Sources::faq_item( absint( $id ) );
            if ( ! is_array( $item ) ) continue;
            TCD_Academy::upsert_maintenance_item( 6, $item );
            self::bump( 'faqs_updated' );
        }
    }

    private static function bump( $key ) {
        $state = self::state();
        $state[ $key ] = absint( $state[ $key ] ?? 0 ) + 1;
        $state['updated_at'] = current_time( 'mysql' );
        update_option( self::STATE, $state, false );
    }

    private static function table_exists( $table ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === (string) $table;
    }
}
