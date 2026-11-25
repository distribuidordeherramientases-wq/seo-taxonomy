<?php
/**
 * Business Hub: administrator-entered official aggregate statistics.
 * No undocumented provider endpoints or account credentials.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_ChatGPT_Business {
    const OPTION = 'tgb_chatgpt_business_snapshots_v1';
    const ACTION = 'tgb_chatgpt_business_save';
    const EXPORT = 'tgb_chatgpt_business_export';

    public static function register() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
        add_action( 'admin_post_' . self::EXPORT, array( __CLASS__, 'export' ) );
    }

    public static function date_valid( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
            return false;
        }
        $p = explode( '-', $value );
        return checkdate( (int) $p[1], (int) $p[2], (int) $p[0] );
    }

    public static function normalize( array $data ) {
        $end = $data['end'] ?? '';
        $start = $data['start'] ?? '';
        $business = sanitize_text_field( is_string( $data['business'] ?? null ) ? $data['business'] : '' );
        $domain = strtolower( trim( is_string( $data['domain'] ?? null ) ? $data['domain'] : '' ) );
        if ( '' === $business || strlen( $business ) > 200 || ! filter_var( $domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) || false === strpos( $domain, '.' ) ) {
            return new WP_Error( 'business', 'Invalid business or domain.' );
        }
        if ( ! self::date_valid( $start ) || ! self::date_valid( $end ) || $end > gmdate( 'Y-m-d' )
            || ( strtotime( $end . ' UTC' ) - strtotime( $start . ' UTC' ) ) !== 27 * DAY_IN_SECONDS ) {
            return new WP_Error( 'period', 'Invalid 28-day period.' );
        }
        $metrics = array();
        foreach ( array( 'mentions', 'clicks' ) as $key ) {
            $value = $data[ $key ] ?? '';
            if ( '' === $value || null === $value ) {
                $metrics[ $key ] = null;
            } elseif ( ( is_string( $value ) || is_int( $value ) ) && preg_match( '/^\d{1,10}$/D', (string) $value ) && (float) $value <= 2147483647 ) {
                $metrics[ $key ] = (int) $value;
            } else {
                return new WP_Error( 'metric', 'Invalid count.' );
            }
        }
        $raw = $data['topics'] ?? '';
        if ( ! is_string( $raw ) || strlen( $raw ) > 12000 ) {
            return new WP_Error( 'topics', 'Invalid topics.' );
        }
        $topics = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $line = sanitize_text_field( $line );
            if ( '' !== $line ) {
                $topics[] = $line;
            }
        }
        $topics = array_values( array_unique( $topics ) );
        if ( count( $topics ) > 100 ) {
            return new WP_Error( 'topics', 'Too many topics.' );
        }
        if ( null === $metrics['mentions'] && null === $metrics['clicks'] && empty( $topics ) ) {
            return new WP_Error( 'empty', 'No observations supplied.' );
        }
        return array(
            'business' => $business, 'domain' => $domain, 'period_start' => $start, 'period_end' => $end,
            'mentions' => $metrics['mentions'], 'clicks' => $metrics['clicks'], 'discovery_topics' => $topics,
            'source' => 'chatgpt_business_hub', 'collection_method' => 'manual_transcription',
            'captured_at' => gmdate( 'c' ), 'site_host' => self::host(),
        );
    }

    public static function host() {
        return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
    }

    public static function snapshots() {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }
        return array_values( array_filter( $stored, static function ( $row ) {
            return is_array( $row ) && ( $row['site_host'] ?? '' ) === self::host();
        } ) );
    }

    private static function url() {
        return add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'chatgpt_business' ), admin_url( 'admin.php' ) );
    }

    private static function authorize( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( $action );
    }

    public static function save() {
        self::authorize( self::ACTION );
        $input = array();
        foreach ( array( 'business', 'domain', 'start', 'end', 'mentions', 'clicks', 'topics' ) as $key ) {
            $input[$key] = isset( $_POST[$key] ) && is_string( $_POST[$key] ) ? wp_unslash( $_POST[$key] ) : '';
        }
        $row = self::normalize( $input );
        if ( is_wp_error( $row ) ) {
            wp_safe_redirect( add_query_arg( 'business_notice', 'invalid', self::url() ) );
            exit;
        }
        $history = get_option( self::OPTION, array() );
        $history = is_array( $history ) ? $history : array();
        $history = array_values( array_filter( $history, static function ( $old ) use ( $row ) {
            return is_array( $old ) && ! (
                ( $old['site_host'] ?? '' ) === $row['site_host'] && ( $old['domain'] ?? '' ) === $row['domain']
                && ( $old['business'] ?? '' ) === $row['business'] && ( $old['period_end'] ?? '' ) === $row['period_end']
            );
        } ) );
        array_unshift( $history, $row );
        update_option( self::OPTION, array_slice( $history, 0, 100 ), false );
        wp_safe_redirect( add_query_arg( 'business_notice', 'saved', self::url() ) );
        exit;
    }

    public static function export() {
        self::authorize( self::EXPORT );
        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="chatgpt-business-insights.json"' );
        echo wp_json_encode( array( 'schema' => 'taxocommerce-chatgpt-business-v1', 'source' => 'chatgpt_business_hub', 'snapshots' => self::snapshots() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $rows = self::snapshots();
        $notice = isset( $_GET['business_notice'] ) && is_string( $_GET['business_notice'] ) ? sanitize_key( wp_unslash( $_GET['business_notice'] ) ) : '';
        ?>
        <h2>ChatGPT Business Hub</h2>
        <p><?php esc_html_e( 'Estadísticas oficiales de visibilidad del negocio en ChatGPT. Consulta el portal para obtener los datos agregados de los últimos 28 días.', 'taxocommerce-google-bing' ); ?></p>
        <p><a class="button button-primary" href="https://chatgpt.com/business-hub/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Consultar Business Hub', 'taxocommerce-google-bing' ); ?></a></p>
        <div class="notice notice-warning inline"><p><?php esc_html_e( 'Descarga automática no disponible: no hay una API pública documentada de Business Hub Insights. Introduce únicamente los datos mostrados en el portal. No se solicitan credenciales ni se utilizan datos de GA4 como sustituto.', 'taxocommerce-google-bing' ); ?></p></div>
        <?php if ( 'saved' === $notice ) : ?>
            <div class="notice notice-success inline"><p><?php esc_html_e( 'Registro guardado.', 'taxocommerce-google-bing' ); ?></p></div>
        <?php elseif ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e( 'Registro no válido. Revisa el dominio, los 28 días del período y los valores de las métricas.', 'taxocommerce-google-bing' ); ?></p></div>
        <?php endif; ?>
        <h3><?php esc_html_e( 'Registrar estadísticas del portal', 'taxocommerce-google-bing' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::ACTION ); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
            <table class="form-table" role="presentation">
                <tr><th><label for="tgb-cb-business"><?php esc_html_e( 'Negocio', 'taxocommerce-google-bing' ); ?></label></th><td><input id="tgb-cb-business" name="business" class="regular-text" maxlength="200" required value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td></tr>
                <tr><th><label for="tgb-cb-domain"><?php esc_html_e( 'Dominio verificado', 'taxocommerce-google-bing' ); ?></label></th><td><input id="tgb-cb-domain" name="domain" class="regular-text" maxlength="253" required value="<?php echo esc_attr( self::host() ); ?>"></td></tr>
                <tr><th><label for="tgb-cb-start"><?php esc_html_e( 'Fecha inicial', 'taxocommerce-google-bing' ); ?></label></th><td><input id="tgb-cb-start" name="start" type="date" required></td></tr>
                <tr><th><label for="tgb-cb-end"><?php esc_html_e( 'Fecha final', 'taxocommerce-google-bing' ); ?></label></th><td><input id="tgb-cb-end" name="end" type="date" required></td></tr>
                <tr><th><label for="tgb-cb-mentions"><?php esc_html_e( 'Menciones', 'taxocommerce-google-bing' ); ?></label></th><td><input id="tgb-cb-mentions" name="mentions" type="number" min="0" max="2147483647" step="1"></td></tr>
                <tr><th><label for="tgb-cb-clicks"><?php esc_html_e( 'Clics hacia la web', 'taxocommerce-google-bing' ); ?></label></th><td><input id="tgb-cb-clicks" name="clicks" type="number" min="0" max="2147483647" step="1"></td></tr>
                <tr><th><label for="tgb-cb-topics"><?php esc_html_e( 'Temáticas de descubrimiento', 'taxocommerce-google-bing' ); ?></label></th><td><textarea id="tgb-cb-topics" name="topics" rows="5" maxlength="12000" class="large-text"></textarea><p class="description"><?php esc_html_e( 'Una temática por línea. Deja en blanco los datos no disponibles; cero es un valor medido. Las ventanas solapadas no se deben sumar.', 'taxocommerce-google-bing' ); ?></p></td></tr>
            </table>
            <?php submit_button( __( 'Guardar registro', 'taxocommerce-google-bing' ) ); ?>
        </form>
        <h3><?php esc_html_e( 'Histórico de Business Hub', 'taxocommerce-google-bing' ); ?></h3>
        <?php if ( empty( $rows ) ) : ?>
            <p><?php esc_html_e( 'Sin datos registrados en este entorno.', 'taxocommerce-google-bing' ); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::EXPORT ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT ); ?>">
                <?php submit_button( __( 'Exportar JSON', 'taxocommerce-google-bing' ), 'secondary', 'submit', false ); ?>
            </form>
            <div style="overflow:auto"><table class="widefat striped"><thead><tr>
                <?php foreach ( array( 'Negocio', 'Dominio', 'Período', 'Menciones', 'Clics hacia la web', 'Temáticas de descubrimiento', 'Fecha de registro' ) as $label ) : ?>
                    <th><?php echo esc_html( translate( $label, 'taxocommerce-google-bing' ) ); ?></th>
                <?php endforeach; ?>
            </tr></thead><tbody>
            <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row['business'] ); ?></td><td><?php echo esc_html( $row['domain'] ); ?></td>
                    <td><?php echo esc_html( $row['period_start'] . ' – ' . $row['period_end'] ); ?></td>
                    <td><?php echo esc_html( null === $row['mentions'] ? '—' : number_format_i18n( $row['mentions'] ) ); ?></td>
                    <td><?php echo esc_html( null === $row['clicks'] ? '—' : number_format_i18n( $row['clicks'] ) ); ?></td>
                    <td><?php echo esc_html( implode( ' · ', $row['discovery_topics'] ) ?: '—' ); ?></td>
                    <td><?php echo esc_html( $row['captured_at'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif;
    }
}
