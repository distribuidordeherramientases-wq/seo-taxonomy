<?php
/**
 * Editorial guidance for the four canonical content types in TaxoCommerce Core.
 *
 * This is read-only editorial help. No drafts, content, SEO metadata or
 * relationships are modified by rendering these recommendations.
 *
 * @package TaxoCommerceCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared people-first content standards based on Google Search Central.
 *
 * Editorial advice is deliberately NOT a mandatory fixed-length template.
 */
final class TaxoCommerce_Core_Editorial_Guide {

	/**
	 * Common rules for every content type.
	 *
	 * @return string[]
	 */
	private static function common_rules() {
		return array(
			__( 'Escribe para ayudar a una persona a elegir, entender o resolver una necesidad real; no para rellenar palabras clave.', 'taxocommerce-core' ),
			__( 'Ofrece información original y concreta: explica qué cambia en la decisión del comprador. No copies fichas del fabricante ni repitas párrafos entre URLs.', 'taxocommerce-core' ),
			__( 'Verifica cada especificación, compatibilidad, norma, prestación, precio y promesa comercial. Si no hay evidencia, no lo afirmes.', 'taxocommerce-core' ),
			__( 'Usa español claro, frases y párrafos breves, términos que emplearía un comprador no experto y encabezados que expliquen el contenido.', 'taxocommerce-core' ),
			__( 'Escribe un título específico, descriptivo y distinto del de las otras páginas; evita títulos genéricos y acumulaciones de palabras clave.', 'taxocommerce-core' ),
			__( 'Utiliza extracto y metadescripción para resumir de forma fiel el contenido. No son el mismo campo: la metadescripción se configura donde el sitio gestione sus metadatos SEO.', 'taxocommerce-core' ),
			__( 'Enlaza a páginas y categorías útiles y relacionadas. Comprueba antes si ya existe una URL que resuelve la misma intención para evitar duplicados.', 'taxocommerce-core' ),
			__( 'Añade imágenes que representen realmente el contenido y un texto alternativo descriptivo cuando transmita información.', 'taxocommerce-core' ),
			__( 'No hay una longitud obligatoria de Google ni un número fijo de apartados, preguntas o repeticiones de palabras clave: escribe lo necesario y elimina el relleno.', 'taxocommerce-core' ),
		);
	}

	/**
	 * Specific guidance, adapted to each WordPress/WooCommerce object.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function type_guides() {
		return array(
			'products' => array(
				'title' => __( 'Productos', 'taxocommerce-core' ),
				'purpose' => __( 'La ficha debe ayudar a decidir si ESTE producto es adecuado: no debe ser una descripción genérica de toda la categoría.', 'taxocommerce-core' ),
				'rules' => array(
					__( 'Nombre: tipo de producto, marca y modelo verificables, más la variante que de verdad lo distingue (potencia, medida, capacidad, etc.).', 'taxocommerce-core' ),
					__( 'Extracto: explica en pocas frases qué es, para qué sirve y qué detalle concreto importa al comprarlo; no repitas el título ni uses eslóganes.', 'taxocommerce-core' ),
					__( 'Descripción: concreta usos reales, prestaciones comprobadas, medidas, compatibilidades, límites y contenido de la caja SOLO si la fuente los confirma.', 'taxocommerce-core' ),
					__( 'Diferencia las variantes aunque compartan referencia de proveedor: identifica el modelo correcto antes de añadir datos o imágenes.', 'taxocommerce-core' ),
					__( 'Comprueba precio, moneda, disponibilidad, envío y condiciones de venta; en productos externos deja claro dónde se realiza la compra y revisa el enlace afiliado.', 'taxocommerce-core' ),
					__( 'Selecciona únicamente categorías que correspondan de verdad al producto; registra atributos técnicos precisos sin inventar valores.', 'taxocommerce-core' ),
					__( 'Comprueba que la imagen muestre el producto o variante exactos. No añadas valoraciones o reseñas que no existan.', 'taxocommerce-core' ),
					__( 'Si la web publica datos estructurados Product/Offer o Merchant Center, deben concordar con los datos visibles y las condiciones reales de compra.', 'taxocommerce-core' ),
				),
				'check' => __( '¿Con esta ficha puedo saber qué compro, si sirve para mi necesidad, qué incluye y cuáles son sus limitaciones?', 'taxocommerce-core' ),
			),
			'categories' => array(
				'title' => __( 'Categorías', 'taxocommerce-core' ),
				'purpose' => __( 'La categoría ayuda a explorar una familia de productos, entender sus diferencias y encontrar la opción adecuada.', 'taxocommerce-core' ),
				'rules' => array(
					__( 'Nombre preciso y coherente con el inventario; no mezcles productos ajenos para rellenar una categoría.', 'taxocommerce-core' ),
					__( 'Extracto: explica qué reúne la familia y su utilidad principal. La descripción desarrolla las decisiones de compra que necesitan contexto.', 'taxocommerce-core' ),
					__( 'Explica solo las diferencias que existen en el catálogo: tipos, usos, medidas, alimentación, accesorios o compatibilidades, según la familia.', 'taxocommerce-core' ),
					__( 'Cuando sea relevante, indica cómo elegir, qué comprobar antes de comprar y qué errores habituales conviene evitar.', 'taxocommerce-core' ),
					__( 'Conecta mediante enlaces navegables con subcategorías y productos realmente relacionados. Mantén una estructura comprensible y sin páginas huérfanas.', 'taxocommerce-core' ),
					__( 'No copies las descripciones de los productos ni fabriques cientos de categorías con textos casi iguales.', 'taxocommerce-core' ),
					__( 'Incluye preguntas frecuentes solo cuando existan dudas reales no resueltas en la descripción; no hay una cuota de FAQs.', 'taxocommerce-core' ),
					__( 'La antigua guía sugería 35–50 palabras de extracto y 400–700 de descripción. Eran referencias editoriales, NO exigencias de Google: prioriza utilidad y concisión.', 'taxocommerce-core' ),
				),
				'check' => __( '¿Entiendo las diferencias entre los productos de esta familia y sé cómo localizar los que me interesan?', 'taxocommerce-core' ),
			),
			'posts' => array(
				'title' => __( 'Entradas', 'taxocommerce-core' ),
				'purpose' => __( 'Una entrada debe resolver una duda, enseñar a usar algo, comparar con criterio o informar de una novedad comprobada.', 'taxocommerce-core' ),
				'rules' => array(
					__( 'Define una pregunta o problema concreto y responde desde el comienzo; no escribas otra categoría comercial con un título distinto.', 'taxocommerce-core' ),
					__( 'Para guías duraderas, explica elecciones, usos, diferencias, compatibilidades, errores o seguridad con ejemplos específicos y contrastados.', 'taxocommerce-core' ),
					__( 'Para noticias, identifica el hecho, la fuente y la fecha real. No conviertas una guía atemporal en noticia ni simules una actualización inexistente.', 'taxocommerce-core' ),
					__( 'Para comparativas, establece criterios y limitaciones, distingue datos verificables de opinión y no inventes pruebas de uso propio.', 'taxocommerce-core' ),
					__( 'Las FAQs, los análisis y los informes internos son materiales de trabajo: redacta un artículo propio, no pegues preguntas, respuestas ni plantillas de forma mecánica.', 'taxocommerce-core' ),
					__( 'Usa los apartados que realmente hacen falta, con encabezados descriptivos; atribuye las afirmaciones importantes a fuentes comprobables cuando corresponda.', 'taxocommerce-core' ),
					__( 'Enlaza a categorías o productos relevantes para continuar la lectura, sin convertir cada párrafo en publicidad.', 'taxocommerce-core' ),
					__( 'Si el artículo apenas repite otra URL, amplía el contenido original o fusiónalo en lugar de publicar un duplicado.', 'taxocommerce-core' ),
				),
				'check' => __( '¿El lector obtiene una respuesta práctica, fiable y diferente de la que ya ofrecen otras páginas del sitio?', 'taxocommerce-core' ),
			),
			'pages' => array(
				'title' => __( 'Páginas', 'taxocommerce-core' ),
				'purpose' => __( 'Cada página necesita una función propia: hub, landing de compra o información corporativa. No todas siguen la misma estructura.', 'taxocommerce-core' ),
				'rules' => array(
					__( 'Antes de crear una página, concreta qué busca resolver y revisa si una categoría, entrada o página existente ya responde mejor.', 'taxocommerce-core' ),
					__( 'En un cluster o hub explica el ámbito y ayuda a navegar hacia hubs secundarios, categorías y soluciones reales mediante enlaces claros.', 'taxocommerce-core' ),
					__( 'En una landing comercial identifica la necesidad del comprador, criterios de elección, alternativas, medidas o compatibilidades y un siguiente paso útil.', 'taxocommerce-core' ),
					__( 'No crees landings casi idénticas orientadas solo a pequeñas variaciones de una búsqueda, lugar o palabra clave.', 'taxocommerce-core' ),
					__( 'En páginas corporativas facilita datos reales y verificables sobre empresa, contacto, políticas y confianza; no inventes certificaciones.', 'taxocommerce-core' ),
					__( 'Mantén una jerarquía legible y enlaces coherentes. Cuando se cambia una URL publicada, revisa los enlaces internos y la redirección necesaria.', 'taxocommerce-core' ),
					__( 'La estructura editorial depende de la intención: respuesta inicial, criterios, alternativas, límites y acción solo cuando aportan valor.', 'taxocommerce-core' ),
					__( 'Evita canibalizar categorías o páginas existentes. Una nueva URL debe justificar una necesidad distinta y contenido propio.', 'taxocommerce-core' ),
				),
				'check' => __( '¿Esta página tiene un propósito único y conduce al visitante al siguiente paso adecuado sin repetir otra URL?', 'taxocommerce-core' ),
			),
		);
	}

	/**
	 * Official references, linked from the editor rather than hardcoded SEO myths.
	 *
	 * @return array<string,string>
	 */
	private static function google_references() {
		return array(
			__( 'Contenido útil y fiable', 'taxocommerce-core' ) => 'https://developers.google.com/search/docs/fundamentals/creating-helpful-content?hl=es',
			__( 'Estructura de comercio electrónico', 'taxocommerce-core' ) => 'https://developers.google.com/search/docs/specialty/ecommerce/help-google-understand-your-ecommerce-site-structure?hl=es',
			__( 'Títulos de resultados', 'taxocommerce-core' ) => 'https://developers.google.com/search/docs/appearance/title-link?hl=es',
			__( 'Metadescripciones y fragmentos', 'taxocommerce-core' ) => 'https://developers.google.com/search/docs/appearance/snippet?hl=es',
			__( 'Datos de producto y comercio', 'taxocommerce-core' ) => 'https://developers.google.com/search/docs/appearance/structured-data/product?hl=es',
			__( 'Políticas de spam y contenido masivo', 'taxocommerce-core' ) => 'https://developers.google.com/search/docs/essentials/spam-policies',
		);
	}

	/**
	 * Render a single consistent help section in list and individual editor.
	 *
	 * @param string $type    One of products, categories, posts, pages.
	 * @param string $context Editor or overview; only controls initial opening.
	 * @return void
	 */
	public static function render( $type, $context = 'overview' ) {
		$guides = self::type_guides();
		$type   = sanitize_key( (string) $type );
		if ( ! isset( $guides[ $type ] ) ) {
			return;
		}
		$guide = $guides[ $type ];
		$expanded = 'editor' !== $context;
		?>
		<section class="tc-editorial-guidance" aria-label="<?php esc_attr_e( 'Normas editoriales de TaxoCommerce Core', 'taxocommerce-core' ); ?>">
			<details class="tc-editorial-guide" <?php echo $expanded ? 'open' : ''; ?>>
				<summary class="tc-editorial-guide__summary">
					<span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span>
					<span class="tc-editorial-guide__summary-text">
						<strong><?php esc_html_e( 'Guía editorial y SEO', 'taxocommerce-core' ); ?> · <?php echo esc_html( $guide['title'] ); ?></strong>
						<small><?php esc_html_e( 'Criterios para mantener contenidos útiles, veraces y homogéneos', 'taxocommerce-core' ); ?></small>
					</span>
					<span class="tc-editorial-guide__hint"><?php esc_html_e( 'Consultar instrucciones', 'taxocommerce-core' ); ?></span>
				</summary>
				<div class="tc-editorial-guide__body">
					<p class="tc-editorial-guide__lead"><?php echo esc_html( $guide['purpose'] ); ?></p>
					<div class="tc-editorial-guide__grid">
						<div class="tc-editorial-guide__panel">
							<h3><?php esc_html_e( 'Criterios comunes', 'taxocommerce-core' ); ?></h3>
							<ul>
								<?php foreach ( self::common_rules() as $rule ) : ?>
									<li><?php echo esc_html( $rule ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
						<div class="tc-editorial-guide__panel">
							<h3><?php esc_html_e( 'Cómo editar este contenido', 'taxocommerce-core' ); ?></h3>
							<ul>
								<?php foreach ( $guide['rules'] as $rule ) : ?>
									<li><?php echo esc_html( $rule ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
					<div class="tc-editorial-guide__final">
						<strong><?php esc_html_e( 'Comprobación antes de guardar:', 'taxocommerce-core' ); ?></strong>
						<?php echo esc_html( $guide['check'] ); ?>
					</div>
					<div class="tc-editorial-guide__references">
						<strong><?php esc_html_e( 'Referencias oficiales de Google Search Central:', 'taxocommerce-core' ); ?></strong>
						<div class="tc-editorial-guide__links">
							<?php foreach ( self::google_references() as $label => $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?><span class="screen-reader-text"> <?php esc_html_e( '(se abre en una pestaña nueva)', 'taxocommerce-core' ); ?></span></a>
							<?php endforeach; ?>
						</div>
						<p><?php esc_html_e( 'Estas son recomendaciones editoriales basadas en documentación pública de Google. No son una certificación, una garantía de posicionamiento ni requisitos de extensión mínimos de texto.', 'taxocommerce-core' ); ?></p>
					</div>
				</div>
			</details>
		</section>
		<?php
	}
}
