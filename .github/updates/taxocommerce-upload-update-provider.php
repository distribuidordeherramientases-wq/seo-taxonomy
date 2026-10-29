<?php
/**
 * Plugin Name: TaxoCommerce Uploads Update Provider (MU)
 * Description: Native WordPress updates for TaxoCommerce plugins from the site's temporary, trusted uploads repository.
 *
 * This must-use provider is independent of TaxoCommerce Core. It never
 * enables automatic installation: WordPress administrators opt in per plugin.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Upload_Update_Provider {
    const HOST = 'www.distribuidordeherramientas.es';
    const URI_PREFIX = 'https://www.distribuidordeherramientas.es/taxocommerce-updates/';
    const REPOSITORY = 'taxocommerce-core/plugins';

    public static function boot() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ), 30 );
        add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
    }

    private static function repository() {
        static $repository = null;
        if ( null !== $repository ) {
            return $repository;
        }
        $repository = false;
        $uploads = wp_get_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
            return false;
        }
        $dir = trailingslashit( $uploads['basedir'] ) . self::REPOSITORY;
        $manifest_file = $dir . '/manifest.json';
        if ( ! is_file( $manifest_file ) || ! is_readable( $manifest_file ) ) {
            return false;
        }
        // The manifest is published last by trusted GitHub FTPS; never accept a
        // URL or filesystem path taken from its contents.
        $contents = file_get_contents( $manifest_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local trusted manifest; WP_Filesystem needs credentials on some hosts.
        $manifest = is_string( $contents ) ? json_decode( $contents, true ) : null;
        if ( ! is_array( $manifest ) || 1 !== ( $manifest['schema'] ?? null ) || ! isset( $manifest['plugins'] ) || ! is_array( $manifest['plugins'] ) ) {
            return false;
        }
        $repository = array(
            'dir' => $dir,
            'url' => trailingslashit( $uploads['baseurl'] ) . self::REPOSITORY,
            'plugins' => $manifest['plugins'],
        );
        return $repository;
    }

    private static function expected_slug( $plugin_file, $plugin_data = array() ) {
        if ( ! is_string( $plugin_file ) || ! preg_match( '#^(taxocommerce-[a-z0-9-]+)/\1\.php$#D', $plugin_file, $match ) ) {
            return '';
        }
        // Another provider (including a future WordPress.org integration) owns
        // plugins that declare a non-empty Update URI. Never override it.
        if ( ! empty( $plugin_data['UpdateURI'] ) ) {
            return '';
        }
        return $match[1];
    }

    private static function version_info( $slug ) {
        $repo = self::repository();
        if ( ! $repo || ! isset( $repo['plugins'][ $slug ] ) || ! is_array( $repo['plugins'][ $slug ] ) ) {
            return false;
        }
        $entry = $repo['plugins'][ $slug ];
        if ( ! isset( $entry['version'], $entry['sha256'], $entry['size'] )
            || ! is_string( $entry['version'] ) || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-.][a-zA-Z0-9.]+)?$/', $entry['version'] )
            || ! is_string( $entry['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/D', $entry['sha256'] )
            || ! is_int( $entry['size'] ) || $entry['size'] <= 0 ) {
            return false;
        }
        return $entry;
    }

    private static function package_url( $slug, $entry ) {
        $repo = self::repository();
        if ( ! $repo ) {
            return false;
        }
        $file = $repo['dir'] . '/' . $slug . '.zip';
        if ( ! is_file( $file ) || ! is_readable( $file ) || is_link( $file )
            || filesize( $file ) !== $entry['size'] ) {
            return false;
        }
        // Validate the complete archive, not just its version or timestamp.
        $hash = hash_file( 'sha256', $file );
        if ( ! is_string( $hash ) || ! hash_equals( $entry['sha256'], $hash ) ) {
            return false;
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $file ) ) {
            return false;
        }
        $valid = false !== $zip->locateName( $slug . '/' . $slug . '.php' );
        $zip->close();
        if ( ! $valid ) {
            return false;
        }
        return esc_url_raw( $repo['url'] . '/' . $slug . '.zip' );
    }

    /**
     * Provide native update metadata without modifying WordPress.org-ready
     * plugin headers. A core MU plugin, not the plugin distribution, owns this.
     * Existing update providers always take precedence.
     */
    public static function check( $transient ) {
        if ( ! is_object( $transient ) || ! isset( $transient->checked )
            || ! is_array( $transient->checked ) || ! function_exists( 'get_plugins' ) ) {
            return $transient;
        }
        $installed = get_plugins();
        if ( ! is_array( $installed ) ) {
            return $transient;
        }
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        foreach ( $installed as $plugin_file => $plugin_data ) {
            if ( ! is_array( $plugin_data ) ) {
                continue;
            }
            $slug = self::expected_slug( $plugin_file, $plugin_data );
            if ( '' === $slug || ! isset( $transient->checked[ $plugin_file ] )
                || isset( $transient->response[ $plugin_file ] )
                || isset( $transient->no_update[ $plugin_file ] ) ) {
                continue;
            }

            $entry = self::version_info( $slug );
            if ( ! $entry || empty( $plugin_data['Version'] ) ) {
                continue;
            }
            // A package must be verified before it is offered for either
            // manual updating or the standard opt-in auto-update UI.
            $package = self::package_url( $slug, $entry );
            if ( ! $package ) {
                continue;
            }
            $current = (string) $plugin_data['Version'];
            $newer = version_compare( $entry['version'], $current, '>' );
            $info = (object) array(
                'id'           => self::URI_PREFIX . $slug . '/',
                'plugin'       => $plugin_file,
                'slug'         => $slug,
                'new_version'  => $newer ? $entry['version'] : $current,
                'version'      => $newer ? $entry['version'] : $current,
                'url'          => home_url( '/' ),
                'package'      => $newer ? $package : '',
                'requires_php' => isset( $entry['requires_php'] ) ? (string) $entry['requires_php'] : '7.4',
                'tested'       => isset( $entry['tested'] ) ? (string) $entry['tested'] : '',
            );
            // Deliberately omit "autoupdate"; WP remains in control of opt-in.
            if ( $newer ) {
                $transient->response[ $plugin_file ] = $info;
            } else {
                $transient->no_update[ $plugin_file ] = $info;
            }
        }
        return $transient;
    }

    public static function details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
            return $result;
        }
        $slug = (string) $args->slug;
        if ( ! preg_match( '/^taxocommerce-[a-z0-9-]+$/D', $slug ) ) {
            return $result;
        }
        // Never intercept WordPress.org details once a plugin migrates off
        // this temporary Update URI; validate the installed header first.
        if ( ! function_exists( 'get_plugins' ) ) {
            return $result;
        }
        $installed = get_plugins();
        $plugin_file = $slug . '/' . $slug . '.php';
        if ( ! isset( $installed[ $plugin_file ] )
            || '' === self::expected_slug( $plugin_file, $installed[ $plugin_file ] ) ) {
            return $result;
        }
        // Respect an installed plugin already covered by WordPress.org.
        if ( function_exists( 'get_site_transient' ) ) {
            $updates = get_site_transient( 'update_plugins' );
            if ( is_object( $updates ) ) {
                $provided = isset( $updates->response[ $plugin_file ] ) ? $updates->response[ $plugin_file ]
                    : ( isset( $updates->no_update[ $plugin_file ] ) ? $updates->no_update[ $plugin_file ] : null );
                if ( is_object( $provided ) && ! empty( $provided->id )
                    && self::URI_PREFIX . $slug . '/' !== $provided->id ) {
                    return $result;
                }
            }
        }
        $entry = self::version_info( $slug );
        if ( ! $entry ) {
            return $result;
        }
        $package = self::package_url( $slug, $entry );
        if ( ! $package ) {
            return $result;
        }
        return (object) array(
            'name'         => 'TaxoCommerce ' . ucwords( str_replace( array( 'taxocommerce-', '-' ), array( '', ' ' ), $slug ) ),
            'slug'         => $slug,
            'version'      => $entry['version'],
            'author'       => 'TaxoCommerce',
            'homepage'     => home_url( '/' ),
            'requires_php' => isset( $entry['requires_php'] ) ? (string) $entry['requires_php'] : '7.4',
            'download_link'=> $package,
            'sections'     => array( 'description' => 'Actualización desde el repositorio temporal local TaxoCommerce (/uploads). Las instalaciones automáticas se habilitan por plugin en WordPress.' ),
        );
    }
}
TaxoCommerce_Upload_Update_Provider::boot();
