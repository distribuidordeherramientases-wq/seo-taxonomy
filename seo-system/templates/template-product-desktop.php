<?php

/**
 * Plantilla de producto personalizada
 * Archivo: template-product.php
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-product-stock-alert.php';

$amazon_product_template = __DIR__ . '/template-amazon-product.php';
if (is_readable($amazon_product_template)) {
    require_once $amazon_product_template;
}

global $product;

if (! $product instanceof WC_Product) {
    $product = wc_get_product(get_the_ID());
}

if (! $product instanceof WC_Product) {
    dht_template_render_header();
    echo '<main class="dht-page dht-status-page"><section class="dht-section"><div class="dht-content dht-status-card"><h1>Producto no disponible</h1><p>No se ha podido cargar la información del producto.</p></div></section></main>';
    dht_template_render_footer();
    return;
}

dht_template_render_header();

/**
 * GALERÍA CUSTOM (ESTILO AMAZON)
 *
 * Prioridad de imágenes:
 * 1. Biblioteca de medios / galería WooCommerce.
 * 2. Tabla wp_seo_supplier_images por product_id.
 * 3. Placeholder de WooCommerce.
 */
/*
 * WooCommerce puede conservar IDs de imagen/galería aunque el attachment haya
 * sido eliminado de wp_posts. Esos IDs rotos no deben bloquear el fallback a
 * imágenes externas del proveedor.
 */
$is_valid_product_attachment = static function ($attachment_id) {
    $attachment_id = absint($attachment_id);

    if ($attachment_id < 1 || 'attachment' !== get_post_type($attachment_id)) {
        return false;
    }

    if (!wp_attachment_is_image($attachment_id)) {
        return false;
    }

    return (bool) wp_get_attachment_image_url($attachment_id, 'full');
};

$main_image_id = absint($product->get_image_id());
if (!$is_valid_product_attachment($main_image_id)) {
    $main_image_id = 0;
}

$attachment_ids = array_values(array_filter(
    array_map('absint', (array) $product->get_gallery_image_ids()),
    $is_valid_product_attachment
));

if (!$main_image_id && !empty($attachment_ids)) {
    $main_image_id = $attachment_ids[0];
}

$product_id       = $product->get_id();
$sku              = $product->get_sku();
$rating_count     = $product->get_rating_count();
$average_rating   = $product->get_average_rating();
$short_description = $product->get_short_description();
$gallery_ids      = array_values(array_unique(array_filter(
    array_merge([$main_image_id], $attachment_ids)
)));

global $wpdb;

$supplier_stock = dht_supplier_product_stock_state($product_id);
$supplier_out_of_stock = !empty($supplier_stock['is_out_of_stock']);

$external_images = [];

/*
 * Imágenes externas del proveedor.
 *
 * Se consultan SIEMPRE, incluso cuando el producto ya tiene imagen local de
 * WooCommerce. De este modo podemos mezclar una imagen principal/local con
 * imágenes complementarias externas asociadas por product_id.
 *
 * No modifica _thumbnail_id ni _product_image_gallery: es solo presentación.
 */
$supplier_images_table = $wpdb->prefix . 'seo_supplier_images';

$supplier_images_exists = $wpdb->get_var(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($supplier_images_table))
) === $supplier_images_table;

if ($supplier_images_exists) {
    $external_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                id,
                image_url,
                image_hash,
                position,
                is_primary,
                status
             FROM {$supplier_images_table}
             WHERE product_id = %d
               AND status = 'active'
               AND image_url IS NOT NULL
               AND TRIM(image_url) <> ''
             ORDER BY
                is_primary DESC,
                position ASC,
                id ASC",
            $product_id
        ),
        ARRAY_A
    );

    $seen_external = [];

    foreach ((array) $external_rows as $external_row) {
        $url = esc_url_raw((string) ($external_row['image_url'] ?? ''));

        if ('' === $url) {
            continue;
        }

        $dedupe_key = md5($url);
        if (isset($seen_external[$dedupe_key])) {
            continue;
        }
        $seen_external[$dedupe_key] = true;

        $external_images[] = [
            'url'           => $url,
            'attachment_id' => 0,
            'usage'         => !empty($external_row['is_primary']) ? 'featured' : 'gallery',
            'is_primary'    => !empty($external_row['is_primary']),
        ];
    }
}

/*
 * Etiquetas visibles de la ficha.
 * Fuente única: vocabulario canónico (APLICACION / PLATAFORMA / SUBTIPO).
 * Las keywords legacy de producto están retiradas y no se usan como fallback.
 */
$technical_tags = [];
if (function_exists('seo_catalog_get_product_public_semantic_labels')) {
    $semantic_rows = seo_catalog_get_product_public_semantic_labels($product_id, 8);
    foreach ($semantic_rows as $semantic_row) {
        $semantic_label = trim((string) ($semantic_row['label'] ?? ''));
        if ($semantic_label !== '') {
            $technical_tags[] = $semantic_label;
        }
    }
}



/*
 * Datos físicos, atributos WooCommerce, atributos SEO y etiquetas.
 * La plantilla muestra todos los atributos con valor, aunque WooCommerce no
 * tenga marcada la casilla «Visible en la página del producto».
 */
$product_tag_terms = get_the_terms($product_id, 'product_tag');
$product_tag_terms = (!is_wp_error($product_tag_terms) && is_array($product_tag_terms))
    ? array_values($product_tag_terms)
    : array();

$format_specification_label = static function ($raw_label) {
    $raw_label = preg_replace('/^pa_/', '', (string) $raw_label);
    $raw_label = str_replace(array('_', '-'), ' ', $raw_label);
    $raw_label = trim((string) preg_replace('/\s+/u', ' ', $raw_label));

    if ($raw_label === '') {
        return '';
    }

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($raw_label, MB_CASE_TITLE, 'UTF-8');
    }

    return ucwords($raw_label);
};

$product_specifications = array();
$specification_keys = array();

$add_product_specification = static function ($label, $value, $source = '') use (&$product_specifications, &$specification_keys) {
    $label = trim(wp_strip_all_tags((string) $label));
    $value = trim(wp_strip_all_tags((string) $value));

    if ($label === '' || $value === '') {
        return;
    }

    $key = sanitize_title(remove_accents($label));
    if ($key === '' || isset($specification_keys[$key])) {
        return;
    }

    $specification_keys[$key] = true;
    $product_specifications[] = array(
        'key'    => $key,
        'label'  => $label,
        'value'  => $value,
        'source' => sanitize_key($source),
    );
};

/* Peso de WooCommerce. */
$product_weight = trim((string) $product->get_weight());
if ($product_weight !== '' && is_numeric($product_weight) && (float) $product_weight > 0) {
    $weight_display = function_exists('wc_format_weight')
        ? wc_format_weight($product_weight)
        : $product_weight . ' ' . get_option('woocommerce_weight_unit', 'kg');

    $add_product_specification('Peso', $weight_display, 'woocommerce_physical');
}

/* Dimensiones de WooCommerce: longitud × anchura × altura. */
$product_dimensions = $product->get_dimensions(false);
$dimension_unit = (string) get_option('woocommerce_dimension_unit', 'cm');
$dimension_labels = array(
    'length' => 'L',
    'width'  => 'An',
    'height' => 'Al',
);
$dimension_values = array();

if (is_array($product_dimensions)) {
    foreach ($dimension_labels as $dimension_key => $short_label) {
        $dimension_value = isset($product_dimensions[$dimension_key])
            ? trim((string) $product_dimensions[$dimension_key])
            : '';

        if ($dimension_value === '' || !is_numeric($dimension_value) || (float) $dimension_value <= 0) {
            continue;
        }

        $formatted_dimension = function_exists('wc_format_localized_decimal')
            ? wc_format_localized_decimal($dimension_value)
            : $dimension_value;

        $dimension_values[$dimension_key] = array(
            'label' => $short_label,
            'value' => $formatted_dimension,
        );
    }
}

if (!empty($dimension_values)) {
    if (count($dimension_values) === 3) {
        $dimensions_display = implode(' × ', array_column($dimension_values, 'value')) . ' ' . $dimension_unit;
        $dimensions_display .= ' (L × An × Al)';
    } else {
        $dimension_parts = array();
        foreach ($dimension_values as $dimension) {
            $dimension_parts[] = $dimension['label'] . ': ' . $dimension['value'] . ' ' . $dimension_unit;
        }
        $dimensions_display = implode(' · ', $dimension_parts);
    }

    $add_product_specification('Dimensiones', $dimensions_display, 'woocommerce_physical');
}

/* Atributos nativos de WooCommerce. */
foreach ($product->get_attributes() as $attribute) {
    if (!$attribute instanceof WC_Product_Attribute) {
        continue;
    }

    $attribute_name = $attribute->get_name();
    $attribute_label = wc_attribute_label($attribute_name, $product);

    if ($attribute_label === '' || $attribute_label === $attribute_name) {
        $attribute_label = $format_specification_label($attribute_name);
    }

    if ($attribute->is_taxonomy()) {
        $attribute_values = wc_get_product_terms(
            $product_id,
            $attribute_name,
            array('fields' => 'names')
        );

        if (is_wp_error($attribute_values)) {
            $attribute_values = array();
        }
    } else {
        $attribute_values = $attribute->get_options();
    }

    $attribute_values = array_values(array_filter(array_map(
        static function ($value) {
            return trim(wp_strip_all_tags((string) $value));
        },
        (array) $attribute_values
    )));

    if (!empty($attribute_values)) {
        $add_product_specification(
            $attribute_label,
            implode(', ', array_unique($attribute_values)),
            'woocommerce_attribute'
        );
    }
}

/* Atributos técnicos canónicos almacenados por SEO System. */
$seo_attribute_rows = function_exists('seo_attributes_get_product_rows')
    ? seo_attributes_get_product_rows($product_id)
    : array();

$seo_attribute_groups = array();
foreach ((array) $seo_attribute_rows as $seo_attribute) {
    if (isset($seo_attribute->attribute_visible) && !(int) $seo_attribute->attribute_visible) {
        continue;
    }

    $attribute_type = sanitize_key($seo_attribute->attribute_type ?? '');
    $attribute_value = trim((string) ($seo_attribute->attribute_value ?? ''));
    if ($attribute_type === '' || $attribute_value === '') {
        continue;
    }

    if (!isset($seo_attribute_groups[$attribute_type])) {
        $label = trim((string) ($seo_attribute->attribute_name ?? ''));
        if ($label === '') {
            $label = $format_specification_label($attribute_type);
        }
        $seo_attribute_groups[$attribute_type] = array(
            'label'  => $label,
            'values' => array(),
        );
    }
    $seo_attribute_groups[$attribute_type]['values'][$attribute_value] = true;
}

foreach ($seo_attribute_groups as $attribute_type => $group) {
    $values = array_keys((array) ($group['values'] ?? array()));
    if (!$values) {
        continue;
    }
    $add_product_specification(
        (string) ($group['label'] ?? $attribute_type),
        implode(', ', $values),
        'seo_attribute'
    );
}

/* Orden estable: datos comerciales relevantes primero y el resto después. */
$specification_priority = array(
    'marca'       => 10,
    'fabricante'  => 20,
    'modelo'      => 30,
    'potencia'    => 40,
    'voltaje'     => 50,
    'capacidad'   => 60,
    'par'         => 70,
    'precision'   => 80,
    'alcance'     => 90,
    'material'    => 100,
    'dimensiones' => 400,
    'peso'        => 410,
    'color'       => 420,
);

foreach ($product_specifications as $index => &$product_specification) {
    $product_specification['_order'] = $index;
    $product_specification['_priority'] = $specification_priority[$product_specification['key']] ?? 500;
}
unset($product_specification);

usort($product_specifications, static function ($left, $right) {
    if ($left['_priority'] === $right['_priority']) {
        return $left['_order'] <=> $right['_order'];
    }

    return $left['_priority'] <=> $right['_priority'];
});

foreach ($product_specifications as &$product_specification) {
    unset($product_specification['_order'], $product_specification['_priority']);
}
unset($product_specification);

$summary_specifications = array_slice($product_specifications, 0, 6);

/* Evita repetir en etiquetas técnicas las etiquetas reales de WooCommerce. */
$product_tag_names = array_map(
    static function ($term) {
        return strtolower(remove_accents((string) $term->name));
    },
    $product_tag_terms
);

$technical_tags = array_values(array_filter(
    $technical_tags,
    static function ($tag) use ($product_tag_names) {
        return !in_array(strtolower(remove_accents((string) $tag)), $product_tag_names, true);
    }
));
?>

<div class="dh-product-page dht-desktop-template">

  <div class="dh-product-layout dh-desktop-product-layout">

    <div class="dh-product-gallery">

      <?php if ($main_image_id) : ?>

        <div class="dh-gallery dh-gallery--stacked">

          <div class="dh-gallery-main">
            <?php echo wp_get_attachment_image($main_image_id, 'large', false, [
                'id'      => 'dh-main-img',
                'loading' => 'eager',
            ]); ?>
          </div>

          <?php
          /*
           * Miniaturas mixtas:
           * - imágenes locales de WooCommerce;
           * - imágenes externas complementarias de seo_supplier_images.
           *
           * Si existe imagen local principal, omitimos la externa marcada como
           * principal para evitar duplicar visualmente la misma portada.
           */
          $external_complementary_images = array_values(array_filter(
              $external_images,
              static function ($external_image) {
                  return empty($external_image['is_primary']);
              }
          ));

          $mixed_thumb_count = count($gallery_ids) + count($external_complementary_images);
          ?>

          <?php if ($mixed_thumb_count > 1) : ?>
            <div class="dh-gallery-thumbs">

              <?php foreach ($gallery_ids as $id) : ?>
                <?php
                $large = wp_get_attachment_image_url($id, 'large');
                ?>

                <button
                  type="button"
                  class="dh-gallery-thumb"
                  data-large="<?php echo esc_url($large); ?>"
                  aria-label="Ver imagen del producto"
                >
                  <?php echo wp_get_attachment_image($id, 'thumbnail'); ?>
                </button>
              <?php endforeach; ?>

              <?php foreach ($external_complementary_images as $external_image) : ?>
                <button
                  type="button"
                  class="dh-gallery-thumb dh-gallery-thumb--external"
                  data-large="<?php echo esc_url($external_image['url']); ?>"
                  aria-label="Ver imagen adicional del producto"
                >
                  <img
                    src="<?php echo esc_url($external_image['url']); ?>"
                    alt=""
                    loading="lazy"
                    decoding="async"
                  >
                </button>
              <?php endforeach; ?>

            </div>
          <?php endif; ?>

        </div>

      <?php elseif (!empty($external_images)) : ?>

        <?php $external_main = $external_images[0]['url']; ?>
        <div class="dh-gallery dh-gallery--stacked dh-gallery--external">

          <div class="dh-gallery-main">
            <img
              id="dh-main-img"
              src="<?php echo esc_url($external_main); ?>"
              alt="<?php echo esc_attr($product->get_name()); ?>"
              loading="eager"
              decoding="async"
            >
          </div>

          <?php if (count($external_images) > 1) : ?>
            <div class="dh-gallery-thumbs">
              <?php foreach ($external_images as $external_image) : ?>
                <button
                  type="button"
                  class="dh-gallery-thumb"
                  data-large="<?php echo esc_url($external_image['url']); ?>"
                  aria-label="Ver imagen del producto"
                >
                  <img
                    src="<?php echo esc_url($external_image['url']); ?>"
                    alt=""
                    loading="lazy"
                    decoding="async"
                  >
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>

      <?php else : ?>

        <div class="dh-product-no-image">
          <?php echo wc_placeholder_img('woocommerce_single'); ?>
        </div>

      <?php endif; ?>

    </div>

    <section class="dh-product-summary">

      <h1 class="dh-product-title"><?php the_title(); ?></h1>

      <div class="dh-product-reference">

        <?php if ($rating_count >= 3) : ?>
          <a class="dh-product-rating" href="#reviews">
            <?php
            echo wp_kses_post(wc_get_rating_html(
                $average_rating,
                $rating_count
            ));
            ?>
            <span><?php echo intval($rating_count); ?> opiniones</span>
          </a>
        <?php endif; ?>


      </div>

      <div class="dh-price-card">

        <div class="dh-price">
          <?php
          $regular = (float) $product->get_regular_price();
          $sale    = (float) $product->get_sale_price();
          ?>

          <?php if ($product->is_on_sale() && $sale > 0) : ?>

            <div class="dh-price-old">
              <del><?php echo wp_kses_post(wc_price($regular)); ?></del>
              <span class="dh-label-old">Precio anterior</span>
            </div>

            <div class="dh-price-current">
              <?php echo wp_kses_post(wc_price($sale)); ?>
            </div>

            <div class="dh-price-save">
              <?php
              $save    = $regular - $sale;
              $percent = ($regular > 0) ? round(($save / $regular) * 100) : 0;
              echo 'Ahorras ' . wp_kses_post(wc_price($save)) . ' (' . intval($percent) . '%)';
              ?>
            </div>

          <?php else : ?>

            <div class="dh-price-normal">
              <?php woocommerce_template_single_price(); ?>
            </div>

          <?php endif; ?>

          <div class="dh-tax-label">IVA incluido</div>
        </div>

      </div>

      <?php if ($short_description !== '') : ?>
        <div class="dh-product-excerpt">
          <?php
          echo apply_filters(
              'woocommerce_short_description',
              $short_description
          );
          ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($summary_specifications)) : ?>
        <section class="dh-product-key-attributes" aria-labelledby="dh-product-key-attributes-title">
          <h2 id="dh-product-key-attributes-title">Datos principales</h2>

          <dl class="dh-product-key-attributes-list">
            <?php foreach ($summary_specifications as $specification) : ?>
              <div class="dh-product-key-attribute">
                <dt><?php echo esc_html($specification['label']); ?></dt>
                <dd><?php echo esc_html($specification['value']); ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        </section>
      <?php endif; ?>

      <div id="dh-product-purchase" class="dh-buybox-card">
        <?php if ($supplier_out_of_stock) : ?>
          <?php dht_render_stock_alert_form($product_id); ?>
        <?php else : ?>
          <div class="dh-stock">
            <?php echo wp_kses_post(wc_get_stock_html($product)); ?>
          </div>

          <div class="dh-cart">
            <?php woocommerce_template_single_add_to_cart(); ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="dh-product-trust" aria-label="Ventajas de compra">
        <div><span aria-hidden="true">🚚</span> Envío</div>
        <div><span aria-hidden="true">🔒</span> Pago seguro</div>
        <div><span aria-hidden="true">↩️</span> Devolución</div>
        <div><span aria-hidden="true">🛡️</span> Garantía</div>
      </div>

      <div class="dh-contact-box">
        <span>¿Necesitas ayuda?</span>
        <a href="tel:+34640874540">640 87 45 40</a>
        <a href="mailto:servicioacliente@distribuidordeherramientas.es">Escríbenos</a>
      </div>

    </section>

  </div>

  <section class="dh-product-description">

    <div class="dh-product-description-content">
      <?php the_content(); ?>
    </div>

    <?php if (!$supplier_out_of_stock && $product->is_purchasable() && $product->is_in_stock()) : ?>
      <a class="dh-back-to-purchase" href="#dh-product-purchase">
        Comprar este producto
      </a>
    <?php endif; ?>
  </section>


  <?php if (!empty($technical_tags)) : ?>
    <section class="dh-technical-tags">
      <h2>Aplicaciones y características</h2>

      <div class="dh-product-tags">
        <?php foreach ($technical_tags as $tag) : ?>
          <span class="dh-product-tag"><?php echo esc_html($tag); ?></span>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($product_specifications)) : ?>
    <section class="dh-product-specifications" aria-labelledby="dh-product-specifications-title">
      <h2 id="dh-product-specifications-title">Especificaciones técnicas</h2>

      <dl class="dh-product-specifications-list">
        <?php foreach ($product_specifications as $specification) : ?>
          <div class="dh-product-specification-row">
            <dt><?php echo esc_html($specification['label']); ?></dt>
            <dd><?php echo esc_html($specification['value']); ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </section>
  <?php endif; ?>

  <?php
  $product_faqs = $wpdb->get_results(
      $wpdb->prepare(
          "SELECT question, answer
           FROM {$wpdb->prefix}seo_faq
           WHERE object_type = 3
             AND object_id = %d
             AND active = 1
           ORDER BY sort_order ASC, id ASC",
          $product_id
      )
  );

  if (!empty($product_faqs)) :
  ?>
    <section class="dh-product-faqs" aria-labelledby="dh-product-faqs-title">
      <h2 id="dh-product-faqs-title">Preguntas frecuentes</h2>

      <div class="dh-product-faq-list">
        <?php foreach ($product_faqs as $faq) : ?>
          <details class="dh-product-faq">
            <summary><?php echo esc_html($faq->question); ?></summary>

            <div class="dh-product-faq-answer">
              <?php echo wp_kses_post(wpautop($faq->answer)); ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section id="reviews" class="dh-product-reviews">
    <h2>Opiniones del producto</h2>
    <?php comments_template(); ?>
  </section>

  <section class="dh-related-products">
    <h2>Productos relacionados</h2>
    <?php
    $related_ids = function_exists('wc_get_related_products')
        ? wc_get_related_products($product_id, 4, array($product_id))
        : array();
    $related_products = array();
    foreach ((array) $related_ids as $related_id) {
        $related_product = wc_get_product($related_id);
        if ($related_product && is_a($related_product, 'WC_Product')) {
            $related_products[] = $related_product;
        }
    }
    if ($related_products) {
        dht_shared_render_product_grid($related_products, 'dht-related-product-grid', 3);
    } else {
        echo '<p>No hay productos relacionados disponibles.</p>';
    }
    ?>
  </section>

  <?php
  if (function_exists('dht_render_amazon_product_block')) {
      dht_render_amazon_product_block($product, array(
          'limit' => 6,
          'title' => 'Otras opciones que te pueden interesar',
          'mode'  => 'dynamic',
      ));
  }
  ?>

  <section class="dh-related-categories">
    <h2>Categorías relacionadas</h2>

    <div class="dh-product-meta">
      <?php
      echo wp_kses_post(wc_get_product_category_list(
          $product_id,
          ', '
      ));
      ?>
    </div>
  </section>

  <?php if (!empty($product_tag_terms)) : ?>
    <section class="dh-product-taxonomy-tags" aria-labelledby="dh-product-taxonomy-tags-title">
      <h2 id="dh-product-taxonomy-tags-title">Etiquetas del producto</h2>

      <div class="dh-product-tags">
        <?php foreach ($product_tag_terms as $product_tag) : ?>
          <?php $product_tag_url = get_term_link($product_tag); ?>
          <?php if (!is_wp_error($product_tag_url)) : ?>
            <a class="dh-product-tag" href="<?php echo esc_url($product_tag_url); ?>">
              <?php echo esc_html($product_tag->name); ?>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

</div>

<script>
document.addEventListener('click', function (event) {
    const thumbButton = event.target.closest('.dh-gallery-thumb');

    if (thumbButton) {
        const mainImage = document.getElementById('dh-main-img');

        if (mainImage && thumbButton.dataset.large) {
            mainImage.src = thumbButton.dataset.large;
            mainImage.removeAttribute('srcset');
        }
    }

    const copyButton = event.target.closest('.dh-copy-sku');

    if (copyButton && copyButton.dataset.sku) {
        const originalText = copyButton.textContent;
        const markCopied = function () {
            copyButton.textContent = 'Copiado';
            window.setTimeout(function () {
                copyButton.textContent = originalText;
            }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(copyButton.dataset.sku).then(markCopied).catch(function () {});
        }
    }
});
</script>

<?php dht_template_render_footer(); ?>