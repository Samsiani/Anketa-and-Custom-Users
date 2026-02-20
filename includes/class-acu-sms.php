<?php
/**
 * ACU_SMS — MS Group API wrapper.
 *
 * Stateless, all methods static. Gateway: http://bi.msg.ge/sendsms.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_SMS {

	const GATEWAY_URL = 'http://bi.msg.ge/sendsms.php';

	/**
	 * Send an SMS message.
	 *
	 * @param string $phone_9_digits 9-digit local Georgian phone number.
	 * @param string $message        Text to send.
	 * @return array{success: bool, code?: string, message?: string, error?: string}
	 */
	public static function send( string $phone_9_digits, string $message ): array {
		$creds = self::get_credentials();

		if ( empty( $creds['username'] ) || empty( $creds['password'] ) ||
		     empty( $creds['client_id'] ) || empty( $creds['service_id'] ) ) {
			return [
				'success' => false,
				'error'   => __( 'SMS API not configured.', 'acu' ),
			];
		}

		$phone_api = self::format_for_api( $phone_9_digits );

		// NOTE: do NOT rawurlencode() $message here — add_query_arg() URL-encodes
		// values itself. Pre-encoding causes double-encoding (%20 → %2520) which
		// corrupts the message text sent to the gateway.
		$api_url = add_query_arg( [
			'username'   => $creds['username'],
			'password'   => $creds['password'],
			'client_id'  => $creds['client_id'],
			'service_id' => $creds['service_id'],
			'to'         => $phone_api,
			'text'       => $message,
			'result'     => 'json',
		], self::GATEWAY_URL );

		$response = wp_remote_get( $api_url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[ACU_SMS] HTTP error sending OTP to %s: %s', $phone_api, $response->get_error_message() ) );
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$body      = wp_remote_retrieve_body( $response );
		$http_code = wp_remote_retrieve_response_code( $response );

		error_log( sprintf( '[ACU_SMS] Gateway response (HTTP %d) for %s: %s', $http_code, $phone_api, $body ) );

		$data = json_decode( $body, true );

		if ( isset( $data['code'] ) ) {
			$code = (string) $data['code'];

			if ( str_starts_with( $code, '0000' ) ) {
				return [
					'success'    => true,
					'code'       => $code,
					'message_id' => $data['message_id'] ?? '',
				];
			}

			$error_messages = [
				'0001' => __( 'Invalid API credentials or forbidden IP.', 'acu' ),
				'0007' => __( 'Invalid phone number.', 'acu' ),
				'0008' => __( 'Insufficient SMS balance.', 'acu' ),
			];

			$error_text = $error_messages[ $code ] ?? __( 'SMS sending failed.', 'acu' );
			error_log( sprintf( '[ACU_SMS] Gateway error code %s for %s: %s', $code, $phone_api, $error_text ) );

			return [
				'success' => false,
				'code'    => $code,
				'error'   => $error_text,
			];
		}

		// Plain-text fallback: response starts with 0000
		if ( str_starts_with( trim( $body ), '0000' ) ) {
			return [
				'success'    => true,
				'message_id' => trim( str_replace( '0000-', '', $body ) ),
			];
		}

		error_log( sprintf( '[ACU_SMS] Unexpected API response for %s: %s', $phone_api, $body ) );

		return [
			'success' => false,
			'error'   => __( 'Unexpected API response.', 'acu' ),
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Prepend 995 country code for API.
	 */
	private static function format_for_api( string $digits ): string {
		return '995' . $digits;
	}

	/**
	 * Read acu_sms_* options.
	 */
	private static function get_credentials(): array {
		return [
			'username'   => (string) get_option( 'acu_sms_username', '' ),
			'password'   => (string) get_option( 'acu_sms_password', '' ),
			'client_id'  => (string) get_option( 'acu_sms_client_id', '' ),
			'service_id' => (string) get_option( 'acu_sms_service_id', '' ),
		];
	}
}
