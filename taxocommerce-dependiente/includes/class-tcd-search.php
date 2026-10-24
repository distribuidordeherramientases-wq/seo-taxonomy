<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public Dependiente runtime.
 *
 * Uses the standalone semantic rules, Vocabulary and L9 memory. A live
 * WordPress/WooCommerce text search remains only as a safety fallback inside
 * TCD_Intelligence; it is no longer the primary interpretation engine.
 */
final class TCD_Search {
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'taxocommerce-dependiente/v1', '/search', array(
                'methods'=>'GET',
                'callback'=>array( __CLASS__, 'search' ),
                'permission_callback'=>'__return_true',
                'args'=>array( 'q'=>array( 'required'=>true, 'sanitize_callback'=>'sanitize_text_field' ) ),
            ) );
        } );

        /*
         * El shortcode canónico del plugin moderno es distinto del legado para
         * permitir comparación A/B con ambos plugins instalados.
         *
         * En STAGING se conserva además taxocommerce_dependiente como alias
         * del moderno, registrado tarde para ganar al runtime legado. En PRO,
         * si el Dependiente antiguo está presente, no se registra ese alias y
         * el antiguo conserva el control de su página pública.
         */
        add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 100 );
    }

    public static function register_shortcodes() {
        add_shortcode( 'taxocommerce_dependiente_moderno', array( __CLASS__, 'shortcode' ) );

        if ( self::modern_alias_enabled() ) {
            add_shortcode( 'taxocommerce_dependiente', array( __CLASS__, 'shortcode' ) );
        }
    }

    private static function modern_alias_enabled() {
        if ( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() ) {
            return true;
        }

        $host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $host = is_string( $host ) ? strtolower( $host ) : '';
        if ( 0 === strpos( $host, 'staging.' ) || false !== strpos( $host, '.staging.' ) ) {
            return true;
        }

        return ! defined( 'SEO_DEPENDIENTE_VERSION' );
    }

    public static function search( $request ) {
        $q = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
        if ( ! $q || mb_strlen( $q ) > 180 ) {
            return new WP_Error( 'tcd_invalid_query', 'Introduce una consulta de hasta 180 caracteres.', array( 'status'=>400 ) );
        }

        $interpretation = TCD_Intelligence::interpret( $q );
        $ranked = TCD_Intelligence::search_products( $q, 24 );
        $decision = TCD_Intelligence::decision( $q, $interpretation, $ranked );
        $suggestions = TCD_Intelligence::suggestions_from_products( $ranked, 8 );
        $results = ! empty( $decision['show_products'] ) ? array_slice( $ranked, 0, 8 ) : array();
        $interpreter = TCD_Intelligence::interpreter_state();
        $state = TCD_Academy::state();

        return rest_ensure_response( array(
            'answer'=>(string) ( $decision['message'] ?? '' ),
            'results'=>$results,
            'suggestions'=>$suggestions,
            'decision'=>$decision,
            'interpretation'=>array(
                'normalized'=>(string) ( $interpretation['normalized'] ?? '' ),
                'concepts'=>array_values( (array) ( $interpretation['groups'] ?? array() ) ),
                'actions'=>array_values( (array) ( $interpretation['actions'] ?? array() ) ),
                'semantic_hits'=>count( (array) ( $interpretation['semantic_hits'] ?? array() ) ),
            ),
            'knowledge_ready'=>'completed' === (string) ( $state['status'] ?? '' ),
            'interpreter_status'=>(string) ( $interpreter['status'] ?? 'building' ),
        ) );
    }

    public static function shortcode() {
        $id = wp_unique_id( 'tcd-search-' );
        $endpoint = rest_url( 'taxocommerce-dependiente/v1/search' );
        ob_start(); ?>
        <div class="tcd-assistant">
            <form action="<?php echo esc_url( $endpoint ); ?>" method="get">
                <label for="<?php echo esc_attr( $id ); ?>">¿Qué herramienta o información necesitas?</label>
                <input id="<?php echo esc_attr( $id ); ?>" name="q" required maxlength="180" type="search">
                <button type="submit">Buscar</button>
            </form>
            <p>Dependiente interpreta la necesidad con su conocimiento semántico y contrasta los resultados con productos publicados.</p>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
