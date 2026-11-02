<?php
/**
 * Read-only user and technical manuals for the actual standalone Scrappers UI.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Scrappers_Manual {
    private static function link( $tab, $label ) {
        $url = add_query_arg(
            array( 'page' => 'taxocommerce-scrappers', 'tcs_tab' => $tab ),
            admin_url( 'admin.php' )
        );
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para consultar el manual.', 'taxocommerce-scrappers' ) );
        }
        echo '<div class="wrap taxocommerce-scrappers-manual">';
        echo '<h2 id="tcs-manual-inicio">' . esc_html__( 'Manual de TaxoCommerce Scrappers', 'taxocommerce-scrappers' ) . '</h2>';
        echo '<p>Documentación integrada de la versión ' . esc_html( TAXOCOMMERCE_SCRAPPERS_VERSION ) . '. Reúne el uso de Connections, Ojeador e Ingeniero y la arquitectura técnica existente.</p>';
        echo '<div class="notice notice-info inline">' . '<p>' . '<strong>' . esc_html__( 'Importante:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'las búsquedas con SerpApi o ScraperAPI pueden tener costes. El manual y el autotest no realizan consultas externas.', 'taxocommerce-scrappers' ) . '</p>' . '</div>';
        echo '<p>' . '<strong>' . esc_html__( 'Índice:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . '<a href="#tcs-manual-usuario">' . esc_html__( 'Manual de usuario', 'taxocommerce-scrappers' ) . '</a>' . ' · ' . '<a href="#tcs-manual-tecnico">' . esc_html__( 'Manual técnico', 'taxocommerce-scrappers' ) . '</a>' . ' · ' . '<a href="#tcs-manual-problemas">' . esc_html__( 'Diagnóstico', 'taxocommerce-scrappers' ) . '</a>' . '</p>';

        echo '<h2 id="tcs-manual-usuario">' . esc_html__( 'Manual de usuario', 'taxocommerce-scrappers' ) . '</h2>';
        echo '<h3 id="tcs-manual-acceso">' . esc_html__( '1. Acceso y navegación', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>Abre <strong>TaxoCommerce &gt; Scrappers</strong> (con Core activo) o <strong>Scrappers</strong> en el menú principal si Core no está instalado. Se necesita el permiso <code>manage_options</code>. Las pestañas del plugin son '
            . wp_kses_post( self::link( 'connections', 'Connections' ) ) . ', ' . wp_kses_post( self::link( 'ojeador', 'Ojeador' ) ) . ', ' . wp_kses_post( self::link( 'ingeniero', 'Ingeniero' ) ) . ', '
            . wp_kses_post( self::link( 'autotest', 'Autotest' ) ) . ' y ' . wp_kses_post( self::link( 'manual', 'Manual' ) ) . '.</p>';

        echo '<h3 id="tcs-manual-proveedores">' . esc_html__( '2. Connections: proveedores externos', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>En ' . wp_kses_post( self::link( 'connections', 'Connections' ) ) . ' se configuran las API keys de <strong>SerpApi</strong> (principal) y <strong>ScraperAPI</strong> (respaldo para búsquedas compatibles). Guarda las claves en el formulario; dejar un campo de clave vacío conserva la que ya estuviera configurada. Las credenciales son compartidas por Ojeador e Ingeniero.</p>';
        echo '<p>' . esc_html__( 'La cadena de búsqueda sigue el orden', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'SerpApi → ScraperAPI', 'taxocommerce-scrappers' ) . '</strong>' . esc_html__( '. Un resultado válido sin coincidencias no es un fallo ni obliga a cambiar de proveedor. Ajusta el límite mensual local de SerpApi según tu contrato. Las cifras de peticiones locales no sustituyen la facturación oficial de los proveedores.', 'taxocommerce-scrappers' ) . '</p>';
        echo '<p>' . esc_html__( 'El botón', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'Probar cadena', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'es una comprobación real de conexión: SerpApi utiliza su consulta de cuenta; si necesita pasar a ScraperAPI puede consumir una solicitud externa. No confundir esta acción con Autotest.', 'taxocommerce-scrappers' ) . '</p>';

        echo '<h3 id="tcs-manual-ojeador">' . esc_html__( '3. Ojeador: mercado y precios', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>En ' . wp_kses_post( self::link( 'ojeador', 'Ojeador' ) ) . ' se consultan las siguientes áreas de trabajo:</p>';
        echo '<ul style="list-style:disc;padding-left:25px">';
        echo '<li>' . '<strong>' . esc_html__( 'Análisis y recomendaciones:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'indicadores por categoría, oportunidades comerciales, bloqueo operativo y comercios representados en la muestra.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Posición de precio:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'compara tu producto con el rango de mercado; permite filtrar por categoría, proveedor y visibilidad.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Comparador de productos:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'resultados de Shopping por precio, comercio, marca/modelo, descuento y valoraciones.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Operación y consultas:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'categorías aptas, cobertura, estado de cada consulta y registro de peticiones; aquí se ajusta el ritmo del servicio.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Merchant · Comparación gratuita:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'consulta los datos Merchant disponibles sin sustituir el histórico de Shopping.', 'taxocommerce-scrappers' ) . '</li>';
        echo '</ul>';
        echo '<p>' . '<strong>' . esc_html__( 'Continuar / actualizar mercado', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'inicia un recorrido por categorías aptas y', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'Detener', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'permite parar el proceso. El trabajo se realiza por lotes mediante WP-Cron. Ajusta las categorías por paso, la frecuencia y la reutilización de consultas antes de aumentar el ritmo. La lectura de informes no modifica productos ni precios de venta.', 'taxocommerce-scrappers' ) . '</p>';
        echo '<p>' . esc_html__( 'Utiliza las exportaciones JSON existentes para conservar resultados y trazabilidad. Revisa fecha, cobertura y fuentes antes de tomar decisiones comerciales.', 'taxocommerce-scrappers' ) . '</p>';

        echo '<h3 id="tcs-manual-ingeniero">' . esc_html__( '4. Ingeniero: investigación técnica y editorial', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>En ' . wp_kses_post( self::link( 'ingeniero', 'Ingeniero' ) ) . ' se organiza la investigación por categoría y tres capas: <strong>L1 documentación técnica</strong>, <strong>L2 experiencia práctica</strong> y <strong>L3 actualidad sectorial</strong>. Las opiniones prácticas no deben tratarse como datos técnicos contrastados.</p>';
        echo '<p>' . esc_html__( 'La interfaz incluye', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'Investigación', 'taxocommerce-scrappers' ) . '</strong>' . ', ' . '<strong>' . esc_html__( 'Editorial', 'taxocommerce-scrappers' ) . '</strong>' . ', ' . '<strong>' . esc_html__( 'Posts', 'taxocommerce-scrappers' ) . '</strong>' . ', ' . '<strong>' . esc_html__( 'Landings', 'taxocommerce-scrappers' ) . '</strong>' . ', ' . '<strong>' . esc_html__( 'Radar externo', 'taxocommerce-scrappers' ) . '</strong>' . ', ' . '<strong>' . esc_html__( 'Datos y fuentes', 'taxocommerce-scrappers' ) . '</strong>' . ' y ' . '<strong>' . esc_html__( 'Pruebas', 'taxocommerce-scrappers' ) . '</strong>' . esc_html__( '. Algunas vistas editoriales dependen de los módulos especializados disponibles en la instalación.', 'taxocommerce-scrappers' ) . '</p>';
        echo '<p>' . esc_html__( 'Para trabajar: selecciona las categorías y la capa, configura las búsquedas por categoría, recopila las fuentes, revisa evidencias y confianza, y utiliza los controles de revisión y aprobación antes de aceptar propuestas o generar borradores. La investigación por sí sola no publica contenidos.', 'taxocommerce-scrappers' ) . '</p>';
        echo '<p>' . '<strong>' . esc_html__( 'Datos y fuentes', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'permite consultar la información conservada;', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'Pruebas', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'contiene comprobaciones propias del proceso editorial, distintas del Autotest general de Scrappers.', 'taxocommerce-scrappers' ) . '</p>';

        echo '<h3 id="tcs-manual-autotest">' . esc_html__( '5. Autotest y resultados', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>Abre ' . wp_kses_post( self::link( 'autotest', 'Autotest' ) ) . ' y pulsa <strong>Ejecutar autotest</strong>. El sistema mostrará un resultado por componente, junto a avisos de configuración y errores de disponibilidad. No inicia Ojeador, no ejecuta Ingeniero, no consulta APIs externas y no guarda los resultados.</p>';
        echo '<p>' . esc_html__( 'Una clave API pendiente de configurar aparece como', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'Aviso', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'y no como error de programación. Si falta una tabla o un controlador, consulta el bloque técnico y comprueba la instalación antes de ejecutar servicios.', 'taxocommerce-scrappers' ) . '</p>';

        echo '<h2 id="tcs-manual-tecnico">' . esc_html__( 'Manual técnico', 'taxocommerce-scrappers' ) . '</h2>';
        echo '<h3 id="tcs-manual-arquitectura">' . esc_html__( '6. Estructura y ciclo de carga', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>El archivo <code>taxocommerce-scrappers.php</code> registra el plugin, sus pestañas y la extensión de Core. Los directorios <code>includes/ojeador/</code> e <code>includes/ingeniero/</code> contienen motores y controladores heredados; sus bootstrap cargan las clases existentes. Cuando el monolito ya ha cargado clases con el mismo nombre, el plugin evita redeclararlas.</p>';
        echo '<p>El menú se registra con <code>admin_menu</code> y capacidad <code>manage_options</code>; la integración con Core utiliza <code>taxocommerce_core_register_modules</code>. Las rutas de navegación usan <code>page=taxocommerce-scrappers</code> y <code>tcs_tab</code>. Ojeador conserva <code>ojeador_tab</code> e Ingeniero el parámetro <code>tab</code> para sus subpantallas.</p>';

        echo '<h3 id="tcs-manual-bbdd">' . esc_html__( '7. Persistencia y compatibilidad', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>Ojeador utiliza seis tablas de WordPress con prefijo dinámico: <code>seo_ojeador_market_products</code>, <code>seo_ojeador_market_offers</code>, <code>seo_ojeador_market_categories</code>, <code>seo_ojeador_market_category_results</code>, <code>seo_ojeador_market_runs</code> y <code>seo_ojeador_query_log</code>. Ingeniero utiliza tres: <code>seo_ingeniero_sources</code>, <code>seo_ingeniero_knowledge</code> y <code>seo_ingeniero_editorial</code>.</p>';
        echo '<p>La configuración compartida de proveedores se conserva en <code>seo_ojeador_shopping_settings</code>; las versiones de esquema usan <code>seo_ojeador_db_version</code> y <code>seo_ingeniero_db_version</code>. El estado del servicio Ingeniero utiliza <code>seo_ingeniero_state_v1</code>. Los nombres legados permiten conservar datos anteriores y no deben renombrarse durante la migración sin un plan específico.</p>';
        echo '<p>Las claves SerpApi y ScraperAPI pueden proceder de las opciones o de las constantes <code>SEO_OJEADOR_SERPAPI_KEY</code> y <code>SEO_OJEADOR_SCRAPERAPI_KEY</code>. No incluyas claves reales en capturas, tickets, exportaciones ni registros.</p>';

        echo '<h3 id="tcs-manual-procesos">' . esc_html__( '8. Procesos programados y seguridad', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>Ojeador registra el hook <code>seo_ojeador_market_tick</code>, usa lotes de consultas y controles de bloqueo. Ingeniero registra su proceso editorial mediante <code>seo_ingeniero_editorial_refresh</code> y conserva interfaces compatibles con el supervisor anterior. No dupliques workers ni actives procesos simultáneos del monolito y del plugin independiente.</p>';
        echo '<p>Las acciones administrativas existentes se gestionan con los handlers <code>admin_post_seo_ojeador_*</code> y <code>admin_post_seo_ingeniero_*</code>, verificando permisos y nonces. El Autotest es un formulario POST independiente con nonce y consultas de solo lectura; no repara tablas, no escribe opciones ni ejecuta el test de conexión de proveedores.</p>';

        echo '<h3 id="tcs-manual-qa">' . esc_html__( '9. Validación, despliegue y límites', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<p>' . esc_html__( 'El autotest interno verifica carga, clases, opciones disponibles, hooks y existencia de tablas, pero', 'taxocommerce-scrappers' ) . ' ' . '<strong>' . esc_html__( 'no acredita', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'acceso HTTP real, calidad de fuentes, ejecución de cron ni resultados comerciales. Para esto, utiliza el test explícito de Connections, los logs de Ojeador, las pruebas editoriales de Ingeniero y los controles de tareas programadas de WordPress.', 'taxocommerce-scrappers' ) . '</p>';
        echo '<p>Las pruebas de desarrollo de <code>tests/</code> se mantienen en GitHub y se excluyen del ZIP distribuible y del despliegue del plugin. Valida los cambios primero en STAGING con pruebas de PHP, regresiones, Plugin Check y controles de acceso. No publiques en PRO sin autorización expresa.</p>';

        echo '<h2 id="tcs-manual-problemas">' . esc_html__( 'Diagnóstico y resolución de incidencias', 'taxocommerce-scrappers' ) . '</h2>';
        echo '<h3>' . esc_html__( '10. Problemas frecuentes', 'taxocommerce-scrappers' ) . '</h3>';
        echo '<ul style="list-style:disc;padding-left:25px">';
        echo '<li>' . '<strong>' . esc_html__( 'Sin clave de proveedor:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'revisa Connections; el autotest señalará la configuración ausente sin mostrar credenciales.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Sin resultados de Shopping:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'distingue respuesta válida de cero resultados, límite de cuota, problemas de red y categoría no apta; revisa Operación y consultas.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Un proceso no avanza:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'comprueba WP-Cron, la cola programada, el estado de bloqueo y la disponibilidad del proveedor; no fuerces llamadas repetidas sin comprobar consumo.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Faltan tablas:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'verifica versión y activación, la instalación previa de Ojeador/Ingeniero y la migración. El Autotest no crea ni modifica tablas.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Ingeniero sin propuestas:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'comprueba cobertura de categorías, fuentes, conocimiento aprobado y acciones editoriales; ausencia de resultados no equivale a error técnico.', 'taxocommerce-scrappers' ) . '</li>';
        echo '<li>' . '<strong>' . esc_html__( 'Coexistencia con monolito:', 'taxocommerce-scrappers' ) . '</strong>' . ' ' . esc_html__( 'comprueba cuál registra los handlers y hooks. Las clases con nombres heredados se reutilizan para evitar redeclaraciones.', 'taxocommerce-scrappers' ) . '</li>';
        echo '</ul>';
        echo '<p>' . '<a href="#tcs-manual-inicio">' . esc_html__( 'Volver al índice', 'taxocommerce-scrappers' ) . '</a>' . '</p>';
        echo '</div>';
    }
}
