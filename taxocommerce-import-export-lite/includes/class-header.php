<?php
/**
 * Standalone administration header for Import-Export Lite.
 *
 * Reads only this plugin's local metadata and bundled changelog. No Core,
 * other TaxoCommerce plugins, remote services or stored site settings.
 *
 * @package TaxoCommerceImportExportLite
 */

namespace TaxoCommerce\ImportExportLite;

defined( 'ABSPATH' ) || exit;

/**
 * Three-zone admin header following the TaxoCommerce family presentation.
 */
final class Header {
	/**
	 * Render the administration header once, before the normal plugin tabs.
	 *
	 * @return void
	 */
	public static function render() {
		$plugin_file = dirname( __DIR__ ) . '/taxocommerce-import-export-lite.php';
		if ( ! is_readable( $plugin_file ) ) {
			return;
		}

		$meta = get_file_data(
			$plugin_file,
			array(
				'name'        => 'Plugin Name',
				'description' => 'Description',
				'uri'         => 'Plugin URI',
				'version'     => 'Version',
				'released'    => 'Release Date',
			),
			'plugin'
		);

		$name        = trim( (string) ( $meta['name'] ?? '' ) );
		$description = trim( wp_strip_all_tags( (string) ( $meta['description'] ?? '' ) ) );
		$uri         = esc_url_raw( (string) ( $meta['uri'] ?? '' ), array( 'https' ) );
		$version     = trim( (string) ( $meta['version'] ?? '' ) );
		$released    = trim( (string) ( $meta['released'] ?? '' ) );
		if ( '' === $name ) {
			return;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $released ) ) {
			$released = '';
		}

		$changelog_file = dirname( __DIR__ ) . '/CHANGELOG.md';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only file bundled with this plugin.
		$history = is_readable( $changelog_file ) ? file_get_contents( $changelog_file ) : '';
		$history = is_string( $history ) ? trim( $history ) : '';

		$language = strtolower( substr( determine_locale(), 0, 2 ) );
		$local = array(
			'es' => array( 'Versión', 'Última actualización', 'Historial de versiones y mejoras', 'Ver ficha oficial' ),
			'en' => array( 'Version', 'Last updated', 'Version history and improvements', 'Official plugin page' ),
			'fr' => array( 'Version', 'Dernière mise à jour', 'Historique des versions et améliorations', 'Voir la fiche officielle' ),
			'de' => array( 'Version', 'Zuletzt aktualisiert', 'Versionsverlauf und Verbesserungen', 'Offizielle Pluginseite' ),
			'nl' => array( 'Versie', 'Laatst bijgewerkt', 'Versiegeschiedenis en verbeteringen', 'Officiële pluginpagina' ),
		);
		$fallback = $local[ $language ] ?? $local['en'];
		$labels = array(
			__( 'Version', 'taxocommerce-import-export-lite' ),
			__( 'Last updated', 'taxocommerce-import-export-lite' ),
			__( 'Version history and improvements', 'taxocommerce-import-export-lite' ),
			__( 'Official plugin page', 'taxocommerce-import-export-lite' ),
		);
		foreach ( $labels as $index => $label ) {
			if ( $local['en'][ $index ] === $label ) {
				$labels[ $index ] = $fallback[ $index ];
			}
		}
		?>
		<header class="tciel-hero">
			<span class="tciel-hero__emblem" aria-hidden="true"><span class="dashicons dashicons-database-import"></span></span>
			<div class="tciel-hero__copy">
				<p class="tciel-hero__eyebrow">TaxoCommerce</p>
				<h1><?php echo esc_html( $name ); ?></h1>
				<p class="tciel-hero__desc"><?php echo esc_html( $description ); ?></p>
				<?php if ( '' !== $uri ) : ?>
					<a class="tciel-hero__link" href="<?php echo esc_url( $uri ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $labels[3] ); ?></a>
				<?php endif; ?>
			</div>
			<div class="tciel-hero__history-zone">
				<?php if ( '' !== $history ) : ?>
					<details class="tciel-hero__history">
						<summary><?php echo esc_html( $labels[2] ); ?></summary>
						<div class="tciel-hero__history-content"><?php echo esc_html( $history ); ?></div>
					</details>
				<?php endif; ?>
			</div>
			<div class="tciel-hero__meta">
				<span class="tciel-hero__version"><?php echo esc_html( $labels[0] . ' ' . $version ); ?></span>
				<?php if ( '' !== $released ) : ?>
					<span class="tciel-hero__updated"><?php echo esc_html( $labels[1] . ': ' . $released ); ?></span>
				<?php endif; ?>
			</div>
			<span class="tciel-hero__art" aria-hidden="true"><i></i><i></i><i></i></span>
		</header>
		<?php
	}
}
