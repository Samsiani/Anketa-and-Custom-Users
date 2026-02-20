<?php
/**
 * ACU_Shortcodes — [user_data_check] and print-terms shortcodes.
 *
 * Shortcodes registered:
 *  [user_data_check]        — staff search form (email/phone/personal ID/club card/external phones)
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

		add_action( 'wp_ajax_acu_udc_search',        [ self::class, 'ajax_udc_search' ] );
		add_action( 'wp_ajax_nopriv_acu_udc_search', [ self::class, 'ajax_udc_search' ] );
	}

	// -------------------------------------------------------------------------
	// [user_data_check] shortcode
	// -------------------------------------------------------------------------

	public static function shortcode_user_data_check(): string {
		wp_enqueue_style( 'acu-shortcode', ACU_URL . 'assets/css/shortcode.css', [], ACU_VERSION );
		wp_enqueue_script( 'acu-shortcode', ACU_URL . 'assets/js/shortcode.js', [], ACU_VERSION, true );
		// Find the page that hosts [club_anketa_form] so the Edit button has a URL.
		// Result is cached in a static so the DB hit only happens once per request.
		static $anketa_edit_base = null;
		if ( $anketa_edit_base === null ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$page_id = $wpdb->get_var(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_content LIKE '%club_anketa_form%'
				   AND post_type = 'page'
				   AND post_status = 'publish'
				 LIMIT 1"
			);
			$anketa_edit_base = $page_id ? get_permalink( (int) $page_id ) : '';
		}

		wp_localize_script( 'acu-shortcode', 'acuUdc', [
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'acu_udc_ajax' ),
			'anketa_edit_url' => $anketa_edit_base, // base URL of the anketa form page (edit_user=ID appended server-side)
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

		// Rate limiting: 10 requests per 60 seconds per IP
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

		$result = null;

		// 1. Email
		if ( is_email( $query ) ) {
			$result = get_user_by( 'email', $query );
		}

		// 2. Phone — format-agnostic: SQL REPLACE strips spaces, dashes, +995 before comparing.
		//    Handles legacy rows stored as "+995 599620303", "599-62-03-03", etc.
		if ( ! $result && ACU_Helpers::is_phone_like( $query ) ) {
			$norm = ACU_Helpers::normalize_phone( $query );
			if ( $norm !== '' ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta}
					 WHERE meta_key = 'billing_phone'
					 AND REPLACE(REPLACE(REPLACE(meta_value, ' ', ''), '-', ''), '+995', '') LIKE %s
					 LIMIT 1",
					$norm
				) );
				if ( ! empty( $user_ids ) ) {
					$result = get_user_by( 'id', (int) $user_ids[0] );
				}
			}
		}

		// 3. Personal ID
		if ( ! $result && ACU_Helpers::validate_personal_id( $query ) ) {
			$users = get_users( [
				'meta_key'    => '_acu_personal_id',
				'meta_value'  => $query,
				'number'      => 1,
				'count_total' => false,
			] );
			if ( ! empty( $users ) ) {
				$result = $users[0];
			}
		}

		// 4. Club card coupon
		if ( ! $result ) {
			$users = get_users( [
				'meta_key'    => '_acu_club_card_coupon',
				'meta_value'  => $query,
				'number'      => 1,
				'count_total' => false,
			] );
			if ( ! empty( $users ) ) {
				$result = $users[0];
			}
		}

		// 5. External phone whitelist — format-agnostic REPLACE match
		if ( ! $result && ACU_Helpers::is_phone_like( $query ) ) {
			$norm = ACU_Helpers::normalize_phone( $query );
			if ( $norm !== '' && strlen( $norm ) === 9 ) {
				global $wpdb;
				$table = $wpdb->prefix . 'acu_external_phones';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$found = $wpdb->get_var( $wpdb->prepare(
					"SELECT phone FROM {$table}
					 WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+995', '') = %s
					 LIMIT 1",
					$norm
				) );
				if ( $found ) {
					wp_send_json_success( [ 'html' => self::render_external_phone_html( $norm ) ] );
				}
			}
		}

		if ( ! $result ) {
			wp_send_json_error( [ 'message' => __( 'No matching user was found.', 'acu' ) ] );
		}

		wp_send_json_success( [ 'html' => self::render_result_html( $result ) ] );
	}

	// -------------------------------------------------------------------------
	// Result HTML renderers
	// -------------------------------------------------------------------------

	private static function render_result_html( WP_User $user ): string {
		$user_id = $user->ID;

		// Build Edit Anketa URL (requires edit_users capability check in the form itself)
		static $anketa_edit_base = null;
		if ( $anketa_edit_base === null ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$page_id = $wpdb->get_var(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_content LIKE '%club_anketa_form%'
				   AND post_type = 'page'
				   AND post_status = 'publish'
				 LIMIT 1"
			);
			$anketa_edit_base = $page_id ? get_permalink( (int) $page_id ) : '';
		}
		$edit_anketa_url = $anketa_edit_base !== ''
			? add_query_arg( 'edit_user', $user_id, $anketa_edit_base )
			: '';

		$data = [
			'email'      => $user->user_email,
			'phone'      => ACU_Helpers::get_user_phone( $user_id ),
			'personal'   => (string) get_user_meta( $user_id, '_acu_personal_id', true ),
			'club_card'  => (string) get_user_meta( $user_id, '_acu_club_card_coupon', true ),
			'sms_consent'=> ACU_Helpers::get_sms_consent( $user_id ),
		];

		$labels = [
			'email'      => 'ელ.ფოსტა',
			'phone'      => 'ტელეფონის ნომერი',
			'personal'   => 'პირადი ნომერი',
			'club_card'  => 'კლუბის ბარათი',
			'sms_consent'=> 'SMS თანხმობა',
		];

		$filled  = [];
		$missing = [];

		foreach ( $data as $key => $value ) {
			if ( $key === 'sms_consent' ) {
				if ( $value === 'yes' || $value === 'no' ) {
					$filled[] = $labels[ $key ];
				} else {
					$missing[] = $labels[ $key ];
				}
				continue;
			}
			if ( $value !== '' ) {
				$filled[] = $labels[ $key ];
			} else {
				$missing[] = $labels[ $key ];
			}
		}

		if ( $data['sms_consent'] === 'yes' ) {
			$sms_display = '<span class="wcu-badge wcu-badge--yes">' . esc_html__( 'ვეთანხმები', 'acu' ) . '</span>';
		} elseif ( $data['sms_consent'] === 'no' ) {
			$sms_display = '<span class="wcu-badge wcu-badge--no">' . esc_html__( 'არ ვეთანხმები', 'acu' ) . '</span>';
		} else {
			$sms_display = '<span class="wcu-badge wcu-badge--unset">' . esc_html__( 'არ არის არჩეული', 'acu' ) . '</span>';
		}

		ob_start();
		?>
		<div class="wcu-udc-results-layout">

			<div class="wcu-udc-panel wcu-udc-panel--details">
				<div class="wcu-udc-panel__header">
					<h3><?php esc_html_e( 'მომხმარებელი', 'acu' ); ?></h3>
					<?php if ( $edit_anketa_url && current_user_can( 'edit_users' ) ) : ?>
					<a class="button button-secondary wcu-edit-anketa-btn" href="<?php echo esc_url( $edit_anketa_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Edit Anketa', 'acu' ); ?>
					</a>
					<?php endif; ?>
				</div>
				<div class="wcu-udc-panel__body">
					<ul class="wcu-detail-list">
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['email'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $data['email'] ? esc_html( $data['email'] ) : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['phone'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $data['phone'] ? esc_html( $data['phone'] ) : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['personal'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $data['personal'] ? esc_html( $data['personal'] ) : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['club_card'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $data['club_card'] ? esc_html( $data['club_card'] ) : '<em>' . esc_html__( 'არ არის', 'acu' ) . '</em>'; ?></span>
						</li>
						<li>
							<span class="wcu-dl-label"><?php echo esc_html( $labels['sms_consent'] ); ?>:</span>
							<span class="wcu-dl-value"><?php echo $sms_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
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
								<span class="wcu-badge wcu-badge--yes"><?php esc_html_e( 'თანხმობა', 'acu' ); ?></span>
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
