<?php
/**
 * User and technical manual embedded in TaxoCommerce Logistics.
 *
 * No order queries, shipping calculations, configuration updates, external
 * requests or file writes are performed by the documentation screen.
 *
 * @package TaxoCommerceLogistica
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Logistica_User_Guide {
    public static function register() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    private static function allowed() {
        return current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
    }

    public static function assets() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
            ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $tab = isset( $_GET['tc_logistica_tab'] ) && is_string( $_GET['tc_logistica_tab'] )
            ? sanitize_key( wp_unslash( $_GET['tc_logistica_tab'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( 'seo-logistica' === $page && 'user-guide' === $tab ) {
            wp_enqueue_style(
                'tc-logistica-user-guide',
                TAXOCOMMERCE_LOGISTICA_URL . 'assets/user-guide.css',
                array(),
                TAXOCOMMERCE_LOGISTICA_VERSION
            );
        }
    }

    private static function guide_url( $type = 'user' ) {
        return add_query_arg(
            array(
                'page' => 'seo-logistica',
                'tc_logistica_tab' => 'user-guide',
                'tc_logistica_manual' => 'technical' === $type ? 'technical' : 'user',
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function section_url( $tab ) {
        $args = array( 'page' => 'seo-logistica' );
        if ( 'shipping' !== $tab ) { $args['tc_logistica_tab'] = $tab; }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * All content is plain translatable text; markup is rendered by a small
     * fixed set of tags. The same chapters drive the index and the article.
     */
    private static function chapters( $technical ) {
        if ( $technical ) {
            return array(
                'scope' => array(
                    'title' => __( '1. Alcance y requisitos', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'TaxoCommerce Logistics es un plugin independiente de gestión del transporte para WordPress 6.5+, PHP 7.4+ y WooCommerce. Con Core activo aparece como módulo de la familia TaxoCommerce; sin Core mantiene su propia entrada de administración.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Controla exclusivamente el cálculo de las tarifas de envío y la auditoría logística de pedidos. El carrito, el checkout, los pedidos y la fiscalidad pertenecen a WooCommerce; las compras y relaciones con proveedores pertenecen a TaxoCommerce Suppliers.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'architecture' => array(
                    'title' => __( '2. Arquitectura y registro', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'h3', __( 'Componentes y carga', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'taxocommerce-logistica.php registra el menú, la integración opcional con Core y carga includes/seo-transporte.php, class-auto-test.php y class-user-guide.php. SEO_Transporte_Costes::init registra las acciones de configuración y los filtros WooCommerce. La documentación no llama al motor operativo.', 'taxocommerce-logistica' ) ),
                        array( 'h3', __( 'Integraciones y hooks', 'taxocommerce-logistica' ) ),
                        array( 'ul', array(
                            __( 'admin_post_seo_transporte_save, _export e _import: acciones protegidas para configuración y copias JSON.', 'taxocommerce-logistica' ),
                            __( 'wc_shipping_enabled: permite que WooCommerce calcule envíos si el gestor propio está activo.', 'taxocommerce-logistica' ),
                            __( 'woocommerce_shipping_methods: método interno seo_transporte_bridge, sin coste propio.', 'taxocommerce-logistica' ),
                            __( 'woocommerce_cart_shipping_packages: completa el destino utilizado por las tarifas.', 'taxocommerce-logistica' ),
                            __( 'woocommerce_package_rates: sustituye las tarifas por la primera regla válida cuando el gestor está activo.', 'taxocommerce-logistica' ),
                            __( 'seo_transporte_shipping_rate: filtro de extensión sobre la tarifa calculada.', 'taxocommerce-logistica' ),
                        ) ),
                    ),
                ),
                'settings' => array(
                    'title' => __( '3. Persistencia y configuración', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'La configuración se guarda en la opción histórica seo_transporte_costes_settings. Se reutiliza sin migrar a otra tabla, preservando las reglas existentes. No se crea una tabla de transporte adicional.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'El esquema incluye enabled, only_spain, default_region, rate_label, shipping_taxable, require_known_metrics y rules. Cada regla conserva id, enabled, priority, name, zone, límites de subtotal, peso, dimensiones y volumen, fixed_cost, per_kg_cost, per_unit_cost y free_from.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'sanitize_settings_payload normaliza los campos, limita las zonas y ordena las reglas por prioridad. Si se intenta activar el gestor sin reglas activas, lo desactiva para evitar un checkout sin tarifas.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'regions' => array(
                    'title' => __( '4. Clasificación de destinos', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'resolve_destination completa el destino a partir del paquete de WooCommerce, la información recibida del checkout y los datos del cliente. classify_destination consulta SEO_Facturas_Tax::classify_location cuando está disponible o utiliza país, provincia y código postal.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Zonas admitidas: spain, peninsula, baleares, canarias, ceuta, melilla, foreign y all. Cuando faltan provincia y código postal se aplica provisionalmente default_region. La opción only_spain bloquea las tarifas a destinos extranjeros.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'La clasificación puede depender del componente fiscal instalado. Validar los destinos efectivamente usados en el checkout; un autochequeo local no sustituye a la prueba con direcciones reales.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'metrics' => array(
                    'title' => __( '5. Métricas de paquetes y pedidos', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'package_metrics obtiene el subtotal de contents_cost, suma unidades y pesos, registra la mayor longitud, anchura y altura individuales y calcula la suma aproximada de volúmenes en dm³. Los datos WooCommerce se convierten a kg y cm.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'missing_weight y missing_dimensions indican datos ausentes. Con require_known_metrics activo no se permite utilizar una regla que requiera esos valores cuando faltan. No existe un optimizador de embalajes ni un cálculo de bultos físicos.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'La auditoría operativa consulta hasta 100 pedidos recientes en estados pendiente, en espera o procesando. Muestra unidades, peso, productos sin peso, método, coste e impuestos registrados; no modifica los pedidos.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'rules' => array(
                    'title' => __( '6. Evaluación y precio de la tarifa', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'h3', __( 'Selección', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'filter_package_rates lee la configuración en cada cálculo, identifica la región y las métricas, filtra reglas habilitadas y las ordena por prioridad numérica ascendente. La primera regla que cumpla zona, subtotal y límites de peso y dimensiones define la tarifa. Si no coincide ninguna, el método devuelve cero tarifas.', 'taxocommerce-logistica' ) ),
                        array( 'h3', __( 'Fórmula', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'calculate_rule_cost aplica coste fijo + (peso en kg × coste/kg) + (unidades × coste/unidad). Si subtotal es mayor o igual que free_from, el coste es cero. El resultado se redondea según los decimales configurados en WooCommerce y nunca puede ser negativo.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Se devuelve una tarifa WC_Shipping_Rate con ID seo_transporte:{id_regla}, nombre de la regla o etiqueta general, coste e impuestos. El método interno seo_transporte_bridge no añade una tarifa propia adicional.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'taxes' => array(
                    'title' => __( '7. Impuestos y checkout', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'rate_taxes delega el impuesto en WC_Tax::get_shipping_tax_rates y WC_Tax::calc_shipping_tax. Si shipping_taxable está desactivado, la tarifa es gratuita o no hay API fiscal disponible, no añade un impuesto calculado por el módulo.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Cuando el gestor está desactivado, filter_package_rates devuelve las tarifas originales de WooCommerce sin cambiarlas. Cuando está activo, puede devolver únicamente la tarifa propia o ninguna si el destino está bloqueado o no coincide una regla.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'transfer' => array(
                    'title' => __( '8. Copias de seguridad JSON', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'El exportador usa el contrato seo-transporte-costes, versión 1, e incluye la configuración completa. El importador valida nonce y permisos, subida legítima, extensión .json, límite de 2 MiB, formato y versión del esquema antes de actualizar la opción.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Importar no combina reglas: REEMPLAZA la configuración y después invalida la caché de transporte. Para restaurar datos, exportar primero un respaldo y probar el JSON en staging.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'security' => array(
                    'title' => __( '9. Permisos, validación y seguridad', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'Las pantallas y acciones requieren manage_woocommerce cuando WooCommerce está disponible o manage_options como alternativa. Los cambios y transferencias usan nonces de WordPress. Los datos numéricos, zonas y etiquetas se normalizan antes de persistirse.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'La pantalla User Guide es de solo lectura y no consulta pedidos, reglas ni datos de clientes. Su navegación se valida por una lista cerrada de apartados. Los informes Auto Test solo almacenan nombres y estados de pruebas, nunca direcciones de clientes.', 'taxocommerce-logistica' ) ),
                    ),
                ),
                'autotest' => array(
                    'title' => __( '10. Auto Test y pruebas de despliegue', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'TaxoCommerce_Logistica_Auto_Test reúne 35 comprobaciones en ocho grupos. Compara un diagnóstico completo ANTES y otro DESPUÉS en el mismo sitio y con el mismo usuario. Detecta errores nuevos, corregidos, persistentes, pérdida de cobertura y nuevas pruebas.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Usa datos sintéticos para validar regiones, pesos, volumen, condiciones y costes. No realiza pedidos, importaciones JSON, peticiones externas ni escrituras sobre las reglas. Las pruebas que requieren checkout o modificación real aparecen como no verificadas.', 'taxocommerce-logistica' ) ),
                        array( 'h3', __( 'Pruebas complementarias', 'taxocommerce-logistica' ) ),
                        array( 'ol', array(
                            __( 'Ejecutar las pruebas PHP y el control de WordPress.org en GitHub.', 'taxocommerce-logistica' ),
                            __( 'Comparar los dos informes Auto Test y confirmar que no aparecen regresiones.', 'taxocommerce-logistica' ),
                            __( 'Probar el checkout con Península, Baleares, Canarias y una dirección extranjera según las reglas existentes.', 'taxocommerce-logistica' ),
                            __( 'Verificar portes gratuitos, impuestos, productos sin peso y restauración JSON solo en staging.', 'taxocommerce-logistica' ),
                        ) ),
                    ),
                ),
                'troubleshooting' => array(
                    'title' => __( '11. Diagnóstico y mantenimiento', 'taxocommerce-logistica' ),
                    'blocks' => array(
                        array( 'p', __( 'Si no aparecen tarifas, comprobar enabled, reglas activas, país y región detectados, prioridades, límites de peso y dimensiones, only_spain y caché de WooCommerce. No forzar una tarifa que no coincida con ninguna regla.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'Si la auditoría muestra productos sin peso, corregir primero las fichas de producto y repetir el cálculo de prueba. Si cambia la fiscalidad, revisar la configuración WooCommerce/Facturación; Logistics no fija tipos impositivos por su cuenta.', 'taxocommerce-logistica' ) ),
                        array( 'p', __( 'La desactivación conserva las opciones guardadas. Antes de eliminar o sustituir el plugin, comprobar quién consume la opción histórica y exportar JSON. No borrar configuración compartida ni cambiar el checkout sin una migración verificada.', 'taxocommerce-logistica' ) ),
                    ),
                ),
            );
        }

        return array(
            'intro' => array(
                'title' => __( '1. ¿Para qué sirve Logistics?', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'p', __( 'TaxoCommerce Logistics permite configurar cuánto se cobra por enviar un pedido de WooCommerce. Puedes crear tarifas por destino, importe de compra, peso, medidas y número de unidades.', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'También permite consultar el peso y los costes de envío de los pedidos activos. No compra productos a proveedores ni gestiona aprovisionamiento: esa tarea corresponde a TaxoCommerce Suppliers.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'start' => array(
                'title' => __( '2. Primeros pasos', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'ol', array(
                        __( 'Accede a Logistics desde TaxoCommerce Core o desde su menú propio si Core no está instalado.', 'taxocommerce-logistica' ),
                        __( 'En Shipping Rules, comprueba la configuración general y las reglas existentes antes de tocar nada.', 'taxocommerce-logistica' ),
                        __( 'Exporta una copia JSON de la configuración actual antes de introducir cambios.', 'taxocommerce-logistica' ),
                        __( 'Prueba una regla en staging con productos y direcciones representativas.', 'taxocommerce-logistica' ),
                        __( 'Activa el gestor solo cuando hayas definido al menos una regla activa y probado el checkout.', 'taxocommerce-logistica' ),
                    ) ),
                ),
            ),
            'settings' => array(
                'title' => __( '3. Configuración general', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'ul', array(
                        __( 'Activar gestor propio: sustituye las tarifas de WooCommerce por la tarifa que coincida con una de tus reglas.', 'taxocommerce-logistica' ),
                        __( 'Solo España: no ofrecer tarifas a direcciones situadas fuera de España.', 'taxocommerce-logistica' ),
                        __( 'Transporte sujeto a impuestos: utiliza los impuestos de envío configurados en WooCommerce.', 'taxocommerce-logistica' ),
                        __( 'No usar reglas avanzadas si faltan peso o medidas: evita aplicar reglas que necesitan esos datos cuando las fichas de producto están incompletas.', 'taxocommerce-logistica' ),
                        __( 'Nombre visible al cliente: etiqueta general de la tarifa, si la regla seleccionada no tiene nombre.', 'taxocommerce-logistica' ),
                        __( 'Zona provisional: región que se utilizará hasta que el comprador indique provincia o código postal.', 'taxocommerce-logistica' ),
                    ) ),
                    array( 'p', __( 'Si intentas activar el gestor sin reglas activas, se desactiva para proteger el checkout.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'zones' => array(
                'title' => __( '4. Destinos de envío', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'p', __( 'Cada regla puede aplicarse a Península, Baleares, Canarias, Ceuta, Melilla, toda España, destinos extranjeros o cualquier destino. Logistics utiliza los datos del comprador para identificar la zona.', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Comprueba especialmente Baleares, Canarias y los destinos internacionales: una regla para Península no sirve automáticamente para todas las zonas.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'rules' => array(
                'title' => __( '5. Crear y ordenar reglas de transporte', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'ol', array(
                        __( 'En Shipping Rules, pulsa Añadir regla.', 'taxocommerce-logistica' ),
                        __( 'Activa la regla, asigna un nombre y selecciona la zona de envío.', 'taxocommerce-logistica' ),
                        __( 'Indica su prioridad: 10 se comprueba antes que 20. Se utiliza la PRIMERA regla activa que encaje.', 'taxocommerce-logistica' ),
                        __( 'Establece, si procede, subtotal mínimo/máximo, peso mínimo/máximo, medidas máximas y volumen.', 'taxocommerce-logistica' ),
                        __( 'Introduce coste fijo, coste por kg, coste por unidad o el umbral de portes gratuitos.', 'taxocommerce-logistica' ),
                        __( 'Pulsa Guardar transporte y comprueba el resultado en el checkout de staging.', 'taxocommerce-logistica' ),
                    ) ),
                    array( 'h3', __( 'Cuidado con las prioridades', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Una regla general de prioridad 10 podría aplicarse antes que otra más específica de prioridad 20. Coloca primero las excepciones que deban ganar.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'costs' => array(
                'title' => __( '6. Cómo se calcula el coste', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'p', __( 'La fórmula es: coste fijo + (peso total en kg × coste por kg) + (unidades × coste por unidad). Solo se aplica la primera regla válida. Si se alcanza el subtotal indicado en Gratis desde, el transporte cuesta cero.', 'taxocommerce-logistica' ) ),
                    array( 'h3', __( 'Ejemplo de cálculo', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'En una regla hipotética de 5 € fijos, 2 € por kg y 1 € por unidad, un pedido de 3 kg y 2 unidades costaría 13 € de transporte antes de los impuestos aplicables. Es un ejemplo, no la tarifa actual de tu tienda.', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Si ninguna regla coincide, no se ofrece una tarifa de este gestor. Por eso conviene definir cuidadosamente una regla general compatible con las zonas que atiendes.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'orders' => array(
                'title' => __( '7. Consultar el peso y coste de pedidos', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'p', __( 'En la parte superior de Shipping Rules aparece una auditoría de hasta 100 pedidos recientes pendientes, en espera o procesando. Muestra las unidades, peso total, artículos sin peso, método de envío, coste e impuestos registrados.', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Si un pedido contiene productos sin peso, corrige la información logística de los productos. La tabla es de consulta: no recalcula ni modifica pedidos históricos.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'json' => array(
                'title' => __( '8. Exportar y restaurar reglas JSON', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'h3', __( 'Exportar copia', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'En Importar / exportar reglas pulsa Exportar reglas (JSON). El archivo incluye opciones generales y todas las reglas de coste. Guárdalo antes de hacer cambios importantes.', 'taxocommerce-logistica' ) ),
                    array( 'h3', __( 'Importar copia', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Selecciona un JSON compatible de hasta 2 MiB y pulsa Importar reglas. ATENCIÓN: la importación reemplaza TODA la configuración actual; no añade ni fusiona reglas. Haz antes una copia de seguridad y comprueba el resultado en staging.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'autotest' => array(
                'title' => __( '9. Auto Test antes y después de actualizar', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'ol', array(
                        __( 'Antes de actualizar, abre Auto Test y pulsa Guardar estado ANTES.', 'taxocommerce-logistica' ),
                        __( 'Descarga el JSON de referencia y actualiza el plugin siguiendo el procedimiento autorizado.', 'taxocommerce-logistica' ),
                        __( 'Después, en el mismo sitio y con el mismo administrador, pulsa Comprobar DESPUÉS.', 'taxocommerce-logistica' ),
                        __( 'Revisa errores nuevos, resueltos y pruebas que han dejado de comprobarse; guarda el segundo JSON.', 'taxocommerce-logistica' ),
                    ) ),
                    array( 'p', __( 'Auto Test agrupa 35 comprobaciones, pero NO realiza pedidos ni cambia tarifas reales. Los casos que exigen pruebas de checkout o importación se muestran como no verificados.', 'taxocommerce-logistica' ) ),
                ),
            ),
            'help' => array(
                'title' => __( '10. Problemas frecuentes', 'taxocommerce-logistica' ),
                'blocks' => array(
                    array( 'h3', __( 'El cliente no ve una opción de transporte', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Comprueba que el gestor esté activo, que haya alguna regla habilitada para la zona, que los límites de peso e importe encajen y que la opción Solo España no esté excluyendo ese destino. Verifica también productos sin peso o dimensiones.', 'taxocommerce-logistica' ) ),
                    array( 'h3', __( 'El precio de envío no es el esperado', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Revisa el orden de prioridades y si existe una regla general anterior. Comprueba el subtotal después de descuentos y antes de impuestos, el peso, las unidades, el umbral Gratis desde y la configuración fiscal de WooCommerce.', 'taxocommerce-logistica' ) ),
                    array( 'h3', __( '¿Por qué no se actualiza un pedido anterior?', 'taxocommerce-logistica' ) ),
                    array( 'p', __( 'Las reglas se aplican al cálculo del carrito y checkout; el panel de pedidos muestra los importes ya guardados. Modificar una regla no reescribe los pedidos antiguos.', 'taxocommerce-logistica' ) ),
                ),
            ),
        );
    }

    private static function render_blocks( $blocks ) {
        foreach ( $blocks as $block ) {
            if ( 'h3' === $block[0] ) {
                echo '<h3>' . esc_html( $block[1] ) . '</h3>';
            } elseif ( 'p' === $block[0] ) {
                echo '<p>' . esc_html( $block[1] ) . '</p>';
            } elseif ( 'ol' === $block[0] || 'ul' === $block[0] ) {
                $tag = $block[0];
                echo '<' . $tag . '>';
                foreach ( $block[1] as $item ) { echo '<li>' . esc_html( $item ) . '</li>'; }
                echo '</' . $tag . '>';
            }
        }
    }

    public static function render() {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'No tienes permiso para ver el manual.', 'taxocommerce-logistica' ) );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET selects a read-only document.
        $requested = isset( $_GET['tc_logistica_manual'] ) && is_string( $_GET['tc_logistica_manual'] )
            ? sanitize_key( wp_unslash( $_GET['tc_logistica_manual'] ) ) : 'user';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $technical = 'technical' === $requested;
        $chapters = self::chapters( $technical );
        $prefix = $technical ? 'tc-log-tech-' : 'tc-log-user-';
        ?>
        <section class="tc-log-guide" aria-labelledby="tc-log-guide-title">
            <header class="tc-log-guide__header">
                <h2 id="tc-log-guide-title"><?php esc_html_e( 'User Guide', 'taxocommerce-logistica' ); ?></h2>
                <p><?php esc_html_e( 'Manual de usuario y manual técnico de TaxoCommerce Logistics, integrados en el propio plugin.', 'taxocommerce-logistica' ); ?></p>
            </header>
            <nav class="tc-log-guide__switch" aria-label="<?php esc_attr_e( 'Tipo de manual', 'taxocommerce-logistica' ); ?>">
                <a href="<?php echo esc_url( self::guide_url( 'user' ) ); ?>"
                    class="tc-log-guide__switch-link<?php echo $technical ? '' : ' is-active'; ?>"
                    <?php if ( ! $technical ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'User Manual', 'taxocommerce-logistica' ); ?>
                </a>
                <a href="<?php echo esc_url( self::guide_url( 'technical' ) ); ?>"
                    class="tc-log-guide__switch-link<?php echo $technical ? ' is-active' : ''; ?>"
                    <?php if ( $technical ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'Technical Manual', 'taxocommerce-logistica' ); ?>
                </a>
            </nav>
            <div class="tc-log-guide__layout">
                <nav class="tc-log-guide__index" aria-label="<?php esc_attr_e( 'Índice del manual', 'taxocommerce-logistica' ); ?>">
                    <h3><?php esc_html_e( 'Contenido', 'taxocommerce-logistica' ); ?></h3>
                    <ol>
                        <?php foreach ( $chapters as $id => $chapter ) : ?>
                            <li><a href="#<?php echo esc_attr( $prefix . $id ); ?>"><?php echo esc_html( $chapter['title'] ); ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <article class="tc-log-guide__article">
                    <?php foreach ( $chapters as $id => $chapter ) : ?>
                        <section id="<?php echo esc_attr( $prefix . $id ); ?>">
                            <h2><?php echo esc_html( $chapter['title'] ); ?></h2>
                            <?php self::render_blocks( $chapter['blocks'] ); ?>
                        </section>
                    <?php endforeach; ?>
                    <p class="tc-log-guide__links">
                        <a href="<?php echo esc_url( self::section_url( 'shipping' ) ); ?>"><?php esc_html_e( 'Abrir Shipping Rules', 'taxocommerce-logistica' ); ?></a>
                        <a href="<?php echo esc_url( self::section_url( 'auto-test' ) ); ?>"><?php esc_html_e( 'Abrir Auto Test', 'taxocommerce-logistica' ); ?></a>
                    </p>
                </article>
            </div>
        </section>
        <?php
    }
}
