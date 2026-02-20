<?php
/**
 * ACU_GitHub_Updater — Lightweight GitHub Releases auto-updater.
 *
 * Hooks into the WordPress update pipeline so site admins can update
 * this plugin through WP Admin → Plugins, exactly like a wp.org plugin.
 *
 * How it works:
 *  1. Filters `pre_set_site_transient_update_plugins` to inject update
 *     data when a newer GitHub Release tag is found.
 *  2. Filters `plugins_api` to populate the "View Details" modal with
 *     release notes from the GitHub Release body.
 *  3. Filters `upgrader_source_selection` to rename the unpacked
 *     GitHub zip folder (e.g. `Samsiani-Anketa-and-Custom-Users-abc123/`)
 *     to the correct plugin slug folder (`arttime-club-member/`).
 *
 * Release flow: bump ACU_VERSION → commit → push tag vX.Y.Z →
 * create GitHub Release on that tag → WordPress sites pick it up within
 * TRANSIENT_TTL hours.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_GitHub_Updater {

	const GITHUB_REPO   = 'Samsiani/Anketa-and-Custom-Users';
	const GITHUB_API    = 'https://api.github.com/repos/Samsiani/Anketa-and-Custom-Users/releases/latest';
	const PLUGIN_SLUG   = 'arttime-club-member';
	const PLUGIN_FILE   = 'arttime-club-member/arttime-club-member.php';
	const TRANSIENT_KEY = 'acu_github_update_data';
	const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS; // Re-check at most every 12 hours

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ self::class, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',             [ self::class, 'fix_source_dir' ], 10, 4 );
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	/**
	 * Returns the latest release object from GitHub, with a 12-hour transient cache.
	 * Returns false on network error or unexpected response.
	 *
	 * @return object|false
	 */
	private static function get_release_data() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_API,
			[
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $data ) || empty( $data->tag_name ) ) {
			return false;
		}

		set_transient( self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL );
		return $data;
	}

	// -------------------------------------------------------------------------
	// Update check
	// -------------------------------------------------------------------------

	/**
	 * Injects update data into the WordPress update transient when a newer
	 * GitHub Release is found.
	 *
	 * @param  object $transient
	 * @return object
	 */
	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::get_release_data();
		if ( ! $release ) {
			return $transient;
		}

		$latest  = ltrim( $release->tag_name, 'v' );
		$current = ACU_VERSION;

		if ( version_compare( $current, $latest, '<' ) ) {
			$transient->response[ self::PLUGIN_FILE ] = (object) [
				'id'          => 'github.com/' . self::GITHUB_REPO,
				'slug'        => self::PLUGIN_SLUG,
				'plugin'      => self::PLUGIN_FILE,
				'new_version' => $latest,
				'url'         => 'https://github.com/' . self::GITHUB_REPO,
				'package'     => $release->zipball_url,
				'icons'       => [],
				'banners'     => [],
				'banners_rtl' => [],
				'tested'      => '',
				'requires_php'=> '8.0',
				'compatibility'=> new stdClass(),
			];
		} else {
			// Tell WP this plugin is up to date (prevents false "update available" notices).
			$transient->no_update[ self::PLUGIN_FILE ] = (object) [
				'id'           => 'github.com/' . self::GITHUB_REPO,
				'slug'         => self::PLUGIN_SLUG,
				'plugin'       => self::PLUGIN_FILE,
				'new_version'  => $current,
				'url'          => 'https://github.com/' . self::GITHUB_REPO,
				'package'      => '',
				'icons'        => [],
				'banners'      => [],
				'banners_rtl'  => [],
				'tested'       => '',
				'requires_php' => '8.0',
				'compatibility' => new stdClass(),
			];
		}

		return $transient;
	}

	// -------------------------------------------------------------------------
	// "View Details" modal
	// -------------------------------------------------------------------------

	/**
	 * Populates the plugin information modal with data from the GitHub Release.
	 *
	 * @param  false|object|array $result
	 * @param  string             $action
	 * @param  object             $args
	 * @return false|object
	 */
	public static function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== self::PLUGIN_SLUG ) {
			return $result;
		}

		$release = self::get_release_data();
		if ( ! $release ) {
			return $result;
		}

		$version      = ltrim( $release->tag_name, 'v' );
		$changelog    = isset( $release->body ) && $release->body !== ''
			? '<pre>' . esc_html( $release->body ) . '</pre>'
			: '<p>See GitHub for release notes.</p>';
		$published_at = isset( $release->published_at )
			? gmdate( 'Y-m-d', strtotime( $release->published_at ) )
			: '';

		return (object) [
			'name'          => 'Anketa and Custom Users',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $version,
			'author'        => '<a href="https://github.com/Samsiani">Samsiani</a>',
			'author_profile'=> 'https://github.com/Samsiani',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'download_link' => $release->zipball_url,
			'trunk'         => $release->zipball_url,
			'requires'      => '6.3',
			'requires_php'  => '8.0',
			'tested'        => '',
			'last_updated'  => $published_at,
			'sections'      => [
				'description' => '<p>Unified club membership plugin: Anketa registration form, SMS OTP verification, phone-based WooCommerce login, custom fields, CSV tools, and ERP coupon linking.</p>',
				'changelog'   => $changelog,
			],
			'banners'       => [],
			'icons'         => [],
		];
	}

	// -------------------------------------------------------------------------
	// Fix extracted folder name
	// -------------------------------------------------------------------------

	/**
	 * GitHub zipball folders are named `{owner}-{repo}-{short-sha}/`.
	 * WordPress expects the plugin folder to match the plugin slug.
	 * This filter renames it during extraction.
	 *
	 * @param  string      $source        Path to the unpacked source folder.
	 * @param  string      $remote_source Path to the temp directory.
	 * @param  WP_Upgrader $upgrader
	 * @param  array       $hook_extra
	 * @return string      Corrected source path.
	 */
	public static function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::PLUGIN_FILE ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $source;
		}

		$corrected = trailingslashit( $remote_source ) . self::PLUGIN_SLUG . '/';

		// Already correctly named (e.g. a release asset was used instead of zipball).
		if ( $source === $corrected ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $corrected ) ) {
			return $corrected;
		}

		return $source;
	}
}
