<?php
defined('ABSPATH') || exit;

function tccampaigns_admin_menu(){
    $parent=(function_exists('taxocommerce_core')||defined('TAXOCOMMERCE_CORE_VERSION'))?'taxocommerce-core':'';
    if($parent){
        add_submenu_page($parent,__( 'TaxoCommerce Campaigns', 'taxocommerce-campaigns' ),__( 'Marketing campaigns', 'taxocommerce-campaigns' ),'manage_options','taxocommerce-campaigns','tccampaigns_admin_render');
    }else{
        add_menu_page(__( 'TaxoCommerce Campaigns', 'taxocommerce-campaigns' ),__( 'TaxoCommerce Campaigns', 'taxocommerce-campaigns' ),'manage_options','taxocommerce-campaigns','tccampaigns_admin_render','dashicons-megaphone',58);
    }
}
add_action('admin_menu','tccampaigns_admin_menu');

function tccampaigns_admin_tab(){
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
    $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'campaigns';
    return in_array($tab,array('campaigns','calendar','banners','autotest','manual'),true)?$tab:'campaigns';
}

function tccampaigns_admin_tab_url($tab,$args=array()){
    return add_query_arg(array_merge(array('page'=>'taxocommerce-campaigns','tab'=>sanitize_key($tab)),is_array($args)?$args:array()),admin_url('admin.php'));
}

function tccampaigns_handle_settings(){
    if(!current_user_can('manage_options')) wp_die(esc_html__( 'No tienes permisos.', 'taxocommerce-campaigns' ));
    check_admin_referer('tccampaigns_settings');
    $time=isset($_POST['publication_time'])?sanitize_text_field(wp_unslash($_POST['publication_time'])):'18:00';
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) $time='18:00';
    update_option('tccampaigns_social_publication_time',$time,false);
    $templates=array();
    if (isset($_POST['social_templates']) && is_array($_POST['social_templates'])) {
        $submitted_templates = map_deep(wp_unslash($_POST['social_templates']), 'sanitize_textarea_field');
        foreach ($submitted_templates as $provider => $template) {
            if (!is_string($template)) {
                continue;
            }
            $provider = sanitize_key((string) $provider);
            if ($provider) {
                $templates[$provider] = $template;
            }
        }
    }
    update_option('tccampaigns_social_templates_v1',$templates,false);
    wp_safe_redirect(tccampaigns_admin_tab_url('banners',array('saved'=>1)));
    exit;
}
add_action('admin_post_tccampaigns_settings','tccampaigns_handle_settings');

function tccampaigns_render_banners(){
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Informational redirect notice; settings POST requires a valid nonce.
    if(isset($_GET['saved'])) echo '<div class="notice notice-success inline">' . '<p>' . esc_html__( 'Configuración guardada.', 'taxocommerce-campaigns' ) . '</p>' . '</div>';
    $providers=function_exists('tcsocial_network_get_providers')?tcsocial_network_get_providers():array(
        'facebook'=>array('label'=>'Facebook'),'instagram'=>array('label'=>'Instagram'),'linkedin'=>array('label'=>'LinkedIn'),'pinterest'=>array('label'=>'Pinterest'),'x'=>array('label'=>'X')
    );
    echo '<section class="tcc-card">' . '<h2>' . esc_html__( 'Banners públicos', 'taxocommerce-campaigns' ) . '</h2>';
    echo '<p>' . wp_kses_post( __( 'Las campañas activas pueden mostrarse mediante la plantilla <code>template-campaign.php</code> de TaxoCommerce Templates. No se crean entradas.', 'taxocommerce-campaigns' ) ) . '</p>';
    echo '<p>' . wp_kses_post( __( 'Puedes insertar una campaña con <code>[taxocommerce_campaign]</code>, seleccionar otra con <code>[taxocommerce_campaign key="mi-campana"]</code> o usar <code>[oferta]</code>. El shortcode usa la campaña activa y sus productos; no crea códigos dinámicos como <code>[oferta01]</code>.', 'taxocommerce-campaigns' ) ) . '</p>';
    echo '</section>';

    echo '<section class="tcc-card">' . '<h2>' . esc_html__( 'Publicación de ofertas en redes', 'taxocommerce-campaigns' ) . '</h2>';
    echo '<p>' . esc_html__( 'Estas plantillas pertenecen a Campañas. TaxoCommerce Social aporta las conexiones y los publicadores, pero su calendario editorial permanece separado.', 'taxocommerce-campaigns' ) . '</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="tccampaigns_settings">';
    wp_nonce_field('tccampaigns_settings');
    echo '<p><label><strong>' . esc_html__( 'Hora de publicación comercial', 'taxocommerce-campaigns' ) . '</strong><br><input type="time" name="publication_time" value="'.esc_attr(tccampaigns_social_fixed_publication_time()).'"></label></p>';
    $saved=get_option('tccampaigns_social_templates_v1',array());
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">';
    foreach($providers as $key=>$provider){
        $label=(string)($provider['label']??ucfirst($key));
        $value=is_array($saved)&&isset($saved[$key])?(string)$saved[$key]:tccampaigns_social_template($key);
        echo '<div><label><strong>'.esc_html($label).'</strong></label><textarea style="width:100%;min-height:140px" name="social_templates['.esc_attr($key).']">'.esc_textarea($value).'</textarea></div>';
    }
    echo '</div>' . '<p class="description">' . esc_html__( 'Variables: {producto}, {campana}, {precio_regular}, {precio_oferta}, {descuento_pct}, {fecha_inicio}, {fecha_fin}, {url}, {categorias}, {imagen}.', 'taxocommerce-campaigns' ) . '</p>';
    submit_button( __( 'Guardar publicación de ofertas', 'taxocommerce-campaigns' ) );
    echo '</form></section>';
}

function tccampaigns_admin_render(){
    if(!current_user_can('manage_options')) return;
    $tab=tccampaigns_admin_tab();
    // Documentation and diagnostics must not trigger schema or template writes.
    if(!in_array($tab,array('manual','autotest'),true)){
        tccampaigns_maybe_install_tables();
        tccampaigns_register_public_template();
    }
    tccampaigns_calendar_styles();
    echo '<div class="wrap">' . '<h1>' . esc_html__( 'TaxoCommerce Campaigns', 'taxocommerce-campaigns' ) . '</h1>' . '<p class="description">' . esc_html__( 'Campañas de ofertas, precios promocionales, banners y agenda comercial autónoma.', 'taxocommerce-campaigns' ) . '</p>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
    foreach(array('campaigns'=>__( 'Marketing campaigns', 'taxocommerce-campaigns' ),'calendar'=>__( 'Calendario', 'taxocommerce-campaigns' ),'banners'=>__( 'Banners / Publicación', 'taxocommerce-campaigns' ),'autotest'=>__( 'Autocheck', 'taxocommerce-campaigns' ),'manual'=>__( 'Manual', 'taxocommerce-campaigns' )) as $key=>$label){
        echo '<a class="nav-tab '.($tab===$key?'nav-tab-active':'').'" href="'.esc_url(tccampaigns_admin_tab_url($key)).'">'.esc_html($label).'</a>';
    }
    echo '</nav>';
    if('campaigns'===$tab){
        tccampaigns_render_tab();
    }elseif('calendar'===$tab){
        if(function_exists('tcsocial_network_get_providers')){
            if(function_exists('tcsocial_network_render_styles')) tcsocial_network_render_styles();
            tccampaigns_social_render_scheduler_panel();
        }else{
            echo '<div class="notice notice-info inline">' . '<p>' . esc_html__( 'TaxoCommerce Social no está activo. El calendario comercial funciona igualmente; la programación de ofertas en redes se habilitará cuando Social esté disponible.', 'taxocommerce-campaigns' ) . '</p>' . '</div>';
        }
        tccampaigns_calendar_render();
    }elseif('banners'===$tab){
        tccampaigns_render_banners();
    }elseif('autotest'===$tab){
        tccampaigns_render_autotest();
    }else{
        tccampaigns_render_manual();
    }
    echo '</div>';
}
