<?php
/*
*/

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

global $wpdb;

$current_id = absint(get_queried_object_id());
$hub_title  = get_the_title($current_id);
$table      = $wpdb->prefix . 'seo_relations';

$primary_id = absint(
    $wpdb->get_var(
        $wpdb->prepare(
            "SELECT source_id
             FROM {$table}
             WHERE source_type = 'hub_primary'
               AND target_type = 'hub_secondary'
               AND target_id = %d
               AND relation_type = 'hub_primary_to_hub_secondary'
             ORDER BY id ASC
             LIMIT 1",
            $current_id
        )
    )
);

if ($primary_id > 0 && get_post_status($primary_id) !== 'publish') {
    $primary_id = 0;
}

$cluster_id = 0;
if ($primary_id > 0) {
    $cluster_id = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT source_id
                 FROM {$table}
                 WHERE source_type = 'cluster'
                   AND target_type = 'hub_primary'
                   AND target_id = %d
                   AND relation_type IN ('cluster_to_primary', 'cluster_to_hub_primary')
                 ORDER BY id ASC
                 LIMIT 1",
                $primary_id
            )
        )
    );

    if ($cluster_id > 0 && get_post_status($cluster_id) !== 'publish') {
        $cluster_id = 0;
    }
}

$category_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT target_id
         FROM {$table}
         WHERE source_type = 'hub_secondary'
           AND source_id = %d
           AND target_type = 'product_cat'
           AND relation_type = 'hub_secondary_to_category'
         ORDER BY id ASC",
        $current_id
    )
);
$category_ids = dht_template_public_term_ids($category_ids, 'product_cat');

$primary_title = $primary_id ? get_the_title($primary_id) : '';
$primary_link  = $primary_id ? get_permalink($primary_id) : '';
$cluster_title = $cluster_id ? get_the_title($cluster_id) : '';
$cluster_link  = $cluster_id ? get_permalink($cluster_id) : '';
$hero_image    = dht_template_structural_image_url($current_id, array(), $category_ids, 'large');
$permalink     = get_permalink($current_id);

$breadcrumb_items = array(
    array('@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => home_url('/')),
);

if ($cluster_id && $cluster_link) {
    $breadcrumb_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($breadcrumb_items) + 1,
        'name'     => $cluster_title,
        'item'     => $cluster_link,
    );
}

if ($primary_id && $primary_link) {
    $breadcrumb_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($breadcrumb_items) + 1,
        'name'     => $primary_title,
        'item'     => $primary_link,
    );
}

$breadcrumb_items[] = array(
    '@type'    => 'ListItem',
    'position' => count($breadcrumb_items) + 1,
    'name'     => $hub_title,
    'item'     => $permalink,
);

$json_items = array();
foreach ($category_ids as $category_id) {
    $term = get_term($category_id, 'product_cat');
    if (!$term || is_wp_error($term)) {
        continue;
    }

    $url = dht_template_safe_term_link($term);
    if (!$url) {
        continue;
    }

    $json_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($json_items) + 1,
        'name'     => $term->name,
        'url'      => $url,
    );
}

$json_graph = array(
    array(
        '@type'       => 'CollectionPage',
        '@id'         => $permalink . '#collection',
        'url'         => $permalink,
        'name'        => $hub_title,
        'description' => wp_strip_all_tags(get_the_excerpt($current_id)),
        'inLanguage'  => get_bloginfo('language'),
    ),
    array(
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $breadcrumb_items,
    ),
);

if (!empty($json_items)) {
    $json_graph[] = array(
        '@type'           => 'ItemList',
        '@id'             => $permalink . '#categories',
        'name'            => 'Categorías de ' . $hub_title,
        'numberOfItems'   => count($json_items),
        'itemListElement' => $json_items,
    );
}

dht_template_render_header();
?>

<script type="application/ld+json" id="schema-hub-secondary">
<?php echo wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $json_graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<main class="hub-page hub-secondary-page dht-mobile-template">
<?php while (have_posts()) : the_post(); ?>
    <header class="hub-hero">
        <div class="hub-container hub-hero-grid<?php echo $hero_image ? '' : ' hub-hero-grid--text-only'; ?>">
            <div class="hub-hero-content">
                <span class="dht-kicker">Guía especializada</span>
                <h1 class="hub-title"><?php echo esc_html($hub_title); ?></h1>

                <?php $excerpt = trim((string) get_the_excerpt()); ?>
                <p class="hub-excerpt">
                    <?php echo esc_html($excerpt !== '' ? $excerpt : 'Información práctica, categorías y productos relacionados con ' . $hub_title . '.'); ?>
                </p>

                <?php if (($primary_id && $primary_link) || ($cluster_id && $cluster_link)) : ?>
                    <nav class="hub-parent-nav" aria-label="Navegación estructural">
                        <?php if ($primary_id && $primary_link) : ?>
                            <a class="hub-parent-link" href="<?php echo esc_url($primary_link); ?>">
                                <span aria-hidden="true">←</span> <?php echo esc_html($primary_title); ?>
                            </a>
                        <?php elseif ($cluster_id && $cluster_link) : ?>
                            <a class="hub-parent-link" href="<?php echo esc_url($cluster_link); ?>">
                                <span aria-hidden="true">←</span> <?php echo esc_html($cluster_title); ?>
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>

            <?php if ($hero_image) : ?>
                <figure class="hub-hero-media">
                    <img src="<?php echo esc_url($hero_image); ?>" alt="<?php echo esc_attr($hub_title); ?>" loading="eager" fetchpriority="high">
                </figure>
            <?php endif; ?>
        </div>
    </header>
<?php if (!empty($category_ids)) : ?>
        <section class="hub-links">
            <div class="hub-container">
                <div class="dht-section-header dht-section-header--left">
                    <span class="dht-kicker">Catálogo relacionado</span>
                    <h2>Categorías de <?php echo esc_html($hub_title); ?></h2>
                    <p>Accede a las familias de producto vinculadas directamente con esta guía.</p>
                </div>

                <div class="hub-grid hub-category-grid dht-mobile-category-grid">
                    <?php foreach ($category_ids as $category_id) : ?>
                        <?php
                        $term = get_term($category_id, 'product_cat');
                        if (!$term || is_wp_error($term)) {
                            continue;
                        }

                        $link = dht_template_safe_term_link($term);
                        if (!$link) {
                            continue;
                        }

                        $image = dht_template_term_image_url($category_id, 'medium_large', true);
                        if (!$image) {
                            $image = dht_template_placeholder_image_url('woocommerce_thumbnail');
                        }

                        $description = wp_trim_words(wp_strip_all_tags(term_description($category_id, 'product_cat')), 10);
                        ?>
                        <a class="hub-card hub-category-card" href="<?php echo esc_url($link); ?>">
                            <?php if ($image) : ?>
                                <div class="hub-img">
                                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="lazy">
                                </div>
                            <?php endif; ?>

                            <div class="hub-body">
                                <h3><?php echo esc_html($term->name); ?></h3>
                                <?php if ($description !== '') : ?>
                                    <p><?php echo esc_html($description); ?></p>
                                <?php endif; ?>
                                <span class="hub-btn">Ver categoría <span aria-hidden="true">→</span></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php else : ?>
        <section class="hub-section">
            <div class="hub-container">
                <div class="dht-empty-state">
                    <h2>Catálogo en preparación</h2>
                    <p>Esta guía todavía no tiene categorías públicas asociadas.</p>
                </div>
            </div>
        </section>
    <?php endif; ?>



<?php if (trim((string) get_the_content()) !== '') : ?>
        <section class="hub-content">
            <div class="dht-content dht-prose">
                <?php the_content(); ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="hub-cta">
        <div class="hub-container hub-cta-inner">
            <div>
                <span class="dht-kicker">Asesoramiento</span>
                <h2>¿Necesitas ayuda para elegir?</h2>
                <p>Consulta el catálogo o contacta para resolver dudas de compatibilidad y uso.</p>
            </div>
            <div class="hub-cta-actions">
                <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver tienda</a>
                <a class="dht-btn dht-btn-secondary" href="<?php echo esc_url(dht_template_contact_url()); ?>">Contactar</a>
            </div>
        </div>
    </section>
<?php endwhile; ?>
</main>

<?php dht_template_render_footer(); ?>
