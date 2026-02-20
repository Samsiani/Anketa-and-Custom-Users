<?php
/**
 * Plugin Name:       Anketa and Custom Users
 * Plugin URI:        https://github.com/Samsiani/Anketa-and-Custom-Users
 * Description:       Unified club membership plugin: Anketa registration form, SMS OTP verification, phone-based login, WooCommerce custom fields (personal ID, consents, club card), user data search, CSV tools, and ERP coupon linking.
 * Version:           1.0.5
 * Author:            Samsiani
 * Text Domain:       acu
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACU_VERSION', '1.0.5' );
define( 'ACU_FILE',    __FILE__ );
define( 'ACU_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ACU_URL',     plugin_dir_url( __FILE__ ) );

require_once ACU_DIR . 'includes/class-acu-core.php';

register_activation_hook( __FILE__, [ 'ACU_Core', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'ACU_Core', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'acu', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Anketa and Custom Users requires WooCommerce to be active.', 'acu' ) .
				'</p></div>';
		} );
		return;
	}

	ACU_Core::instance();
}, 5 );
