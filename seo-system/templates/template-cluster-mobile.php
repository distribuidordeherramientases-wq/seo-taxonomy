<?php
/*
*/

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

global $wpdb;

$current_id    = absint(get_queried_object_id());
$cluster_title = get_the_title($current_id);
$table         = $wpdb->prefix . 'seo_relations';

$hub_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT target_id
         FROM {$table}
         WHERE source_type = 'cluster'
           AND source_id = %d
           AND target_type = 'hub_primary'
           AND relation_type IN ('cluster_to_primary', 'cluster_to_hub_primary')
         ORDER BY id ASC",
        $current_id
    )
);
$hub_ids = dht_template_public_post_ids($hub_ids);

$direct_category_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT target_id
         FROM {$table}
         WHERE source_type = 'cluster'
           AND source_id = %d
           AND target_type = 'product_cat'
           AND relation_type = 'cluster_to_category'
         ORDER BY id ASC",
        $current_id
    )
);

$hub_category_ids = array();
if (!empty($hub_ids)) {
    $placeholders = implode(', ', array_fill(0, count($hub_ids), '%d'));
    $sql = "SELECT DISTINCT target_id
            FROM {$table}
            WHERE source_type = 'hub_primary'
              AND target_type = 'product_cat'
              AND relation_type = 'hub_primary_to_category'
              AND source_id IN ({$placeholders})
            ORDER BY target_id ASC";
    $prepared = $wpdb->prepare($sql, $hub_ids);
    $hub_category_ids = $wpdb->get_col($prepared);
}

$category_ids = dht_template_public_term_ids(
    array_merge((array) $direct_category_ids, (array) $hub_category_ids),
    'product_cat'
);

$hero_image = dht_template_structural_image_url(
    $current_id,
    $hub_ids,
    $category_ids,
    'large'
);

$permalink = get_permalink($current_id);
$json_items = array();

foreach ($hub_ids as $hub_id) {
    $hub_url = get_permalink($hub_id);
    if (!$hub_url) {
        continue;
    }

    $json_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($json_items) + 1,
        'name'     => get_the_title($hub_id),
        'url'      => $hub_url,
    );
}

foreach ($category_ids as $category_id) {
    $term = get_term($category_id, 'product_cat');
    if (!$term || is_wp_error($term)) {
        continue;
    }

    $term_url = dht_template_safe_term_link($term);
    if (!$term_url) {
        continue;
    }

    $json_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($json_items) + 1,
        'name'     => $term->name,
        'url'      => $term_url,
    );
}

$json_graph = array(
    array(
        '@type'       => 'CollectionPage',
        '@id'         => $permalink . '#collection',
        'url'         => $permalink,
        'name'        => $cluster_title,
        'description' => wp_strip_all_tags(get_the_excerpt($current_id)),
        'inLanguage'  => get_bloginfo('language'),
    ),
    array(
        '@type' => 'BreadcrumbList',
        'itemListElement' => array(
            array(
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => 'Inicio',
                'item'     => home_url('/'),
            ),
            array(
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $cluster_title,
                'item'     => $permalink,
            ),
        ),
    ),
);

if (!empty($json_items)) {
    $json_graph[] = array(
        '@type'           => 'ItemList',
        '@id'             => $permalink . '#items',
        'name'            => 'Contenido de ' . $cluster_title,
        'numberOfItems'   => count($json_items),
        'itemListElement' => $json_items,
    );
}

dht_template_render_header();
?>

<script type="application/ld+json" id="schema-cluster">
<?php echo wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $json_graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<main class="hub-page cluster-page dht-mobile-template">
<?php while (have_posts()) : the_post(); ?>
    <header class="hub-hero">
        <div class="hub-container hub-hero-grid<?php echo $hero_image ? '' : ' hub-hero-grid--text-only'; ?>">
            <div class="hub-hero-content">
                <span class="dht-kicker">Área temática</span>
                <h1 class="hub-title"><?php echo esc_html($cluster_title); ?></h1>

                <?php $excerpt = trim((string) get_the_excerpt()); ?>
                <?php if ($excerpt !== '') : ?>
                    <p class="hub-excerpt"><?php echo esc_html($excerpt); ?></p>
                <?php endif; ?>

                <?php if (!empty($hub_ids)) : ?>
                    <a class="dht-btn dht-btn-primary" href="#hubs-cluster">Explorar áreas especializadas</a>
                <?php elseif (!empty($category_ids)) : ?>
                    <a class="dht-btn dht-btn-primary" href="#categorias-cluster">Ver categorías</a>
                <?php endif; ?>
            </div>

            <?php if ($hero_image) : ?>
                <figure class="hub-hero-media">
                    <img
                        src="<?php echo esc_url($hero_image); ?>"
                        alt="<?php echo esc_attr($cluster_title); ?>"
                        loading="eager"
                        fetchpriority="high"
                    >
                </figure>
            <?php endif; ?>
        </div>
    </header>
<?php if (!empty($hub_ids)) : ?>
        <section id="hubs-cluster" class="hub-section hub-secondary-section">
            <div class="hub-container">
                <div class="dht-section-header dht-section-header--left">
                    <span class="dht-kicker">Navegación temática</span>
                    <h2>Áreas principales de <?php echo esc_html($cluster_title); ?></h2>
                    <p>Accede a las páginas especializadas y continúa hacia categorías concretas del catálogo.</p>
                </div>

                <div class="hub-grid dht-mobile-rail">
                    <?php foreach ($hub_ids as $hub_id) : ?>
                        <?php
                        $title   = get_the_title($hub_id);
                        $link    = get_permalink($hub_id);
                        $image   = dht_template_node_image_url('hub_primary', $hub_id, 'medium_large');
                        $summary = dht_template_post_summary($hub_id, 12);

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
                                <span class="hub-btn">Ver área <span aria-hidden="true">→</span></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($category_ids)) : ?>
        <section id="categorias-cluster" class="hub-links">
            <div class="hub-container">
                <div class="dht-section-header dht-section-header--left">
                    <span class="dht-kicker">Catálogo relacionado</span>
                    <h2>Categorías destacadas</h2>
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
                <span class="dht-kicker">¿Buscas un producto concreto?</span>
                <h2>Explora el catálogo completo</h2>
                <p>Compara categorías y productos con información práctica para elegir la opción adecuada.</p>
            </div>
            <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ir a la tienda</a>
        </div>
    </section>
<?php endwhile; ?>
</main>

<?php dht_template_render_footer(); ?>
