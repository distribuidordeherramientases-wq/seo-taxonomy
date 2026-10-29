<?php
/**
 * Read-only user and technical manuals for TaxoCommerce Insights.
 *
 * Renders documentation only: no credentials, provider requests, settings,
 * report downloads or analytics jobs are triggered by this screen.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_User_Guide {

    public static function register() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selection.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
        $tab = isset( $_GET['tgb_tab'] ) && is_string( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : '';
        if ( 'taxocommerce-google-bing' !== $page || 'user_guide' !== $tab ) {
            return;
        }
        wp_enqueue_style(
            'tgb-user-guide',
            TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/user-guide.css',
            array(),
            TAXOCOMMERCE_GOOGLE_BING_VERSION
        );
    }

    private static function guide_url( $manual = 'user' ) {
        return add_query_arg(
            array(
                'page'       => 'taxocommerce-google-bing',
                'tgb_tab'    => 'user_guide',
                'tgb_manual' => 'technical' === $manual ? 'technical' : 'user',
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function section_url( $tab ) {
        return add_query_arg(
            array(
                'page'    => 'taxocommerce-google-bing',
                'tgb_tab' => sanitize_key( $tab ),
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function index( $technical ) {
        if ( $technical ) {
            return array(
                'scope'       => 'Alcance y requisitos',
                'architecture'=> 'Arquitectura del plugin',
                'connections' => 'Conexiones y OAuth',
                'reports'     => 'Descarga y persistencia de informes',
                'analysis'    => 'Motor Analysis',
                'trends'      => 'Google Trends',
                'data'        => 'Datos, opciones y compatibilidad',
                'security'    => 'Seguridad y privacidad',
                'performance' => 'Caché y límites de ejecución',
                'tests'       => 'Pruebas y despliegue',
                'support'     => 'Diagnóstico y mantenimiento',
                'autotest'    => 'Auto Test y despliegues',
            );
        }
        return array(
            'intro'      => '¿Para qué sirve?',
            'start'      => 'Primeros pasos',
            'connections'=> 'Connections',
            'search'     => 'Google Search',
            'analytics'  => 'Google Analytics',
            'bing'       => 'Bing',
            'trends'     => 'Google Trends',
            'analysis'   => 'Analysis',
            'export'     => 'Actualización y exportación',
            'autotest'   => 'Auto Test: antes y después',
            'questions'  => 'Preguntas habituales',
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para consultar esta documentación.', 'taxocommerce-google-bing' ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET selects read-only manual content; no state changes.
        $requested = isset( $_GET['tgb_manual'] ) && is_string( $_GET['tgb_manual'] )
            ? sanitize_key( wp_unslash( $_GET['tgb_manual'] ) ) : 'user';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $technical = 'technical' === $requested;
        $manual = $technical ? 'technical' : 'user';
        $prefix = $technical ? 'tgb-tech-' : 'tgb-user-';
        ?>
        <section class="tgb-guide" aria-labelledby="tgb-guide-title">
            <header class="tgb-guide__header">
                <h2 id="tgb-guide-title"><?php esc_html_e( 'User Guide', 'taxocommerce-google-bing' ); ?></h2>
                <p><?php esc_html_e( 'Manual de uso y documentación técnica del plugin TaxoCommerce Insights.', 'taxocommerce-google-bing' ); ?></p>
            </header>
            <nav class="tgb-guide__switch" aria-label="<?php esc_attr_e( 'Tipo de manual', 'taxocommerce-google-bing' ); ?>">
                <a href="<?php echo esc_url( self::guide_url( 'user' ) ); ?>"
                   class="tgb-guide__switch-link<?php echo $technical ? '' : ' is-active'; ?>"
                   <?php if ( ! $technical ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'User Manual', 'taxocommerce-google-bing' ); ?>
                </a>
                <a href="<?php echo esc_url( self::guide_url( 'technical' ) ); ?>"
                   class="tgb-guide__switch-link<?php echo $technical ? ' is-active' : ''; ?>"
                   <?php if ( $technical ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'Technical Manual', 'taxocommerce-google-bing' ); ?>
                </a>
            </nav>
            <div class="tgb-guide__layout">
                <nav class="tgb-guide__index" aria-label="<?php esc_attr_e( 'Índice del manual', 'taxocommerce-google-bing' ); ?>">
                    <h3><?php esc_html_e( 'Contenido', 'taxocommerce-google-bing' ); ?></h3>
                    <ol>
                        <?php foreach ( self::index( $technical ) as $key => $label ) : ?>
                            <li><a href="#<?php echo esc_attr( $prefix . $key ); ?>"><?php echo esc_html( $label ); ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <article class="tgb-guide__article">
                    <?php if ( $technical ) : ?>
                        <?php self::render_technical(); ?>
                    <?php else : ?>
                        <?php self::render_user(); ?>
                    <?php endif; ?>
                </article>
            </div>
        </section>
        <?php
    }

    private static function render_user() {
        ?>
        <section id="tgb-user-intro">
            <h2><?php esc_html_e( '1. ¿Para qué sirve Insights?', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'TaxoCommerce Insights reúne conexiones externas, datos de buscadores, visitas de la web y un apartado de análisis para ayudar a entender la evolución de una tienda online. Consulta Google Search Console, Google Analytics 4 y Bing; también permite explorar Google Trends.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'No publica productos ni modifica el SEO de la tienda. Las métricas externas necesitan permisos de acceso y una descarga solicitada por un administrador. Si no hay datos guardados, el informe puede aparecer vacío.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-user-start">
            <h2><?php esc_html_e( '2. Primeros pasos', 'taxocommerce-google-bing' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Abre Insights y entra en Connections.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Configura cada servicio que quieras consultar. Las instrucciones y los enlaces oficiales están junto a los campos de conexión.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Guarda y utiliza el botón de prueba de cada conexión. Tener una credencial almacenada no confirma que tenga acceso a la cuenta o propiedad.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Abre Google Search, Google Analytics o Bing, selecciona las fechas y pulsa Actualizar datos.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Consulta las gráficas y, si necesitas una copia, exporta el JSON del informe disponible.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'En Analysis solicita de forma individual el informe que necesites; contrasta sus conclusiones con las fuentes y la fecha de los datos.', 'taxocommerce-google-bing' ); ?></li>
            </ol>
        </section>
        <section id="tgb-user-connections">
            <h2><?php esc_html_e( '3. Connections: conectar servicios', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'La pantalla presenta los campos de conexión a la izquierda y una guía de configuración con enlaces oficiales a la derecha. Incluye indicadores de salud de las conexiones y pruebas manuales.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Servicios disponibles', 'taxocommerce-google-bing' ); ?></h3>
            <ul>
                <li><strong>Google Search Console:</strong> <?php esc_html_e( 'propiedad verificada; acceso por cuenta de servicio o autorización OAuth.', 'taxocommerce-google-bing' ); ?></li>
                <li><strong>Google Analytics 4:</strong> <?php esc_html_e( 'ID de propiedad y permisos adecuados de la identidad configurada.', 'taxocommerce-google-bing' ); ?></li>
                <li><strong>Google Merchant Center:</strong> <?php esc_html_e( 'ID numérico de Merchant Center y permisos de la cuenta; no es el ID de Analytics.', 'taxocommerce-google-bing' ); ?></li>
                <li><strong>Microsoft Bing Webmaster:</strong> <?php esc_html_e( 'sitio verificado y clave API.', 'taxocommerce-google-bing' ); ?></li>
                <li><strong>Amazon Affiliates / Creators API:</strong> <?php esc_html_e( 'Partner Tag para enlaces de afiliación y, opcionalmente, credenciales para enriquecer datos.', 'taxocommerce-google-bing' ); ?></li>
                <li><strong>Pinterest Business:</strong> <?php esc_html_e( 'OAuth, tablero de destino y configuración de verificación del dominio y catálogo.', 'taxocommerce-google-bing' ); ?></li>
            </ul>
            <h3><?php esc_html_e( 'Si una conexión falla', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Comprueba el identificador de propiedad, los permisos de la cuenta, la activación de la API correspondiente y el resultado de Test. Una conexión GA4 correcta no concede por sí misma acceso a Search Console.', 'taxocommerce-google-bing' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'connections' ) ); ?>"><?php esc_html_e( 'Abrir Connections', 'taxocommerce-google-bing' ); ?></a></p>
        </section>
        <section id="tgb-user-search">
            <h2><?php esc_html_e( '4. Google Search: rendimiento orgánico', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Presenta clics, impresiones, CTR, posición media y desgloses por consultas, páginas, países o dispositivos, según los datos descargados. Permite comparar periodos equivalentes.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Cómo interpretar los resultados', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Las impresiones indican apariciones en Google y no visitas. El CTR relaciona clics e impresiones; la posición es una media que requiere contexto. Comprueba qué páginas y consultas cambian antes de decidir una modificación SEO.', 'taxocommerce-google-bing' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'google_search' ) ); ?>"><?php esc_html_e( 'Abrir Google Search', 'taxocommerce-google-bing' ); ?></a></p>
        </section>
        <section id="tgb-user-analytics">
            <h2><?php esc_html_e( '5. Google Analytics: visitas y comportamiento', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Muestra sesiones, usuarios, interacción, canales, páginas, países, dispositivos y eventos de comercio electrónico cuando están medidos. Puede ayudar a detectar diferencias entre visitas, uso del catálogo y compras registradas.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Lectura de las conversiones', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Las compras de WooCommerce y sus eventos GA4 no representan automáticamente ventas realizadas en una tienda externa mediante afiliación. Para conocer comisiones necesitas la atribución confirmada por el proveedor y la medición específica de clics o conversiones externas.', 'taxocommerce-google-bing' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'google_analytics' ) ); ?>"><?php esc_html_e( 'Abrir Google Analytics', 'taxocommerce-google-bing' ); ?></a></p>
        </section>
        <section id="tgb-user-bing">
            <h2><?php esc_html_e( '6. Bing: búsqueda e indexación', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Consulta datos de Bing Webmaster sobre impresiones, clics, consultas, páginas y señales de rastreo e indexación, cuando los devuelve el servicio. Es una fuente complementaria: las métricas no deben confundirse con las de Google.', 'taxocommerce-google-bing' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'bing' ) ); ?>"><?php esc_html_e( 'Abrir Bing', 'taxocommerce-google-bing' ); ?></a></p>
        </section>
        <section id="tgb-user-trends">
            <h2><?php esc_html_e( '7. Google Trends: explorar la demanda', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'La pestaña compara gráficamente los clústeres y los hubs primarios españoles de la jerarquía SEO. Los hubs secundarios y las categorías se consultan manualmente. También puedes abrir Búsqueda manual de términos y comparar hasta cinco expresiones.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Gestor de carga y límites', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Dispone de velocidades 1–5, pausa, reanudación y detención de las comparaciones. La velocidad regula intervalos entre gráficos solicitados por el navegador; no representa la carga real de CPU del servidor.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Los gráficos incrustados de Google muestran interés relativo de 0 a 100, no número absoluto de búsquedas. No proporcionan automáticamente una serie numérica exportable ni acreditan que una palabra signifique exactamente lo mismo que tu categoría.', 'taxocommerce-google-bing' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'google_trends' ) ); ?>"><?php esc_html_e( 'Abrir Google Trends', 'taxocommerce-google-bing' ); ?></a></p>
        </section>
        <section id="tgb-user-analysis">
            <h2><?php esc_html_e( '8. Analysis: informes de Analista', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Analysis integra el motor de Analista recuperado. No ejecuta los once informes al abrir la pestaña: eliges el bloque y se calcula bajo demanda. La disponibilidad de resultados depende de los datos y módulos instalados.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Los once informes disponibles', 'taxocommerce-google-bing' ); ?></h3>
            <ol>
                <?php foreach ( array( 'Qué hacer', 'Resumen', 'Search Console', 'Contenido', 'Estructura', 'Catálogo', 'Tendencias', 'Plan de acción', 'Competencia', 'Proveedores', 'Fuentes' ) as $name ) : ?>
                    <li><?php echo esc_html( $name ); ?></li>
                <?php endforeach; ?>
            </ol>
            <p><?php esc_html_e( 'Elige el periodo admitido de 28, 60 o 90 días y solicita un informe concreto. Cuando está habilitada, la exportación JSON reúne el análisis. Las recomendaciones son apoyo a la decisión: una señal de tendencia no demuestra por sí sola una oportunidad comercial rentable.', 'taxocommerce-google-bing' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'analysis' ) ); ?>"><?php esc_html_e( 'Abrir Analysis', 'taxocommerce-google-bing' ); ?></a></p>
        </section>
        <section id="tgb-user-export">
            <h2><?php esc_html_e( '9. Fechas, actualizaciones y exportación', 'taxocommerce-google-bing' ); ?></h2>
            <h3><?php esc_html_e( 'Datos descargados', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Google Search, Google Analytics y Bing admiten periodos de 7, 30 y 90 días o un intervalo personalizado de hasta 90 días completos. El día actual y los días futuros no son válidos como fecha final. Se compara con el periodo anterior de igual duración.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Cambiar la selección no llama a la API. Pulsa Actualizar datos si necesitas una nueva descarga; el resultado se almacena localmente. Si no existe una copia para esas fechas, puede mostrarse el último informe guardado con un aviso.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Exportar el resultado', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Cuando haya datos guardados, utiliza Export JSON copy en la pantalla del proveedor correspondiente. El fichero representa una foto de los datos descargados y lleva sus fechas; no garantiza datos actuales si la descarga es antigua.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-user-autotest">
            <h2>Auto Test: antes y después del despliegue</h2>
            <p>En la pestaña Auto Test, ejecuta «Guardar estado ANTES» en cada sitio antes de actualizar. Tras la actualización ejecuta «Comprobar DESPUÉS» en el mismo sitio y con el mismo administrador. Revisa nuevos errores, pruebas corregidas y pérdida de cobertura. Descarga ambos informes JSON si necesitas trazabilidad. Los informes se conservan por usuario y sitio, incluso al cambiar la versión del plugin, siempre que no se eliminen sus metadatos.</p>
            <p>El chequeo no contacta con las APIs ni ejecuta descargas. Los proveedores configurados sin una comprobación externa reciente quedan como no verificados. Para completar la aceptación de producción ejecuta las pruebas de conexión en Connections y comprueba los gráficos en el navegador.</p>
            <p><a href="<?php echo esc_url( self::section_url( 'auto_test' ) ); ?>">Abrir Auto Test</a></p>
        </section>
        <section id="tgb-user-questions">
            <h2><?php esc_html_e( '10. Preguntas habituales', 'taxocommerce-google-bing' ); ?></h2>
            <h3><?php esc_html_e( '¿Abrir Insights inicia descargas?', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Abrir Connections o los informes guardados no ejecuta descargas de Google/Bing. Los gráficos de Google Trends son una excepción visual: algunas comparaciones se solicitan automáticamente al navegador cuando se abre esa pestaña.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( '¿Por qué falta información?', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Puede faltar una conexión, permisos, histórico descargado o datos publicados por el proveedor para las fechas elegidas. Una cifra no disponible no debe interpretarse como cero.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( '¿Analysis sustituye el informe de mercado?', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'No. La versión actual presenta los once informes de Analista dentro de Analysis. Las antiguas pestañas independientes Market y Executive Reports ya no forman parte de la interfaz activa.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <?php
    }

    private static function render_technical() {
        ?>
        <section id="tgb-tech-scope">
            <h2><?php esc_html_e( '1. Alcance y requisitos', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Insights es el módulo independiente de conexiones y análisis de TaxoCommerce. La carpeta y el slug siguen siendo taxocommerce-google-bing por compatibilidad. WordPress 5.8+ y PHP 7.4+ son los mínimos declarados; Core y WooCommerce son opcionales para el arranque, aunque algunos informes necesitan sus datos.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Versión cargada:', 'taxocommerce-google-bing' ); ?> <strong><?php echo esc_html( TAXOCOMMERCE_GOOGLE_BING_VERSION ); ?></strong></p>
            <p><?php esc_html_e( 'Superficie administrativa activa: Connections, Analysis, Google Search, Google Analytics, Bing, Google Trends y esta documentación. Market, Analyst y Executive Reports independientes fueron retirados de la navegación en la línea 1.1.4.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-architecture">
            <h2><?php esc_html_e( '2. Arquitectura y ciclo de arranque', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'taxocommerce-google-bing.php carga componentes en includes/ y registra los servicios en plugins_loaded con prioridad 100. Se integra de manera opcional en el registro de Core mediante taxocommerce_core_register_modules y crea una pantalla propia o un submenú según exista Core.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Componentes relevantes', 'taxocommerce-google-bing' ); ?></h3>
            <div class="tgb-guide__table-scroll">
                <table class="tgb-guide__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Clase o archivo', 'taxocommerce-google-bing' ); ?></th><th scope="col"><?php esc_html_e( 'Responsabilidad', 'taxocommerce-google-bing' ); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><code>TaxoCommerce_Google_Bing_Connections</code></th><td><?php esc_html_e( 'Registro de ajustes, migración de credenciales, autenticación y comprobaciones.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Google_Bing_OAuth</code></th><td><?php esc_html_e( 'Autorización y tokens OAuth de Search Console.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Connections_Admin</code></th><td><?php esc_html_e( 'Formulario de conexiones y acciones de guardar/probar.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Connections_Status</code></th><td><?php esc_html_e( 'Estados de salud, comprobaciones explícitas y avisos.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Google_Bing_Reports</code></th><td><?php esc_html_e( 'Descarga, almacenamiento, renderizado y exportación de GA4, Search y Bing.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Google_Bing_GSC</code></th><td><?php esc_html_e( 'Consultas a Search Console y comparación de periodos.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Google_Bing_Trends</code></th><td><?php esc_html_e( 'Consultas manuales y visualización de Google Trends.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>TaxoCommerce_Google_Bing_Trends_Hierarchy</code></th><td><?php esc_html_e( 'Comparaciones españolas basadas en clústeres y hubs SEO.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>includes/analysis/</code></th><td><?php esc_html_e( 'Motor de Analista 3.9.0 recuperado, incluyendo los once informes y el controlador AJAX.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>includes/class-taxocommerce-connections-*.php</code></th><td><?php esc_html_e( 'Compatibilidad y conexión de Amazon, Pinterest y servicios heredados.', 'taxocommerce-google-bing' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section id="tgb-tech-connections">
            <h2><?php esc_html_e( '3. Conexiones, credenciales y OAuth', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'El registro de conexiones conserva claves históricas y recupera opciones anteriores cuando las modernas están vacías. Los valores ya configurados tienen prioridad: instalar Insights no debería obligar a reconectar todas las fuentes.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Flujos de autenticación', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Search Console admite OAuth con el scope webmasters.readonly y accesos por cuenta de servicio configurada. GA4 usa la propiedad y credenciales correspondientes. Bing utiliza la URL verificada y una clave API. Merchant, Amazon y Pinterest gestionan sus credenciales y acciones dentro de Connections.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Las credenciales se conservan en opciones de WordPress; el manual no lee ni muestra sus contenidos. Las pruebas de conexión comprueban permisos o acceso, pero no sustituyen una descarga real ni demuestran que todas las métricas estén disponibles.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Contrato de administración', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Las operaciones de configuración, conexión, test y descarga usan acciones admin_post_* protegidas. El callback OAuth mantiene su ruta histórica para evitar perder compatibilidad con autorizaciones previas.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-reports">
            <h2><?php esc_html_e( '4. Informes: flujo de datos y almacenamiento', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Los informes de Google Search, Analytics y Bing tienen un contrato local de lectura mediante TaxoCommerce_Google_Bing_Reports::saved_reports() y get_saved_snapshot(). La visualización GET lee opciones guardadas; las llamadas externas ocurren al utilizar admin_post_tgb_download_report.', 'taxocommerce-google-bing' ); ?></p>
            <div class="tgb-guide__chain"><?php esc_html_e( 'Conexión autorizada → Descarga solicitada → API del proveedor → Snapshot en options → Visualización / JSON', 'taxocommerce-google-bing' ); ?></div>
            <h3><?php esc_html_e( 'Selección de fechas y comparación', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Admite 7, 30, 90 o un rango personalizado de 1–90 días cerrados, excluido el día actual. El comparador usa el periodo anterior de igual duración. La descarga guarda el snapshot en la opción tgb_manual_report_v1_{source} con autoload deshabilitado.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Por cada proveedor se conservan hasta diez snapshots recientes identificados por fechas. La opción no se reemplaza por la mera apertura de una pantalla. Un bloqueo transient por fuente evita descargas simultáneas durante una ventana de dos minutos.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'El contrato de notificación tgb_manual_report_saved permite que otros módulos reaccionen a una nueva descarga. El exportador de informes utiliza admin_post_tgb_export_report y genera una copia JSON sin repetir la consulta externa.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-analysis">
            <h2><?php esc_html_e( '5. Motor Analysis y once informes', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'includes/analysis/analista-bootstrap.php carga el motor Analista 3.9.0 recuperado. La vista Analysis usa seo_analista_render_report() y los informes parciales definidos en seo_analista_lazy_reports(). Las peticiones de cálculo se atienden por wp_ajax_seo_analista_partial_report con nonce y permisos de administración.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Ejecución bajo demanda', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Abrir la pestaña construye la interfaz, no ejecuta de golpe los once diagnósticos. Cada botón solicita el informe elegido. Algunas fuentes internas y compatibilidades pueden faltar: cuando no hay evidencia disponible, el resultado debe indicarlo y no inventar valores.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Los once bloques son: Qué hacer, Resumen, Search Console, Contenido, Estructura, Catálogo, Tendencias, Plan de acción, Competencia, Proveedores y Fuentes. Los periodos usados por Analista son 28, 60 y 90 días, distintos de los de los informes directos de proveedor.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'El análisis reutiliza datos y APIs internos disponibles, por lo que deben verificarse las dependencias de la instalación real. No debe interpretarse como un sistema independiente de captación de datos de mercado ni como contabilidad de ventas de afiliación.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-trends">
            <h2><?php esc_html_e( '6. Google Trends: fuente, jerarquía y límites', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'La pestaña integra gráficos incrustados de Google Trends. La comparación jerárquica toma clústeres y hubs primarios españoles del árbol SEO y solicita visualizaciones por el navegador, con controles de ritmo 1–5, pausa y detención.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'La búsqueda manual recibe hasta cinco términos y periodos entre siete días y cinco años, con región ES. Los hubs secundarios y categorías se mantienen como exploración manual. No se deben tratar los índices relativos 0–100 como volumen absoluto.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'El antiguo soporte RSS Trending Now sigue presente por compatibilidad, pero no es la fuente de los gráficos Explore actuales. La API oficial de Trends no se utiliza aquí como API pública de series numéricas; su acceso está restringido.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-data">
            <h2><?php esc_html_e( '7. Opciones y compatibilidad de datos', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'La configuración y las copias de informes se guardan principalmente en wp_options mediante la API de opciones de WordPress (el prefijo wp_ es solo un ejemplo). No se debe asumir una nueva tabla SQL propia para cada proveedor.', 'taxocommerce-google-bing' ); ?></p>
            <div class="tgb-guide__table-scroll">
                <table class="tgb-guide__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Clave o prefijo', 'taxocommerce-google-bing' ); ?></th><th scope="col"><?php esc_html_e( 'Uso', 'taxocommerce-google-bing' ); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><code>taxocommerce_google_bing_google_settings</code></th><td><?php esc_html_e( 'Propiedad Search Console, GA4 y cuenta de servicio.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>taxocommerce_google_bing_bing_settings</code></th><td><?php esc_html_e( 'Sitio Bing y API key.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>taxocommerce_google_bing_merchant_account</code></th><td><?php esc_html_e( 'Identificador de Merchant Center.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>taxocommerce_google_bing_oauth_settings</code></th><td><?php esc_html_e( 'Configuración del cliente OAuth.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>taxocommerce_google_bing_oauth_tokens</code></th><td><?php esc_html_e( 'Tokens OAuth; dato sensible.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>tgb_manual_report_v1_{source}</code></th><td><?php esc_html_e( 'Snapshots locales de analytics, search o bing.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>tgb_connection_health_v1</code></th><td><?php esc_html_e( 'Resultados de comprobaciones de conexiones.', 'taxocommerce-google-bing' ); ?></td></tr>
                        <tr><th scope="row"><code>tgb_trends_rss_snapshot_v1_*</code></th><td><?php esc_html_e( 'Snapshots RSS históricos para compatibilidad, no series Explore.', 'taxocommerce-google-bing' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <p><?php esc_html_e( 'Las clases históricas TaxoCommerce_Connections_* y el plugin puente TaxoCommerce Connections conservan contratos de migración. No renombres slugs, callbacks ni claves de opciones sin plan de compatibilidad y pruebas.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-security">
            <h2><?php esc_html_e( '8. Seguridad, permisos y privacidad', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'El acceso a la pantalla Insights requiere manage_options. Las operaciones que guardan credenciales, prueban conexiones y descargan datos validan capacidades y nonces. El manual no contiene acciones de escritura ni consulta credenciales almacenadas.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Algunos servicios externos implican transferir peticiones y metadatos al proveedor elegido. Verifica sus permisos, límites de API y condiciones. No incluyas tokens OAuth, JSON de cuentas de servicio o claves API en incidencias, informes compartidos ni repositorios.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Las métricas agregadas no constituyen una relación individual demostrada entre comprador, pedido y comisión de afiliado. Las conclusiones de ventas fuera de WooCommerce necesitan otra fuente de atribución.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-performance">
            <h2><?php esc_html_e( '9. Recursos, caché y procesos', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'Las descargas de Search, GA4 y Bing son acciones explícitas de administración. El módulo de informes define una ventana CACHE_TTL de 900 segundos para su contrato de caché, y usa un bloqueo transient por proveedor de dos minutos para evitar solapamientos.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Analysis separa cada informe pesado por petición AJAX. Los gráficos jerárquicos de Trends se cargan secuencialmente en el navegador; su velocidad no es un regulador del uso global de CPU. No se programan descargas periódicas nuevas desde el simple hecho de abrir la documentación.', 'taxocommerce-google-bing' ); ?></p>
            <p><?php esc_html_e( 'Si se produce un fallo, comprueba primero la fecha de la última copia guardada, la conexión probada, la cuota del proveedor y el resultado HTTP antes de repetir solicitudes. Reintentos innecesarios pueden agotar las cuotas.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <section id="tgb-tech-tests">
            <h2><?php esc_html_e( '10. Pruebas, versiones y despliegue', 'taxocommerce-google-bing' ); ?></h2>
            <p><?php esc_html_e( 'El workflow .github/workflows/taxocommerce-google-bing.yml valida sintaxis PHP, funcionamiento local de informes, compatibilidad de Connections, Search Console, Google Trends y el estado de las conexiones. También comprueba JavaScript de Trends y genera el ZIP del plugin.', 'taxocommerce-google-bing' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Trabaja en una rama y actualiza la versión del archivo principal y el Stable tag del readme.txt.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Ejecuta pruebas sin red para garantizar que abrir pantallas documentales o informes guardados no dispara peticiones externas.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Crea una pull request a staging, revisa los resultados y fusiona únicamente cuando las comprobaciones estén correctas.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'En staging se usa un workflow con FTPS para desplegar la carpeta taxocommerce-google-bing. Verifica el resultado real en WordPress; un merge no demuestra por sí mismo que el FTP haya terminado.', 'taxocommerce-google-bing' ); ?></li>
                <li><?php esc_html_e( 'Para producción utiliza el procedimiento independiente autorizado, con copia recuperable y comprobación de conexiones y credenciales.', 'taxocommerce-google-bing' ); ?></li>
            </ol>
        </section>
        <section id="tgb-tech-autotest">
            <h2>Auto Test: diagnóstico técnico y regresiones</h2>
            <p>TaxoCommerce_Google_Bing_Auto_Test registra dos acciones admin-post protegidas con manage_options y nonce. Agrupa controles de arranque, conexiones, Search Console, GA4, Bing, Trends, Analysis, almacenamiento, seguridad y UAT. Solo hace lecturas locales y pruebas deterministas; no modifica tokens, informes ni fuentes externas.</p>
            <p>Los informes por usuario y sitio se guardan en usermeta mediante las claves tgb_auto_test_before_{blog_id} y tgb_auto_test_last_{blog_id}. El identificador SHA-256 del sitio impide comparar distintas instalaciones; no se incluyen credenciales ni mensajes de excepciones potencialmente sensibles. La comparación solo se activa para dos ejecuciones completas, siendo la segunda «DESPUÉS», y distingue errores nuevos, corregidos, existentes, pruebas nuevas y cobertura perdida.</p>
            <p>La evaluación no sustituye pruebas de integraciones en vivo, reconexión OAuth, descargas ni renderizado externo. Los estados skip/not_run son explícitos y no equivalen a pass. Se valida en CI con tests/auto-test-smoke.php, que se excluye del ZIP público.</p>
        </section>
        <section id="tgb-tech-support">
            <h2><?php esc_html_e( '11. Diagnóstico y mantenimiento', 'taxocommerce-google-bing' ); ?></h2>
            <h3><?php esc_html_e( 'Search Console devuelve error de permiso', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Comprueba que la propiedad exacta esté verificada y compartida con la identidad usada. Diferencia la cuenta de servicio, OAuth moderno y autorización heredada. GA4 no hereda permisos de Search Console.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'No aparecen métricas o Analysis está incompleto', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Comprueba qué fuentes guardadas existen, la fecha de descarga y los módulos que aportan cada dato. Un informe sin fuente fiable debe marcar información no disponible en lugar de presentar ceros como hechos.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Google Trends no muestra un gráfico', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Las inserciones dependen de Google y del navegador: algunas pueden quedar limitadas. Abre el enlace oficial Explore y compara los términos manualmente. Una gráfica vacía no demuestra ausencia de demanda.', 'taxocommerce-google-bing' ); ?></p>
            <h3><?php esc_html_e( 'Migraciones y credenciales', 'taxocommerce-google-bing' ); ?></h3>
            <p><?php esc_html_e( 'Las claves de opciones y los callbacks históricos son parte de la compatibilidad. Antes de cualquier migración de producción haz backup de WordPress/MySQL y verifica que los accesos OAuth no se invalidan. El manual no instala, modifica ni reconecta servicios.', 'taxocommerce-google-bing' ); ?></p>
        </section>
        <?php
    }
}
