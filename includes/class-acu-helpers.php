<?php
/**
 * ACU_Helpers — Static utility belt (DRY prevention).
 *
 * All shared logic lives here as static methods.
 * No other class duplicates these.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Helpers {

	// -------------------------------------------------------------------------
	// Phone utilities
	// -------------------------------------------------------------------------

	/**
	 * Unified normalization: strip non-digits, strip 995 prefix, take last 9.
	 *
	 * @param string $phone Raw phone string.
	 * @return string 9-digit local number, or empty string if invalid.
	 */
	public static function normalize_phone( string $phone ): string {
		if ( $phone === '' ) {
			return '';
		}

		$digits = preg_replace( '/\D+/', '', $phone );

		// Strip Georgian country code
		if ( strlen( $digits ) > 9 && str_starts_with( $digits, '995' ) ) {
			$digits = substr( $digits, 3 );
		}

		// Take last 9 digits
		if ( strlen( $digits ) > 9 ) {
			$digits = substr( $digits, -9 );
		}

		return $digits;
	}

	/**
	 * Basic format check — digits, +, -, space, parens.
	 */
	public static function is_phone_like( string $raw ): bool {
		return (bool) preg_match( '/^[0-9\+\s\-\(\)]+$/', $raw );
	}

	/**
	 * Validate 11-digit personal ID.
	 */
	public static function validate_personal_id( string $raw ): bool {
		return (bool) preg_match( '/^\d{11}$/', $raw );
	}

	// -------------------------------------------------------------------------
	// Consent getters
	// -------------------------------------------------------------------------

	/**
	 * @return string 'yes', 'no', or ''
	 */
	public static function get_sms_consent( int $user_id ): string {
		$val = get_user_meta( $user_id, '_sms_consent', true );
		$val = is_string( $val ) ? strtolower( $val ) : '';
		return in_array( $val, [ 'yes', 'no' ], true ) ? $val : '';
	}

	/**
	 * @return string 'yes', 'no', or ''
	 */
	public static function get_call_consent( int $user_id ): string {
		$val = get_user_meta( $user_id, '_call_consent', true );
		$val = is_string( $val ) ? strtolower( $val ) : '';
		return in_array( $val, [ 'yes', 'no' ], true ) ? $val : '';
	}

	/**
	 * Raw billing_phone meta value.
	 */
	public static function get_user_phone( int $user_id ): string {
		$val = get_user_meta( $user_id, 'billing_phone', true );
		return is_string( $val ) ? $val : '';
	}

	// -------------------------------------------------------------------------
	// Phone uniqueness check
	// -------------------------------------------------------------------------

	/**
	 * Check for phone collision across users (exact match first, LIKE fallback limited to 50).
	 */
	public static function phone_exists_for_another_user( string $normalized_phone, int $current_user_id = 0 ): bool {
		$normalized_phone = trim( $normalized_phone );
		if ( $normalized_phone === '' ) {
			return false;
		}

		// Exact match
		$exact = new WP_User_Query( [
			'number'      => 1,
			'fields'      => 'ids',
			'count_total' => false,
			'meta_query'  => [
				[ 'key' => 'billing_phone', 'value' => $normalized_phone ],
			],
		] );
		$ids = $exact->get_results();
		if ( ! empty( $ids ) ) {
			$found_id = (int) $ids[0];
			if ( $found_id && $found_id !== $current_user_id ) {
				return true;
			}
		}

		// LIKE fallback (catch +995 prefix variants)
		$candidates = new WP_User_Query( [
			'number'      => 50,
			'fields'      => 'ids',
			'count_total' => false,
			'meta_query'  => [
				[ 'key' => 'billing_phone', 'value' => $normalized_phone, 'compare' => 'LIKE' ],
			],
		] );
		foreach ( $candidates->get_results() as $uid ) {
			if ( (int) $uid === $current_user_id ) {
				continue;
			}
			$stored_norm = self::normalize_phone( (string) get_user_meta( $uid, 'billing_phone', true ) );
			if ( $stored_norm !== '' && $stored_norm === $normalized_phone ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Terms helpers
	// -------------------------------------------------------------------------

	/**
	 * Custom T&C URL → WC terms page → empty.
	 */
	public static function get_terms_url(): string {
		$opt = get_option( 'acu_terms_url', '' );
		if ( $opt ) {
			return esc_url( $opt );
		}
		$wc_terms_page_id = get_option( 'woocommerce_terms_page_id' );
		if ( $wc_terms_page_id ) {
			$url = get_permalink( (int) $wc_terms_page_id );
			if ( $url ) {
				return esc_url( $url );
			}
		}
		return '';
	}

	/**
	 * T&C content by type ('default'|'sms'|'call') → option HTML → WC page → ''.
	 */
	public static function get_terms_content_html( string $type = 'default' ): string {
		$option_key = match ( $type ) {
			'sms'  => 'acu_sms_terms_html',
			'call' => 'acu_call_terms_html',
			default=> 'acu_terms_html',
		};

		$html = get_option( $option_key, '' );
		if ( is_string( $html ) && trim( $html ) !== '' ) {
			return self::prepare_terms_html( $html );
		}

		// Default type only: fall back to WC terms page content
		if ( $type === 'default' && get_option( 'woocommerce_enable_terms_and_conditions' ) ) {
			$page_id = get_option( 'woocommerce_terms_page_id' );
			if ( $page_id ) {
				$content = get_post_field( 'post_content', (int) $page_id );
				if ( $content ) {
					return wp_kses_post( $content );
				}
			}
		}

		return '';
	}

	/**
	 * Auto-paragraph if content has no block-level elements.
	 */
	public static function prepare_terms_html( string $html ): string {
		if ( $html === '' ) {
			return '';
		}
		if ( preg_match( '/<(p|div|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6])\b/i', $html ) ) {
			return $html;
		}
		return wpautop( $html );
	}

	// -------------------------------------------------------------------------
	// Admin email
	// -------------------------------------------------------------------------

	/**
	 * Custom email → site admin email fallback.
	 */
	public static function get_admin_notify_email(): string {
		$opt = get_option( 'acu_admin_email', '' );
		return ( is_string( $opt ) && is_email( $opt ) ) ? $opt : (string) get_option( 'admin_email' );
	}

	// -------------------------------------------------------------------------
	// Consent notification (with static-cache dedup)
	// -------------------------------------------------------------------------

	/**
	 * Fire admin email notification on consent change.
	 */
	public static function maybe_send_consent_notification(
		int $user_id,
		string $old,
		string $new_val,
		string $context = ''
	): void {
		static $sent = [];

		if ( ! get_option( 'acu_enable_email_notification', false ) ) {
			return;
		}

		$new_val = strtolower( $new_val );
		if ( $new_val !== 'yes' ) {
			return;
		}

		$old_norm = strtolower( $old );
		if ( $old_norm === $new_val && $context !== 'registration' ) {
			return;
		}

		$sent_key = $user_id . '_' . $new_val . '_' . $context;
		if ( isset( $sent[ $sent_key ] ) ) {
			return;
		}

		$admin_email = self::get_admin_notify_email();
		if ( ! $admin_email ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$first = $user->first_name;
		$last  = $user->last_name;
		if ( empty( $first ) && isset( $_POST['account_first_name'] ) ) {
			$first = sanitize_text_field( wp_unslash( $_POST['account_first_name'] ) );
		}
		if ( empty( $last ) && isset( $_POST['account_last_name'] ) ) {
			$last = sanitize_text_field( wp_unslash( $_POST['account_last_name'] ) );
		}
		$full_name = trim( $first . ' ' . $last );
		if ( $full_name === '' ) {
			$full_name = $user->display_name ?: $user->user_login;
		}

		$phone_display = self::get_user_phone( $user_id );
		if ( $phone_display === '' ) {
			foreach ( [ 'billing_phone', 'account_phone', 'anketa_phone_local' ] as $key ) {
				if ( ! empty( $_POST[ $key ] ) ) {
					$phone_display = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
					break;
				}
			}
		}
		if ( $phone_display === '' ) {
			$phone_display = __( '(not provided)', 'acu' );
		}

		$site_name  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject    = sprintf( '[%s] SMS consent update', $site_name );
		$agree_str  = ( $new_val === 'yes' )
			? __( 'now agrees to receive SMS.', 'acu' )
			: __( 'does not agree to receive SMS.', 'acu' );
		$context_str = $context ? sprintf( 'Context: %s', $context ) : '';
		$body = sprintf(
			/* translators: 1: name, 2: phone, 3: agreement, 4: context */
			__( 'User %1$s, phone number: %2$s, %3$s %4$s', 'acu' ),
			$full_name,
			$phone_display,
			$agree_str,
			$context_str
		);

		wp_mail( $admin_email, $subject, $body );
		$sent[ $sent_key ] = true;
	}

	// -------------------------------------------------------------------------
	// Client IP
	// -------------------------------------------------------------------------

	public static function get_client_ip(): string {
		$candidates = [
			isset( $_SERVER['HTTP_CLIENT_IP'] )       ? wp_unslash( $_SERVER['HTTP_CLIENT_IP'] )       : '',
			isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : '',
			isset( $_SERVER['REMOTE_ADDR'] )           ? wp_unslash( $_SERVER['REMOTE_ADDR'] )           : '',
		];
		foreach ( $candidates as $candidate ) {
			// X-Forwarded-For can be a comma-separated list; take the first entry
			$candidate = trim( explode( ',', (string) $candidate )[0] );
			$ip        = filter_var( $candidate, FILTER_VALIDATE_IP );
			if ( $ip !== false ) {
				return $ip;
			}
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Club card helpers
	// -------------------------------------------------------------------------

	/**
	 * Find coupon post by phone via _erp_sync_allowed_phones meta.
	 *
	 * @return string|false Coupon code (post title) or false.
	 */
	public static function find_coupon_by_phone( string $phone ): string|false {
		$normalized = self::normalize_phone( $phone );
		if ( $normalized === '' ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type   = 'shop_coupon'
				   AND p.post_status = 'publish'
				   AND pm.meta_key   = %s
				   AND pm.meta_value LIKE %s
				 ORDER BY p.ID DESC",
				'_erp_sync_allowed_phones',
				'%' . $wpdb->esc_like( $normalized ) . '%'
			)
		);

		// _erp_sync_allowed_phones is a comma-separated list of phone numbers.
		// Normalize each entry individually before comparing.
		foreach ( $post_ids as $post_id ) {
			$meta_raw = (string) get_post_meta( (int) $post_id, '_erp_sync_allowed_phones', true );
			foreach ( array_map( 'trim', explode( ',', $meta_raw ) ) as $raw_phone ) {
				if ( self::normalize_phone( $raw_phone ) === $normalized ) {
					return get_the_title( (int) $post_id );
				}
			}
		}

		return false;
	}

	/**
	 * Write coupon code to user meta after phone match.
	 */
	public static function link_coupon_to_user( int $user_id ): bool {
		$phone = self::get_user_phone( $user_id );
		if ( $phone === '' ) {
			return false;
		}

		$coupon_code = self::find_coupon_by_phone( $phone );
		if ( $coupon_code === false ) {
			return false;
		}

		update_user_meta( $user_id, '_acu_club_card_coupon', $coupon_code );
		return true;
	}

	// -------------------------------------------------------------------------
	// Page discovery
	// -------------------------------------------------------------------------

	/**
	 * Returns the ID of the published page containing [club_anketa_form].
	 * Result is cached in a transient for 1 hour.
	 */
	public static function find_anketa_page_id(): int {
		$cached = get_transient( 'acu_anketa_page_id' );
		if ( $cached !== false ) {
			return (int) $cached;
		}
		global $wpdb;
		$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_content LIKE '%club_anketa_form%'
			   AND post_type = 'page'
			   AND post_status = 'publish'
			 LIMIT 1"
		);
		set_transient( 'acu_anketa_page_id', $id, HOUR_IN_SECONDS );
		return $id;
	}

	// -------------------------------------------------------------------------
	// HTML sanitizer
	// -------------------------------------------------------------------------

	/**
	 * Custom sanitizer allowing richer HTML (styles, classes, ids, data-*).
	 */
	public static function sanitize_html( string $html ): string {
		if ( $html === '' ) {
			return '';
		}

		$allowed = [
			'div'    => [ 'class' => true, 'id' => true ],
			'span'   => [ 'class' => true, 'id' => true ],
			'p'      => [ 'class' => true, 'id' => true ],
			'br'     => [],
			'hr'     => [ 'class' => true, 'id' => true ],
			'strong' => [ 'class' => true, 'id' => true ],
			'em'     => [ 'class' => true, 'id' => true ],
			'b'      => [ 'class' => true, 'id' => true ],
			'i'      => [ 'class' => true, 'id' => true ],
			'u'      => [ 'class' => true, 'id' => true ],
			'small'  => [ 'class' => true, 'id' => true ],
			'mark'   => [ 'class' => true, 'id' => true ],
			'sub'    => [ 'class' => true, 'id' => true ],
			'sup'    => [ 'class' => true, 'id' => true ],
			'code'   => [ 'class' => true, 'id' => true ],
			'pre'    => [ 'class' => true, 'id' => true ],
			'h1'     => [ 'class' => true, 'id' => true ],
			'h2'     => [ 'class' => true, 'id' => true ],
			'h3'     => [ 'class' => true, 'id' => true ],
			'h4'     => [ 'class' => true, 'id' => true ],
			'h5'     => [ 'class' => true, 'id' => true ],
			'h6'     => [ 'class' => true, 'id' => true ],
			'ul'     => [ 'class' => true, 'id' => true ],
			'ol'     => [ 'class' => true, 'id' => true ],
			'li'     => [ 'class' => true, 'id' => true ],
			'table'  => [ 'class' => true, 'id' => true, 'data-title' => true ],
			'thead'  => [ 'class' => true, 'id' => true ],
			'tbody'  => [ 'class' => true, 'id' => true ],
			'tr'     => [ 'class' => true, 'id' => true ],
			'td'     => [ 'class' => true, 'id' => true, 'colspan' => true, 'rowspan' => true ],
			'th'     => [ 'class' => true, 'id' => true, 'colspan' => true, 'rowspan' => true ],
			'a'      => [ 'class' => true, 'id' => true, 'href' => true, 'title' => true, 'target' => true, 'rel' => true ],
			'img'    => [ 'class' => true, 'id' => true, 'src' => true, 'alt' => true, 'title' => true, 'width' => true, 'height' => true, 'loading' => true ],
		];

		return wp_kses( $html, $allowed );
	}
}
