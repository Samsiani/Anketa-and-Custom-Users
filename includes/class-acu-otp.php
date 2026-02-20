<?php
/**
 * ACU_OTP — OTP generation, transient storage, rate limiting, verification.
 *
 * All transient keys use acu_ prefix to avoid collision with legacy otp_ keys.
 *
 * Transient TTLs:
 *  acm_otp_{phone9}          → 300s (OTP code)
 *  acm_rate_{md5(phone+ip)}  → 600s (rate limit counter)
 *  acm_vtoken_{phone9}       → 300s (verification token)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_OTP {

	const MAX_ATTEMPTS       = 3;
	const RATE_LIMIT_SECONDS = 600;  // 10 min
	const OTP_EXPIRY_SECONDS = 300;  // 5 min
	const TOKEN_EXPIRY       = 300;  // 5 min

	// -------------------------------------------------------------------------
	// Transient key helpers
	// -------------------------------------------------------------------------

	private static function otp_key( string $phone9 ): string {
		return 'acu_otp_' . $phone9;
	}

	private static function rate_key( string $phone9 ): string {
		return 'acu_rate_' . md5( $phone9 . ACU_Helpers::get_client_ip() );
	}

	private static function token_key( string $phone9 ): string {
		return 'acu_vtoken_' . $phone9;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Full send flow: rate check → generate → store → SMS → increment.
	 *
	 * @return array{success: bool, message: string, expires?: int}
	 */
	public static function send_otp( string $phone_9_digits ): array {
		if ( ! self::check_rate_limit( $phone_9_digits ) ) {
			return [
				'success' => false,
				'message' => __( 'Too many attempts. Please try again later.', 'acu' ),
			];
		}

		$otp = self::generate_otp();
		set_transient( self::otp_key( $phone_9_digits ), $otp, self::OTP_EXPIRY_SECONDS );

		$message = sprintf(
			/* translators: %s: OTP code */
			__( 'თქვენი ვერიფიკაციის კოდია: %s', 'acu' ),
			$otp
		);

		$result = ACU_SMS::send( $phone_9_digits, $message );

		if ( $result['success'] ) {
			self::increment_rate_limit( $phone_9_digits );
			return [
				'success' => true,
				'message' => __( 'OTP sent successfully.', 'acu' ),
				'expires' => self::OTP_EXPIRY_SECONDS,
			];
		}

		// SMS failed — delete the stored OTP
		delete_transient( self::otp_key( $phone_9_digits ) );

		return [
			'success' => false,
			'message' => $result['error'] ?? __( 'SMS sending failed.', 'acu' ),
		];
	}

	/**
	 * Full verify flow: retrieve stored → compare → generate token → store.
	 *
	 * @return array{success: bool, token?: string, verifiedPhone?: string, message: string}
	 */
	public static function verify_otp( string $phone_9_digits, string $code ): array {
		$stored_otp = get_transient( self::otp_key( $phone_9_digits ) );

		if ( $stored_otp === false ) {
			return [
				'success' => false,
				'message' => __( 'OTP expired. Please request a new code.', 'acu' ),
			];
		}

		if ( $stored_otp !== $code ) {
			return [
				'success' => false,
				'message' => __( 'Invalid OTP code.', 'acu' ),
			];
		}

		// OTP verified — clean up and issue token
		delete_transient( self::otp_key( $phone_9_digits ) );

		$token = wp_generate_password( 32, false );
		set_transient( self::token_key( $phone_9_digits ), $token, self::TOKEN_EXPIRY );

		// Update verified phone for logged-in users
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), '_acu_verified_phone', $phone_9_digits );
		}

		return [
			'success'       => true,
			'token'         => $token,
			'verifiedPhone' => $phone_9_digits,
			'message'       => __( 'Phone verified successfully.', 'acu' ),
		];
	}

	/**
	 * Token validation (used by checkout + account save hooks).
	 */
	public static function is_phone_verified( string $phone_9_digits, string $token ): bool {
		if ( $token === '' ) {
			return false;
		}
		$stored = get_transient( self::token_key( $phone_9_digits ) );
		return $stored !== false && $stored === $token;
	}

	/**
	 * Rate limit check: returns true if sending is allowed.
	 */
	public static function check_rate_limit( string $phone_9_digits ): bool {
		$key      = self::rate_key( $phone_9_digits );
		$attempts = get_transient( $key );
		if ( $attempts === false ) {
			return true;
		}
		return (int) $attempts < self::MAX_ATTEMPTS;
	}

	/**
	 * Increment rate limit counter.
	 */
	public static function increment_rate_limit( string $phone_9_digits ): void {
		$key      = self::rate_key( $phone_9_digits );
		$attempts = get_transient( $key );
		if ( $attempts === false ) {
			set_transient( $key, 1, self::RATE_LIMIT_SECONDS );
		} else {
			set_transient( $key, (int) $attempts + 1, self::RATE_LIMIT_SECONDS );
		}
	}

	/**
	 * Cleanup all transients after successful use.
	 */
	public static function cleanup( string $phone_9_digits ): void {
		delete_transient( self::otp_key( $phone_9_digits ) );
		delete_transient( self::token_key( $phone_9_digits ) );
		delete_transient( self::rate_key( $phone_9_digits ) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers (registered from ACU_Registration)
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Send OTP.
	 */
	public static function ajax_send_otp(): void {
		check_ajax_referer( 'acu_sms_nonce', 'nonce' );

		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone_digits = ACU_Helpers::normalize_phone( $phone );

		if ( strlen( $phone_digits ) !== 9 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid phone number. Must be 9 digits.', 'acu' ) ] );
		}

		$result = self::send_otp( $phone_digits );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	/**
	 * AJAX: Verify OTP.
	 */
	public static function ajax_verify_otp(): void {
		check_ajax_referer( 'acu_sms_nonce', 'nonce' );

		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$code         = isset( $_POST['code'] )  ? sanitize_text_field( wp_unslash( $_POST['code'] ) )  : '';
		$phone_digits = ACU_Helpers::normalize_phone( $phone );

		if ( strlen( $phone_digits ) !== 9 || strlen( $code ) !== 6 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid phone or code format.', 'acu' ) ] );
		}

		$result = self::verify_otp( $phone_digits, $code );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	// -------------------------------------------------------------------------
	// Private
	// -------------------------------------------------------------------------

	private static function generate_otp(): string {
		return str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}
}
