<?php
/**
 * ACU SMS Test Script
 *
 * Run via WP-CLI from the WordPress root:
 *   wp eval-file wp-content/plugins/arttime-club-member/test-sms.php --phone=5XXXXXXXX
 *
 * Or via SSH:
 *   php -d display_errors=1 /home/arttime.ge/public_html/wp-content/plugins/arttime-club-member/test-sms.php 5XXXXXXXX
 *
 * REMOVE THIS FILE FROM THE SERVER AFTER TESTING.
 *
 * @package ACU
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

$is_cli = PHP_SAPI === 'cli';

// When run via wp eval-file, WordPress is already loaded.
// When run via plain `php`, bootstrap WordPress manually.
if ( ! function_exists( 'get_option' ) ) {
	// Adjust this path if your WordPress root differs.
	$wp_root = dirname( __FILE__, 5 ); // plugin → plugins → wp-content → public_html
	if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
		// fallback: one level up from the standard wp-content location
		$wp_root = dirname( __FILE__, 4 );
	}
	if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
		echo "ERROR: Cannot locate wp-load.php. Edit \$wp_root in this script.\n";
		exit( 1 );
	}
	$_SERVER['HTTP_HOST']   = 'localhost';
	$_SERVER['REQUEST_URI'] = '/';
	require $wp_root . '/wp-load.php';
}

// ── Phone argument ────────────────────────────────────────────────────────────

$phone_raw = '';
if ( $is_cli ) {
	// WP-CLI: wp eval-file test-sms.php --phone=599123456
	if ( isset( $GLOBALS['argv'] ) ) {
		foreach ( $GLOBALS['argv'] as $arg ) {
			if ( preg_match( '/^--phone=(\d+)$/', $arg, $m ) ) {
				$phone_raw = $m[1];
			}
		}
	}
	// plain php: php test-sms.php 599123456
	if ( $phone_raw === '' && isset( $GLOBALS['argv'][1] ) ) {
		$phone_raw = preg_replace( '/\D/', '', $GLOBALS['argv'][1] );
	}
}

if ( $phone_raw === '' ) {
	echo "Usage: wp eval-file ... --phone=5XXXXXXXX\n";
	echo "       php test-sms.php 5XXXXXXXX\n";
	exit( 1 );
}

// Normalize to 9 digits (strip country code if present)
$phone_digits = preg_replace( '/\D/', '', $phone_raw );
if ( strlen( $phone_digits ) === 12 && str_starts_with( $phone_digits, '995' ) ) {
	$phone_digits = substr( $phone_digits, 3 );
}
if ( strlen( $phone_digits ) !== 9 ) {
	echo "ERROR: Phone must be 9 digits (got: {$phone_digits})\n";
	exit( 1 );
}

// ── Credential check ──────────────────────────────────────────────────────────

echo "=== ACU SMS Test ===\n";
echo "Target phone : +995 {$phone_digits}\n\n";

$creds = [
	'username'   => (string) get_option( 'acu_sms_username', '' ),
	'password'   => (string) get_option( 'acu_sms_password', '' ),
	'client_id'  => (string) get_option( 'acu_sms_client_id', '' ),
	'service_id' => (string) get_option( 'acu_sms_service_id', '' ),
];

echo "--- Credentials from DB ---\n";
echo "  username   : " . ( $creds['username']   !== '' ? $creds['username']   : '(EMPTY — NOT SET)' ) . "\n";
echo "  password   : " . ( $creds['password']   !== '' ? str_repeat( '*', strlen( $creds['password'] ) ) : '(EMPTY — NOT SET)' ) . "\n";
echo "  client_id  : " . ( $creds['client_id']  !== '' ? $creds['client_id']  : '(EMPTY — NOT SET)' ) . "\n";
echo "  service_id : " . ( $creds['service_id'] !== '' ? $creds['service_id'] : '(EMPTY — NOT SET)' ) . "\n\n";

foreach ( $creds as $k => $v ) {
	if ( $v === '' ) {
		echo "ABORT: '{$k}' is empty. Set SMS credentials at WP Admin → Settings → Club Member Settings.\n";
		exit( 1 );
	}
}

// ── Build request URL (mirrors ACU_SMS::send()) ───────────────────────────────

$gateway  = 'http://bi.msg.ge/sendsms.php';
$phone_api = '995' . $phone_digits;
$message  = 'TEST: ACU SMS check - ' . gmdate( 'H:i:s' );

// `text` is excluded from add_query_arg() and appended manually with
// rawurlencode() so Georgian UTF-8 characters use RFC 3986 (%20 for spaces)
// instead of RFC 1738 (+), which the MS Group gateway requires.
$api_url = add_query_arg( [
	'username'   => $creds['username'],
	'password'   => $creds['password'],
	'client_id'  => $creds['client_id'],
	'service_id' => $creds['service_id'],
	'to'         => $phone_api,
	'result'     => 'json',
], $gateway );

$encoded_text = rawurlencode( $message );
$api_url     .= '&text=' . $encoded_text;

echo "--- Request ---\n";
// Print URL with password masked
$masked_url = preg_replace( '/password=[^&]+/', 'password=****', $api_url );
echo "  URL             : {$masked_url}\n";
echo "  text (raw)      : {$message}\n";
echo "  text (encoded)  : {$encoded_text}\n\n";

// ── Send ──────────────────────────────────────────────────────────────────────

echo "--- Sending via wp_remote_get() ---\n";
$response = wp_remote_get( $api_url, [ 'timeout' => 30 ] );

if ( is_wp_error( $response ) ) {
	echo "FAILED — WP HTTP error: " . $response->get_error_message() . "\n";
	exit( 1 );
}

$http_code = wp_remote_retrieve_response_code( $response );
$body      = wp_remote_retrieve_body( $response );

echo "  HTTP status : {$http_code}\n";
echo "  Body        : {$body}\n\n";

// ── Parse result ──────────────────────────────────────────────────────────────

$data = json_decode( $body, true );

if ( isset( $data['code'] ) ) {
	$code = (string) $data['code'];
	// Gateway returns integer 0 (JSON) → '0', or legacy string '0000' on success.
	if ( $code === '0' || $code === '0000' ) {
		echo "SUCCESS: SMS accepted by gateway (code {$code}).\n";
		echo "  message_id : " . ( $data['message_id'] ?? '(none)' ) . "\n";
	} else {
		$errors = [
			'0001' => 'Invalid API credentials or forbidden IP.',
			'0007' => 'Invalid phone number.',
			'0008' => 'Insufficient SMS balance.',
		];
		$msg = $errors[ $code ] ?? 'Unknown gateway error.';
		echo "FAILED: Gateway returned error code {$code} — {$msg}\n";
	}
} elseif ( str_starts_with( trim( $body ), '0000' ) ) {
	echo "SUCCESS: SMS accepted (plain-text response).\n";
} else {
	echo "FAILED: Unexpected response — {$body}\n";
}

echo "\nDone.\n";
