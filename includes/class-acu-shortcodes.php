<?php
/**
 * ACU_Shortcodes — [user_data_check] and print-terms shortcodes.
 *
 * Shortcodes registered:
 *  [user_data_check]        — staff search form (user + coupon-aware)
 *  [acm_print_terms_button] — print Terms button (attrs: label, class, type)
 *  [wcu_print_terms_button] — backward-compatibility alias
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Shortcodes {

	public static function init(): void {
		add_shortcode( 'user_data_check',        [ self::class, 'shortcode_user_data_check' ] );
		add_shortcode( 'acm_print_terms_button', [ self::class, 'shortcode_print_terms_button' ] );
		add_shortcode( 'wcu_print_terms_button', [ self::class, 'shortcode_print_terms_button' ] ); // compat

		add_action( 'wp_ajax_acu_udc_search', [ self::class, 'ajax_udc_search' ] );
	}

	// -------------------------------------------------------------------------
	// [user_data_check] shortcode
	// -------------------------------------------------------------------------

	public static function shortcode_user_data_check(): string {
		if ( ! is_user_logged_in() ) {
			return ACU_Helpers::render_login_form();
		}
		if ( ! ACU_Helpers::current_user_can_manage_members() ) {
			return '<p class="wcu-error">' . esc_html__( 'Permission denied.', 'acu' ) . '</p>';
		}

		wp_enqueue_style( 'acu-shortcode', ACU_URL . 'assets/css/shortcode.css', [], ACU_VERSION );
		wp_enqueue_script( 'acu-shortcode', ACU_URL . 'assets/js/shortcode.js', [], ACU_VERSION, true );
		$page_id          = ACU_Helpers::find_anketa_page_id();
		$anketa_edit_base = $page_id ? get_permalink( $page_id ) : '';

		wp_localize_script( 'acu-shortcode', 'acuUdc', [
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'acu_udc_ajax' ),
			'anketa_edit_url' => $anketa_edit_base,
			'i18n'            => [
				'searching' => __( 'Searching…', 'acu' ),
				'no_query'  => __( 'Please enter a value to search.', 'acu' ),
				'error'     => __( 'Something went wrong. Please try again.', 'acu' ),
			],
		] );

		ob_start();
		?>
		<div class="wcu-udc wcu-udc--modern">
			<form method="post" class="wcu-udc__form" novalidate>
				<label for="acu_udc_query" class="wcu-udc__label"><?php esc_html_e( 'ტელეფონით, ელფოსტით, პირადი ID-ით ან კლუბის ბარათით ძიება', 'acu' ); ?></label>
				<div class="wcu-udc__input-group">
					<input type="text" id="acu_udc_query" name="acu_udc_query" class="wcu-udc__input"
						placeholder="<?php esc_attr_e( 'მაგ: user@example.com, +995 599..., 12345678901, CARD2024', 'acu' ); ?>" />
					<button type="submit" class="wcu-udc__btn"><?php esc_html_e( 'ძიება', 'acu' ); ?></button>
				</div>
			</form>

			<div class="wcu-udc__notice wcu-udc__notice--error" data-wcu-udc-error style="display:none;"></div>
			<div class="wcu-udc__loading" data-wcu-udc-loading style="display:none;">
				<span class="wcu-spinner" aria-hidden="true"></span>
				<span class="wcu-udc__loading-text"><?php esc_html_e( 'Searching…', 'acu' ); ?></span>
			</div>
			<div class="wcu-udc__results" data-wcu-udc-results></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// AJAX: user data search
	// -------------------------------------------------------------------------

	public static function ajax_udc_search(): void {
		check_ajax_referer( 'acu_udc_ajax', 'nonce' );

		if ( ! ACU_Helpers::current_user_can_manage_members() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'acu' ) ] );
		}

		$rate_key = 'acu_udc_rate_' . md5( ACU_Helpers::get_client_ip() );
		$hits     = (int) get_transient( $rate_key );
		if ( $hits >= 10 ) {
			wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait.', 'acu' ) ] );
		}
		set_transient( $rate_key, $hits + 1, 60 );

		$query = isset( $_POST['query'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['query'] ) ) ) : '';
		if ( $query === '' ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a value to search.', 'acu' ) ] );
		}

		$norm_phone = ACU_Helpers::is_phone_like( $query ) ? ACU_Helpers::normalize_phone( $query ) : '';
		$user       = null;

		// 1. Email
		if ( is_email( $query ) ) {
			$found = get_user_by( 'email', $query );
			if ( $found ) { $user = $found; }
		}

		// 2. Phone (registered user via billing_phone)
		if ( ! $user && $norm_phone !== '' && strlen( $norm_phone ) === 9 ) {
			$user = self::find_user_by_norm_phone( $norm_phone );
		}

		// 3. Personal ID / passport (user meta) — exact match, format-agnostic so
		//    passport numbers (e.g. "PD0361145") are searchable alongside 11-digit IDs.
		if ( ! $user ) {
			$users = get_users( [
				'meta_key'    => '_acu_personal_id',
				'meta_value'  => $query,
				'number'      => 1,
				'count_total' => false,
			] );
			if ( ! empty( $users ) ) { $user = $users[0]; }
		}

		// 4. Club card coupon (user meta)
		if ( ! $user ) {
			$users = get_users( [
				'meta_key'    => '_acu_club_card_coupon',
				'meta_value'  => $query,
				'number'      => 1,
				'count_total' => false,
			] );
			if ( ! empty( $users ) ) { $user = $users[0]; }
		}

		// 5. Match a coupon by query (title / cardholder INN / cardholder mobile / allowed_phones).
		$coupon_id = self::find_coupon_id_for_query( $query, $norm_phone );

		// If user found but no coupon by query, look for a user-linked coupon.
		if ( $user && ! $coupon_id ) {
			$coupon_id = self::find_coupon_id_for_user( (int) $user->ID );
		}

		// If coupon was found via cardholder fields but user not yet, resolve user via cardholder mobile, then via allowed_phones.
		if ( ! $user && $coupon_id ) {
			$cdata = self::get_coupon_data( $coupon_id );
			if ( strlen( $cdata['mobile'] ) === 9 ) {
				$user = self::find_user_by_norm_phone( $cdata['mobile'] );
			}
			if ( ! $user ) {
				foreach ( $cdata['allowed_phones'] as $allowed ) {
					$found = self::find_user_by_norm_phone( $allowed );
					if ( $found ) { $user = $found; break; }
				}
			}
		}

		// 6. External phone whitelist (only when nothing else matched).
		if ( ! $user && ! $coupon_id && $norm_phone !== '' && strlen( $norm_phone ) === 9 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'acu_external_phones';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $wpdb->get_var( $wpdb->prepare(
				"SELECT phone FROM {$table}
				 WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+995', '') = %s
				 LIMIT 1",
				$norm_phone
			) );
			if ( $found ) {
				wp_send_json_success( [ 'html' => self::render_external_phone_html( $norm_phone ) ] );
			}
		}

		if ( $user ) {
			$coupon_data = $coupon_id ? self::get_coupon_data( $coupon_id ) : null;
			wp_send_json_success( [ 'html' => self::render_result_html( $user, $coupon_data ) ] );
		}

		if ( $coupon_id ) {
			$coupon_data  = self::get_coupon_data( $coupon_id );
			$bridge_phone = $coupon_data['mobile'] !== ''
				? $coupon_data['mobile']
				: ( ! empty( $coupon_data['allowed_phones'] ) ? $coupon_data['allowed_phones'][0] : $norm_phone );
			wp_send_json_success( [ 'html' => self::render_coupon_result_html( $bridge_phone, $coupon_data ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'No matching user was found.', 'acu' ) ] );
	}

	// -------------------------------------------------------------------------
	// Lookup helpers
	// -------------------------------------------------------------------------

	private static function find_user_by_norm_phone( string $norm_phone ): ?WP_User {
		if ( strlen( $norm_phone ) !== 9 ) { return null; }
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$uid = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta}
			 WHERE meta_key = 'billing_phone'
			 AND REPLACE(REPLACE(REPLACE(meta_value, ' ', ''), '-', ''), '+995', '') LIKE %s
			 LIMIT 1",
			$norm_phone
		) );
		if ( ! $uid ) { return null; }
		$u = get_user_by( 'id', (int) $uid );
		return $u ?: null;
	}

	private static function find_coupon_id_for_query( string $query, string $norm_phone ): ?int {
		global $wpdb;

		// 1. Coupon code (post_title)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'shop_coupon' AND post_status = 'publish'
			 AND LOWER(post_title) = LOWER(%s)
			 LIMIT 1",
			$query
		) );
		if ( $id ) { return $id; }

		// 2. Cardholder personal ID / passport (_erp_sync_inn) — exact match, format-agnostic.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_erp_sync_inn' AND pm.meta_value = %s
			 AND p.post_type = 'shop_coupon' AND p.post_status = 'publish'
			 LIMIT 1",
			$query
		) );
		if ( $id ) { return $id; }

		// 3. Cardholder mobile (_erp_sync_mobile) — phone-normalized match
		if ( $norm_phone !== '' && strlen( $norm_phone ) === 9 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_erp_sync_mobile'
				 AND REPLACE(REPLACE(REPLACE(pm.meta_value, ' ', ''), '-', ''), '+995', '') LIKE %s
				 AND p.post_type = 'shop_coupon' AND p.post_status = 'publish'
				 LIMIT 1",
				$norm_phone
			) );
			if ( $id ) { return $id; }
		}

		// 4. Allowed phones list (legacy, comma-separated _erp_sync_allowed_phones)
		if ( $norm_phone !== '' && strlen( $norm_phone ) === 9 ) {
			$code = ACU_Helpers::find_coupon_by_phone( $norm_phone );
			if ( $code !== false ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'shop_coupon' AND post_status = 'publish'
					 AND LOWER(post_title) = LOWER(%s)
					 LIMIT 1",
					$code
				) );
				if ( $id ) { return $id; }
			}
		}

		return null;
	}

	public static function find_coupon_id_for_user( int $user_id ): ?int {
		global $wpdb;

		$club = (string) get_user_meta( $user_id, '_acu_club_card_coupon', true );
		if ( $club !== '' ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'shop_coupon' AND post_status = 'publish'
				 AND LOWER(post_title) = LOWER(%s)
				 LIMIT 1",
				$club
			) );
			if ( $id ) { return $id; }
		}

		$inn = (string) get_user_meta( $user_id, '_acu_personal_id', true );
		if ( $inn !== '' ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_erp_sync_inn' AND pm.meta_value = %s
				 AND p.post_type = 'shop_coupon' AND p.post_status = 'publish'
				 LIMIT 1",
				$inn
			) );
			if ( $id ) { return $id; }
		}

		$phone_n = ACU_Helpers::normalize_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		if ( strlen( $phone_n ) === 9 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_erp_sync_mobile'
				 AND REPLACE(REPLACE(REPLACE(pm.meta_value, ' ', ''), '-', ''), '+995', '') LIKE %s
				 AND p.post_type = 'shop_coupon' AND p.post_status = 'publish'
				 LIMIT 1",
				$phone_n
			) );
			if ( $id ) { return $id; }
		}

		return null;
	}

	/**
	 * Read all relevant fields from a coupon post.
	 *
	 * @return array{id:int,code:string,name:string,inn:string,mobile:string,dob:string,base_discount:string,discount_amount:float,allowed_phones:list<string>}
	 */
	public static function get_coupon_data( int $coupon_id ): array {
		$code = (string) get_post_field( 'post_title', $coupon_id );

		$mobile_raw = (string) get_post_meta( $coupon_id, '_erp_sync_mobile', true );
		$mobile     = ACU_Helpers::normalize_phone( $mobile_raw );

		$discount_amount = 0.0;
		if ( $code !== '' && function_exists( 'wc_get_coupon_id_by_code' ) ) {
			$wc              = new WC_Coupon( $code );
			$discount_amount = (float) $wc->get_amount();
		}

		$allowed_raw    = (string) get_post_meta( $coupon_id, '_erp_sync_allowed_phones', true );
		$allowed_phones = [];
		if ( $allowed_raw !== '' ) {
			foreach ( array_map( 'trim', explode( ',', $allowed_raw ) ) as $raw ) {
				$norm = ACU_Helpers::normalize_phone( $raw );
				if ( strlen( $norm ) === 9 ) { $allowed_phones[] = $norm; }
			}
		}

		return [
			'id'              => $coupon_id,
			'code'            => $code,
			'name'            => (string) get_post_meta( $coupon_id, '_erp_sync_name', true ),
			'inn'             => (string) get_post_meta( $coupon_id, '_erp_sync_inn', true ),
			'mobile'          => $mobile,
			'dob'             => (string) get_post_meta( $coupon_id, '_erp_sync_dob', true ),
			'base_discount'   => (string) get_post_meta( $coupon_id, '_erp_sync_base_discount', true ),
			'discount_amount' => $discount_amount,
			'allowed_phones'  => $allowed_phones,
		];
	}

	// -------------------------------------------------------------------------
	// Mix helpers (coupon-preferred, with user-side note on mismatch)
	// -------------------------------------------------------------------------

	private static function mix_value( string $user_val, string $coupon_val ): string {
		$u = trim( $user_val );
		$c = trim( $coupon_val );
		if ( $c === '' && $u === '' ) { return ''; }
		if ( $c === '' )              { return esc_html( $u ); }
		if ( $u === '' )              { return esc_html( $c ); }
		if ( strcasecmp( $u, $c ) === 0 ) { return esc_html( $c ); }
		return esc_html( $c ) . ' <small class="wcu-dl-note">(' . esc_html__( 'მომხმ.', 'acu' ) . ': ' . esc_html( $u ) . ')</small>';
	}

	private static function mix_phone_value( string $user_phone, string $user_phone_n, string $coupon_phone_n ): string {
		if ( $coupon_phone_n === '' && $user_phone_n === '' ) { return ''; }
		if ( $coupon_phone_n === '' )                         { return esc_html( $user_phone ); }
		if ( $user_phone_n === '' )                           { return esc_html( $coupon_phone_n ); }
		if ( $user_phone_n === $coupon_phone_n )              { return esc_html( $coupon_phone_n ); }
		return esc_html( $coupon_phone_n ) . ' <small class="wcu-dl-note">(' . esc_html__( 'მომხმ.', 'acu' ) . ': ' . esc_html( $user_phone ) . ')</small>';
	}

	private static function consent_badge( string $value ): string {
		if ( $value === 'yes' ) { return '<span class="wcu-badge wcu-badge--yes">' . esc_html__( 'ვეთანხმები', 'acu' ) . '</span>'; }
		if ( $value === 'no' )  { return '<span class="wcu-badge wcu-badge--no">'  . esc_html__( 'არ ვეთანხმები', 'acu' ) . '</span>'; }
		return '<span class="wcu-badge wcu-badge--unset">' . esc_html__( 'არ არის არჩეული', 'acu' ) . '</span>';
	}

	// -------------------------------------------------------------------------
	// Result HTML renderers
	// -------------------------------------------------------------------------

	private static function render_result_html( WP_User $user, ?array $coupon = null ): string {
		$user_id = $user->ID;

		$anketa_page_id   = ACU_Helpers::find_anketa_page_id();
		$anketa_edit_base = $anketa_page_id ? get_permalink( $anketa_page_id ) : '';
		$edit_anketa_url  = $anketa_edit_base !== '' ? add_query_arg( 'edit_user', $user_id, $anketa_edit_base ) : '';

		// User-side raw values
		$u_full_name = trim( $user->first_name . ' ' . $user->last_name );
		$u_email     = (string) $user->user_email;
		$u_phone     = ACU_Helpers::get_user_phone( $user_id );
		$u_phone_n   = ACU_Helpers::normalize_phone( $u_phone );
		$u_personal  = (string) get_user_meta( $user_id, '_acu_personal_id', true );
		$u_dob       = (string) get_user_meta( $user_id, '_acu_dob', true );
		$u_club_card = (string) get_user_meta( $user_id, '_acu_club_card_coupon', true );

		// Coupon-side values
		$c_name   = $coupon ? $coupon['name']            : '';
		$c_phone  = $coupon ? $coupon['mobile']          : '';
		$c_inn    = $coupon ? $coupon['inn']             : '';
		$c_dob    = $coupon ? $coupon['dob']             : '';
		$c_code   = $coupon ? $coupon['code']            : '';
		$c_amount = $coupon ? $coupon['discount_amount'] : 0.0;
		$c_base   = $coupon ? $coupon['base_discount']   : '';

		// Mix (coupon preferred; first/last name and Cardholder Name are shown as separate rows below)
		$phone_html    = self::mix_phone_value( $u_phone, $u_phone_n, $c_phone );
		$personal_html = self::mix_value( $u_personal, $c_inn );
		$dob_html      = self::mix_value( $u_dob, $c_dob );

		// Club card display: prefer coupon code if present, otherwise the user-stored code.
		$club_code = $c_code !== '' ? $c_code : $u_club_card;
		if ( $club_code !== '' ) {
			$club_amount = $c_amount;
			if ( $club_amount <= 0 && function_exists( 'wc_get_coupon_id_by_code' ) ) {
				$wc          = new WC_Coupon( $club_code );
				$club_amount = (float) $wc->get_amount();
			}
			$club_html = esc_html( $club_code );
			if ( $club_amount > 0 ) {
				$club_html .= ' &mdash; <strong>' . esc_html( $club_amount . '%' ) . '</strong>';
			}
		} else {
			$club_html = '';
		}

		$sms_consent  = ACU_Helpers::get_sms_consent( $user_id );
		$call_consent = ACU_Helpers::get_call_consent( $user_id );

		$labels = [
			'full_name'    => __( 'სახელი და გვარი', 'acu' ),
			'card_name'    => __( 'ბარათის მფლობელი', 'acu' ),
			'email'        => __( 'ელ.ფოსტა', 'acu' ),
			'phone'        => __( 'ტელეფონის ნომერი', 'acu' ),
			'personal'     => __( 'პირადი ნომერი', 'acu' ),
			'dob'          => __( 'დაბადების თარიღი', 'acu' ),
			'club_card'    => __( 'კლუბის ბარათი', 'acu' ),
			'base_disc'    => __( 'საბაზო ფასდაკლება', 'acu' ),
			'sms_consent'  => __( 'SMS შეტყობინებების მიღების თანხმობა', 'acu' ),
			'call_consent' => __( 'თანხმობა სატელეფონო ზარზე', 'acu' ),
		];
		$has_value = [
			'full_name'    => $u_full_name !== '',
			'email'        => $u_email !== '' && strpos( $u_email, '@no-email.local' ) === false,
			'phone'        => $u_phone !== '' || $c_phone !== '',
			'personal'     => $u_personal !== '' || $c_inn !== '',
			'dob'          => $u_dob !== '' || $c_dob !== '',
			'club_card'    => $club_code !== '',
			'sms_consent'  => in_array( $sms_consent, [ 'yes', 'no' ], true ),
			'call_consent' => in_array( $call_consent, [ 'yes', 'no' ], true ),
		];
		$filled = []; $missing = [];
		foreach ( $has_value as $k => $ok ) {
			if ( $ok ) { $filled[]  = $labels[ $k ]; }
			else       { $missing[] = $labels[ $k ]; }
		}

		$sms_display  = self::consent_badge( $sms_consent );
		$call_display = self::consent_badge( $call_consent );

		ob_start();
		?>
		<div class="wcu-udc-results-layout">

			<div class="wcu-udc-panel wcu-udc-panel--details">
				<div class="wcu-udc-panel__header">
					<h3>
						<?php esc_html_e( 'მომხმარებელი', 'acu' ); ?>
						<?php if ( $coupon ) : ?>
						<small style="font-weight:normal;font-size:0.8em;margin-left:0.5em;">
							(<?php echo esc_html( sprintf( /* translators: %s: coupon code */ __( 'Linked to coupon: %s', 'acu' ), $coupon['code'] ) ); ?>)
						</small>
						<?php endif; ?>
					</h3>
					<?php if ( ACU_Helpers::current_user_can_manage_members() ) : ?>
					<div class="wcu-udc-actions acu-edit-actions">
						<?php if ( $edit_anketa_url ) : ?>
						<a class="button button-secondary wcu-edit-anketa-btn" href="<?php echo esc_url( $edit_anketa_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit Anketa', 'acu' ); ?></a>
						<?php endif; ?>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'user_id', $user_id, home_url( '/print-anketa/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Anketa', 'acu' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'user_id' => $user_id, 'terms_type' => 'sms' ], home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'SMS Terms', 'acu' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'user_id' => $user_id, 'terms_type' => 'call' ], home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Call Terms', 'acu' ); ?></a>
					</div>
					<?php endif; ?>
				</div>
				<div class="wcu-udc-panel__body">
					<ul class="wcu-detail-list">
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['full_name'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $u_full_name !== '' ? esc_html( $u_full_name ) : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; ?></span>
						</li>
						<?php if ( $c_name !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['card_name'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $c_name ); ?></span>
						</li>
						<?php endif; ?>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['email'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $u_email && strpos( $u_email, '@no-email.local' ) === false ? esc_html( $u_email ) : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['phone'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $phone_html !== '' ? $phone_html : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['personal'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $personal_html !== '' ? $personal_html : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
						<?php if ( $u_dob !== '' || $c_dob !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['dob'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $dob_html !== '' ? $dob_html : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
						<?php endif; ?>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['club_card'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $club_html !== '' ? $club_html : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
						<?php if ( $coupon && $c_base !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['base_disc'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $c_base . '%' ); ?></span>
						</li>
						<?php endif; ?>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['sms_consent'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $sms_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['call_consent'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $call_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
					</ul>
				</div>
			</div>

			<div class="wcu-udc-panel wcu-udc-panel--status">
				<div class="wcu-udc-panel__header">
					<h3><?php esc_html_e( 'ინფორმაცია', 'acu' ); ?></h3>
				</div>
				<div class="wcu-udc-panel__body wcu-udc-status-body">
					<div class="wcu-status-section">
						<h4><?php esc_html_e( 'შევსებული ველები', 'acu' ); ?></h4>
						<div class="wcu-chip-row">
							<?php foreach ( $filled as $f ) : ?>
								<span class="wcu-chip wcu-chip--filled"><?php echo esc_html( $f ); ?></span>
							<?php endforeach; ?>
							<?php if ( empty( $filled ) ) : ?>
								<span class="wcu-empty-note"><?php esc_html_e( 'არაფერი', 'acu' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="wcu-status-section">
						<h4><?php esc_html_e( 'შეუვსებელი ველები', 'acu' ); ?></h4>
						<div class="wcu-chip-row">
							<?php foreach ( $missing as $m ) : ?>
								<span class="wcu-chip wcu-chip--missing"><?php echo esc_html( $m ); ?></span>
							<?php endforeach; ?>
							<?php if ( empty( $missing ) ) : ?>
								<span class="wcu-empty-note"><?php esc_html_e( 'არაფერი', 'acu' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_external_phone_html( string $phone ): string {
		ob_start();
		?>
		<div class="wcu-udc-results-layout">
			<div class="wcu-udc-panel wcu-udc-panel--details">
				<div class="wcu-udc-panel__header">
					<h3><?php esc_html_e( 'ნომერი SMS ბაზაში', 'acu' ); ?></h3>
				</div>
				<div class="wcu-udc-panel__body">
					<ul class="wcu-detail-list">
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'ტელეფონის ნომერი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $phone ); ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'სტატუსი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value">
								<span class="wcu-badge wcu-badge--warning"><?php esc_html_e( 'არ არის რეგისტრირებული', 'acu' ); ?></span>
							</span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'SMS თანხმობა', 'acu' ); ?>:</span>
							<span class="wcu-dl-value">
								<span class="wcu-badge wcu-badge--yes"><?php esc_html_e( 'კი', 'acu' ); ?></span>
							</span>
						</li>
					</ul>
					<div style="margin-top:1rem;padding:0.75rem;background:#fff3cd;border-left:4px solid #856404;color:#856404;">
						<strong><?php esc_html_e( 'ინფორმაცია:', 'acu' ); ?></strong>
						<?php esc_html_e( 'ნომერი ნაპოვნია SMS თანხმობის ბაზაში, მაგრამ არ არის რეგისტრირებული საიტზე.', 'acu' ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_coupon_result_html( string $bridge_phone, array $coupon ): string {
		$anketa_page_id = ACU_Helpers::find_anketa_page_id();
		$anketa_base    = $anketa_page_id ? get_permalink( $anketa_page_id ) : '';

		$register_url = $anketa_base !== '' && $bridge_phone !== ''
			? add_query_arg(
				[
					'prefill_phone' => rawurlencode( $bridge_phone ),
					'prefill_card'  => rawurlencode( $coupon['code'] ),
				],
				$anketa_base
			)
			: '';

		$has_sms_consent = false;
		if ( $bridge_phone !== '' ) {
			global $wpdb;
			$ext_table = $wpdb->prefix . 'acu_external_phones';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$in_whitelist = $wpdb->get_var( $wpdb->prepare(
				"SELECT phone FROM {$ext_table}
				 WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+995', '') = %s
				 LIMIT 1",
				$bridge_phone
			) );
			$has_sms_consent = ! empty( $in_whitelist );
		}

		$coupon_display = esc_html( $coupon['code'] );
		if ( $coupon['discount_amount'] > 0 ) {
			$coupon_display .= ' &mdash; <strong>' . esc_html( $coupon['discount_amount'] . '%' ) . '</strong>';
		}

		ob_start();
		?>
		<div class="wcu-udc-results-layout">
			<div class="wcu-udc-panel wcu-udc-panel--details">
				<div class="wcu-udc-panel__header">
					<h3>
						<?php esc_html_e( 'Unregistered User', 'acu' ); ?>
						<small style="font-weight:normal;font-size:0.8em;margin-left:0.5em;">
							(<?php echo esc_html( sprintf( /* translators: %s: coupon code */ __( 'Found via Coupon: %s', 'acu' ), $coupon['code'] ) ); ?>)
						</small>
					</h3>
					<?php if ( ACU_Helpers::current_user_can_manage_members() ) : ?>
					<div class="wcu-udc-actions acu-edit-actions">
						<?php if ( $register_url ) : ?>
						<a class="button button-secondary wcu-edit-anketa-btn" href="<?php echo esc_url( $register_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Register (Anketa)', 'acu' ); ?>
						</a>
						<?php endif; ?>
						<a class="button button-secondary" href="<?php echo esc_url( home_url( '/print-anketa/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Anketa', 'acu' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'terms_type', 'sms', home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'SMS Terms', 'acu' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'terms_type', 'call', home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Call Terms', 'acu' ); ?></a>
					</div>
					<?php endif; ?>
				</div>
				<div class="wcu-udc-panel__body">
					<ul class="wcu-detail-list">
						<?php if ( $coupon['name'] !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'ბარათის მფლობელი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $coupon['name'] ); ?></span>
						</li>
						<?php endif; ?>
						<?php if ( $coupon['inn'] !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'პირადი ნომერი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $coupon['inn'] ); ?></span>
						</li>
						<?php endif; ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'ტელეფონის ნომერი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $bridge_phone ); ?></span>
						</li>
						<?php if ( $coupon['dob'] !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'დაბადების თარიღი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $coupon['dob'] ); ?></span>
						</li>
						<?php endif; ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'კლუბის ბარათი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $coupon_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
						<?php if ( $coupon['base_discount'] !== '' ) : ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'საბაზო ფასდაკლება', 'acu' ); ?>:</span>
							<span class="wcu-dl-value"><?php echo esc_html( $coupon['base_discount'] . '%' ); ?></span>
						</li>
						<?php endif; ?>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'სტატუსი', 'acu' ); ?>:</span>
							<span class="wcu-dl-value">
								<span class="wcu-badge wcu-badge--warning"><?php esc_html_e( 'არ არის რეგისტრირებული', 'acu' ); ?></span>
							</span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php esc_html_e( 'SMS თანხმობა', 'acu' ); ?>:</span>
							<span class="wcu-dl-value">
								<?php if ( $has_sms_consent ) : ?>
									<span class="wcu-badge wcu-badge--yes"><?php esc_html_e( 'კი', 'acu' ); ?></span>
								<?php else : ?>
									<span class="wcu-badge wcu-badge--no"><?php esc_html_e( 'არა', 'acu' ); ?></span>
								<?php endif; ?>
							</span>
						</li>
					</ul>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [acm_print_terms_button] / [wcu_print_terms_button] shortcode
	// -------------------------------------------------------------------------

	public static function shortcode_print_terms_button( array $atts ): string {
		$atts = shortcode_atts( [
			'label' => __( 'Print Terms', 'acu' ),
			'class' => 'button',
			'type'  => 'default',
		], $atts, 'acm_print_terms_button' );

		$url = add_query_arg( 'terms_type', sanitize_key( $atts['type'] ), home_url( '/signature-terms/' ) );

		return sprintf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_attr( $atts['class'] ),
			esc_url( $url ),
			esc_html( $atts['label'] )
		);
	}
}
