<?php
/**
 * Dedicated, read-only legal-page shell for US/FR/DE/NL policies.
 *
 * Displays the WordPress page content unchanged and preserves the market
 * header/footer. No local translation or duplicate legal text is embedded.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-cookie-footer.php';
require_once __DIR__ . '/template-pilot-country-switcher.php';

$market = isset( $GLOBALS['dht_cookie_policy_market'] )
    ? strtoupper( (string) $GLOBALS['dht_cookie_policy_market'] ) : '';
$labels = array(
    'US' => array( 'lang' => 'en-US', 'market' => 'United States', 'back' => 'Browse the catalogue', 'publisher' => 'Independent professional tools and buying guides' ),
    'FR' => array( 'lang' => 'fr-FR', 'market' => 'France', 'back' => 'Explorer le catalogue', 'publisher' => 'Guides indépendants et outils professionnels' ),
    'DE' => array( 'lang' => 'de-DE', 'market' => 'Deutschland', 'back' => 'Zum Katalog', 'publisher' => 'Unabhängige Ratgeber und professionelle Werkzeuge' ),
    'NL' => array( 'lang' => 'nl-NL', 'market' => 'Nederland', 'back' => 'Bekijk de catalogus', 'publisher' => 'Onafhankelijke gidsen en professioneel gereedschap' ),
);
if ( ! isset( $labels[ $market ] ) ) {
    return;
}
$t = $labels[ $market ];
$site = 'Distribuidor de Herramientas';
?>
<!doctype html>
<html lang="<?php echo esc_attr( $t['lang'] ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
body.dht-cookie-policy-intl{margin:0;min-height:100vh;display:flex;flex-direction:column;background:#f6f8fb;color:#172336;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.65}
.dht-cookie-policy-intl *, .dht-cookie-policy-intl *:before, .dht-cookie-policy-intl *:after{box-sizing:border-box}
.dht-cookie-policy-intl a:focus-visible,.dht-cookie-policy-intl button:focus-visible{outline:3px solid #72a6ff;outline-offset:3px}
.dht-policy-wrap{width:min(1060px,calc(100% - 32px));margin:0 auto}
.dht-policy-header{background:#fff;border-bottom:1px solid #dfe5ee;padding:18px 0}
.dht-policy-head{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}
.dht-policy-brand{display:block;color:#142c4b;font-weight:700;text-decoration:none;font-size:20px}
.dht-policy-market{font-size:13px;color:#44556d}
.dht-policy-main{flex:1;padding:30px 0 55px}
.dht-policy-main article{background:#fff;border:1px solid #e0e6ef;border-radius:12px;padding:clamp(18px,4vw,42px);overflow-wrap:anywhere}
.dht-policy-main h1{font-size:clamp(25px,3.5vw,38px);line-height:1.2;margin-top:0}
.dht-policy-main h2{font-size:22px;margin-top:28px}
.dht-policy-main a{color:#184b90}
.dht-policy-footer{background:#142238;color:#eef3fa;padding:24px 0;font-size:14px}
.dht-policy-footer a{color:#fff}
.dht-policy-footer .dht-footer-cookie-links{margin-top:14px}
</style>
</head>
<body <?php body_class( 'dht-cookie-policy-intl' ); ?>>
<?php wp_body_open(); ?>
<header class="dht-policy-header" role="banner">
  <div class="dht-policy-wrap dht-policy-head">
    <div>
      <a class="dht-policy-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $site ); ?></a>
      <span class="dht-policy-market"><?php echo esc_html( $t['market'] ); ?></span>
    </div>
    <div><?php dht_pilot_render_country_switcher( $market ); ?></div>
  </div>
</header>
<main id="main" class="dht-policy-main"><div class="dht-policy-wrap">
<?php while ( have_posts() ) : the_post(); ?>
  <article id="post-<?php the_ID(); ?>">
    <h1><?php the_title(); ?></h1>
    <div class="dht-policy-content"><?php the_content(); ?></div>
  </article>
<?php endwhile; ?>
</div></main>
<footer class="dht-policy-footer" role="contentinfo">
  <div class="dht-policy-wrap">
    <strong><?php echo esc_html( $site ); ?> · <?php echo esc_html( $t['market'] ); ?></strong>
    <p><?php echo esc_html( $t['publisher'] ); ?></p>
    <?php dht_template_render_cookie_footer_links( $market ); ?>
    <p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $site ); ?></p>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
