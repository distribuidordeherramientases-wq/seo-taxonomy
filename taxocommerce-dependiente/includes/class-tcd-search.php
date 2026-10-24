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
        add_shortcode( 'taxocommerce_dependiente', array( __CLASS__, 'shortcode' ) );
    }

    public static function search( $request ) {
        $q = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
        if ( ! $q || mb_strlen( $q ) > 180 ) {
            return new WP_Error( 'tcd_invalid_query', 'Introduce una consulta de hasta 180 caracteres.', array( 'status'=>400 ) );
        }

        $interpretation = TCD_Intelligence::interpret( $q );
        $results = TCD_Intelligence::search_products( $q, 8 );
        $interpreter = TCD_Intelligence::interpreter_state();
        $state = TCD_Academy::state();

        if ( $results ) {
            $semantic = ! empty( $interpretation['semantic_hits'] );
            $lesson9 = false;
            foreach ( $results as $result ) {
                if ( in_array( 'lesson9', (array) ( $result['sources'] ?? array() ), true ) ) {
                    $lesson9 = true;
                    break;
                }
            }
            if ( $lesson9 ) {
                $answer = 'He relacionado tu necesidad con el conocimiento aprendido del catálogo. Estas son las opciones con mayor evidencia.';
            } elseif ( $semantic ) {
                $answer = 'He interpretado los conceptos de tu consulta y los he relacionado con el catálogo.';
            } else {
                $answer = 'He encontrado estas referencias en el catálogo. La coincidencia textual se usa como red de seguridad cuando no hay suficiente evidencia semántica.';
            }
        } else {
            $answer = 'No he encontrado una coincidencia con evidencia suficiente en el conocimiento y el catálogo actual.';
        }

        return rest_ensure_response( array(
            'answer'=>$answer,
            'results'=>$results,
            'interpretation'=>array(
                'normalized'=>(string) ( $interpretation['normalized'] ?? '' ),
                'concepts'=>array_values( (array) ( $interpretation['groups'] ?? array() ) ),
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
