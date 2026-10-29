<?php
/**
 * Read-only user and technical manual for TaxoCommerce Redirects.
 *
 * A static reference screen: no redirect rule access, external requests,
 * settings changes, content deletion or export jobs.
 *
 * @package TaxoCommerceRedirects
 */
namespace TaxoCommerce\Redirects;

defined( 'ABSPATH' ) || exit;

final class User_Guide {
    public static function register() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function assets( $hook ) {
        if ( false === strpos( (string) $hook, Plugin::SLUG ) ) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        $tab = isset( $_GET['tc_rd_tab'] ) && is_string( $_GET['tc_rd_tab'] )
            ? sanitize_key( wp_unslash( $_GET['tc_rd_tab'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( 'user-guide' === $tab ) {
            wp_enqueue_style(
                'tc-redirects-user-guide',
                plugins_url( 'assets/user-guide.css', dirname( __DIR__ ) . '/taxocommerce-redirects.php' ),
                array(),
                Plugin::VERSION
            );
        }
    }

    private static function guide_url( $type ) {
        return add_query_arg(
            array(
                'page' => Plugin::SLUG,
                'tc_rd_tab' => 'user-guide',
                'tc_rd_manual' => 'technical' === $type ? 'technical' : 'user',
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function section_url( $tab ) {
        return add_query_arg(
            array( 'page' => Plugin::SLUG, 'tc_rd_tab' => $tab ),
            admin_url( 'admin.php' )
        );
    }

    private static function index( $technical ) {
        return $technical
            ? array(
                'scope' => 'Requisitos y alcance',
                'architecture' => 'Arquitectura y registro de hooks',
                'data' => 'Modelo de datos y compatibilidad',
                'runtime' => 'Resolución HTTP y control de ciclos',
                'validation' => 'Normalización y validación',
                'admin' => 'CRUD y diagnóstico estructural',
                'assisted' => 'Borrado asistido y rollback',
                'transfer' => 'Transferencia JSON',
                'security' => 'Permisos y límites de seguridad',
                'lifecycle' => 'Activación, desactivación y desinstalación',
                'autotest' => 'Auto Test y despliegue',
                'limitations' => 'Limitaciones y mantenimiento',
            )
            : array(
                'intro' => '¿Para qué sirve?',
                'types' => '301 y 302: cuál elegir',
                'create' => 'Crear y editar redirecciones',
                'review' => 'Buscar, diagnosticar y comprobar',
                'transfer' => 'Copias JSON e importaciones',
                'delete' => 'Borrado asistido de contenido',
                'categories' => 'Precauciones con categorías',
                'autotest' => 'Auto Test antes y después',
                'faq' => 'Problemas frecuentes',
            );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permiso para consultar los manuales.', 'taxocommerce-redirects' ) );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET selects static, read-only documentation.
        $requested = isset( $_GET['tc_rd_manual'] ) && is_string( $_GET['tc_rd_manual'] )
            ? sanitize_key( wp_unslash( $_GET['tc_rd_manual'] ) ) : 'user';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $technical = 'technical' === $requested;
        $prefix = $technical ? 'tc-rd-tech-' : 'tc-rd-user-';
        ?>
        <section class="tc-rd-guide" aria-labelledby="tc-rd-guide-title">
            <header class="tc-rd-guide__header">
                <h2 id="tc-rd-guide-title"><?php esc_html_e( 'User Guide', 'taxocommerce-redirects' ); ?></h2>
                <p><?php esc_html_e( 'Manual de usuario y manual técnico de TaxoCommerce Redirects. Documentación integrada, sin operaciones sobre las reglas.', 'taxocommerce-redirects' ); ?></p>
            </header>
            <nav class="tc-rd-guide__switch" aria-label="<?php esc_attr_e( 'Tipo de manual', 'taxocommerce-redirects' ); ?>">
                <a href="<?php echo esc_url( self::guide_url( 'user' ) ); ?>"
                    class="tc-rd-guide__switch-link<?php echo $technical ? '' : ' is-active'; ?>"
                    <?php if ( ! $technical ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'User Manual', 'taxocommerce-redirects' ); ?>
                </a>
                <a href="<?php echo esc_url( self::guide_url( 'technical' ) ); ?>"
                    class="tc-rd-guide__switch-link<?php echo $technical ? ' is-active' : ''; ?>"
                    <?php if ( $technical ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'Technical Manual', 'taxocommerce-redirects' ); ?>
                </a>
            </nav>
            <div class="tc-rd-guide__layout">
                <nav class="tc-rd-guide__index" aria-label="<?php esc_attr_e( 'Índice del manual', 'taxocommerce-redirects' ); ?>">
                    <h3><?php esc_html_e( 'Contenido', 'taxocommerce-redirects' ); ?></h3>
                    <ol>
                        <?php foreach ( self::index( $technical ) as $key => $label ) : ?>
                            <li><a href="#<?php echo esc_attr( $prefix . $key ); ?>"><?php echo esc_html( $label ); ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <article class="tc-rd-guide__article">
                    <?php if ( $technical ) : ?>
                        <?php self::technical(); ?>
                    <?php else : ?>
                        <?php self::user(); ?>
                    <?php endif; ?>
                </article>
            </div>
        </section>
        <?php
    }

    private static function user() {
        ?>
        <section id="tc-rd-user-intro">
            <h2><?php esc_html_e( '1. ¿Para qué sirve Redirects?', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'TaxoCommerce Redirects envía automáticamente al visitante desde una URL antigua a otra URL de la misma web. Sirve para conservar el acceso cuando cambias la ruta de una página, categoría o producto y para evitar enlaces antiguos rotos.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'También muestra incidencias en las reglas, permite guardar copias JSON y ofrece un borrado asistido con confirmación para entradas, páginas, productos y categorías.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Funciona de forma independiente. Si TaxoCommerce Core está activo, Redirects aparece como extensión y ofrece accesos adicionales de borrado asistido.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-user-types">
            <h2><?php esc_html_e( '2. Diferencias entre 301 y 302', 'taxocommerce-redirects' ); ?></h2>
            <h3><?php esc_html_e( '301: traslado permanente', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Utiliza 301 si la URL antigua ya no se va a usar y su sustituta es definitiva. Es la opción habitual al renombrar una categoría o eliminar una ficha que tiene una sustituta real.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( '302: traslado temporal', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Utiliza 302 si prevés volver a mostrar la URL antigua. La redirección 302 no implica un cambio permanente de dirección.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Evita enviar todas las URL retiradas a la portada sin una relación útil. El destino debe responder y ser relevante para el visitante.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-user-create">
            <h2><?php esc_html_e( '3. Crear y editar una redirección', 'taxocommerce-redirects' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Entra en Redirects y abre la pestaña Redirects.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Escribe la URL de origen, por ejemplo /categoria-antigua. No uses la portada ni rutas de administración.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Escribe la URL de destino dentro del mismo sitio, por ejemplo /categoria-nueva.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Selecciona 301 o 302 y pulsa «Crear redirección».', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Para modificarla, pulsa Editar sobre la fila y guarda los cambios; para eliminar la regla, usa Borrar y confirma.', 'taxocommerce-redirects' ); ?></li>
            </ol>
            <h3><?php esc_html_e( 'Qué impide el formulario', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'El plugin rechaza destinos externos, enlaces que se redirigen a sí mismos, orígenes duplicados y ciclos de redirecciones. Las nuevas reglas solo utilizan 301 o 302.', 'taxocommerce-redirects' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'redirects' ) ); ?>"><?php esc_html_e( 'Abrir el gestor de redirecciones', 'taxocommerce-redirects' ); ?></a></p>
        </section>
        <section id="tc-rd-user-review">
            <h2><?php esc_html_e( '4. Consultar las reglas y sus incidencias', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'La parte superior de Redirects muestra el total de reglas, las que se han usado, las que no, el número acumulado de hits, las reglas utilizadas durante las últimas 24 horas, las incidencias, las cadenas y los ciclos.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'En la tabla puedes buscar por ID, origen o destino; filtrar Todas, Revisar, Con uso y Sin uso; abrir una URL de prueba y visitar su destino en otra pestaña.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( 'Qué significa una incidencia', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Una cadena hace pasar la navegación por varias redirecciones; un ciclo intenta volver a una URL anterior. El diagnóstico también identifica orígenes incompatibles, reglas duplicadas y destinos externos históricos.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Importante: que una regla figure sin incidencias solo indica que su estructura es válida. No confirma por HTTP que la página de destino esté disponible.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-user-transfer">
            <h2><?php esc_html_e( '5. Exportar e importar una copia JSON', 'taxocommerce-redirects' ); ?></h2>
            <h3><?php esc_html_e( 'Guardar una copia de seguridad', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'En el gestor, pulsa «Exportar copia JSON». El archivo incluye los orígenes, destinos, códigos de estado, contadores de uso y fecha del último hit. «Exportar análisis JSON» añade el diagnóstico estructural.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( 'Importar reglas nuevas', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Selecciona el JSON y pulsa «Importar solo reglas nuevas». La importación no sustituye las reglas existentes; informa de registros creados, repetidos, inválidos y con ciclos.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Si marcas «Conservar hits y último uso», también recuperará esos datos históricos. El archivo tiene un límite de 25 MiB y 50.000 reglas. Prueba siempre primero en staging y haz una copia previa.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-user-delete">
            <h2><?php esc_html_e( '6. Borrado asistido de contenido', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'El botón de eliminación asistida está disponible en listados y editores compatibles de WordPress y WooCommerce. En Core también se integra en sus editores y listas. Es una operación sobre contenido, distinta de borrar una regla del gestor.', 'taxocommerce-redirects' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Abre la confirmación del elemento y revisa su título y URL actual.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Elige entre crear una 301 hacia una sustituta válida o eliminar sin redirección.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Confirma la operación. También puedes cancelar sin cambios.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Las entradas, páginas y productos van a la papelera. Para borrarlos definitivamente desde ella es necesaria una confirmación adicional.', 'taxocommerce-redirects' ); ?></li>
            </ol>
            <h3><?php esc_html_e( 'Restauración desde la papelera', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Al restaurar un elemento, el plugin elimina la redirección asistida únicamente si sigue siendo exactamente la misma y no se ha modificado después. No elimina una regla que otro administrador haya cambiado.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-user-categories">
            <h2><?php esc_html_e( '7. Precauciones al borrar categorías', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'El borrado de categorías de producto es permanente. Solo se permite cuando no contienen productos ni subcategorías, no son la categoría predeterminada de WooCommerce y no existen relaciones Core protegidas.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Si sigue instalado el control antiguo de eliminación de categorías de TaxoCommerce SEO, Redirects bloquea esa operación para evitar inconsistencias. Utiliza el procedimiento antiguo hasta retirar y verificar completamente ese control.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'La eliminación en masa y los borrados iniciados por clientes REST externos no utilizan este asistente.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-user-autotest">
            <h2><?php esc_html_e( '8. Auto Test: comparar antes y después de actualizar', 'taxocommerce-redirects' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Antes del despliegue, abre Auto Test y pulsa «Guardar estado ANTES». Descarga el informe JSON.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Actualiza el plugin siguiendo el procedimiento autorizado de la instalación.', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'En la misma instalación y con el mismo administrador, pulsa «Comprobar DESPUÉS».', 'taxocommerce-redirects' ); ?></li>
                <li><?php esc_html_e( 'Revisa los errores nuevos, resueltos, existentes y la pérdida de cobertura; descarga el segundo informe JSON.', 'taxocommerce-redirects' ); ?></li>
            </ol>
            <p><?php esc_html_e( 'Las 26 comprobaciones cubren ocho grupos. No generan visitas, no importan reglas ni eliminan contenido; las pruebas HTTP y de borrado real siguen pendientes de una comprobación controlada.', 'taxocommerce-redirects' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'auto-test' ) ); ?>"><?php esc_html_e( 'Abrir Auto Test', 'taxocommerce-redirects' ); ?></a></p>
        </section>
        <section id="tc-rd-user-faq">
            <h2><?php esc_html_e( '9. Preguntas y problemas frecuentes', 'taxocommerce-redirects' ); ?></h2>
            <h3><?php esc_html_e( 'La redirección no funciona', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Comprueba la tabla y el estado en Auto Test, revisa que el origen coincide con la URL solicitada, verifica que el destino está en el mismo dominio y prueba el comportamiento HTTP. Los archivos estáticos y ciertas rutas excluidas no pasan por el motor.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( 'La regla existe, pero el destino da error', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'El diagnóstico comprueba la estructura, no el estado HTTP del destino. Comprueba la URL con el navegador y revisa si tiene otra redirección o está eliminada.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( '¿Qué ocurre si desactivo o desinstalo?', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Desactivar el plugin conserva las reglas. Desinstalarlo desde WordPress elimina definitivamente la tabla compartida seo_redirects y todas sus reglas, incluidas las históricas: exporta una copia y valida las consecuencias antes de desinstalar.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <?php
    }

    private static function technical() {
        ?>
        <section id="tc-rd-tech-scope">
            <h2><?php esc_html_e( '1. Alcance y compatibilidad', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Plugin autónomo para WordPress 6.5+ y PHP 7.4+, con extensión opcional a WooCommerce y TaxoCommerce Core. No requiere APIs externas ni reescrituras en .htaccess, configuración Nginx o el tema.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Soporta reglas 301/302 de origen y destino locales, controles estructurales, importación/exportación JSON y borrado asistido de post, page, product y product_cat.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-architecture">
            <h2><?php esc_html_e( '2. Arquitectura y carga', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'El archivo principal taxocommerce-redirects.php declara los metadatos y registra Plugin::boot en plugins_loaded con prioridad 30. La extensión Core se anuncia mediante taxocommerce_core_register_modules y contempla cuando Core ya ha emitido ese hook.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( 'Clases principales', 'taxocommerce-redirects' ); ?></h3>
            <ul>
                <li><code>Store</code>: <?php esc_html_e( 'esquema compartido, consultas, normalización, guardado, hits y diagnóstico.', 'taxocommerce-redirects' ); ?></li>
                <li><code>Engine</code>: <?php esc_html_e( 'detección de origen en frontend y emisión de respuesta 301/302.', 'taxocommerce-redirects' ); ?></li>
                <li><code>Admin</code>: <?php esc_html_e( 'pantalla de reglas, filtros, CRUD, exportación e importación JSON.', 'taxocommerce-redirects' ); ?></li>
                <li><code>Assisted_Delete</code>: <?php esc_html_e( 'intercepción, confirmación, papelera, protecciones y restauración.', 'taxocommerce-redirects' ); ?></li>
                <li><code>Lifecycle</code>: <?php esc_html_e( 'activación, instalación del esquema, eliminación y multisitio.', 'taxocommerce-redirects' ); ?></li>
                <li><code>Auto_Test</code>: <?php esc_html_e( 'diagnóstico no destructivo con informes comparables.', 'taxocommerce-redirects' ); ?></li>
                <li><code>User_Guide</code>: <?php esc_html_e( 'esta documentación estática y sus estilos.', 'taxocommerce-redirects' ); ?></li>
            </ul>
        </section>
        <section id="tc-rd-tech-data">
            <h2><?php esc_html_e( '3. Persistencia y compatibilidad heredada', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Store::table() utiliza $wpdb->prefix . seo_redirects; no crea otra tabla paralela. El esquema incluye id, origin_url, target_url, status_code, hits, last_hit, created_at y updated_at, con origen único.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'El motor nuevo reutiliza las reglas del plugin SEO anterior y únicamente desregistra su callback histórico de send_headers durante la petición cuando la tabla está preparada. No modifica el código del plugin antiguo.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'La marca tc_redirects_schema_ready indica que el esquema está operativo. Los diagnósticos de Auto Test se guardan en metadatos del administrador, no en seo_redirects.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-runtime">
            <h2><?php esc_html_e( '4. Ejecución del motor HTTP', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Engine::maybe_redirect corre en template_redirect con prioridad 0. Omite administración, AJAX, cron, REST y WP-CLI; solo procesa GET y HEAD y exige la marca de esquema preparado.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Extrae la ruta, omite archivos estáticos y la ruta especial logística-embalaje-preparacion-de-pedidos, normaliza el origen y consulta Store::find_origin.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( 'Seguridad de las cadenas', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Recorre los destinos hasta un máximo de 20 saltos y aborta en ciclos o cadenas no terminadas. Valida el destino mediante WordPress y emite wp_safe_redirect con el código guardado, 301 o 302.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Solo cuando la redirección se envía correctamente se registra el hit y se termina la petición. El motor no convierte silenciosamente un código 302 en 301.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-validation">
            <h2><?php esc_html_e( '5. Validación de rutas y reglas', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Store::normalize_origin y Store::normalize_target validan las rutas. Se rechazan orígenes no permitidos, protocolos distintos de HTTP(S), destinos de otro dominio y cadenas que terminan en el mismo origen.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Store::validate_rule detecta colisiones y ciclos sobre los registros actuales. Las altas y ediciones conservan únicamente 301/302. Las reglas anteriores potencialmente inválidas se diagnostican sin borrarlas automáticamente.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-admin">
            <h2><?php esc_html_e( '6. Administración, métricas y diagnóstico', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Admin::register añade el menú y las acciones admin_post_tc_redirects_manage y admin_post_tc_redirects_export. La tabla de gestión muestra hasta 50 filas por página y aplica búsqueda/filtros a un conjunto limitado por Store::MAX_ROWS.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Store::diagnostics calcula colisiones efectivas, autoredirecciones, cadenas y ciclos, además de hits acumulados, reglas con hits y actividad reciente. No realiza peticiones HTTP a los destinos.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'La pestaña User Guide se renderiza sin llamar a Store::rows ni Store::diagnostics, de modo que consultar la documentación no inspecciona innecesariamente toda la tabla.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-assisted">
            <h2><?php esc_html_e( '7. Borrado asistido: transacción funcional', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Assisted_Delete ofrece una pantalla de confirmación y registra integraciones en editor clásico, Gutenberg, listados nativos y las vistas Core. Antes de borrar revisa capacidades específicas del objeto, nonce, URL origen, reglas preexistentes, ciclos y restricciones de categoría.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Si se solicita una 301, guarda la regla antes del borrado. Si falla la eliminación, revierte la nueva regla. En posts, páginas y productos se utiliza la papelera; en product_cat la eliminación es permanente bajo controles adicionales.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'La relación de redirección asistida se conserva en postmeta bajo _tc_redirects_assisted_rule. Al restaurar un elemento, solo se quita la regla si el ID, origen y destino siguen coincidiendo, para respetar cambios manuales posteriores.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Cuando existe el antiguo pre_delete_term de SEO, la categoría no se elimina desde el nuevo flujo. Los borrados masivos y las llamadas REST de terceros no están cubiertos por el asistente.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-transfer">
            <h2><?php esc_html_e( '8. Contrato JSON de intercambio', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'La exportación genera taxocommerce-redirects-v1, tipo backup o analysis, fecha UTC y array redirects con origin_url, target_url, status_code, hits y last_hit. El tipo analysis agrega el diagnóstico estructural.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'La importación valida que el fichero sea una subida legítima, comprueba JSON y limita a 25 MiB y 50.000 reglas. Importa solo orígenes nuevos, aplica validaciones y no sobrescribe los existentes. Los hits y last_hit solo se restauran si el administrador lo autoriza.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-security">
            <h2><?php esc_html_e( '9. Seguridad y permisos', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'La gestión y exportación requieren manage_options y nonces independientes. Auto Test también verifica permisos y nonce antes de generar o exportar informes. El borrado asistido exige capacidades de objeto/taxonomía y su propia confirmación.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Las entradas de formularios se normalizan y sanitizan y las salidas administrativas se escapan. wp_safe_redirect restringe los destinos; no se deben añadir hosts externos sin revisar la política y sus implicaciones de seguridad.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-lifecycle">
            <h2><?php esc_html_e( '10. Ciclo de vida del plugin', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Lifecycle::activate reutiliza seo_redirects si existe o crea la tabla que falta; en instalaciones multisitio procesa cada blog. Desactivar o actualizar conserva las reglas y no modifica sus contenidos.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'La desinstalación es destructiva: elimina la tabla compartida y limpia referencias del borrado asistido, opciones y avisos. Puede eliminar reglas compartidas con el antiguo plugin SEO. La copia JSON previa y la autorización explícita son imprescindibles.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <section id="tc-rd-tech-autotest">
            <h2><?php esc_html_e( '11. Auto Test y protocolo de despliegue', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'Auto_Test ejecuta 26 comprobaciones en ocho grupos, sin realizar cambios en las redirecciones. Guarda resultados ANTES y último resultado en usermeta separados por usuario y blog. La comparación solo admite dos ejecuciones completas del mismo sitio y fase DESPUÉS.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'El informe registra versión, instante, estado por caso y tiempo de ejecución. Detecta fallos nuevos, resueltos, persistentes, cobertura perdida y casos nuevos. El JSON no incluye las URL del catálogo.', 'taxocommerce-redirects' ); ?></p>
            <h3><?php esc_html_e( 'Validación adicional obligatoria', 'taxocommerce-redirects' ); ?></h3>
            <p><?php esc_html_e( 'Además del Auto Test, ejecuta en staging la batería PHP 7.4/8.4, tests de integración y ciclo de vida, prueba una 301 y una 302 reales, confirma los hits, la copia JSON y un borrado/restauración con contenido de prueba. El resultado del Auto Test no garantiza esas operaciones.', 'taxocommerce-redirects' ); ?></p>
            <p><a href="<?php echo esc_url( self::section_url( 'auto-test' ) ); ?>"><?php esc_html_e( 'Abrir comprobaciones', 'taxocommerce-redirects' ); ?></a></p>
        </section>
        <section id="tc-rd-tech-limitations">
            <h2><?php esc_html_e( '12. Limitaciones, soporte y mantenimiento', 'taxocommerce-redirects' ); ?></h2>
            <p><?php esc_html_e( 'El diagnóstico de destinos es estructural y no sustituye a HTTP. Los filtros y el listado trabajan con un máximo de 50.000 reglas. Las rutas REST de terceros y el borrado en masa no pasan por el asistente.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'El plugin comparte tabla con TaxoCommerce SEO antiguo: cualquier migración debe preservar el esquema, los IDs, los códigos 301/302 y los hits. No elimines código o tablas por considerarlos redundantes sin verificar dependencias.', 'taxocommerce-redirects' ); ?></p>
            <p><?php esc_html_e( 'Para investigar un fallo, conserva los informes JSON ANTES y DESPUÉS, la versión exacta, registros de WordPress y los resultados de CI. No consideres un problema resuelto hasta comprobarlo en el entorno afectado.', 'taxocommerce-redirects' ); ?></p>
        </section>
        <?php
    }
}
