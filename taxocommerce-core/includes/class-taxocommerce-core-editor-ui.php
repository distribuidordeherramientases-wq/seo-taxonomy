<?php
/**
 * Responsive WordPress/WooCommerce content editor for TaxoCommerce Core.
 *
 * @package TaxoCommerceCore
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Editor_UI {
    public static function register_hooks() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_taxocommerce_core_editor_terms', array( __CLASS__, 'ajax_terms' ) );
    }

    public static function enqueue() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin router.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'taxocommerce-core-content' !== $page ) {
            return;
        }
        wp_enqueue_style(
            'taxocommerce-core-editor',
            plugin_dir_url( TAXOCOMMERCE_CORE_FILE ) . 'assets/core-editor.css',
            array(),
            TAXOCOMMERCE_CORE_VERSION
        );
        wp_enqueue_script(
            'taxocommerce-core-editor',
            plugin_dir_url( TAXOCOMMERCE_CORE_FILE ) . 'assets/core-editor.js',
            array(),
            TAXOCOMMERCE_CORE_VERSION,
            true
        );
        wp_localize_script(
            'taxocommerce-core-editor',
            'tcCoreEditorSettings',
            array(
                'url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'taxocommerce_core_editor_terms' ),
                'strings' => array(
                    'none' => __( 'No hay términos coincidentes.', 'taxocommerce-core' ),
                    'loading' => __( 'Buscando…', 'taxocommerce-core' ),
                    'error' => __( 'No se han podido cargar los términos.', 'taxocommerce-core' ),
                    'choose' => __( 'Selecciona primero un atributo global.', 'taxocommerce-core' ),
                    'remove' => /* translators: %s: name of the attribute term being removed. */ __( 'Quitar %s', 'taxocommerce-core' ),
                    'mediaTitle' => __( 'Elegir imagen', 'taxocommerce-core' ),
                    'mediaButton' => __( 'Utilizar imagen', 'taxocommerce-core' ),
                    'imageEmpty' => __( 'Sin imagen destacada', 'taxocommerce-core' ),
                ),
            )
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route.
        if ( isset( $_GET['tc_action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['tc_action'] ) ) ) {
            wp_enqueue_editor();
            wp_enqueue_media();
        }
    }

    public static function ajax_terms() {
        check_ajax_referer( 'taxocommerce_core_editor_terms', 'nonce' );
        if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Acceso no autorizado.', 'taxocommerce-core' ) ), 403 );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $needle = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        if ( 'vocab' === $mode ) {
            wp_send_json_success( TaxoCommerce_Core_Vocabulary::search( $group, $needle ) );
        }
        if ( 'taxonomy' !== $mode || ! self::allowed_taxonomy( $group ) ) {
            wp_send_json_error( array( 'message' => __( 'Taxonomía no admitida.', 'taxocommerce-core' ) ), 400 );
        }
        $terms = get_terms(
            array(
                'taxonomy'   => $group,
                'hide_empty' => false,
                'search'     => $needle,
                'number'     => 25,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( array( 'message' => __( 'No se pudieron consultar las etiquetas.', 'taxocommerce-core' ) ), 500 );
        }
        $result = array();
        foreach ( (array) $terms as $term ) {
            $result[] = array( 'id' => (int) $term->term_id, 'label' => (string) $term->name );
        }
        wp_send_json_success( $result );
    }

    private static function allowed_taxonomy( $taxonomy ) {
        return taxonomy_exists( $taxonomy )
            && ( in_array( $taxonomy, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true )
                || ( 0 === strpos( $taxonomy, 'pa_' ) && function_exists( 'wc_attribute_taxonomy_id_by_name' )
                    && wc_attribute_taxonomy_id_by_name( $taxonomy ) > 0 ) );
    }

    private static function toolbar( $type, $id, $title, $score, $status, $permalink = '' ) {
        $back = add_query_arg(
            array( 'page' => 'taxocommerce-core-content', 'tc_type' => $type ),
            admin_url( 'admin.php' )
        );
        $native = 'categories' === $type ? get_edit_term_link( $id, 'product_cat' ) : get_edit_post_link( $id, '' );
        if ( is_wp_error( $native ) ) {
            $native = '';
        }
        if ( is_wp_error( $permalink ) ) {
            $permalink = '';
        }
        echo '<div class="tc-editor-shell">';
        echo '<div class="tc-editor-top"><a class="tc-back-link" href="' . esc_url( $back ) . '">← Volver a contenidos</a><div class="tc-editor-top-actions">';
        if ( $permalink ) {
            echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $permalink ) . '">' . esc_html__( 'Ver en la tienda ↗', 'taxocommerce-core' ) . '</a>';
        }
        if ( $native ) {
            echo '<a class="button" href="' . esc_url( $native ) . '">' . esc_html__( 'Editor WordPress / WooCommerce ↗', 'taxocommerce-core' ) . '</a>';
        }
        echo '</div></div>';
        $types = array( 'products' => 'PRODUCTO', 'categories' => 'CATEGORÍA', 'posts' => 'POST', 'pages' => 'PÁGINA' );
        $status_obj = get_post_status_object( $status );
        $status_name = $status_obj ? $status_obj->label : ucfirst( $status );
        echo '<header class="tc-editor-hero"><div><p class="tc-eyebrow">' . esc_html( $types[ $type ] ?? '' ) . ' · ID ' . esc_html( (string) $id ) . '</p>';
        echo '<h2>' . esc_html( wp_trim_words( $title, 14 ) ) . '</h2>';
        echo '<p>Edición centralizada: los cambios se guardan en las tablas nativas de WordPress y WooCommerce.</p></div>';
        echo '<div class="tc-hero-metrics"><span class="tc-status-indicator">' . esc_html( $status_name ) . '</span><span class="tc-score"><strong>' . esc_html( (string) $score ) . '%</strong> Completitud</span></div></header>';
        // Read-only help outside the form; never alters the edited object.
        TaxoCommerce_Core_Editorial_Guide::render( $type, 'editor' );
    }

    private static function open_form( $type, $id ) {
        echo '<form method="post" class="tc-editor-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_core_save_content">';
        echo '<input type="hidden" name="content_type" value="' . esc_attr( $type ) . '">';
        echo '<input type="hidden" name="item_id" value="' . esc_attr( (string) $id ) . '">';
        wp_nonce_field( 'taxocommerce_core_save_content', 'taxocommerce_core_nonce' );
        echo '<div class="tc-editor-grid"><div class="tc-editor-primary">';
    }

    private static function side() {
        echo '</div><aside class="tc-editor-aside">';
    }

    private static function close_form( $type ) {
        echo '</aside></div><footer class="tc-editor-savebar"><div><strong>¿Todo correcto?</strong><span>' . esc_html__( 'Los cambios se aplican al contenido original, sin crear copias.', 'taxocommerce-core' ) . '</span></div>';
        echo '<button type="submit" class="button button-primary button-hero">' . esc_html__( 'Guardar cambios', 'taxocommerce-core' ) . '</button></footer></form></div>';
    }

    private static function rich_editor( $value, $label, $rows = 14 ) {
        echo '<section class="tc-editor-card"><div class="tc-card-heading"><div><span class="tc-eyebrow">' . esc_html__( 'EDITOR VISUAL', 'taxocommerce-core' ) . '</span><h2>' . esc_html( $label ) . '</h2>';
        echo '<p>Utiliza negritas, listas, enlaces y medios para estructurar el contenido.</p></div></div>';
        wp_editor(
            $value,
            'tc_core_content',
            array(
                'textarea_name' => 'content',
                'textarea_rows' => absint( $rows ),
                'teeny'         => false,
                'media_buttons' => true,
                'quicktags'     => true,
                'editor_height' => 360,
            )
        );
        echo '</section>';
    }

    private static function input( $name, $label, $value, $required = false, $hint = '', $type = 'text' ) {
        $id = 'tc-field-' . sanitize_html_class( $name );
        echo '<div class="tc-field"><label class="tc-field-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        echo '<input id="' . esc_attr( $id ) . '" class="tc-input" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( $required ? ' required' : '' ) . '>';
        if ( $hint ) {
            echo '<p class="tc-field-help">' . esc_html( $hint ) . '</p>';
        }
        echo '</div>';
    }

    private static function textarea( $name, $label, $value, $rows = 4, $hint = '' ) {
        echo '<div class="tc-field"><label class="tc-field-label" for="tc-field-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
        echo '<textarea class="tc-input" id="tc-field-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="' . absint( $rows ) . '">' . esc_textarea( $value ) . '</textarea>';
        if ( $hint ) {
            echo '<p class="tc-field-help">' . esc_html( $hint ) . '</p>';
        }
        echo '</div>';
    }

    private static function status( $current ) {
        $statuses = array(
            'publish' => 'Publicado',
            'draft'   => 'Borrador',
            'pending' => 'Pendiente de revisión',
            'private' => 'Privado',
            'trash'   => 'Papelera',
        );
        echo '<section class="tc-editor-card tc-card-publish"><div class="tc-card-heading"><div><span class="tc-eyebrow">CONTROL EDITORIAL</span><h2>Publicación</h2></div></div>';
        if ( ! isset( $statuses[ $current ] ) && $current ) {
            $status_obj = get_post_status_object( $current );
            $statuses[ $current ] = $status_obj ? $status_obj->label : ucfirst( $current );
        }
        echo '<label class="tc-field-label" for="tc-edit-status">Estado del contenido</label><select class="tc-input" id="tc-edit-status" name="status">';
        foreach ( $statuses as $status => $label ) {
            echo '<option value="' . esc_attr( $status ) . '" ' . selected( $current, $status, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><p class="tc-field-help">La publicación y la papelera se aplican solo al guardar. Los estados existentes permanecen sin cambios si no eliges otro.</p></section>';
    }

    private static function image( $image_id, $label = 'Imagen destacada' ) {
        $image_id = absint( $image_id );
        echo '<section class="tc-editor-card tc-card-image"><div class="tc-card-heading"><h2>' . esc_html( $label ) . '</h2></div>';
        echo '<input type="hidden" name="tc_image_present" value="1">';
        echo '<input type="hidden" name="image_id" value="' . esc_attr( (string) $image_id ) . '" data-tc-image-id>';
        echo '<div class="tc-image-preview" data-tc-image-preview>';
        if ( $image_id ) {
            echo wp_kses_post( wp_get_attachment_image( $image_id, 'medium' ) );
        } else {
            echo '<div class="tc-image-empty"><span class="dashicons dashicons-format-image"></span><small>Sin imagen destacada</small></div>';
        }
        echo '</div><div class="tc-image-actions"><button type="button" class="button" data-tc-media-select>Elegir imagen</button> ';
        echo '<button type="button" class="button button-link-delete" data-tc-media-remove>Quitar</button></div></section>';
    }

    private static function terms( $taxonomy, $item_id, $label, $single = false, $selected_ids = null ) {
        if ( ! self::allowed_taxonomy( $taxonomy ) ) {
            return;
        }
        if ( null === $selected_ids ) {
            $ids = wp_get_object_terms( $item_id, $taxonomy, array( 'fields' => 'ids' ) );
            $selected_ids = is_wp_error( $ids ) ? array() : $ids;
        }
        $selected = array();
        foreach ( array_unique( array_filter( array_map( 'absint', (array) $selected_ids ) ) ) as $id ) {
            $term = get_term( $id, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                $selected[] = array( 'id' => $id, 'label' => $term->name );
            }
        }
        $field = $single ? 'parent' : 'tc_tax[' . $taxonomy . '][]';
        echo '<section class="tc-editor-card tc-taxonomy-card"><div class="tc-card-heading"><h2>' . esc_html( $label ) . '</h2></div>';
        TaxoCommerce_Core_Vocabulary::render_picker( $field, 'taxonomy', $taxonomy, $selected, 'Buscar y seleccionar…', $single );
        echo '<p class="tc-field-help">Busca por nombre y selecciona los términos. Los cambios se aplican al guardar.</p></section>';
    }

    private static function content_card( $name, $slug, $is_category = false, $short = '' ) {
        echo '<section class="tc-editor-card"><div class="tc-card-heading"><div><span class="tc-eyebrow">IDENTIDAD</span><h2>Información principal</h2></div></div>';
        self::input( $is_category ? 'name' : 'title', $is_category ? 'Nombre' : 'Título', $name, true );
        self::input( 'slug', 'Slug / URL amigable', $slug, false, 'Modifícalo únicamente si también tienes prevista la redirección SEO correspondiente.' );
        if ( '' !== $short ) {
            self::textarea( 'excerpt', $is_category ? 'Resumen' : 'Extracto / descripción corta', $short, 5 );
        }
        echo '</section>';
    }

    public static function product( $product, $score ) {
        $id = $product->get_id();
        self::toolbar( 'products', $id, $product->get_name(), $score, $product->get_status(), get_permalink( $id ) );
        self::open_form( 'products', $id );
        self::content_card( $product->get_name( 'edit' ), $product->get_slug( 'edit' ), false );
        echo '<section class="tc-editor-card"><div class="tc-card-heading"><div><span class="tc-eyebrow">DESCRIPCIÓN BREVE</span><h2>Resumen comercial</h2></div></div>';
        self::textarea( 'excerpt', 'Descripción corta', $product->get_short_description( 'edit' ), 6 );
        echo '</section>';
        self::rich_editor( $product->get_description( 'edit' ), 'Descripción del producto' );
        self::attributes( $product );
        TaxoCommerce_Core_Vocabulary::render( 'products', $id );
        self::side();
        self::status( $product->get_status( 'edit' ) );
        TaxoCommerce_Core_Country::field( TaxoCommerce_Core_Country::post( $id ) );
        echo '<section class="tc-editor-card"><div class="tc-card-heading"><h2>Datos comerciales</h2></div>';
        self::input( 'sku', 'SKU', $product->get_sku( 'edit' ) );
        if ( $product->is_type( 'simple' ) || $product->is_type( 'external' ) ) {
            echo '<div class="tc-field-pair">';
            self::input( 'regular_price', 'Precio normal', $product->get_regular_price( 'edit' ) );
            self::input( 'sale_price', 'Precio de oferta', $product->get_sale_price( 'edit' ) );
            echo '</div>';
        } else {
            echo '<div class="tc-editor-note">Precio gestionado por el tipo de producto (' . esc_html( $product->get_type() ) . '). Edítalo desde el editor nativo de WooCommerce.</div>';
        }
        if ( $product->is_type( 'external' ) ) {
            self::input( 'product_url', 'URL de producto externo / afiliación', $product->get_product_url( 'edit' ), false, 'Conserva el enlace afiliado del proveedor.', 'url' );
            self::input( 'button_text', 'Texto del botón externo', $product->get_button_text( 'edit' ) );
        }
        if ( method_exists( $product, 'get_stock_status' ) ) {
            echo '<div class="tc-field"><label class="tc-field-label" for="tc-stock-status">Disponibilidad</label><select class="tc-input" name="stock_status" id="tc-stock-status">';
            foreach ( array( 'instock' => 'En stock', 'outofstock' => 'Agotado', 'onbackorder' => 'Bajo pedido' ) as $key => $text ) {
                echo '<option value="' . esc_attr( $key ) . '" ' . selected( $product->get_stock_status( 'edit' ), $key, false ) . '>' . esc_html( $text ) . '</option>';
            }
            echo '</select></div>';
        }
        echo '</section>';
        echo '<input type="hidden" name="tc_tax_present" value="1">';
        self::terms( 'product_cat', $id, 'Categorías de producto' );
        self::terms( 'product_tag', $id, 'Etiquetas WooCommerce' );
        self::image( $product->get_image_id(), 'Imagen del producto' );
        self::close_form( 'products' );
    }

    public static function post( $post, $score, $content_type ) {
        $id = $post->ID;
        self::toolbar( $content_type, $id, $post->post_title, $score, $post->post_status, get_permalink( $id ) );
        self::open_form( $content_type, $id );
        self::content_card( $post->post_title, $post->post_name, false );
        self::rich_editor( $post->post_content, 'Contenido principal', 18 );
        echo '<section class="tc-editor-card"><div class="tc-card-heading"><h2>Extracto</h2></div>';
        self::textarea( 'excerpt', 'Resumen SEO / editorial', $post->post_excerpt, 5 );
        echo '</section>';
        TaxoCommerce_Core_Vocabulary::render( $content_type, $id );
        self::side();
        self::status( $post->post_status );
        TaxoCommerce_Core_Country::field( TaxoCommerce_Core_Country::post( $id ) );
        if ( 'posts' === $content_type ) {
            echo '<input type="hidden" name="tc_tax_present" value="1">';
            self::terms( 'category', $id, 'Categorías WordPress' );
            self::terms( 'post_tag', $id, 'Etiquetas WordPress' );
        }
        self::image( get_post_thumbnail_id( $id ) );
        self::close_form( $content_type );
    }

    public static function category( $term, $score ) {
        $id = $term->term_id;
        self::toolbar( 'categories', $id, $term->name, $score, 'publish', get_term_link( $term, 'product_cat' ) );
        self::open_form( 'categories', $id );
        self::content_card( $term->name, $term->slug, true );
        self::rich_editor( $term->description, 'Descripción de categoría', 13 );
        TaxoCommerce_Core_Vocabulary::render( 'categories', $id );
        self::side();
        TaxoCommerce_Core_Country::field( TaxoCommerce_Core_Country::term( $id ) );
        echo '<section class="tc-editor-card"><div class="tc-card-heading"><h2>Organización</h2></div>';
        echo '<p class="tc-field-help">La categoría superior define la posición en la jerarquía WooCommerce.</p>';
        TaxoCommerce_Core_Vocabulary::render_picker(
            'parent',
            'taxonomy',
            'product_cat',
            $term->parent ? self::terms_for_ids( 'product_cat', array( $term->parent ) ) : array(),
            'Buscar categoría superior…',
            true
        );
        echo '</section>';
        self::image( get_term_meta( $id, 'thumbnail_id', true ), 'Imagen de categoría' );
        self::close_form( 'categories' );
    }

    private static function terms_for_ids( $taxonomy, $ids ) {
        $out = array();
        foreach ( $ids as $id ) {
            $term = get_term( $id, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                $out[] = array( 'id' => (int) $id, 'label' => $term->name );
            }
        }
        return $out;
    }

    /**
     * Editable WooCommerce attributes. Variation-defining attributes are
     * visible but read-only and preserved on save.
     */
    private static function attributes( $product ) {
        echo '<section class="tc-editor-card tc-attributes-card"><div class="tc-card-heading"><div><span class="tc-eyebrow">WOOCOMMERCE</span><h2>Atributos del producto</h2>';
        echo '<p>Atributos globales compartidos y atributos personalizados del artículo.</p></div></div>';
        if ( ! class_exists( 'WC_Product_Attribute' ) ) {
            echo '<p class="tc-editor-note">WooCommerce no ofrece la API de atributos en esta instalación.</p></section>';
            return;
        }
        $type = $product->get_type();
        if ( 'variation' === $type ) {
            echo '<div class="tc-editor-note">Los atributos de esta variación se editan desde su producto variable principal para preservar las combinaciones.</div></section>';
            return;
        }
        echo '<input type="hidden" name="tc_attrs_present" value="1">';
        echo '<div class="tc-attribute-list" data-tc-attribute-list>';
        $count = 0;
        foreach ( $product->get_attributes() as $attribute ) {
            if ( ! $attribute instanceof WC_Product_Attribute ) {
                continue;
            }
            self::attribute_row( $attribute, $count++ );
        }
        echo '</div><div class="tc-attribute-actions">';
        echo '<button class="button" type="button" data-tc-add-attribute="global">+ Atributo global</button>';
        echo '<button class="button" type="button" data-tc-add-attribute="custom">+ Atributo personalizado</button></div>';
        echo '<p class="tc-field-help">Los atributos usados para variaciones se muestran bloqueados. No se borran ni se alteran desde este editor.</p>';
        self::attribute_templates();
        echo '</section>';
    }

    private static function attribute_row( $attribute, $index ) {
        $name = $attribute->get_name();
        $global = $attribute->is_taxonomy();
        $variation = (bool) $attribute->get_variation();
        echo '<article class="tc-attribute-row" data-tc-attribute-row>';
        echo '<div class="tc-attribute-heading"><strong>' . esc_html( $global ? wc_attribute_label( $name ) : $name ) . '</strong>';
        echo '<span class="tc-mini-label">' . ( $global ? 'GLOBAL' : 'PERSONALIZADO' ) . '</span>';
        if ( $variation ) {
            echo '<span class="tc-mini-label tc-mini-label--warn">USADO EN VARIACIONES</span>';
        } else {
            echo '<button type="button" class="button-link-delete" data-tc-remove-attribute>Quitar</button>';
        }
        echo '</div>';
        if ( $variation ) {
            echo '<p class="tc-field-help">Se conserva intacto para no afectar a las variaciones del producto.</p></article>';
            return;
        }
        echo '<input type="hidden" name="tc_attrs[' . esc_attr( (string) $index ) . '][kind]" value="' . ( $global ? 'global' : 'custom' ) . '">';
        if ( $global ) {
            echo '<input type="hidden" name="tc_attrs[' . esc_attr( (string) $index ) . '][taxonomy]" value="' . esc_attr( $name ) . '">';
            $selected = self::terms_for_ids( $name, $attribute->get_options() );
            TaxoCommerce_Core_Vocabulary::render_picker(
                'tc_attrs[' . $index . '][terms][]', 'taxonomy', $name, $selected, 'Buscar valores…'
            );
        } else {
            self::attribute_custom_fields( $index, $name, $attribute->get_options() );
        }
        echo '<label class="tc-toggle"><input type="checkbox" name="tc_attrs[' . esc_attr( (string) $index ) . '][visible]" value="1" ' . checked( $attribute->get_visible(), true, false ) . '> Visible en la ficha del producto</label>';
        echo '</article>';
    }

    private static function attribute_custom_fields( $index, $name, $options ) {
        echo '<div class="tc-field-pair">';
        echo '<div class="tc-field"><label class="tc-field-label">Nombre</label><input class="tc-input" name="tc_attrs[' . esc_attr( (string) $index ) . '][name]" value="' . esc_attr( $name ) . '" required></div>';
        echo '<div class="tc-field"><label class="tc-field-label">Valores (separados por |)</label><textarea rows="2" class="tc-input" name="tc_attrs[' . esc_attr( (string) $index ) . '][values]">' . esc_textarea( implode( ' | ', (array) $options ) ) . '</textarea></div>';
        echo '</div>';
    }

    private static function attribute_templates() {
        $global = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
        echo '<template id="tc-template-global-attribute"><article class="tc-attribute-row" data-tc-attribute-row>';
        echo '<div class="tc-attribute-heading"><strong>Nuevo atributo global</strong><button type="button" class="button-link-delete" data-tc-remove-attribute>Quitar</button></div>';
        echo '<input type="hidden" name="tc_attrs[__index__][kind]" value="global">';
        echo '<div class="tc-field"><label class="tc-field-label">Atributo</label><select class="tc-input" name="tc_attrs[__index__][taxonomy]" data-tc-taxonomy-select required>';
        echo '<option value="">Seleccionar atributo…</option>';
        foreach ( (array) $global as $attribute ) {
            echo '<option value="' . esc_attr( wc_attribute_taxonomy_name( $attribute->attribute_name ) ) . '">' . esc_html( $attribute->attribute_label ?: $attribute->attribute_name ) . '</option>';
        }
        echo '</select></div>';
        TaxoCommerce_Core_Vocabulary::render_picker(
            'tc_attrs[__index__][terms][]', 'taxonomy', '', array(), 'Buscar valores…'
        );
        echo '<label class="tc-toggle"><input type="checkbox" name="tc_attrs[__index__][visible]" value="1" checked> Visible en la ficha del producto</label>';
        echo '</article></template>';
        echo '<template id="tc-template-custom-attribute"><article class="tc-attribute-row" data-tc-attribute-row>';
        echo '<div class="tc-attribute-heading"><strong>Nuevo atributo personalizado</strong><button type="button" class="button-link-delete" data-tc-remove-attribute>Quitar</button></div>';
        echo '<input type="hidden" name="tc_attrs[__index__][kind]" value="custom">';
        self::attribute_custom_fields( '__index__', '', array() );
        echo '<label class="tc-toggle"><input type="checkbox" name="tc_attrs[__index__][visible]" value="1" checked> Visible en la ficha del producto</label>';
        echo '</article></template>';
    }

    public static function selected_terms( $taxonomy ) {
        if ( ! self::allowed_taxonomy( $taxonomy ) ) {
            return new WP_Error( 'tc_core_taxonomy', 'Taxonomía no admitida: ' . $taxonomy );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies mutation nonce.
        $values = isset( $_POST['tc_tax'][ $taxonomy ] ) && is_array( $_POST['tc_tax'][ $taxonomy ] )
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- selected_terms() is called after save_content() verifies its nonce.
            ? array_map( 'absint', wp_unslash( $_POST['tc_tax'][ $taxonomy ] ) ) : array();
        return self::validate_term_ids( $taxonomy, $values );
    }

    private static function validate_term_ids( $taxonomy, $raw ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
        if ( count( $ids ) > 250 ) {
            return new WP_Error( 'tc_core_terms_limit', 'Demasiados términos seleccionados.' );
        }
        foreach ( $ids as $id ) {
            $term = get_term( $id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy ) {
                return new WP_Error( 'tc_core_term_invalid', 'Se ha seleccionado un término inexistente en ' . $taxonomy . '.' );
            }
        }
        return $ids;
    }

    public static function save_native_terms( $type, $item_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core guard + nonce applied earlier.
        if ( empty( $_POST['tc_tax_present'] ) ) {
            return true;
        }
        $taxonomies = 'products' === $type ? array( 'product_tag' ) : ( 'posts' === $type ? array( 'category', 'post_tag' ) : array() );
        $validated = array();
        foreach ( $taxonomies as $taxonomy ) {
            $ids = self::selected_terms( $taxonomy );
            if ( is_wp_error( $ids ) ) {
                return $ids;
            }
            $validated[ $taxonomy ] = $ids;
        }
        foreach ( $validated as $taxonomy => $ids ) {
            $result = wp_set_object_terms( $item_id, $ids, $taxonomy, false );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
        return true;
    }

    public static function read_product_attributes( $product ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies mutation nonce.
        if ( empty( $_POST['tc_attrs_present'] ) || ! class_exists( 'WC_Product_Attribute' ) || $product->is_type( 'variation' ) ) {
            return null;
        }
        $result = array();
        foreach ( $product->get_attributes() as $attribute ) {
            if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
                $result[ $attribute->get_name() ] = $attribute;
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies mutation nonce.
        $raw = isset( $_POST['tc_attrs'] ) && is_array( $_POST['tc_attrs'] ) ? map_deep( wp_unslash( $_POST['tc_attrs'] ), 'sanitize_textarea_field' ) : array();
        if ( count( $raw ) > 50 ) {
            return new WP_Error( 'tc_core_attr_limit', 'Se han enviado demasiados atributos.' );
        }
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                return new WP_Error( 'tc_core_attr_invalid', 'Formato de atributo no válido.' );
            }
            $kind = isset( $row['kind'] ) ? sanitize_key( $row['kind'] ) : '';
            $global = 'global' === $kind;
            if ( ! $global && 'custom' !== $kind ) {
                return new WP_Error( 'tc_core_attr_kind', 'Tipo de atributo no admitido.' );
            }
            if ( $global ) {
                $name = isset( $row['taxonomy'] ) ? sanitize_key( $row['taxonomy'] ) : '';
                if ( ! self::allowed_taxonomy( $name ) || 0 !== strpos( $name, 'pa_' ) ) {
                    return new WP_Error( 'tc_core_attr_tax', 'Atributo global no válido.' );
                }
                $ids = self::validate_term_ids( $name, $row['terms'] ?? array() );
                if ( is_wp_error( $ids ) ) {
                    return $ids;
                }
                $id = wc_attribute_taxonomy_id_by_name( $name );
                if ( $id <= 0 ) {
                    return new WP_Error( 'tc_core_attr_id', 'No se ha encontrado el atributo global.' );
                }
                $options = $ids;
            } else {
                $name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
                if ( '' === $name || strlen( $name ) > 190 ) {
                    return new WP_Error( 'tc_core_attr_name', 'El atributo personalizado necesita un nombre válido.' );
                }
                $values = isset( $row['values'] ) ? sanitize_textarea_field( $row['values'] ) : '';
                $options = array_values( array_unique( array_filter( array_map( 'trim', explode( '|', $values ) ), 'strlen' ) ) );
                if ( count( $options ) > 250 ) {
                    return new WP_Error( 'tc_core_attr_values', 'Demasiados valores personalizados.' );
                }
                $id = 0;
            }
            if ( isset( $result[ $name ] ) ) {
                return new WP_Error( 'tc_core_attr_duplicate', 'Un atributo aparece más de una vez o pertenece a variaciones: ' . $name );
            }
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( $id );
            $attribute->set_name( $name );
            $attribute->set_options( $options );
            $attribute->set_visible( ! empty( $row['visible'] ) );
            $attribute->set_variation( false );
            $result[ $name ] = $attribute;
        }
        return $result;
    }

    public static function image_id() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies mutation nonce.
        if ( empty( $_POST['tc_image_present'] ) ) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies mutation nonce.
        $id = isset( $_POST['image_id'] ) ? absint( wp_unslash( $_POST['image_id'] ) ) : 0;
        if ( $id && ! wp_attachment_is_image( $id ) ) {
            return new WP_Error( 'tc_core_image_invalid', 'La imagen seleccionada no pertenece a la biblioteca de medios.' );
        }
        return $id;
    }
}
