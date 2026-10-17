<?php
/** Recover already-approved regional VEVOR offers as WooCommerce drafts. */
defined( 'ABSPATH' ) || exit;

function seo_vevor_recover_one( array $row ) {
    $country = strtoupper( (string) ( $row['pais_origen'] ?? '' ) );
    $roots = array( 'US' => 'vevor-us', 'DE' => 'vevor-de', 'NL' => 'vevor-nl' );
    $hosts = array( 'US' => 'vevor.com', 'DE' => 'vevor.de', 'NL' => 'vevor.nl' );
    $currencies = array( 'US' => 'USD', 'DE' => 'EUR', 'NL' => 'EUR' );
    if ( ! isset( $roots[ $country ] ) || 'VEVOR' !== strtoupper( (string) $row['proveedor'] )
        || 'aceptado' !== $row['estado_seleccion'] || ! class_exists( 'WC_Product_External' ) ) {
        return new WP_Error( 'invalid', 'Oferta no valida' );
    }
    $root = get_term_by( 'slug', $roots[ $country ], 'product_cat' );
    if ( ! $root instanceof WP_Term ) {
        return new WP_Error( 'no_root', 'Falta la categoria raiz ' . $country );
    }
    $url = esc_url_raw( (string) $row['url_origen'] );
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $query = array();
    wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
    $external = (string) $row['proveedor_id_externo'];
    if ( ! in_array( $host, array( $hosts[ $country ], 'www.' . $hosts[ $country ] ), true )
        || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
        || (string) ( $query['utm_campaign'] ?? '' ) !== '53435399'
        || (string) ( $query['utm_medium'] ?? '' ) !== 'affiliate'
        || strtoupper( (string) $row['moneda'] ) !== $currencies[ $country ]
        || ! preg_match( '/^[0-9]{12}$/', $external )
        || '' === trim( (string) $row['nombre'] )
        || empty( $row['imagenes'] ) ) {
        return new WP_Error( 'invalid_feed', 'Datos regionales incompletos o enlace afiliado invalido' );
    }
    $sku = 'vevor-' . strtolower( $country ) . '-p_' . $external;
    $lock = seo_proveedores_adquirir_bloqueo_alta( $row, 5 );
    if ( is_wp_error( $lock ) ) {
        return $lock;
    }
    try {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", absint( $row['id'] ) ), ARRAY_A );
        if ( ! $fresh || 'aceptado' !== $fresh['estado_seleccion'] || absint( $fresh['object_id'] ?? 0 ) ) {
            return new WP_Error( 'changed', 'Oferta ya enlazada o modificada' );
        }
        $matched = seo_vevor_region_existing_product( $fresh, $country, $sku );
        if ( is_wp_error( $matched ) ) {
            return $matched;
        }
        $created = false;
        if ( $matched ) {
            $id = absint( $matched );
        } else {
            $product = new WC_Product_External();
            $product->set_name( sanitize_text_field( $fresh['nombre'] ) );
            $product->set_status( 'draft' );
            $product->set_sku( $sku );
            $product->set_category_ids( array( $root->term_id ) );
            $product->set_product_url( $url );
            $product->set_button_text( array( 'US'=>'View at VEVOR', 'DE'=>'Bei VEVOR ansehen', 'NL'=>'Bekijk bij VEVOR' )[ $country ] );
            $product->set_description( wp_kses_post( (string) ( $fresh['descripcion'] ?? '' ) ) );
            $id = absint( $product->save() );
            if ( ! $id ) {
                return new WP_Error( 'save', 'No se pudo crear borrador' );
            }
            $created = true;
            update_post_meta( $id, '_seo_proveedor', 'VEVOR' );
            update_post_meta( $id, '_seo_proveedor_id_externo', $external );
            update_post_meta( $id, '_seo_proveedor_catalogo_id', absint( $row['id'] ) );
            update_post_meta( $id, '_seo_pais_origen', $country );
            update_post_meta( $id, '_seo_locale', array( 'US'=>'en-US', 'DE'=>'de-DE', 'NL'=>'nl-NL' )[ $country ] );
            update_post_meta( $id, '_seo_market_currency', $currencies[ $country ] );
            update_post_meta( $id, '_seo_market_price', sanitize_text_field( (string) $fresh['precio_con_iva'] ) );
            update_post_meta( $id, '_seo_vevor_source_category', sanitize_text_field( (string) $fresh['categoria_proveedor'] ) );
            update_post_meta( $id, '_seo_vevor_category_review', '1' );
            update_post_meta( $id, '_seo_proveedor_url_origen', $url );
        }
        $image_row = $fresh;
        $image_row['sku'] = $sku;
        if ( function_exists( 'seo_proveedores_guardar_imagenes_externas' ) ) {
            seo_proveedores_guardar_imagenes_externas( $image_row, $id );
        }
        if ( ! seo_supplier_sync_finalize_applied_row( $fresh, $id ) ) {
            return new WP_Error( 'link', 'Borrador creado pero enlace no registrado; reintentar' );
        }
        return array( 'created' => $created, 'product_id' => $id );
    } finally {
        seo_proveedores_liberar_bloqueo_alta( $lock );
    }
}

function seo_vevor_recover_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permisos insuficientes' ), 403 );
    }
    check_ajax_referer( 'seo_vevor_recover', 'nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $cursor = absint( $_POST['cursor'] ?? 0 );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE proveedor=%s AND pais_origen IN ('US','DE','NL')
         AND estado_seleccion=%s AND (object_id IS NULL OR object_id=0)
         AND id>%d ORDER BY id ASC LIMIT 10", 'VEVOR', 'aceptado', $cursor
    ), ARRAY_A );
    if ( null === $rows || $wpdb->last_error ) {
        wp_send_json_error( array( 'message' => 'Fallo al leer catalogo' ), 500 );
    }
    $created = 0; $linked = 0; $errors = array();
    foreach ( $rows as $row ) {
        $cursor = absint( $row['id'] );
        $result = seo_vevor_recover_one( $row );
        if ( is_wp_error( $result ) ) {
            $errors[] = '#' . $cursor . ': ' . $result->get_error_message();
        } elseif ( $result['created'] ) {
            ++$created;
        } else {
            ++$linked;
        }
    }
    $remaining = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE proveedor=%s AND pais_origen IN ('US','DE','NL')
         AND estado_seleccion=%s AND (object_id IS NULL OR object_id=0)", 'VEVOR', 'aceptado'
    ) );
    wp_send_json_success( array( 'cursor'=>$cursor, 'created'=>$created, 'linked'=>$linked,
        'remaining'=>$remaining, 'errors'=>$errors, 'done'=>count( $rows ) < 10 ) );
}
add_action( 'wp_ajax_seo_vevor_recover', 'seo_vevor_recover_ajax' );

function seo_vevor_recover_panel() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $nonce = wp_create_nonce( 'seo_vevor_recover' );
    $url = admin_url( 'admin-ajax.php' );
    ?>
    <div class="card" style="max-width:none;padding:16px">
      <h3>VEVOR: pasar ofertas internacionales aceptadas a borradores</h3>
      <p>Recupera US/DE/NL en tandas de diez. Las fichas se crean como productos externos en borrador, dentro de la categoria raiz del pais, para clasificarlas antes de publicar. No modifica productos espanoles.</p>
      <button class="button button-primary" id="seo-vevor-recover-start" type="button">Convertir aceptados</button>
      <button class="button" id="seo-vevor-recover-pause" type="button" disabled>Pausar</button>
      <p id="seo-vevor-recover-result" role="status"></p>
    </div>
    <script>
    (function(){
      const start=document.getElementById('seo-vevor-recover-start');
      const pause=document.getElementById('seo-vevor-recover-pause');
      const out=document.getElementById('seo-vevor-recover-result');
      if(!start||!pause||!out)return;
      let running=false,cursor=0,created=0,linked=0,errors=0;
      async function next(){
        if(!running)return;
        try{
          const form=new URLSearchParams({action:'seo_vevor_recover',nonce:<?php echo wp_json_encode( $nonce ); ?>,cursor:String(cursor)});
          const response=await fetch(<?php echo wp_json_encode( $url ); ?>,{method:'POST',credentials:'same-origin',body:form});
          const result=await response.json();
          if(!result.success)throw new Error(result.data?.message||'Error al convertir');
          const d=result.data;
          cursor=d.cursor; created+=d.created; linked+=d.linked; errors+=d.errors.length;
          out.textContent='Creados: '+created+'; vinculados: '+linked+'; errores: '+errors+'; pendientes: '+d.remaining;
          if(d.errors.length)out.textContent+='; ultimos errores: '+d.errors.slice(0,2).join(' | ');
          if(d.done){running=false;start.disabled=false;pause.disabled=true;return;}
          if(running)next();
        }catch(e){running=false;start.disabled=false;pause.disabled=true;out.textContent='Lote detenido: '+e.message;}
      }
      start.addEventListener('click',()=>{if(running)return;running=true;start.disabled=true;pause.disabled=false;next();});
      pause.addEventListener('click',()=>{running=false;start.disabled=false;pause.disabled=true;out.textContent+=' (pausado; para reanudar se inicia desde el principio, sin duplicar productos ya enlazados)';});
    })();
    </script>
    <?php
}
add_action( 'seo_vevor_region_batch_card', 'seo_vevor_recover_panel', 20 );
