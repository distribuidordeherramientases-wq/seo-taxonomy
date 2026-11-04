<?php
/**
 * Read-only user and technical manuals for TaxoCommerce Templates.
 *
 * This screen does not initialize tables, modify options, run checks or load
 * executable template files. All content is escaped or deliberately static.
 *
 * @package TaxoCommerceTemplates
 */

defined( 'ABSPATH' ) || exit;

final class TCT_Manual {
    /** Documentation is a read-only administrator screen. */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para consultar el manual.', 'taxocommerce-templates' ) );
        }
        ?>
        <div class="wrap tct-manual" id="tct-manual">
            <h2><?php esc_html_e( 'Manual de TaxoCommerce Templates', 'taxocommerce-templates' ); ?></h2>
            <p><?php esc_html_e( 'Documentación de uso y mantenimiento del plugin de enrutamiento y diseño de plantillas WordPress y WooCommerce. Es de solo lectura: abrir esta pestaña no ejecuta chequeos ni altera la web.', 'taxocommerce-templates' ); ?></p>
            <nav aria-label="<?php esc_attr_e( 'Índice de manuales', 'taxocommerce-templates' ); ?>">
                <p>
                    <a href="#tct-manual-usuario"><?php esc_html_e( 'Manual de usuario', 'taxocommerce-templates' ); ?></a>
                    |
                    <a href="#tct-manual-tecnico"><?php esc_html_e( 'Manual técnico', 'taxocommerce-templates' ); ?></a>
                    |
                    <a href="#tct-manual-incidencias"><?php esc_html_e( 'Diagnóstico', 'taxocommerce-templates' ); ?></a>
                </p>
            </nav>

            <section id="tct-manual-usuario" aria-labelledby="tct-manual-usuario-title">
                <h2 id="tct-manual-usuario-title"><?php esc_html_e( 'Manual de usuario', 'taxocommerce-templates' ); ?></h2>
                <p><?php esc_html_e( 'Destinatarios: administradores y responsables de contenidos. Este manual describe las pantallas que existen en el plugin y diferencia guardar una configuración de aplicarla realmente a la web.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '1. Acceso y navegación', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'Accede a Templates desde el menú de TaxoCommerce Core si está disponible; en una instalación independiente utiliza el menú Templates de WordPress. Las pestañas principales son Enrutamiento, Diseñador de plantillas, Autocheck y Manual. Algunas direcciones antiguas abren directamente funciones avanzadas del mismo gestor.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Solo los usuarios con capacidad de administración manage_options pueden abrir estas pantallas. Editar archivos PHP requiere permisos adicionales y que WordPress permita la edición de código.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '2. Aplicación de políticas: interruptor general', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'En la parte superior de la administración está Aplicación de políticas. Si está desmarcada, puedes preparar diseños, archivos y asociaciones, pero Templates no sustituye las plantillas públicas. La web conserva el comportamiento de WordPress, WooCommerce, el tema y otros plugins.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Marcar y guardar el interruptor concede autorización expresa para utilizar rutas configuradas; no publica automáticamente los diseños que sigan como borrador. Tras actualizar a la versión con consentimiento obligatorio, las políticas permanecen suspendidas hasta que un administrador las habilite de forma consciente.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Recomendación: conserva el interruptor desmarcado al instalar, migrar o diagnosticar. Prueba una ruta en STAGING antes de habilitarla en un sitio público.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '3. Enrutamiento: elegir qué plantilla corresponde a cada solicitud', 'taxocommerce-templates' ); ?></h3>
                <ol>
                    <li><?php esc_html_e( 'Abre Enrutamiento. La vista Mis plantillas muestra asociaciones activas; Por configurar muestra tipos principales pendientes; Todas amplía el inventario, incluidos fragmentos internos de WooCommerce.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Utiliza Familia, Buscar y Filtrar para localizar páginas, entradas, productos, categorías, archivos, rutas WooCommerce o roles SEO.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Revisa la columna Estado y origen. Sin asignar usa WordPress o el tema; configurado con motor desactivado no implica que se esté mostrando en la web.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Selecciona un archivo PHP existente para la petición y pulsa Guardar, o utiliza WordPress / Tema para retirar la asociación. Puedes acceder al Diseñador desde cada ruta.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Verifica la página real en STAGING: un archivo asociado puede no llegar a ejecutarse debido a reglas más prioritarias o a una ruta especializada.', 'taxocommerce-templates' ); ?></li>
                </ol>
                <p><?php esc_html_e( 'El catálogo puede detectar tipos de contenido y taxonomías públicos registrados por otros plugins. Las rutas especiales de VEVOR para mercados internacionales conservan su prioridad: la tabla de enrutamiento no garantiza por sí sola que la plantilla seleccionada sea la que se ve en el navegador.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '4. Diseñador de plantillas: instancias, países y dispositivos', 'taxocommerce-templates' ); ?></h3>
                <ol>
                    <li><?php esc_html_e( 'Desde Enrutamiento, utiliza Crear otra plantilla o Editar para abrir una instancia concreta. Cada instancia tiene su propio nombre, rol o petición y conjunto de países.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Selecciona los mercados ES, US, DE, NL y FR a los que debe aplicarse, y el país cuyos módulos vas a editar.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Configura por separado Ordenador y Móvil. Puedes añadir, quitar, ordenar y desactivar módulos visuales, y especificar títulos opcionales. Guarda antes de cambiar de país para no perder la composición actual.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Si solo configuras módulos, sin archivo PHP, el resultado es un borrador visual. El compositor aún no sustituye automáticamente el renderizador público.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Para activar una ruta hace falta un archivo PHP existente, países no solapados con otras plantillas activas del mismo rol y el interruptor general habilitado. Comprueba siempre el resultado en STAGING.', 'taxocommerce-templates' ); ?></li>
                </ol>
                <p><?php esc_html_e( 'El inventario de módulos incluye cabecera, menú del mercado, migas de pan, campañas, bloque principal, ventajas, populares, clústeres, hubs, categorías, productos, artículos, contenido y pie. Los módulos visuales guardados no modifican el PHP por sí mismos.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '5. Biblioteca y editor de archivos PHP', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'En Diseñador de plantillas abre Biblioteca y archivos PHP para consultar archivos integrados y personalizados, descargar copias, duplicar, crear o editar una plantilla clásica. Los archivos que vienen con el plugin son de solo lectura: las modificaciones se guardan como copias nuevas.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Los archivos propios se almacenan en wp-content/taxocommerce-templates-library, separado de la carpeta del plugin, para que una actualización no los sustituya. El editor acepta únicamente nombres PHP válidos y contenido PHP comprobado sintácticamente; el tamaño máximo es 1 MiB. Al reemplazar archivos propios se exige confirmación y se crea una copia de seguridad.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Importante: validar la sintaxis no garantiza que una plantilla funcione. No subas PHP de terceros no fiables. Si la configuración de seguridad de WordPress prohíbe editar código, el editor puede mostrar el contenido sin permitir guardarlo.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '6. Roles editoriales y asociaciones existentes', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'El gestor conserva relaciones de páginas con roles SEO como clúster, hub primario, hub secundario y landing. En las opciones avanzadas de roles, o en el cuadro lateral de edición de páginas, es posible consultar o asignar el rol correspondiente. Las variantes para móvil y ordenador no son roles independientes.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Antes de reasignar una página, comprueba si tiene relaciones activas en la jerarquía. El plugin puede impedir cambios que afecten a asociaciones existentes. No elimines ni recrees relaciones a ciegas.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '7. Auditoría de asociaciones y migración', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'La auditoría avanzada permite revisar asociaciones antiguas y nuevas, archivos no encontrados, variantes ausentes, roles sin registro y plantillas de correo WooCommerce. Puede exportar un CSV de asociaciones. El informe se refiere a la instalación que estás consultando y no demuestra que STAGING y producción tengan el mismo contenido.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'No utilices una operación de restauración de roles sin revisar el informe y disponer de copia de seguridad. La consulta de auditoría es distinta de las acciones correctivas que puedan aparecer en esa pantalla.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '8. Autocheck y JSON', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'Abre Autocheck y pulsa Ejecutar Autocheck cuando quieras revisar componentes, rutas, tablas, políticas y disponibilidad de servicios. El diagnóstico es manual y de solo lectura; no genera diseños, no cambia asociaciones y no activa plantillas. Descarga el JSON para conservar el resultado y los detalles de cada comprobación.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Un resultado correcto de disponibilidad no sustituye la prueba funcional de la página pública ni demuestra que un flujo de compra, un correo o un diseño móvil se haya ejecutado correctamente.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '9. Procedimiento recomendado antes de publicar', 'taxocommerce-templates' ); ?></h3>
                <ol>
                    <li><?php esc_html_e( 'Guarda una copia de seguridad de los archivos personalizados, las tablas y las opciones del sitio.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Mantén las políticas desactivadas mientras revisas rutas, países, plantilla PHP y dependencias.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Ejecuta Autocheck y revisa la auditoría de asociaciones si procede.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Prueba en STAGING la URL afectada en ordenador y móvil, con el idioma y mercado esperados, incluidos errores 404, menús y enlaces.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Activa de forma explícita solo rutas probadas y comprueba que WordPress recupera su plantilla original si vuelves a suspender las políticas.', 'taxocommerce-templates' ); ?></li>
                </ol>
            </section>

            <section id="tct-manual-tecnico" aria-labelledby="tct-manual-tecnico-title">
                <h2 id="tct-manual-tecnico-title"><?php esc_html_e( 'Manual técnico', 'taxocommerce-templates' ); ?></h2>
                <p><?php esc_html_e( 'Destinatarios: desarrolladores y responsables de mantenimiento. Referencia comprobada frente a los archivos del plugin, no frente al monolito histórico. Los cambios de arquitectura deben actualizar también esta documentación y sus pruebas.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '10. Componentes y estructura', 'taxocommerce-templates' ); ?></h3>
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'Archivo o clase', 'taxocommerce-templates' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Responsabilidad', 'taxocommerce-templates' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <tr><td><code>taxocommerce-templates.php</code></td><td><?php esc_html_e( 'Bootstrap, activación, permisos, configuración de políticas, pestañas y registro de hooks.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Router</code></td><td><?php esc_html_e( 'Catálogo de roles, tablas de plantillas, resolución clásica, nombres y variantes de archivos.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Request_Registry</code></td><td><?php esc_html_e( 'Definiciones WordPress y WooCommerce, candidatas de petición y resolución de archivos públicos y fragmentos.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Routing_Admin</code> / <code>TCT_Request_Admin</code></td><td><?php esc_html_e( 'Inventario, filtros y formularios de asignación de solicitudes.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Unified_Manager</code> / <code>TCT_Designs</code></td><td><?php esc_html_e( 'Diseñador, instancias de plantilla, países, archivo PHP y configuración independiente por dispositivo.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Front_Composer</code></td><td><?php esc_html_e( 'Catálogo de catorce módulos visuales, borradores FRONT y validación de composición.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Template_Editor</code></td><td><?php esc_html_e( 'Biblioteca persistente, lectura, carga, comprobación sintáctica, edición y copias de seguridad PHP.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Association_Audit</code></td><td><?php esc_html_e( 'Diagnóstico de relaciones, faltantes, correo y exportación CSV.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Pilot_Router</code> / <code>TCT_Legacy_Email_Compat</code></td><td><?php esc_html_e( 'Prioridad del piloto internacional y compatibilidad de plantillas de correo preexistentes.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><code>TCT_Autocheck</code> / <code>TCT_Manual</code></td><td><?php esc_html_e( 'Diagnóstico voluntario y documentación de solo lectura.', 'taxocommerce-templates' ); ?></td></tr>
                    </tbody>
                </table>

                <h3><?php esc_html_e( '11. Ciclo de arranque y persistencia', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'El archivo principal registra activación y arranque en plugins_loaded (prioridad 30). La activación utiliza dbDelta para crear o ajustar las tablas con prefijo WordPress seo_nodes y seo_templates, conservando los datos compatibles. No hagas borrados destructivos durante actualizaciones.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'seo_nodes relaciona object_type y object_id con seo_role y status. seo_templates conserva template_key, template_name, template_file, is_active, template_type, assignment_mode, is_assignable, device_variants_enabled y otros metadatos. Una ruta y el archivo que contiene su código son entidades distintas.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Las opciones principales son taxocommerce_templates_render_enabled (interruptor), taxocommerce_templates_review_consent_v1 (consentimiento tras actualización), taxocommerce_templates_custom_roles (roles personalizados), tct_template_designs_v1 (instancias y composiciones por mercado/dispositivo) y tct_front_composer_drafts_v1 (borradores FRONT). Son propias de cada instalación.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'La biblioteca personalizada persiste en wp-content/taxocommerce-templates-library; los archivos incluidos en el ZIP permanecen bajo templates y son de solo lectura. Los borradores visuales no generan por sí mismos archivos PHP ejecutables.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '12. Hooks y prioridad de resolución', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'Mientras las políticas estén suspendidas no se registran los filtros públicos de sustitución de plantillas. Con ambos controles de autorización activados, se registran template_include (prioridad PHP_INT_MAX), comments_template (40), wc_get_template (40), wc_get_template_part (40) y woocommerce_email_classes (999). Estas integraciones no deben instalarse desde la pantalla Manual.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'La resolución respeta primero las rutas piloto especializadas y después puede seleccionar un diseño por petición y mercado. Una instancia activa debe tener un PHP existente y no solaparse con otra instancia activa del mismo rol para los mismos países. Sin coincidencia válida, se devuelve el archivo original de WordPress, WooCommerce o tema.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'El catálogo se puede ampliar mediante los filtros tct_request_definitions y tct_request_candidates. Declarar una nueva definición no garantiza que un endpoint AJAX, JSON o una acción comercial pueda resolverse por template_include: se necesita un punto de integración adecuado.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'WooCommerce distingue documentos de página completa y fragmentos PHP cargados mediante wc_get_template o wc_get_template_part. No confundas ambos contratos ni hagas sustituciones genéricas sobre solicitudes AJAX o respuestas JSON.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '13. Administración y controles de seguridad', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'La pantalla principal requiere manage_options. Los formularios que escriben utilizan admin-post y nonces, por ejemplo tct_save_route_mapping, tct_design_create, tct_design_save, tct_save_code, tct_upload_template, tct_settings, tct_assign_page y tct_unassign_page. Los datos de URL son filtros de lectura y no deben convertirse en cambios de estado.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'La edición PHP requiere además edit_plugins, que DISALLOW_FILE_EDIT no esté activo y que wp_is_file_mod_allowed permita code_edit; subir archivos exige también update_plugins. El código se valida con token_get_all y TOKEN_PARSE, con límite de 1 MiB, nombre saneado, confirmación de reemplazo y copia de seguridad. La carpeta se prepara con protecciones de acceso y un index.php vacío.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'La validación sintáctica no es una revisión de seguridad. Las plantillas PHP ejecutan código confiable del administrador y esta capacidad puede impedir la admisión del plugin en WordPress.org. No distribuyas una edición pública sin resolver la separación o retirada de esta funcionalidad.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '14. Administración avanzada y migración', 'taxocommerce-templates' ); ?></h3>
                <p><?php esc_html_e( 'Los enlaces antiguos ?tab=requests, ?tab=roles, ?tab=audit, ?tab=editor y ?tab=composer se mantienen por compatibilidad. Enrutamiento y Diseñador son las vistas de trabajo; la auditoría avanzada compara registros y archivos de cada instalación, incluidos correos y variantes. No se deben reutilizar rutas absolutas ni tablas de otra tienda.', 'taxocommerce-templates' ); ?></p>
                <p><?php esc_html_e( 'Al migrar desde un sistema anterior, conserva el contenido de seo_nodes y seo_templates, comprueba la existencia de cada archivo y detecta colisiones de país y rol. Prueba el fallback antes de habilitar políticas. No asumas que los resultados de un entorno equivalen a los de otro.', 'taxocommerce-templates' ); ?></p>

                <h3><?php esc_html_e( '15. Pruebas y procedimiento de mantenimiento', 'taxocommerce-templates' ); ?></h3>
                <ol>
                    <li><?php esc_html_e( 'Comprueba la sintaxis PHP de los archivos modificados y ejecuta taxocommerce-templates/tests/smoke.php en un entorno de pruebas.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Verifica en WordPress la apertura de las cuatro pestañas y la lectura de los manuales, sin producir escrituras ni ejecutar el Autocheck automáticamente.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Ejecuta manualmente Autocheck, descarga su JSON e inspecciona fallos y pruebas omitidas; no equipares un PASS de disponibilidad a UAT.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'En STAGING comprueba guardado/recuperación de rutas, aislamiento de países y dispositivos, edición permitida y denegada, prioridad de piloto, WooCommerce y fallback con políticas desactivadas.', 'taxocommerce-templates' ); ?></li>
                    <li><?php esc_html_e( 'Mantén separados los datos y archivos propios de cada WordPress. Revisa la verificación del despliegue antes de declarar que la actualización llegó a ambos sitios.', 'taxocommerce-templates' ); ?></li>
                </ol>
                <p><?php esc_html_e( 'El manual forma parte del plugin pero no instala datos, no hace llamadas externas, no crea trabajos programados y no altera opciones de activación. Cualquier modificación de la lógica de enrutamiento requiere pruebas independientes.', 'taxocommerce-templates' ); ?></p>
            </section>

            <section id="tct-manual-incidencias" aria-labelledby="tct-manual-incidencias-title">
                <h2 id="tct-manual-incidencias-title"><?php esc_html_e( 'Diagnóstico y resolución de incidencias', 'taxocommerce-templates' ); ?></h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'Síntoma', 'taxocommerce-templates' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Comprobación recomendada', 'taxocommerce-templates' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <tr><td><?php esc_html_e( 'La plantilla guardada no aparece en la web.', 'taxocommerce-templates' ); ?></td><td><?php esc_html_e( 'Comprueba el interruptor y consentimiento, el estado activo de la ruta, el archivo PHP, el país y la prioridad de otras reglas. Si solo existe un diseño visual, sigue siendo un borrador.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'No puedo editar o subir archivos PHP.', 'taxocommerce-templates' ); ?></td><td><?php esc_html_e( 'Verifica permisos de usuario, DISALLOW_FILE_EDIT, permisos de modificación de WordPress, tamaño de 1 MiB, nombre válido y permisos de escritura de la biblioteca.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'La asociación señala un archivo inexistente.', 'taxocommerce-templates' ); ?></td><td><?php esc_html_e( 'Revisa la biblioteca y el informe de auditoría. Reasigna un archivo disponible o vuelve al respaldo WordPress/Tema; no actives una ruta rota.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Dos diseños compiten por el mismo país.', 'taxocommerce-templates' ); ?></td><td><?php esc_html_e( 'Comprueba instancias activas del mismo rol y retira el solapamiento. El motor evita elegir una coincidencia ambigua.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'El menú internacional o la vista móvil no coincide.', 'taxocommerce-templates' ); ?></td><td><?php esc_html_e( 'Verifica el mercado de la petición, la plantilla PHP que realmente se ejecuta y las variantes por dispositivo. Un borrador del compositor no modifica el archivo público.', 'taxocommerce-templates' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Autocheck informa de servicios omitidos.', 'taxocommerce-templates' ); ?></td><td><?php esc_html_e( 'No conviertas omitidos en correctos. Realiza la comprobación funcional manual que corresponda en STAGING y conserva su evidencia.', 'taxocommerce-templates' ); ?></td></tr>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    }
}
