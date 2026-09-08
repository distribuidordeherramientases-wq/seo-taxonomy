<?php
/**
 * Plantilla VEVOR dinámica.
 *
 * Esta plantilla se ejecuta directamente cuando se incluye desde una plantilla
 * de categoría. No necesita que otra plantilla llame a ninguna función.
 *
 * Fuente de datos:
 *   {$wpdb->prefix}seo_proveedores_productos
 *
 * Criterio:
 *   proveedor = vevor
 *   estado_seleccion = descartado
 *   estado_sincronizacion = ignorado
 *   8 productos aleatorios
 */

defined('ABSPATH') || exit;

global $wpdb;

$table = $wpdb->prefix . 'seo_proveedores_productos';
$limit = 8;

$items = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT
            id,
            proveedor_id_externo,
            sku,
            url_origen,
            url_canonica,
            nombre,
            categoria_proveedor,
            precio_con_iva,
            moneda,
            imagenes,
            estado_seleccion,
            estado_sincronizacion
        FROM {$table}
        WHERE proveedor = %s
          AND estado_seleccion = %s
          AND estado_sincronizacion = %s
        ORDER BY RAND()
        LIMIT %d",
        'vevor',
        'descartado',
        'ignorado',
        $limit
    ),
    ARRAY_A
);

/*
 * Solo mostramos productos VEVOR descartados e ignorados.
 * No filtramos por stock, http_status u object_id.
 */

if (!is_array($items)) {
    $items = array();
}

/* Extrae la primera URL de imagen válida de distintos formatos posibles. */
$vevor_first_image = static function ($raw) {
    if (is_array($raw)) {
        $candidates = $raw;
    } else {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $candidates = $decoded;
        } else {
            $candidates = preg_split('/[\r\n|,;]+/', $raw);
        }
    }

    $queue = array_values((array) $candidates);

    while ($queue) {
        $value = array_shift($queue);

        if (is_array($value)) {
            foreach (array('url', 'image_url', 'src', 'image') as $key) {
                if (!empty($value[$key])) {
                    array_unshift($queue, $value[$key]);
                }
            }
            continue;
        }

        $url = esc_url_raw(trim((string) $value));
        if ($url !== '') {
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, array('http', 'https'), true)) {
                return $url;
            }
        }
    }

    return '';
};

/* Convierte la URL del producto en enlace de afiliado VEVOR. */
$vevor_affiliate_url = static function ($row) {
    $url = trim((string) ($row['url_canonica'] ?? ''));
    if ($url === '') {
        $url = trim((string) ($row['url_origen'] ?? ''));
    }

    $url = esc_url_raw($url);
    if ($url === '') {
        return '';
    }

    return add_query_arg(
        array(
            'utm_source'   => 'inhouse',
            'utm_medium'   => 'affiliate',
            'utm_campaign' => '53435399',
        ),
        $url
    );
};

/* Si no hay filas, no mostramos un bloque vacío al visitante. */
if (!$items) {
    if (current_user_can('manage_options')) {
        $error = trim((string) $wpdb->last_error);
        echo '<!-- VEVOR: 0 productos encontrados en ' . esc_html($table) . '. SQL error: ' . esc_html($error) . ' -->';
    }
    return;
}
?>

<section class="dht-vevor-products" aria-labelledby="dht-vevor-products-title">
    <div class="dht-vevor-products__inner">
        <header class="dht-vevor-products__header">
            <span class="dht-vevor-products__kicker">Selección VEVOR</span>
            <h2 id="dht-vevor-products-title">Productos destacados en VEVOR</h2>
            <p>Una selección aleatoria de productos disponibles en nuestro catálogo VEVOR.</p>
        </header>

        <div class="dht-vevor-products__grid">
            <?php foreach ($items as $item) :
                $title = trim((string) ($item['nombre'] ?? 'Producto VEVOR'));
                $category = trim((string) ($item['categoria_proveedor'] ?? 'VEVOR'));
                $image = $vevor_first_image($item['imagenes'] ?? '');
                $url = $vevor_affiliate_url($item);
                $price = isset($item['precio_con_iva']) ? (float) $item['precio_con_iva'] : 0.0;
                $currency = strtoupper(trim((string) ($item['moneda'] ?? 'EUR')));
            ?>
                <article class="dht-vevor-product" data-vevor-id="<?php echo esc_attr((string) ($item['id'] ?? '')); ?>">
                    <?php if ($url !== '') : ?>
                        <a class="dht-vevor-product__media" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener">
                    <?php else : ?>
                        <div class="dht-vevor-product__media">
                    <?php endif; ?>

                        <?php if ($image !== '') : ?>
                            <img
                                src="<?php echo esc_url($image); ?>"
                                alt="<?php echo esc_attr($title); ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php else : ?>
                            <span class="dht-vevor-product__no-image">VEVOR</span>
                        <?php endif; ?>

                    <?php if ($url !== '') : ?>
                        </a>
                    <?php else : ?>
                        </div>
                    <?php endif; ?>

                    <div class="dht-vevor-product__body">
                        <?php if ($category !== '') : ?>
                            <span class="dht-vevor-product__category"><?php echo esc_html($category); ?></span>
                        <?php endif; ?>

                        <h3><?php echo esc_html($title); ?></h3>

                        <?php if ($price > 0) : ?>
                            <div class="dht-vevor-product__price">
                                <?php
                                if ($currency === 'EUR' && function_exists('wc_price')) {
                                    echo wp_kses_post(wc_price($price));
                                } else {
                                    echo esc_html(number_format_i18n($price, 2) . ' ' . ($currency ?: 'EUR'));
                                }
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($url !== '') : ?>
                            <a class="dht-vevor-product__button" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener">
                                Ver en VEVOR <span aria-hidden="true">→</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="dht-vevor-products__notice">Enlaces de afiliado. Podemos recibir una comisión si realizas una compra, sin coste adicional para ti.</p>
    </div>
</section>

<style>
.dht-vevor-products{padding:34px 0;background:#fff}
.dht-vevor-products__inner{width:min(1200px,calc(100% - 32px));margin:0 auto}
.dht-vevor-products__header{margin-bottom:20px}
.dht-vevor-products__kicker{display:block;margin-bottom:5px;color:#667085;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.dht-vevor-products__header h2{margin:0 0 7px;font-size:clamp(24px,3vw,34px);line-height:1.15}
.dht-vevor-products__header p{margin:0;color:#667085}
.dht-vevor-products__grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}
.dht-vevor-product{display:flex;min-width:0;overflow:hidden;flex-direction:column;border:1px solid #e4e7ec;border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(16,24,40,.06)}
.dht-vevor-product__media{display:flex;aspect-ratio:1/1;align-items:center;justify-content:center;padding:14px;overflow:hidden;background:#f7f8fa;text-decoration:none}
.dht-vevor-product__media img{display:block;width:100%;height:100%;object-fit:contain}
.dht-vevor-product__no-image{font-size:24px;font-weight:900;color:#667085}
.dht-vevor-product__body{display:flex;flex:1;flex-direction:column;gap:8px;padding:15px}
.dht-vevor-product__category{overflow:hidden;color:#667085;font-size:11px;font-weight:700;letter-spacing:.04em;text-overflow:ellipsis;text-transform:uppercase;white-space:nowrap}
.dht-vevor-product h3{display:-webkit-box;margin:0;overflow:hidden;color:#182230;font-size:15px;line-height:1.4;-webkit-box-orient:vertical;-webkit-line-clamp:3}
.dht-vevor-product__price{font-size:18px;font-weight:850;color:#101828}
.dht-vevor-product__button{display:inline-flex;align-items:center;justify-content:center;gap:6px;margin-top:auto;padding:10px 12px;border-radius:8px;background:#e84b2c;color:#fff!important;font-size:13px;font-weight:800;text-decoration:none}
.dht-vevor-product__button:hover{background:#c93a20;color:#fff!important}
.dht-vevor-products__notice{margin:14px 0 0;color:#667085;font-size:11px}
@media(max-width:900px){.dht-vevor-products__grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:680px){.dht-vevor-products__grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.dht-vevor-products__inner{width:min(100% - 22px,1200px)}.dht-vevor-product__body{padding:12px}.dht-vevor-product__media{padding:9px}}
</style>
