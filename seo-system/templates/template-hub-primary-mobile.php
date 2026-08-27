<?php
/*
*/

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

global $wpdb;

$current_id = absint(get_queried_object_id());
$hub_title  = get_the_title($current_id);
$table      = $wpdb->prefix . 'seo_relations';

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
            $current_id
        )
    )
);

if ($cluster_id > 0 && get_post_status($cluster_id) !== 'publish') {
    $cluster_id = 0;
}

$secondary_hub_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT target_id
         FROM {$table}
         WHERE source_type = 'hub_primary'
           AND source_id = %d
           AND target_type = 'hub_secondary'
           AND relation_type = 'hub_primary_to_hub_secondary'
         ORDER BY id ASC",
        $current_id
    )
);
$secondary_hub_ids = dht_template_public_post_ids($secondary_hub_ids);

$category_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT target_id
         FROM {$table}
         WHERE source_type = 'hub_primary'
           AND source_id = %d
           AND target_type = 'product_cat'
           AND relation_type = 'hub_primary_to_category'
         ORDER BY id ASC",
        $current_id
    )
);
$category_ids = dht_template_public_term_ids($category_ids, 'product_cat');

$cluster_title = $cluster_id ? get_the_title($cluster_id) : '';
$cluster_link  = $cluster_id ? get_permalink($cluster_id) : '';
$hero_image    = dht_template_structural_image_url($current_id, $secondary_hub_ids, $category_ids, 'large');
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

$breadcrumb_items[] = array(
    '@type'    => 'ListItem',
    'position' => count($breadcrumb_items) + 1,
    'name'     => $hub_title,
    'item'     => $permalink,
);

$json_items = array();
foreach ($secondary_hub_ids as $secondary_hub_id) {
    $url = get_permalink($secondary_hub_id);
    if (!$url) {
        continue;
    }

    $json_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($json_items) + 1,
        'name'     => get_the_title($secondary_hub_id),
        'url'      => $url,
    );
}

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
        '@id'             => $permalink . '#items',
        'name'            => 'Contenido de ' . $hub_title,
        'numberOfItems'   => count($json_items),
        'itemListElement' => $json_items,
    );
}

dht_template_render_header();
?>

<script type="application/ld+json" id="schema-hub-primary">
<?php echo wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $json_graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<main class="hub-page hub-primary-page dht-mobile-template">
<?php while (have_posts()) : the_post(); ?>
    <header class="hub-hero">
        <div class="hub-container hub-hero-grid<?php echo $hero_image ? '' : ' hub-hero-grid--text-only'; ?>">
            <div class="hub-hero-content">
                <span class="dht-kicker">Guía principal</span>
                <h1 class="hub-title"><?php echo esc_html($hub_title); ?></h1>

                <?php $excerpt = trim((string) get_the_excerpt()); ?>
                <p class="hub-excerpt">
                    <?php echo esc_html($excerpt !== '' ? $excerpt : 'Información práctica y categorías especializadas sobre ' . $hub_title . '.'); ?>
                </p>

                <?php if ($cluster_id && $cluster_link) : ?>
                    <nav class="hub-parent-nav" aria-label="Navegación estructural">
                        <a class="hub-parent-link" href="<?php echo esc_url($cluster_link); ?>">
                            <span aria-hidden="true">←</span> <?php echo esc_html($cluster_title); ?>
                        </a>
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
<?php if (!empty($secondary_hub_ids)) : ?>
        <section class="hub-section hub-secondary-section">
            <div class="hub-container">
                <div class="dht-section-header dht-section-header--left">
                    <span class="dht-kicker">Especialización</span>
                    <h2>Áreas especializadas de <?php echo esc_html($hub_title); ?></h2>
                    <p>Profundiza en usos, necesidades y familias de producto más concretas.</p>
                </div>

                <div class="hub-grid dht-mobile-rail">
                    <?php foreach ($secondary_hub_ids as $secondary_hub_id) : ?>
                        <?php
                        $title   = get_the_title($secondary_hub_id);
                        $link    = get_permalink($secondary_hub_id);
                        $image   = dht_template_node_image_url('hub_secondary', $secondary_hub_id, 'medium_large');
                        $summary = dht_template_post_summary($secondary_hub_id, 12);

                        if (!$image) {
                            $image = dht_template_placeholder_image_url('woocommerce_thumbnail');
                        }
                        ?>
                        <a class="hub-card" href="<?php echo esc_url($link); ?>">
                            <?php if ($image) : ?>
                                <div class="hub-img">
                                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                                </div>
                            <?php endif; ?>

                            <div class="hub-body">
                                <h3><?php echo esc_html($title); ?></h3>
                                <?php if ($summary !== '') : ?>
                                    <p><?php echo esc_html($summary); ?></p>
                                <?php endif; ?>
                                <span class="hub-btn">Ver guía <span aria-hidden="true">→</span></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($category_ids)) : ?>
        <section class="hub-links">
            <div class="hub-container">
                <div class="dht-section-header dht-section-header--left">
                    <span class="dht-kicker">Selección comercial</span>
                    <h2>Categorías relacionadas</h2>
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
                <span class="dht-kicker">Catálogo</span>
                <h2>Encuentra productos para <?php echo esc_html($hub_title); ?></h2>
                <p>Consulta categorías y compara alternativas antes de decidir.</p>
            </div>
            <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Explorar tienda</a>
        </div>
    </section>
<?php endwhile; ?>
</main>

<?php dht_template_render_footer(); ?>
