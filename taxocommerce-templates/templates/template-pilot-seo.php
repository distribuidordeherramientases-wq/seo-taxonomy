<?php
/**
 * SEO head for all international pilot market templates.
 *
 * Runs wp_head once and consolidates only tags owned by this template.
 * No dependency on the legacy SEO plugin or its global dht_* functions.
 *
 * @package TaxoCommerce_Templates
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCT_Pilot_SEO_Head', false ) ) {
	final class TCT_Pilot_SEO_Head {
		/**
		 * Convert existing taxonomy, page or product copy into a short SEO summary.
		 *
		 * @param string $source Existing content.
		 * @return string
		 */
		public static function description( $source ) {
			$source = trim( (string) wp_strip_all_tags( strip_shortcodes( (string) $source ), true ) );
			$source = trim( (string) preg_replace( '/\s+/u', ' ', $source ) );
			if ( '' === $source ) {
				return '';
			}
			return (string) apply_filters(
				'taxocommerce_templates_fallback_meta_description',
				wp_html_excerpt( $source, 160, '' )
			);
		}

		/**
		 * Extract a quoted attribute from a head tag.
		 *
		 * @param string $tag HTML tag.
		 * @param string $attribute Attribute name.
		 * @return string
		 */
		private static function attribute( $tag, $attribute ) {
			if ( preg_match(
				'~(?:^|[\s<])' . preg_quote( $attribute, '~' ) . '\s*=\s*(["\x27])(.*?)\1~is',
				$tag,
				$matches
			) ) {
				return trim( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
			}
			return '';
		}

		/**
		 * Render a unique canonical and meta description, without suppressing SEO,
		 * consent, tracking or international hreflang hooks from wp_head.
		 *
		 * The caller already outputs one responsive viewport meta tag.
		 *
		 * @param string $canonical Canonical URL for this request.
		 * @param string $fallback  Existing language-specific page copy.
		 * @return void
		 */
		public static function render( $canonical, $fallback ) {
			$canonical = esc_url_raw( (string) $canonical );
			$fallback  = self::description( $fallback );

			ob_start();
			wp_head();
			$head = (string) ob_get_clean();

			$has_description = false;
			$verifications   = array();
			$head = preg_replace_callback(
				'~<meta\b[^>]*>~i',
				static function ( $matches ) use ( &$has_description, &$verifications ) {
					$tag  = $matches[0];
					$name = strtolower( self::attribute( $tag, 'name' ) );
					if ( 'viewport' === $name ) {
						// The international document already declares this in its <head>.
						return '';
					}
					if ( 'description' === $name ) {
						if ( $has_description || '' === self::attribute( $tag, 'content' ) ) {
							return '';
						}
						$has_description = true;
					}
					if ( 'p:domain_verify' === $name ) {
						$key = self::attribute( $tag, 'content' );
						if ( '' !== $key && isset( $verifications[ $key ] ) ) {
							return '';
						}
						if ( '' !== $key ) {
							$verifications[ $key ] = true;
						}
					}
					return $tag;
				},
				$head
			);

			if ( '' !== $canonical ) {
				$head = preg_replace_callback(
					'~<link\b[^>]*>~i',
					static function ( $matches ) {
						$rel = strtolower( self::attribute( $matches[0], 'rel' ) );
						return in_array( 'canonical', preg_split( '/\s+/', $rel ), true ) ? '' : $matches[0];
					},
					$head
				);
			}

			echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Preserved output of trusted wp_head hooks; only duplicate tags are removed.
			if ( ! $has_description && '' !== $fallback ) {
				echo "\n<meta name=\"description\" content=\"" . esc_attr( $fallback ) . "\">\n";
			}
			if ( '' !== $canonical ) {
				echo "\n<link rel=\"canonical\" href=\"" . esc_url( $canonical ) . "\">\n";
			}
		}
	}
}
