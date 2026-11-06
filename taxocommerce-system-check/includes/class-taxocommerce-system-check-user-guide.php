<?php
/**
 * Read-only, in-plugin User Manual and Technical Manual for System Check.
 *
 * @package TaxoCommerceSystemCheck
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_User_Guide {

    /** The current manuals describe implemented behavior, not future plans. */
    public static function sections( $technical = false ) {
        if ( $technical ) {
            return array(
                'architecture' => array(
                    'title' => 'Arquitectura e independencia',
                    'body'  => 'System Check es un plugin independiente con una entrada administrativa propia. Se integra opcionalmente en el menú de TaxoCommerce Core y conserva rutas antiguas para evitar perder enlaces existentes.',
                    'parts' => array(
                        array( 'Componentes', 'El archivo principal registra las pestañas. class-taxocommerce-system-check-php.php ofrece diagnóstico local. class-taxocommerce-system-check-url-audit.php gestiona la auditoría HTTP. includes/system-check/ conserva las implementaciones históricas de Sistema, validación e informes.' ),
                        array( 'Convivencia con el monolito', 'Si TaxoCommerce SEO conserva el motor global seo_*, System Check no vuelve a cargarlo. Si el monolito está instalado pero inactivo, System Check evita apropiarse de sus constantes globales. En esa situación pueden aparecer componentes no disponibles: no es un fallo de un servicio que no se ha ejecutado.' ),
                    ),
                ),
                'bootstrap' => array(
                    'title' => 'Arranque y navegación',
                    'body'  => 'El plugin arranca en plugins_loaded con prioridad 100, registra sus acciones, integra Core si está disponible y añade un menú principal independiente o un submenú de Core.',
                    'parts' => array(
                        array( 'Pestañas', 'Sistema, Plugin Validation, HTTP, Procesos, Auto Test y Manual utilizan la página taxocommerce-system-check y el parámetro tcs_tab. En Manual, tcs_manual selecciona user o technical sin cambios de estado.' ),
                        array( 'Seguridad', 'La administración usa manage_options. Las acciones sensibles se protegen con nonces; Auto Test solo acepta un enlace firmado y no realiza escrituras.' ),
                    ),
                ),
                'php' => array(
                    'title' => 'Motor de comprobaciones PHP',
                    'body'  => 'TaxoCommerce_System_Check_PHP::collect() calcula un conjunto de observaciones en memoria: versión, directivas ini, extensiones PHP, librería de imágenes, OPcache y configuración adicional. La vista PHP independiente muestra las mismas observaciones.',
                    'parts' => array(
                        array( 'Interpretación', 'Los estados ok, warning e info describen condiciones del entorno. No todas las extensiones opcionales ausentes son errores ni un resultado correcto implica que WooCommerce funcione.' ),
                        array( 'Límites', 'El motor PHP no necesita Core, no ejecuta consultas de base de datos y no utiliza conexiones remotas.' ),
                    ),
                ),
                'system' => array(
                    'title' => 'Sistema, seguridad e informes',
                    'body'  => 'El diagnóstico histórico presenta estados del servidor, WordPress, MySQL, cron, seguridad, rendimiento y registros. Algunos chequeos de seguridad profundos o monitorizaciones requieren que el administrador los solicite; abrir Auto Test no los inicia.',
                    'parts' => array(
                        array( 'Informes', 'El motor histórico proporciona exportaciones JSON y PDF, y permite configurar avisos de correo. Las notificaciones no se activan por defecto y su destinatario se obtiene de la configuración del sitio o del administrador.' ),
                        array( 'Compatibilidad', 'Los informes históricos dependen de que sus funciones estén cargadas. Si no están disponibles, Auto Test declara esa prueba no aplicable en vez de inventar un resultado.' ),
                    ),
                ),
                'plugins' => array(
                    'title' => 'Plugin Validation y módulos',
                    'body'  => 'taxocommerce_system_check_registered_modules() consulta los módulos activos registrados en Core. module_checks() comprueba carga, versión y, para System Check, disponibilidad de motores. Este inventario es básico y no sustituye las pruebas funcionales de cada extensión.',
                    'parts' => array(
                        array( 'Exportación', 'El endpoint admin-post taxocommerce_system_check_modules_json genera una descarga JSON protegida por permisos y nonce; no modifica el inventario.' ),
                    ),
                ),
                'processes' => array(
                    'title' => 'Control de procesos de aplicación',
                    'body'  => 'La pestaña Procesos consulta Action Scheduler y WP-Cron sin shell ni acceso a procesos Linux. Los inventarios son acotados y las acciones requieren manage_options y nonce.',
                    'parts' => array(
                        array( 'Cancelación segura', 'Solo se cancela una acción pendiente con namespace considerado no crítico; los pagos, el mantenimiento de WordPress y WooCommerce quedan protegidos. Una acción en ejecución no puede terminarse físicamente desde otra petición PHP.' ),
                        array( 'Trabajos de larga duración', 'Los adaptadores opcionales de Dependiente, Import-Export y el auditor HTTP establecen las señales de detención y preservan los avances pertinentes. La exportación CSV realiza la limpieza bajo el bloqueo de su propio worker. Los plugins de terceros pueden registrar su estado y parada cooperativa mediante filtros sin depender de System Check.' ),
                        array( 'Parada global', 'Aplica controles sobre las tareas elegibles de la página, sin detener Apache, PHP-FPM, MySQL ni trabajos de sistema. Las tareas en ejecución requieren una comprobación posterior para confirmar su finalización.' ),
                    ),
                ),
                'http' => array(
                    'title' => 'Auditoría HTTP: ejecución y persistencia',
                    'body'  => 'TaxoCommerce_System_Check_URL_Audit permite auditar páginas, entradas, imágenes y productos. Las ejecuciones se inician de forma expresa y utilizan tandas limitadas y trabajos programados cuando el entorno lo permite.',
                    'parts' => array(
                        array( 'Medidas de protección', 'Las URL se validan para impedir objetivos privados (SSRF), las redirecciones se limitan y las respuestas se leen de forma acotada. La auditoría local puede obtener fallos por restricciones de hosting que no afectan a visitantes externos.' ),
                        array( 'Persistencia y seguimiento', 'Los resultados y el estado se asocian al inventario histórico seo_health_runs / seo_health_items. El motor dispone de bloqueo, cancelación, reanudación y vigilancia de ejecuciones interrumpidas.' ),
                        array( 'Dependencia operativa', 'WP-Cron o un cron real debe funcionar para que los lotes programados progresen sin visitas. Auto Test solo comprueba el registro de los hooks, no ejecuta el trabajo.' ),
                    ),
                ),
                'autotest' => array(
                    'title' => 'Autocheck: contratos, cobertura y límites',
                    'body'  => 'TaxoCommerce_System_Check_Auto_Test::run() verifica contratos y resultados locales de PHP, módulos, auditor HTTP y exportaciones, además del último informe de servidor ya guardado. Devuelve estados, evidencias, cobertura y totales, sin ejecutar nuevas auditorías externas.',
                    'parts' => array(
                        array( 'Estados', 'Correcto indica una condición comprobada; Fallo indica un contrato ausente; No ejecutado / N/A señala una prueba externa, con escritura o de un motor no cargado. No se convierten omisiones en aciertos.' ),
                        array( 'Efectos secundarios', 'El Autocheck no envía correos, no consulta HTTP externo, no programa procesos ni modifica datos de la tienda. Solo conserva el informe resultante en un transient de WordPress durante una hora, separado por usuario y sitio.' ),
                        array( 'Cobertura', 'El Autocheck verifica estructuras y estados de retorno, identifica informes de servidor inexistentes o antiguos como no evaluables y muestra porcentaje real de pruebas ejecutadas. No sustituye Plugin Check de WordPress.org, UAT de compras, pruebas externas de HTTP ni diagnósticos de seguridad profundos.' ),
                    ),
                ),
                'data' => array(
                    'title' => 'Datos, privacidad y mantenimiento',
                    'body'  => 'Las exportaciones de sistema pueden contener rutas, inventarios, incidencias y detalles de configuración. Deben tratarse como información sensible y compartirse únicamente con destinatarios autorizados.',
                    'parts' => array(
                        array( 'Registros y copias', 'La instalación del plugin no sustituye copias de seguridad. Antes de actualizar, conservar una copia verificable del código y de la base de datos si el cambio afecta a operaciones del sitio.' ),
                        array( 'Despliegue', 'Validar sintaxis PHP, autotest, exportaciones y las pantallas Sistema/Plugin Validation/HTTP en STAGING antes de producción. Confirmar la compatibilidad con TaxoCommerce SEO activo e inactivo.' ),
                    ),
                ),
            );
        }
        return array(
            'purpose' => array(
                'title' => '¿Para qué sirve System Check?',
                'body'  => 'System Check ayuda a revisar el estado de WordPress, PHP, el servidor, los módulos instalados y determinadas URL del sitio. Sus resultados aportan información para el diagnóstico, no corrigen automáticamente incidencias.',
                'parts' => array(
                    array( 'Quién puede utilizarlo', 'Es una herramienta para administradores con el permiso manage_options. No aparece en el área pública de la tienda.' ),
                ),
            ),
            'start' => array(
                'title' => 'Acceso y primeros pasos',
                'body'  => 'En el escritorio de WordPress, entra en System Check. Si TaxoCommerce Core está activo, busca System Check dentro de su menú. Elige la pestaña adecuada sin ejecutar chequeos innecesarios.',
                'parts' => array(
                    array( 'Orden recomendado', 'Revisa Sistema para el estado del alojamiento; abre Plugin Validation para la carga de los módulos; utiliza Auto Test para comprobar los servicios del propio System Check. HTTP debe ejecutarse solo cuando quieras examinar direcciones reales.' ),
                ),
            ),
            'system' => array(
                'title' => 'Pestaña Sistema',
                'body'  => 'Presenta diagnósticos de WordPress, PHP, base de datos, seguridad, tareas programadas y rendimiento. Su contenido depende del motor histórico disponible en la instalación.',
                'parts' => array(
                    array( 'Diagnóstico PHP independiente', 'Utiliza el enlace «Abrir diagnóstico PHP independiente» para ver versión de PHP, memoria, límites de ejecución, extensiones y otras observaciones sin iniciar un análisis remoto.' ),
                    array( 'Cómo leer los avisos', 'Una advertencia de entorno no implica necesariamente que una función haya fallado. Revisa cada detalle y compáralo con las necesidades de tu tienda.' ),
                ),
            ),
            'processes' => array(
                'title' => 'Pestaña Procesos',
                'body' => 'Permite consultar tareas de WordPress y WooCommerce gestionadas por Action Scheduler, WP-Cron y los procesos integrados. No requiere acceso al servidor.',
                'parts' => array(
                    array( 'Detener', 'Puedes cancelar tareas pendientes elegibles o solicitar que los trabajos compatibles no ejecuten nuevos lotes. La parada no interrumpe el PHP que ya esté ejecutándose.' ),
                    array( 'Detener todos', 'Actúa solo sobre tareas de aplicación consideradas cancelables y conserva los trabajos internos críticos. Comprueba el estado después: solicitar una parada no equivale a confirmar su finalización.' ),
                ),
            ),
            'plugin' => array(
                'title' => 'Pestaña Plugin Validation',
                'body'  => 'Resume qué módulos están registrados y sus versiones. Los módulos disponibles proceden del registro de TaxoCommerce Core si está instalado.',
                'parts' => array(
                    array( 'Alcance', 'Esta comprobación es básica: confirma carga y ciertos servicios. No sustituye Plugin Check de WordPress.org ni el autotest individual de cada plugin.' ),
                    array( 'Exportar', 'Puedes descargar un JSON del estado de los módulos sin realizar nuevas pruebas de carga.' ),
                ),
            ),
            'http' => array(
                'title' => 'Pestaña HTTP',
                'body'  => 'Audita URL de Páginas, Posts, Imágenes y Productos. Presenta resultados y estadísticas por tipo de contenido, incluidos errores HTTP y tiempos de respuesta.',
                'parts' => array(
                    array( 'Iniciar', 'Selecciona el tipo de contenido y utiliza los controles de la pestaña HTTP. Los trabajos se procesan por lotes pequeños para limitar el consumo de recursos.' ),
                    array( 'Detener y reanudar', 'Utiliza los controles del auditor cuando necesites interrumpir un proceso. El funcionamiento en segundo plano necesita WP-Cron o un cron real operativo.' ),
                    array( 'Interpretar los fallos', 'Un error desde el propio servidor puede deberse a DNS, firewall o límites del alojamiento. Confirma desde fuera del sitio antes de considerar inaccesible una URL.' ),
                ),
            ),
            'autotest' => array(
                'title' => 'Pestaña Autocheck',
                'body'  => 'Pulsa «Ejecutar Autocheck» para validar los servicios de System Check y examinar el último snapshot guardado del servidor sin lanzar otro escaneo. Puedes descargar el JSON del último informe mientras esté vigente.',
                'parts' => array(
                    array( 'Resultados', 'Correcto significa que la comprobación se completó; Fallo señala una dependencia ausente; No ejecutado / N/A indica algo que necesita una prueba independiente. Un test omitido nunca se considera correcto.' ),
                    array( 'Seguridad', 'La ejecución no envía correos, no inicia auditorías HTTP, no programa trabajos y no modifica productos ni opciones. El único dato que se guarda es el JSON del último resultado en un transient de una hora, protegido por usuario, sitio, permisos y nonce.' ),
                ),
            ),
            'reports' => array(
                'title' => 'Informes y exportaciones',
                'body'  => 'Algunas pestañas permiten descargar JSON con datos de las comprobaciones actuales o históricas. El motor original también puede ofrecer PDF y alertas de correo configuradas por el administrador.',
                'parts' => array(
                    array( 'Privacidad', 'No publiques archivos de diagnóstico: pueden mostrar nombres de tablas, rutas, URL y detalles de configuración. Revisa su contenido antes de compartirlo.' ),
                ),
            ),
            'help' => array(
                'title' => 'Problemas habituales',
                'body'  => 'Si una pantalla informa de que el motor original no está disponible, comprueba qué versión de TaxoCommerce SEO está instalada y activa. Si falla HTTP, verifica cron, DNS y las restricciones del proveedor.',
                'parts' => array(
                    array( 'Después de actualizar', 'Comprueba la versión, ejecuta Auto Test y revisa los informes relevantes. Realiza la validación completa en STAGING antes de desplegar en producción.' ),
                ),
            ),
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only manual selection without state changes.
        $requested = isset( $_GET['tcs_manual'] ) && is_scalar( $_GET['tcs_manual'] )
            ? sanitize_key( wp_unslash( $_GET['tcs_manual'] ) ) : 'user';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $technical = 'technical' === $requested;
        $sections = self::sections( $technical );
        $manual_id = $technical ? 'technical' : 'user';

        echo '<section class="wrap tcs-guide">' . '<h2>' . esc_html__( 'Manual de System Check', 'taxocommerce-system-check' ) . '</h2>';
        echo '<p>' . esc_html__( 'Documentación de usuario y técnica de las funciones disponibles en este plugin.', 'taxocommerce-system-check' ) . '</p>';
        echo '<nav aria-label="Tipo de manual"><p>';
        foreach ( array( 'user' => 'Manual de usuario', 'technical' => 'Manual técnico' ) as $id => $label ) {
            $link = taxocommerce_system_check_tab_url( 'manual', array( 'tcs_manual' => $id ) );
            echo '<a class="button' . ( $id === $manual_id ? ' button-primary' : '' ) . '" href="'
                . esc_url( $link ) . '"' . ( $id === $manual_id ? ' aria-current="page"' : '' ) . '>'
                . esc_html( $label ) . '</a> ';
        }
        echo '</p></nav>';
        echo '<h3>' . esc_html__( 'Índice', 'taxocommerce-system-check' ) . '</h3>' . '<ol>';
        foreach ( $sections as $id => $section ) {
            echo '<li><a href="#' . esc_attr( 'tcs-guide-' . $manual_id . '-' . $id ) . '">'
                . esc_html( $section['title'] ) . '</a></li>';
        }
        echo '</ol><hr />';
        foreach ( $sections as $id => $section ) {
            echo '<section id="' . esc_attr( 'tcs-guide-' . $manual_id . '-' . $id ) . '">';
            echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
            echo '<p>' . esc_html( $section['body'] ) . '</p>';
            foreach ( $section['parts'] as $part ) {
                echo '<h3>' . esc_html( $part[0] ) . '</h3><p>' . esc_html( $part[1] ) . '</p>';
            }
            echo '</section>';
        }
        echo '</section>';
    }
}
