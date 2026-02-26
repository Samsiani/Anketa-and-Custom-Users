<?php
/**
 * ACU_Auth — Phone-based WooCommerce login.
 *
 * Hooks into the `authenticate` filter at priority 20.
 * Detects phone-like input → normalize → lookup by billing_phone → check password.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Auth {

	public static function init(): void {
		add_filter( 'authenticate', [ self::class, 'phone_authenticate' ], 20, 3 );
		add_action( 'template_redirect', [ self::class, 'maybe_process_staff_login' ], 0 );
	}

	// -------------------------------------------------------------------------
	// Staff login form processor
	// -------------------------------------------------------------------------

	/**
	 * Process the ACU staff login form submitted from a shortcode page.
	 * Runs before any output (priority 0 on template_redirect).
	 */
	public static function maybe_process_staff_login(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['acu_staff_login'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['acu_staff_login_nonce'] ?? '' ) ),
			'acu_staff_login'
		) ) {
			return;
		}

		$username = sanitize_user( wp_unslash( $_POST['acu_login_username'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['acu_login_password'] ?? '' );

		// Redirect target: hidden field set by render_login_form(); fall back to current URI.
		$redirect = (string) wp_unslash( $_POST['acu_login_redirect'] ?? '' );
		if ( ! $redirect || ! wp_validate_redirect( $redirect ) ) {
			$redirect = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		}
		$redirect = remove_query_arg( 'acu_lerr', $redirect );

		$result = wp_signon( [
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		] );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'acu_lerr', '1', $redirect ) );
			exit;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Allow login with phone number as username.
	 *
	 * @param WP_User|WP_Error|null $user     Previously resolved user (pass-through).
	 * @param string                $username  Username / phone input.
	 * @param string                $password  Plain-text password.
	 * @return WP_User|WP_Error|null
	 */
	public static function phone_authenticate( $user, string $username, string $password ) {
		// Already resolved or missing input → pass through
		if ( $user instanceof WP_User ) {
			return $user;
		}
		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}
		if ( ! ACU_Helpers::is_phone_like( $username ) ) {
			return $user;
		}

		$normalized = ACU_Helpers::normalize_phone( $username );
		if ( $normalized === '' ) {
			return $user;
		}

		$users = get_users( [
			'meta_key'    => 'billing_phone',
			'meta_value'  => $normalized,
			'number'      => 1,
			'count_total' => false,
		] );

		if ( empty( $users ) || ! $users[0] instanceof WP_User ) {
			return $user;
		}

		$u = $users[0];

		if ( wp_check_password( $password, $u->user_pass, $u->ID ) ) {
			return $u;
		}

		return new WP_Error(
			'invalid_phone_password',
			__( '<strong>Error</strong>: The password you entered for the phone number is incorrect.', 'acu' )
		);
	}
}
