<?php
/**
 * ACU_Core — Singleton bootstrap orchestrator.
 *
 * Responsibilities:
 *  - Static activate/deactivate hooks (DB creation, rewrite flush, old-plugin deactivation)
 *  - Singleton instance that requires and inits all modules
 *  - DB version check on admin_init
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Core {

	private static ?ACU_Core $instance = null;

	/** @var string Current DB schema version */
	const DB_VERSION = '1.0';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_modules();
		add_action( 'admin_init', [ $this, 'maybe_upgrade_db' ] );
	}

	// -------------------------------------------------------------------------
	// Activation / Deactivation
	// -------------------------------------------------------------------------

	public static function activate(): void {
		// These classes are not yet loaded during activation (plugins_loaded hasn't fired).
		require_once ACU_DIR . 'includes/class-acu-helpers.php';
		require_once ACU_DIR . 'includes/class-acu-migration.php';
		require_once ACU_DIR . 'includes/class-acu-print.php';

		self::create_db_table();
		ACU_Migration::run();
		self::deactivate_old_plugins();

		// Register rewrite rules before flushing
		ACU_Print::register_rewrite_rules();
		flush_rewrite_rules();

		update_option( 'acu_db_version', self::DB_VERSION );

		// Flush cached page discovery so find_anketa_page_id() re-queries after activation
		delete_transient( 'acu_anketa_page_id' );

		// Show one-time admin notice
		set_transient( 'acu_activation_notice', true, 30 );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// DB Table
	// -------------------------------------------------------------------------

	public static function create_db_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'acu_external_phones';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone      VARCHAR(20) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function maybe_upgrade_db(): void {
		$current = get_option( 'acu_db_version', '' );
		if ( $current !== self::DB_VERSION ) {
			self::create_db_table();
			update_option( 'acu_db_version', self::DB_VERSION );
		}
	}

	// -------------------------------------------------------------------------
	// Old-plugin deactivation
	// -------------------------------------------------------------------------

	private static function deactivate_old_plugins(): void {
		$old_plugins = [
			'new-club-anketa-arttime-main/club-anketa-registration-for-woocommerce.php',
			'arttime-woo-custom-user-main/woo-custom-user.php',
		];

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$deactivated = [];
		foreach ( $old_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				deactivate_plugins( $plugin );
				$deactivated[] = $plugin;
			}
		}

		if ( ! empty( $deactivated ) ) {
			set_transient( 'acu_deactivated_plugins_notice', $deactivated, 60 );
		}
	}

	// -------------------------------------------------------------------------
	// Module loading
	// -------------------------------------------------------------------------

	private function load_modules(): void {
		$includes = [
			'class-acu-helpers.php',
			'class-acu-sms.php',
			'class-acu-otp.php',
			'class-acu-migration.php',
			'class-acu-settings.php',
			'class-acu-auth.php',
			'class-acu-registration.php',
			'class-acu-account.php',
			'class-acu-checkout.php',
			'class-acu-print.php',
			'class-acu-shortcodes.php',
			'class-acu-admin.php',
		];

		foreach ( $includes as $file ) {
			require_once ACU_DIR . 'includes/' . $file;
		}

		// Init each module
		ACU_Settings::init();
		ACU_Auth::init();
		ACU_Registration::init();
		ACU_Account::init();
		ACU_Checkout::init();
		ACU_Print::init();
		ACU_Shortcodes::init();
		ACU_Admin::init();
		$this->init_update_checker();

		// Activation notices
		add_action( 'admin_notices', [ $this, 'show_activation_notices' ] );
	}

	// -------------------------------------------------------------------------
	// Update checker (Plugin Update Checker v5)
	// -------------------------------------------------------------------------

	private function init_update_checker(): void {
		$puc_path = ACU_DIR . 'vendor/plugin-update-checker/load-v5p6.php';
		if ( ! file_exists( $puc_path ) ) {
			return; // Library not present — fail silently.
		}

		require_once $puc_path;

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/Samsiani/Anketa-and-Custom-Users/',
			ACU_FILE,
			'arttime-club-member'
		);

		// Use the .zip asset from each GitHub Release (includes vendor/) instead
		// of the raw source zipball (which does NOT include vendor/).
		$checker->getVcsApi()->enableReleaseAssets();
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	public function show_activation_notices(): void {
		if ( get_transient( 'acu_activation_notice' ) ) {
			delete_transient( 'acu_activation_notice' );
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Anketa and Custom Users plugin activated successfully.', 'acu' ) .
				'</p></div>';
		}

		$deactivated = get_transient( 'acu_deactivated_plugins_notice' );
		if ( $deactivated ) {
			delete_transient( 'acu_deactivated_plugins_notice' );
			echo '<div class="notice notice-info is-dismissible"><p>' .
				esc_html__( 'The following legacy plugins were automatically deactivated:', 'acu' ) .
				' <code>' . esc_html( implode( '</code>, <code>', $deactivated ) ) . '</code></p></div>';
		}
	}
}
