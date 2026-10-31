<?php
/**
 * In-plugin user and technical guide for standalone TaxoCommerce Social.
 * Documentation is rendered only by an administrator and never calls providers.
 */
defined( 'ABSPATH' ) || exit;

function tcsocial_manual_render() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Sin permisos para consultar el manual.', 'taxocommerce-social' ) );
    }
    ?>
    <section class="seo-social-card" id="tcsocial-manual">
        <h2>Manual de TaxoCommerce Social · v<?php echo esc_html( TAXOCOMMERCE_SOCIAL_VERSION ); ?></h2>
        <p>Guía para administrar cuentas autorizadas, plantillas y calendario; referencia técnica para mantener el módulo independiente. Abrir el manual no crea publicaciones ni contactos con las redes.</p>
        <p><a href="#tcsocial-manual-user">Manual de usuario</a> · <a href="#tcsocial-manual-tech">Manual técnico</a></p>

        <section id="tcsocial-manual-user">
            <h2>1. Manual de usuario</h2>
            <h3>1.1 Acceso y finalidad</h3>
            <p>Abre <strong>TaxoCommerce Core → Social</strong> si la familia Core está instalada; de lo contrario, abre el menú propio <strong>TaxoCommerce Social</strong>. El módulo administra conexiones autorizadas a redes, formatos de publicación y un calendario autónomo. Las campañas y ofertas de marketing se gestionan por separado en Campaigns.</p>
            <p>Las pestañas principales son <strong>Conexiones, Plantillas y Calendario</strong>. Se añaden <strong>Auto Test</strong> y <strong>Manual</strong> al final. La instalación no conecta ni publica por sí sola.</p>
            <h3>1.2 Conexiones y permisos</h3>
            <p>En <strong>Conexiones</strong> encontrarás los proveedores <strong>Facebook, Instagram, LinkedIn, Pinterest y X</strong>. Cada red tiene requisitos propios de cuenta, permisos, identificadores y credenciales. Autoriza únicamente cuentas que administres y revisa las condiciones del proveedor. El indicador conectado/sin conectar refleja la configuración local; no significa necesariamente que un token siga siendo válido.</p>
            <p>Configura cada proveedor, guarda y utiliza su opción de prueba de conexión cuando corresponda. Si cambian los permisos o expira una autorización, reconecta o actualiza las credenciales. La desconexión se realiza desde las opciones de la propia red; no se deben compartir tokens ni copiarlos a incidencias o capturas.</p>
            <h3>1.3 Plantillas de publicación</h3>
            <p>En <strong>Plantillas</strong> se definen los textos para entradas y páginas en cada red. Las plantillas predeterminadas emplean variables como <code>{titulo}</code>, <code>{extracto}</code> y <code>{url}</code>; el formato final se adapta a las opciones del proveedor. Revisa el título, la descripción, los enlaces y los recursos multimedia antes de publicar.</p>
            <p>Las plantillas constituyen configuraciones reutilizables: guardarlas no envía un mensaje a ninguna plataforma. También pueden existir configuraciones específicas para contenidos en las opciones de publicación existentes.</p>
            <h3>1.4 Calendario y programador</h3>
            <p>La pestaña <strong>Calendario</strong> combina la creación y gestión de tareas con la agenda mensual y una vista cronológica compacta. Selecciona el contenido, la red, la fecha/hora y la plantilla o mensaje según los campos disponibles. Las tareas guardadas pasan a la cola de programación.</p>
            <p>El programador funciona mediante <strong>WP-Cron</strong>. El resultado depende de que la tarea llegue a ejecutarse y de que la red acepte la publicación en ese momento; una programación guardada no equivale a una publicación confirmada. Revisa los estados, errores, reintentos y cancelaciones. La vista mensual permite filtrar por proveedor y detectar coincidencias en el mismo día y red.</p>
            <h3>1.5 Importación y exportación CSV</h3>
            <p>El módulo dispone de utilidades CSV para preparar agendas, revisar contenido, descargar datos y consultar el historial en las pantallas que ofrecen estas acciones. La importación de la agenda utiliza una <strong>previsualización previa a la confirmación</strong>: comprueba columnas, identificadores, proveedores y fechas antes de aplicar cambios. Guarda una copia de la agenda y realiza primero una prueba en STAGING.</p>
            <h3>1.6 Seguimiento de publicaciones</h3>
            <p>Las publicaciones conservan estado, resultado, identificadores de plataforma y métricas cuando el proveedor las facilita. El enlace de seguimiento puede registrar visitas locales atribuidas. Las métricas externas dependen de los permisos y de las capacidades de sincronización de cada red; las cifras no disponibles no deben interpretarse como cero.</p>
            <h3>1.7 Cómo utilizar Auto Test</h3>
            <p>En <strong>Auto Test</strong> pulsa <strong>Ejecutar Auto Test</strong>. Sus 16 comprobaciones indican <strong>Correcto, Error o No ejecutado</strong> para los componentes locales: funciones cargadas, rutas administrativas, cinco proveedores, plantillas, tablas, calendario, programador, cifrado y contratos de publicación.</p>
            <p>No prueba credenciales reales, no llama a las redes, no publica, no crea tareas y no importa/exporta archivos. Los dos últimos informes se conservan por usuario durante 30 días y permiten comprobar regresiones tras una actualización. Una red no conectada no suspende el test técnico del proveedor.</p>
            <h3>1.8 Procedimiento recomendado</h3>
            <p>Primero ejecuta Auto Test; conecta una sola red y verifica su autorización con la opción de conexión. Revisa las plantillas, crea una publicación de prueba y comprueba el estado final en el calendario. Si utilizas CSV, confirma la previsualización antes de aplicar las tareas. Repite Auto Test después de actualizar el plugin.</p>
        </section>

        <section id="tcsocial-manual-tech">
            <h2>2. Manual técnico</h2>
            <h3>2.1 Arranque y arquitectura</h3>
            <p><code>taxocommerce-social.php</code> declara versión, rutas y cargas <code>includes/core.php</code>, <code>facebook.php</code>, <code>instagram.php</code>, <code>linkedin.php</code>, <code>pinterest.php</code>, <code>x.php</code>, <code>programador.php</code>, <code>calendar.php</code> y <code>admin.php</code>. Las nuevas clases son <code>includes/class-auto-test.php</code> y <code>includes/class-manual.php</code>. El registro opcional con Core se hace mediante <code>taxocommerce_core_register_modules</code>.</p>
            <p><code>admin.php</code> mantiene las pestañas, la navegación de compatibilidad con <code>social_subtab</code> y la capacidad administrativa <code>manage_options</code>. El Auto Test y el Manual no crean submenús independientes ni alteran las pantallas existentes.</p>
            <h3>2.2 Tablas y opciones persistentes</h3>
            <p>La configuración se conserva en la opción existente <code>seo_social_network_settings_v1</code>. No la borres al actualizar: contiene proveedores, plantillas y opciones de automatización, incluidos datos de conexión cifrados.</p>
            <p>Las tablas <code>{prefix}seo_social_publications</code> y <code>{prefix}seo_social_schedule</code> guardan respectivamente los registros de publicaciones y las tareas programadas. Las funciones <code>tcsocial_network_maybe_install_tables()</code> y <code>tcsocial_programador_maybe_install_table()</code> conservan el esquema y sus opciones de versión. La nueva documentación y el Auto Test <strong>no crean tablas</strong>; las actualizaciones de esquema siguen las rutas originales de activación e inicio de WordPress.</p>
            <h3>2.3 Registro de proveedores</h3>
            <p>El filtro <code>tcsocial_network_providers</code> reúne Facebook, Instagram, LinkedIn, Pinterest y X. Cada módulo declara sus callbacks de saneamiento de credenciales, test de conexión, publicación y representación del formulario. La disponibilidad de una callback <strong>no implica</strong> que el servicio remoto acepte las credenciales, permisos o formatos de contenido.</p>
            <h3>2.4 Protección de credenciales y peticiones externas</h3>
            <p><code>tcsocial_network_encrypt_secret()</code> y <code>tcsocial_network_decrypt_secret()</code> encapsulan la protección de secretos con las claves de la instalación. Los tokens nunca deben aparecer en el informe de Auto Test, logs, capturas o exportaciones de diagnóstico. El Auto Test solo verifica que existen estas funciones; no descifra credenciales ni ejecuta peticiones HTTP.</p>
            <p>Las llamadas externas ocurren en flujos explícitos de prueba, conexión y publicación del proveedor, sujetos a permisos y límites de su API. El entorno STAGING debe utilizar cuentas de prueba o una configuración segura para evitar publicaciones no deseadas.</p>
            <h3>2.5 Publicación, agenda y WP-Cron</h3>
            <p><code>tcsocial_network_publish_content()</code> prepara y envía contenido con el adaptador de red; <code>tcsocial_programador_create_job()</code> registra una tarea y su evento WP-Cron. El hook <code>tcsocial_programador_run_job</code> gestiona la ejecución, mientras <code>tcsocial_network_publish_scheduled</code> y <code>tcsocial_network_publish_manual_scheduled</code> atienden publicaciones planificadas.</p>
            <p>La interfaz de Calendar usa <code>tcsocial_calendar_events()</code>, <code>tcsocial_calendar_group_by_day()</code> y <code>tcsocial_calendar_has_same_network_collision()</code> para representar agendas y detectar coincidencias. En el Auto Test solo se valida que esas APIs están cargadas; no se consulta la agenda completa, no se programa WP-Cron y no se publica.</p>
            <h3>2.6 Controladores administrativos y seguridad</h3>
            <p>Las rutas <code>admin_post_*</code> permiten configurar conexiones, guardar plantillas y gestionar el programador. Entre ellas se encuentran <code>tcsocial_network_save_connection</code>, <code>tcsocial_network_disconnect</code>, <code>tcsocial_network_save_templates</code>, <code>tcsocial_network_publish_now</code>, <code>tcsocial_programador_create</code>, <code>tcsocial_programador_cancel</code> y <code>tcsocial_programador_retry</code>. Conserva la validación de capacidad, nonce y saneamiento de entradas en cada controlador cuando modifiques estas operaciones.</p>
            <h3>2.7 CSV y exportación de datos</h3>
            <p>El sistema CSV incorpora <code>tcsocial_network_scheduler_parse_import_file()</code>, <code>tcsocial_network_handle_scheduler_import_preview()</code>, <code>tcsocial_network_handle_scheduler_import_confirm()</code> y <code>tcsocial_network_handle_scheduler_export()</code>. La previsualización debe preceder a la confirmación; el Auto Test no abre ficheros, no simula una importación y no expone contenido de la agenda.</p>
            <h3>2.8 Auto Test: contrato y persistencia</h3>
            <p><code>admin_post_tcsocial_run_auto_test</code> exige <code>manage_options</code> y nonce <code>tcsocial_auto_nonce</code>. La batería de <code>TCSocial_Auto_Test::run_suite()</code> agrupa 16 controles sin efectos externos, guarda los dos últimos resultados en el transitorio por usuario <code>tcsocial_auto_test_{user_id}</code> durante 30 días y compara fallos que antes superaban la prueba. No introduce tablas ni opciones permanentes.</p>
            <p>Las pruebas de MySQL ejecutan solamente <code>SELECT 1</code>, comprobaciones de presencia de tablas y <code>SELECT id ... LIMIT 1</code> por tabla. Auto Test no ejecuta <code>dbDelta()</code>, inserciones, actualizaciones, borrados, trabajo programado, cifrado/descifrado ni publicaciones de prueba.</p>
            <h3>2.9 Diagnóstico y despliegue</h3>
            <p>Ante errores, verifica primero los permisos del usuario, presencia de las dos tablas, registro de proveedores y callbacks, configuración de WP-Cron y límites de la API externa. Un resultado correcto de Auto Test acredita disponibilidad de componentes, <strong>no</strong> autorización de cuentas ni entregas exitosas. Comprueba una publicación real únicamente en STAGING con autorización y datos de prueba.</p>
            <p>Valida sintaxis y regresiones en PHP 7.4 y 8.4, comprueba que <code>readme.txt</code> y la cabecera PHP coinciden y distribuye un ZIP sin <code>tests/</code>, archivos de desarrollo ni estado FTP oculto. El fichero histórico <code>changelog.txt</code> no se modifica; los cambios nuevos se registran en <code>CHANGELOG.md</code>.</p>
        </section>
    </section>
    <?php
}
