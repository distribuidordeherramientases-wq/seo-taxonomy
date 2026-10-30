<?php
/**
 * Single responsive international cluster view (US / DE / NL / FR).
 * Read-only. The Spanish cluster variants remain unchanged.
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-cluster-market.php';
require_once __DIR__ . '/template-pilot-country-switcher.php';
require_once __DIR__ . '/template-pilot-market.php';

$market = isset( $dht_cluster_country ) ? $dht_cluster_country : dht_cluster_country_for_page( get_queried_object_id() );
if ( ! in_array( $market, array( 'US', 'DE', 'NL', 'FR' ), true ) ) {
    return;
}
$copy = array(
    'US' => array(
        'lang' => 'en-US', 'flag' => '🇺🇸', 'country' => 'United States',
        'brand' => 'Professional Tools & Equipment', 'tagline' => 'Independent tool guides and VEVOR product selections',
        'menu' => 'Categories', 'open' => 'Open navigation', 'area' => 'Topic area', 'hubs' => 'Explore specialist areas',
        'categories' => 'Explore categories', 'primary' => 'Main areas', 'related' => 'Related categories',
        'view_hub' => 'View area', 'view_cat' => 'View category', 'browse' => 'Browse our guides',
        'disclosure' => 'Independent buying guides. International product purchases and customer service are handled by VEVOR.',
        'privacy' => 'Privacy policy',
    ),
    'DE' => array(
        'lang' => 'de-DE', 'flag' => '🇩🇪', 'country' => 'Deutschland',
        'brand' => 'Professionelle Werkzeuge & Ausrüstung', 'tagline' => 'Unabhängige Ratgeber und VEVOR-Produktempfehlungen',
        'menu' => 'Kategorien', 'open' => 'Navigation öffnen', 'area' => 'Themenbereich', 'hubs' => 'Fachbereiche entdecken',
        'categories' => 'Kategorien ansehen', 'primary' => 'Hauptbereiche', 'related' => 'Verwandte Kategorien',
        'view_hub' => 'Bereich ansehen', 'view_cat' => 'Kategorie ansehen', 'browse' => 'Unsere Ratgeber entdecken',
        'disclosure' => 'Unabhängige Kaufratgeber. Internationale Käufe und der Kundenservice erfolgen direkt über VEVOR.',
        'privacy' => 'Datenschutz',
    ),
    'NL' => array(
        'lang' => 'nl-NL', 'flag' => '🇳🇱', 'country' => 'Nederland',
        'brand' => 'Professioneel gereedschap & uitrusting', 'tagline' => 'Onafhankelijke keuzehulpen en VEVOR-productselecties',
        'menu' => 'Categorieën', 'open' => 'Navigatie openen', 'area' => 'Themagebied', 'hubs' => 'Ontdek vakgebieden',
        'categories' => 'Bekijk categorieën', 'primary' => 'Hoofdonderwerpen', 'related' => 'Gerelateerde categorieën',
        'view_hub' => 'Bekijk onderwerp', 'view_cat' => 'Bekijk categorie', 'browse' => 'Ontdek onze gidsen',
        'disclosure' => 'Onafhankelijke koopgidsen. Internationale aankopen en klantenservice verlopen rechtstreeks via VEVOR.',
        'privacy' => 'Privacybeleid',
    ),
    'FR' => array(
        'lang' => 'fr-FR', 'flag' => '🇫🇷', 'country' => 'France',
        'brand' => 'Outils et équipements professionnels', 'tagline' => 'Guides indépendants et sélections de produits VEVOR',
        'menu' => 'Catégories', 'open' => 'Ouvrir la navigation', 'area' => 'Domaine thématique', 'hubs' => 'Explorer les domaines spécialisés',
        'categories' => 'Voir les catégories', 'primary' => 'Domaines principaux', 'related' => 'Catégories associées',
        'view_hub' => 'Voir le domaine', 'view_cat' => 'Voir la catégorie', 'browse' => 'Découvrir nos guides',
        'disclosure' => 'Guides d’achat indépendants. Les achats internationaux et le service client sont assurés directement par VEVOR.',
        'privacy' => 'Politique de confidentialité',
    ),
);
$t = $copy[ $market ];
$current_id = absint( get_queried_object_id() );
$cluster_title = (string) get_the_title( $current_id );
$permalink = (string) get_permalink( $current_id );
global $wpdb;
$table = $wpdb->prefix . 'seo_relations';

$raw_hubs = $wpdb->get_col( $wpdb->prepare(
    "SELECT target_id FROM {$table} WHERE source_type = 'cluster' AND source_id = %d
      AND target_type = 'hub_primary' AND relation_type IN ('cluster_to_primary','cluster_to_hub_primary')
      ORDER BY id ASC", $current_id
) );
$hub_ids = array_values( array_filter( dht_template_public_post_ids( $raw_hubs ), static function ( $id ) use ( $market ) {
    return dht_cluster_country_for_page( $id ) === $market;
} ) );
$raw_categories = $wpdb->get_col( $wpdb->prepare(
    "SELECT target_id FROM {$table} WHERE source_type = 'cluster' AND source_id = %d
      AND target_type = 'product_cat' AND relation_type = 'cluster_to_category'
      ORDER BY id ASC", $current_id
) );
$category_ids = array_values( array_filter(
    dht_template_public_term_ids( $raw_categories, 'product_cat' ),
    static function ( $term_id ) use ( $market ) {
        if ( class_exists( 'TaxoCommerce_Core_Country', false ) ) {
            return TaxoCommerce_Core_Country::term( $term_id ) === $market;
        }
        $explicit = strtoupper( trim( (string) get_term_meta( $term_id, '_seo_pais_origen', true ) ) );
        if ( $explicit !== '' ) {
            return $explicit === $market;
        }
        return dht_pilot_country_for_term( get_term( $term_id, 'product_cat' ) ) === $market;
    }
) );

// The native menu must belong to the page's market; no Spanish/category-tree fallback.
$menu_name = 'TaxoCommerce - ' . $market;
$nav_menu = wp_get_nav_menu_object( $menu_name );
$menu_id = ( $nav_menu instanceof WP_Term && (string) $nav_menu->name === $menu_name )
    ? (int) $nav_menu->term_id : 0;

// Reuse existing cluster styling; no supplier image is downloaded or copied.
$style_url = defined( 'TCT_URL' ) && 0 === strpos( __DIR__, dirname( TCT_DIR ) )
    ? TCT_URL . 'templates/styles-template.css'
    : ( defined( 'SEO_SYSTEM_URL' ) ? SEO_SYSTEM_URL . 'seo-system/templates/styles-template.css' : '' );
if ( $style_url !== '' ) {
    wp_enqueue_style( 'dht-cluster-international-base', $style_url, array(), null );
}
$site_name = 'Distribuidor de Herramientas';
$privacy_url = get_privacy_policy_url();
$schema_items = array();
foreach ( $hub_ids as $id ) {
    $link = get_permalink( $id );
    if ( $link ) {
        $schema_items[] = array( '@type' => 'ListItem', 'position' => count( $schema_items ) + 1, 'name' => get_the_title( $id ), 'url' => $link );
    }
}
foreach ( $category_ids as $id ) {
    $term = get_term( $id, 'product_cat' );
    $link = $term instanceof WP_Term ? get_term_link( $term ) : '';
    if ( $term instanceof WP_Term && ! is_wp_error( $link ) && $link ) {
        $schema_items[] = array( '@type' => 'ListItem', 'position' => count( $schema_items ) + 1, 'name' => $term->name, 'url' => $link );
    }
}
$schema = array(
    '@context' => 'https://schema.org',
    '@graph' => array(
        array( '@type' => 'CollectionPage', '@id' => $permalink . '#collection', 'url' => $permalink,
            'name' => $cluster_title, 'description' => wp_strip_all_tags( get_the_excerpt( $current_id ) ), 'inLanguage' => $t['lang'] ),
        array( '@type' => 'ItemList', '@id' => $permalink . '#items',
            'numberOfItems' => count( $schema_items ), 'itemListElement' => $schema_items ),
    ),
);
?>
<!doctype html>
<html lang="<?php echo esc_attr( $t['lang'] ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ( function_exists( 'dht_template_render_wp_head' ) ) { dht_template_render_wp_head(); } else { wp_head(); } ?>
<style>
body.dht-cluster-intl{margin:0;background:#f6f8fb;color:#172336;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.dht-cluster-intl *{box-sizing:border-box}
.dht-cluster-intl a:focus-visible,.dht-cluster-intl button:focus-visible{outline:3px solid #2368e7;outline-offset:2px}
.dht-intl-wrap{max-width:1160px;margin:auto;padding:0 16px}
.dht-intl-header{background:#fff;border-bottom:1px solid #dfe5ee;position:relative;z-index:10}
.dht-intl-top{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:17px 0}
.dht-intl-brand{text-decoration:none;color:#122b49;display:flex;flex-direction:column}
.dht-intl-brand strong{font-size:clamp(18px,2.2vw,26px);line-height:1.2}
.dht-intl-brand small{font-size:12px;color:#526273}
.dht-intl-market{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dht-intl-market-label{font-size:12px;padding:6px 10px;border:1px solid #d6dfeb;border-radius:20px}
.dht-intl-market .dht-country-switcher{display:flex;align-items:center;gap:5px}
.dht-intl-market .dht-country-switcher__link{display:inline-flex;align-items:center;justify-content:center;min-height:34px;min-width:34px;border:1px solid #dfe5ee;border-radius:7px;text-decoration:none;background:#f7f9fc;font-size:20px}
.dht-intl-nav{position:relative;padding:0 0 12px}
.dht-intl-nav button{cursor:pointer;font:inherit;border:1px solid #dfe5ee;background:#fff;padding:8px 12px;border-radius:7px}
.dht-intl-nav .dht-intl-main-toggle{display:none}
.dht-intl-nav ul{list-style:none;margin:0;padding:0}
.dht-intl-nav li{list-style:none;position:relative}
.dht-intl-nav li>a{display:block;padding:10px 12px;text-decoration:none;color:#173454;font-size:13px;font-weight:600}
.dht-intl-nav li>a:hover,.dht-intl-nav li>a:focus-visible{background:#eaf1fc}
.dht-intl-nav .dht-intl-menu{display:flex;flex-wrap:wrap;gap:5px}
.dht-intl-nav ul.sub-menu{display:none;position:absolute;top:100%;left:0;z-index:30;width:290px;max-height:60vh;overflow-y:auto;padding:8px;border:1px solid #dfe5ee;box-shadow:0 8px 22px #10253e24;background:#fff;border-radius:8px}
.dht-intl-nav ul.sub-menu ul.sub-menu{position:static;width:auto;max-height:none;overflow:visible;border:0;border-left:2px solid #dfe5ee;box-shadow:none;padding:0 0 0 8px}
.dht-intl-nav li.dht-intl-open>ul.sub-menu,.dht-intl-nav li:hover>ul.sub-menu,.dht-intl-nav li:focus-within>ul.sub-menu{display:block}
.dht-intl-sub-toggle{display:none;position:absolute;right:0;top:4px;min-height:34px;min-width:34px}
.dht-intl-nav li.menu-item-has-children>a{padding-right:42px}
.dht-intl-main{padding:25px 0 45px}
.dht-intl-main .hub-hero,.dht-intl-main .hub-section,.dht-intl-main .hub-content,.dht-intl-main .hub-links{margin-bottom:24px}
.dht-intl-main .hub-container{max-width:1160px;margin:0 auto;padding:0 16px}
.dht-intl-main .hub-hero-grid--text-only{display:block}
.dht-intl-main .hub-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.dht-intl-main .hub-card{display:block;background:#fff;border:1px solid #dfe5ee;border-radius:10px;padding:18px;color:inherit;text-decoration:none}
.dht-intl-main .hub-card:hover,.dht-intl-main .hub-card:focus-visible{border-color:#98b1d2}
.dht-intl-main .hub-card h3{margin:0 0 8px}
.dht-intl-main .hub-card p{color:#475467}
.dht-intl-footer{background:#142238;color:#eef3fa;padding:26px 0;font-size:13px}
.dht-intl-footer a{color:#fff}
@media(max-width:900px){
 .dht-intl-top{flex-wrap:wrap}
 .dht-intl-nav .dht-intl-main-toggle{display:inline-flex}
 .dht-intl-nav .dht-intl-menu{display:block;margin-top:8px;background:white;border:1px solid #dfe5ee;border-radius:8px;max-height:60vh;overflow:auto}
 .dht-intl-nav.dht-intl-ready .dht-intl-menu{display:none}
 .dht-intl-nav.dht-intl-ready.dht-intl-expanded .dht-intl-menu{display:block}
 .dht-intl-nav li>a{border-bottom:1px solid #edf1f5}
 .dht-intl-nav ul.sub-menu,.dht-intl-nav ul.sub-menu ul.sub-menu{position:static;width:auto;max-height:none;box-shadow:none;border:0;border-left:2px solid #dfe5ee;border-radius:0;margin:0 0 5px 15px}
 .dht-intl-nav li:hover>ul.sub-menu,.dht-intl-nav li:focus-within>ul.sub-menu{display:none}
 .dht-intl-nav li.dht-intl-open>ul.sub-menu{display:block}
 .dht-intl-sub-toggle{display:inline-flex}
 .dht-intl-main .hub-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:580px){.dht-intl-main .hub-grid{grid-template-columns:1fr}.dht-intl-top{align-items:flex-start}}
</style>
<script type="application/ld+json" id="schema-cluster"><?php
echo wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
?></script>
</head>
<body <?php body_class( 'dht-cluster-intl' ); ?>>
<?php wp_body_open(); ?>
<header class="dht-intl-header" role="banner">
<div class="dht-intl-wrap">
  <div class="dht-intl-top">
    <a class="dht-intl-brand" href="<?php echo esc_url( $permalink ); ?>"><strong><?php echo esc_html( $t['brand'] . ' · ' . $t['country'] ); ?></strong><small><?php echo esc_html( $t['tagline'] ); ?></small></a>
    <div class="dht-intl-market"><span class="dht-intl-market-label"><?php echo esc_html( $t['flag'] . ' ' . $t['country'] ); ?></span><?php dht_pilot_render_country_switcher( $market ); ?></div>
  </div>
  <?php if ( $menu_id ) : ?>
    <nav class="dht-intl-nav" aria-label="<?php echo esc_attr( $t['menu'] ); ?>">
      <button type="button" class="dht-intl-main-toggle" aria-controls="dht-intl-cluster-menu" aria-expanded="false">&#9776; <?php echo esc_html( $t['menu'] ); ?></button>
      <?php wp_nav_menu( array( 'menu' => $menu_id, 'container' => false, 'menu_id' => 'dht-intl-cluster-menu',
          'menu_class' => 'dht-intl-menu', 'fallback_cb' => false, 'depth' => 0 ) ); ?>
    </nav>
  <?php endif; ?>
</div>
</header>
<main id="main" class="hub-page cluster-page dht-intl-main">
<?php while ( have_posts() ) : the_post(); ?>
    <header class="hub-hero"><div class="hub-container hub-hero-grid--text-only"><div class="hub-hero-content">
        <span class="dht-kicker"><?php echo esc_html( $t['area'] ); ?></span>
        <h1 class="hub-title"><?php echo esc_html( $cluster_title ); ?></h1>
        <?php $excerpt = trim( (string) get_the_excerpt() ); if ( $excerpt !== '' ) : ?>
            <p class="hub-excerpt"><?php echo esc_html( $excerpt ); ?></p>
        <?php endif; ?>
        <?php if ( $hub_ids ) : ?><a class="dht-btn dht-btn-primary" href="#hubs-cluster"><?php echo esc_html( $t['hubs'] ); ?></a>
        <?php elseif ( $category_ids ) : ?><a class="dht-btn dht-btn-primary" href="#categorias-cluster"><?php echo esc_html( $t['categories'] ); ?></a><?php endif; ?>
    </div></div></header>
    <?php if ( trim( (string) get_the_content() ) !== '' ) : ?>
        <section class="hub-content"><div class="hub-container dht-content dht-prose"><?php the_content(); ?></div></section>
    <?php endif; ?>
    <?php if ( $hub_ids ) : ?>
    <section id="hubs-cluster" class="hub-section"><div class="hub-container">
        <div class="dht-section-header"><h2><?php echo esc_html( $t['primary'] ); ?></h2></div>
        <div class="hub-grid">
        <?php foreach ( $hub_ids as $hub_id ) :
            $link = get_permalink( $hub_id ); if ( ! $link ) { continue; }
            $title = (string) get_the_title( $hub_id );
            $summary = dht_template_post_summary( $hub_id, 30 ); ?>
            <a class="hub-card" href="<?php echo esc_url( $link ); ?>"><h3><?php echo esc_html( $title ); ?></h3>
                <?php if ( $summary !== '' ) : ?><p><?php echo esc_html( $summary ); ?></p><?php endif; ?>
                <span class="hub-btn"><?php echo esc_html( $t['view_hub'] ); ?> →</span>
            </a>
        <?php endforeach; ?>
        </div>
    </div></section>
    <?php endif; ?>
    <?php if ( $category_ids ) : ?>
    <section id="categorias-cluster" class="hub-links"><div class="hub-container">
        <div class="dht-section-header"><h2><?php echo esc_html( $t['related'] ); ?></h2></div><div class="hub-grid">
        <?php foreach ( $category_ids as $cat_id ) :
            $term = get_term( $cat_id, 'product_cat' );
            $link = $term instanceof WP_Term ? get_term_link( $term ) : '';
            if ( ! ( $term instanceof WP_Term ) || is_wp_error( $link ) || ! $link ) { continue; }
            $summary = wp_trim_words( wp_strip_all_tags( term_description( $cat_id ) ), 20 ); ?>
            <a class="hub-card" href="<?php echo esc_url( $link ); ?>"><h3><?php echo esc_html( $term->name ); ?></h3>
                <?php if ( $summary !== '' ) : ?><p><?php echo esc_html( $summary ); ?></p><?php endif; ?>
                <span class="hub-btn"><?php echo esc_html( $t['view_cat'] ); ?> →</span>
            </a>
        <?php endforeach; ?>
        </div>
    </div></section>
    <?php endif; ?>
<?php endwhile; ?>
</main>
<footer class="dht-intl-footer"><div class="dht-intl-wrap"><strong><?php echo esc_html( $site_name ); ?></strong>
<p><?php echo esc_html( $t['disclosure'] ); ?></p>
<?php if ( $privacy_url ) : ?><a href="<?php echo esc_url( $privacy_url ); ?>"><?php echo esc_html( $t['privacy'] ); ?></a><?php endif; ?>
</div></footer>
<script>
(function(){
    var nav=document.querySelector('.dht-intl-nav');
    if(!nav)return;
    var menu=nav.querySelector('.dht-intl-menu'),main=nav.querySelector('.dht-intl-main-toggle');
    if(!menu||!main)return;
    menu.querySelectorAll('li.menu-item-has-children').forEach(function(item,i){
        var list=item.querySelector(':scope > ul.sub-menu');
        var link=item.querySelector(':scope > a');
        if(!list||!link)return;
        list.id='dht-intl-sub-'+i;
        var btn=document.createElement('button');
        btn.type='button';btn.className='dht-intl-sub-toggle';btn.textContent='⌄';
        btn.setAttribute('aria-label',<?php echo wp_json_encode( $t['open'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>+' '+link.textContent.trim());
        btn.setAttribute('aria-controls',list.id);btn.setAttribute('aria-expanded','false');
        link.insertAdjacentElement('afterend',btn);
        btn.addEventListener('click',function(){
            var open=!item.classList.contains('dht-intl-open');
            item.classList.toggle('dht-intl-open',open);
            btn.setAttribute('aria-expanded',String(open));
        });
    });
    function close(){
        nav.classList.remove('dht-intl-expanded');main.setAttribute('aria-expanded','false');
        menu.querySelectorAll('.dht-intl-open').forEach(function(item){item.classList.remove('dht-intl-open')});
        menu.querySelectorAll('.dht-intl-sub-toggle').forEach(function(btn){btn.setAttribute('aria-expanded','false')});
    }
    main.addEventListener('click',function(){
        var expanded=!nav.classList.contains('dht-intl-expanded');
        if(!expanded){close();return}
        nav.classList.add('dht-intl-expanded');main.setAttribute('aria-expanded','true');
    });
    nav.addEventListener('keydown',function(e){if(e.key==='Escape'){close();main.focus()}});
    document.addEventListener('click',function(e){if(!nav.contains(e.target))close()});
    nav.classList.add('dht-intl-ready');
})();
</script>
<?php wp_footer(); ?>
</body></html>
