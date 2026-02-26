<?php
/**
 * ACU_Registration — [club_anketa_form] shortcode + form processor.
 *
 * Backward-compatible: shortcode name [club_anketa_form] is preserved.
 *
 * Security:
 *  - Nonce verification
 *  - Honeypot anti-spam
 *  - Required field validation
 *  - Phone uniqueness check
 *  - Email uniqueness check
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Registration {

	private static array $errors       = [];
	private static array $old          = [];
	private static int   $edit_user_id = 0; // 0 = create mode; >0 = edit mode

	public static function init(): void {
		add_shortcode( 'club_anketa_form', [ self::class, 'shortcode_form' ] );
		// Process early so we can redirect before any output
		add_action( 'template_redirect', [ self::class, 'maybe_process_submission' ], 1 );

		// OTP AJAX endpoints
		add_action( 'wp_ajax_acu_send_otp',         [ 'ACU_OTP', 'ajax_send_otp' ] );
		add_action( 'wp_ajax_nopriv_acu_send_otp',  [ 'ACU_OTP', 'ajax_send_otp' ] );
		add_action( 'wp_ajax_acu_verify_otp',        [ 'ACU_OTP', 'ajax_verify_otp' ] );
		add_action( 'wp_ajax_nopriv_acu_verify_otp', [ 'ACU_OTP', 'ajax_verify_otp' ] );

		// Script enqueue
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_scripts' ] );
	}

	// -------------------------------------------------------------------------
	// Asset enqueue
	// -------------------------------------------------------------------------

	public static function enqueue_scripts(): void {
		global $post;

		$is_checkout    = function_exists( 'is_checkout' )     && is_checkout();
		$is_account     = function_exists( 'is_account_page' ) && is_account_page();
		$has_form       = $post && has_shortcode( $post->post_content, 'club_anketa_form' );
		$load_otp       = $is_checkout || $is_account || $has_form;

		if ( ! $load_otp ) {
			return;
		}

		wp_enqueue_style(
			'acu-frontend',
			ACU_URL . 'assets/css/frontend.css',
			[],
			ACU_VERSION
		);

		wp_enqueue_script(
			'acu-sms-verification',
			ACU_URL . 'assets/js/sms-verification.js',
			[ 'jquery' ],
			ACU_VERSION,
			true
		);

		$verified_phone = '';
		if ( is_user_logged_in() ) {
			$verified_phone = (string) get_user_meta( get_current_user_id(), '_acu_verified_phone', true );
		}

		wp_localize_script( 'acu-sms-verification', 'acuSms', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'acu_sms_nonce' ),
			'verifiedPhone' => $verified_phone,
			'i18n'          => [
				'sendingOtp'           => __( 'იგზავნება...', 'acu' ),
				'enterCode'            => __( 'შეიყვანეთ 6-ნიშნა კოდი', 'acu' ),
				'verifying'            => __( 'მოწმდება...', 'acu' ),
				'verified'             => __( 'დადასტურებულია!', 'acu' ),
				'error'                => __( 'შეცდომა', 'acu' ),
				'invalidCode'          => __( 'არასწორი კოდი', 'acu' ),
				'codeExpired'          => __( 'კოდის ვადა ამოიწურა', 'acu' ),
				'resendIn'             => __( 'ხელახლა გაგზავნა:', 'acu' ),
				'resend'               => __( 'ხელახლა გაგზავნა', 'acu' ),
				'close'                => __( 'დახურვა', 'acu' ),
				'verify'               => __( 'დადასტურება', 'acu' ),
				'verifyBtn'            => __( 'Verify', 'acu' ),
				'phoneRequired'        => __( 'ტელეფონის ნომერი სავალდებულოა', 'acu' ),
				'rateLimitError'       => __( 'ზედმეტად ბევრი მცდელობა. გთხოვთ სცადეთ მოგვიანებით.', 'acu' ),
				'modalTitle'           => __( 'ტელეფონის ვერიფიკაცია', 'acu' ),
				'modalSubtitle'        => __( 'SMS კოდი გამოგზავნილია ნომერზე:', 'acu' ),
				'verificationRequired' => __( 'ტელეფონის ვერიფიკაცია სავალდებულოა', 'acu' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Form submission processor
	// -------------------------------------------------------------------------

	public static function maybe_process_submission(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['acu_form_submitted'] ) ) {
			return;
		}

		// Nonce
		if (
			empty( $_POST['acu_form_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['acu_form_nonce'] ) ), 'acu_register' )
		) {
			return;
		}

		// Honeypot
		$honeypot = isset( $_POST['acu_security_field'] ) ? trim( (string) $_POST['acu_security_field'] ) : '';
		if ( $honeypot !== '' ) {
			return;
		}

		// Auth gate — staff only (both create and edit mode)
		if ( ! ACU_Helpers::current_user_can_manage_members() ) {
			return;
		}

		// Detect edit mode
		self::$edit_user_id = isset( $_POST['acu_edit_user_id'] ) ? absint( $_POST['acu_edit_user_id'] ) : 0;
		if ( self::$edit_user_id > 0 ) {
			if ( ! current_user_can( 'edit_users' ) ) {
				self::$errors[] = __( 'You do not have permission to edit users.', 'acu' );
				return;
			}
			if ( ! get_user_by( 'ID', self::$edit_user_id ) ) {
				self::$errors[] = __( 'User not found.', 'acu' );
				return;
			}
		}

		// Collect and sanitize inputs
		$data = self::collect_form_data();
		self::$old = $data;

		// Validate
		self::validate_form_data( $data );

		if ( ! empty( self::$errors ) ) {
			return;
		}

		// Normalize consents
		$sms_consent = in_array( strtolower( $data['anketa_sms_consent'] ), [ 'yes', 'no' ], true )
			? strtolower( $data['anketa_sms_consent'] ) : 'yes';
		$call_consent = in_array( strtolower( $data['anketa_call_consent'] ), [ 'yes', 'no' ], true )
			? strtolower( $data['anketa_call_consent'] ) : 'yes';

		$local_digits = ACU_Helpers::normalize_phone( $data['anketa_phone_local'] );

		// OTP verification — preserved from stored meta in edit mode, checked from token in create mode
		$otp_token      = isset( $_POST['otp_verification_token'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_verification_token'] ) ) : '';
		$phone_verified = self::$edit_user_id > 0
			? (bool) get_user_meta( self::$edit_user_id, '_acu_verified_phone', true )
			: ACU_OTP::is_phone_verified( $local_digits, $otp_token );

		if ( self::$edit_user_id > 0 ) {
			// ── Edit existing user ────────────────────────────────────────────
			$user_id     = self::$edit_user_id;
			$update_data = [
				'ID'           => $user_id,
				'first_name'   => $data['anketa_first_name'],
				'last_name'    => $data['anketa_last_name'],
				'display_name' => trim( $data['anketa_first_name'] . ' ' . $data['anketa_last_name'] ),
			];
			// Real email → save it. Cleared → revert to dummy so WP user_email is never stale.
			$update_data['user_email'] = $data['anketa_email'] !== ''
				? $data['anketa_email']
				: $local_digits . '@no-email.local';
			$result = wp_update_user( $update_data );
			if ( is_wp_error( $result ) ) {
				self::$errors[] = $result->get_error_message();
				return;
			}
		} else {
			// ── Create new user ───────────────────────────────────────────────
			$password     = wp_generate_password( 18, true, true );
			$insert_email = $data['anketa_email'] !== ''
				? $data['anketa_email']
				: $local_digits . '@no-email.local';
			$user_id = wp_insert_user( [
				'user_login'   => $local_digits,
				'user_email'   => $insert_email,
				'user_pass'    => $password,
				'first_name'   => $data['anketa_first_name'],
				'last_name'    => $data['anketa_last_name'],
				'display_name' => trim( $data['anketa_first_name'] . ' ' . $data['anketa_last_name'] ),
			] );
			if ( is_wp_error( $user_id ) ) {
				self::$errors[] = $user_id->get_error_message();
				return;
			}
		}

		// Save / update meta — billing_phone stored as strict 9-digit string
		$meta_map = [
			'billing_phone'           => $local_digits,
			'billing_address_1'       => $data['anketa_address'],
			'_acu_personal_id'        => $data['anketa_personal_id'],
			'_acu_dob'                => $data['anketa_dob'],
			'_acu_card_no'            => $data['anketa_card_no'],
			'_acu_responsible_person' => $data['anketa_responsible_person'],
			'_acu_form_date'          => $data['anketa_form_date'],
			'_acu_shop'               => $data['anketa_shop'],
			'_sms_consent'            => $sms_consent,
			'_call_consent'           => $call_consent,
			'_acu_verified_phone'     => $phone_verified ? $local_digits : '',
		];
		foreach ( $meta_map as $key => $val ) {
			// Edit mode: always update (even to clear a field). Create mode: skip empty strings.
			if ( self::$edit_user_id > 0 || $val !== '' ) {
				update_user_meta( $user_id, $key, $val );
			}
		}

		// Clean up OTP token after use (create mode only)
		if ( self::$edit_user_id === 0 && $phone_verified ) {
			ACU_OTP::cleanup( $local_digits );
		}

		// Link club card coupon
		ACU_Helpers::link_coupon_to_user( $user_id );

		// Admin notification
		$old_sms = self::$edit_user_id > 0
			? ACU_Helpers::get_sms_consent( self::$edit_user_id )
			: '';
		ACU_Helpers::maybe_send_consent_notification(
			$user_id,
			$old_sms,
			$sms_consent,
			self::$edit_user_id > 0 ? 'anketa_edit' : 'anketa_registration'
		);

		// Redirect to print page
		$url = home_url( '/print-anketa/?user_id=' . absint( $user_id ) );
		wp_safe_redirect( $url );
		exit;
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public static function shortcode_form(): string {
		// ── Auth gate — staff only ────────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			return ACU_Helpers::render_login_form();
		}
		if ( ! ACU_Helpers::current_user_can_manage_members() ) {
			return '<p class="wcu-error">' . esc_html__( 'You do not have permission to access this form.', 'acu' ) . '</p>';
		}

		// ── Edit mode detection ───────────────────────────────────────────────
		$edit_user_id = isset( $_GET['edit_user'] ) ? absint( $_GET['edit_user'] ) : 0;
		$edit_user    = $edit_user_id ? get_user_by( 'ID', $edit_user_id ) : false;
		$is_edit      = (bool) $edit_user;

		if ( $is_edit && ! current_user_can( 'edit_users' ) ) {
			return '<p class="wcu-error">' . esc_html__( 'You do not have permission to edit users.', 'acu' ) . '</p>';
		}

		$errors = self::$errors;
		$old    = self::$old;

		// Pre-fill form from existing user data (first page-load only; POST values take priority on re-render after error)
		if ( $is_edit && empty( $old ) ) {
			$uid       = $edit_user->ID;
			$raw_phone = (string) get_user_meta( $uid, 'billing_phone', true );
			$old = [
				'anketa_personal_id'        => (string) get_user_meta( $uid, '_acu_personal_id', true ),
				'anketa_first_name'         => $edit_user->first_name,
				'anketa_last_name'          => $edit_user->last_name,
				'anketa_dob'                => (string) get_user_meta( $uid, '_acu_dob', true ),
				'anketa_phone_local'        => ACU_Helpers::normalize_phone( $raw_phone ),
				'anketa_address'            => (string) get_user_meta( $uid, 'billing_address_1', true ),
				'anketa_email'              => str_ends_with( $edit_user->user_email, '@no-email.local' ) ? '' : $edit_user->user_email,
				'anketa_card_no'            => (string) get_user_meta( $uid, '_acu_card_no', true ),
				'anketa_responsible_person' => (string) get_user_meta( $uid, '_acu_responsible_person', true ),
				'anketa_form_date'          => (string) get_user_meta( $uid, '_acu_form_date', true ),
				'anketa_shop'               => (string) get_user_meta( $uid, '_acu_shop', true ),
				'anketa_sms_consent'        => (string) get_user_meta( $uid, '_sms_consent', true ) ?: 'yes',
				'anketa_call_consent'       => (string) get_user_meta( $uid, '_call_consent', true ) ?: 'yes',
			];
		}

		// Pre-fill phone from URL — coupon registration bridge (?prefill_phone=XXXXXXXXX).
		// Only applied in create mode and only when the field hasn't been filled yet
		// (e.g. POST re-render after a validation error takes priority).
		if ( ! $is_edit && isset( $_GET['prefill_phone'] ) ) {
			$prefill_phone = ACU_Helpers::normalize_phone( sanitize_text_field( wp_unslash( $_GET['prefill_phone'] ) ) );
			if ( $prefill_phone !== '' && empty( $old['anketa_phone_local'] ) ) {
				$old['anketa_phone_local'] = $prefill_phone;
			}
		}

		$v = static function ( string $key ) use ( $old ): string {
			return isset( $old[ $key ] ) ? esc_attr( $old[ $key ] ) : '';
		};

		$sms_old = isset( $old['anketa_sms_consent'] ) ? strtolower( $old['anketa_sms_consent'] ) : 'yes';
		if ( ! in_array( $sms_old, [ 'yes', 'no' ], true ) ) {
			$sms_old = 'yes';
		}
		$call_old = isset( $old['anketa_call_consent'] ) ? strtolower( $old['anketa_call_consent'] ) : 'yes';
		if ( ! in_array( $call_old, [ 'yes', 'no' ], true ) ) {
			$call_old = 'yes';
		}

		$rules_html   = apply_filters( 'acu_anketa_rules_text', self::default_rules_html() );
		$submit_label = $is_edit ? __( 'განახლება', 'acu' ) : 'რეგისტრაცია';

		ob_start();
		?>
		<div class="acu-form-wrap club-anketa-form-wrap">

			<?php if ( $is_edit ) : ?>
			<div class="acu-edit-actions">
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'edit_user', $edit_user_id, get_permalink() ) ); ?>"><?php esc_html_e( 'Edit Anketa', 'acu' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'user_id', $edit_user_id, home_url( '/print-anketa/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print Anketa', 'acu' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'user_id' => $edit_user_id, 'terms_type' => 'sms' ], home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print SMS Terms', 'acu' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'user_id' => $edit_user_id, 'terms_type' => 'call' ], home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print Call Terms', 'acu' ); ?></a>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="club-anketa-errors" role="alert">
					<?php foreach ( $errors as $e ) : ?>
						<div><?php echo esc_html( $e ); ?></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form class="club-anketa-form" method="post" action="">
				<?php wp_nonce_field( 'acu_register', 'acu_form_nonce' ); ?>
				<input type="hidden" name="acu_form_submitted" value="1" />
				<?php if ( $is_edit ) : ?>
				<input type="hidden" name="acu_edit_user_id" value="<?php echo esc_attr( $edit_user_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />
				<div class="club-anketa-hp">
					<label for="acu_security_field">Leave this empty</label>
					<input type="text" id="acu_security_field" name="acu_security_field" value="" autocomplete="off" tabindex="-1" />
				</div>

				<!-- ── Section: Personal Info ── -->
				<div class="acu-section">
					<div class="acu-section__header">
						<span class="acu-section__icon">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
						</span>
						<span class="acu-section__label">პირადი ინფორმაცია</span>
					</div>
					<div class="acu-grid-2">
						<div class="acu-field">
							<label for="anketa_personal_id">პირადი ნომერი <span class="acu-required" aria-hidden="true">*</span></label>
							<input type="text" id="anketa_personal_id" name="anketa_personal_id" required
								placeholder="11-ნიშნა კოდი"
								value="<?php echo $v( 'anketa_personal_id' ); ?>" />
						</div>
						<div class="acu-field">
							<label for="anketa_first_name">სახელი <span class="acu-required" aria-hidden="true">*</span></label>
							<input type="text" id="anketa_first_name" name="anketa_first_name" required value="<?php echo $v( 'anketa_first_name' ); ?>" />
						</div>
						<div class="acu-field">
							<label for="anketa_last_name">გვარი <span class="acu-required" aria-hidden="true">*</span></label>
							<input type="text" id="anketa_last_name" name="anketa_last_name" required value="<?php echo $v( 'anketa_last_name' ); ?>" />
						</div>
						<div class="acu-field">
							<label for="anketa_dob">დაბადების თარიღი <span class="acu-required" aria-hidden="true">*</span></label>
							<input type="date" id="anketa_dob" name="anketa_dob" required value="<?php echo $v( 'anketa_dob' ); ?>" />
						</div>
					</div>
				</div>

				<!-- ── Section: Contact ── -->
				<div class="acu-section">
					<div class="acu-section__header">
						<span class="acu-section__icon">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.72 16.92z"/></svg>
						</span>
						<span class="acu-section__label">საკონტაქტო ინფორმაცია</span>
					</div>
					<div class="acu-grid-1">
						<div class="acu-field acu-phone-field">
							<div class="acu-phone-label-row">
								<label for="anketa_phone_local">ტელეფონის ნომერი <span class="acu-required" aria-hidden="true">*</span></label>
								<span class="acu-verify-badge">ვერიფიკაცია სავალდებულოა</span>
							</div>
							<div class="acu-phone-wrap phone-verify-group">
								<div class="acu-phone-input-row phone-group">
									<input class="phone-prefix" type="text" value="+995" readonly tabindex="-1" aria-hidden="true" />
									<input class="phone-local" type="tel" id="anketa_phone_local" name="anketa_phone_local"
										inputmode="numeric" pattern="[0-9]{9}" maxlength="9" placeholder="599 000 000"
										required value="<?php echo $v( 'anketa_phone_local' ); ?>" />
								</div>
								<div class="acu-phone-verify-row phone-verify-container">
									<button type="button" class="phone-verify-btn" aria-label="<?php esc_attr_e( 'Verify phone number via SMS', 'acu' ); ?>">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.72 16.92z"/></svg>
										SMS-ით ვერიფიკაცია
									</button>
									<span class="phone-verified-icon" style="display:none;" aria-label="<?php esc_attr_e( 'Phone verified', 'acu' ); ?>">
										<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
										ტელეფონი დადასტურებულია
									</span>
								</div>
							</div>
						</div>
						<div class="acu-grid-2">
							<div class="acu-field">
								<label for="anketa_address">მისამართი <span class="acu-optional">ოფციური</span></label>
								<input type="text" id="anketa_address" name="anketa_address" value="<?php echo $v( 'anketa_address' ); ?>" />
							</div>
							<div class="acu-field">
								<label for="anketa_email">E-mail <span class="acu-optional">ოფციური</span></label>
								<input type="email" id="anketa_email" name="anketa_email"
									placeholder="საიტზე რეგისტრაციისთვის"
									value="<?php echo $v( 'anketa_email' ); ?>" />
							</div>
						</div>
					</div>
				</div>

				<!-- ── Section: Club Card (staff fields) ── -->
				<div class="acu-section">
					<div class="acu-section__header">
						<span class="acu-section__icon">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
						</span>
						<span class="acu-section__label">კლუბის ბარათი</span>
					</div>
					<div class="acu-grid-2">
						<div class="acu-field">
							<label for="anketa_card_no">მივიღე ბარათი № <span class="acu-optional">ოფციური</span></label>
							<input type="text" id="anketa_card_no" name="anketa_card_no" value="<?php echo $v( 'anketa_card_no' ); ?>" />
						</div>
						<div class="acu-field">
							<label for="anketa_responsible_person">პასუხისმგებელი პირი <span class="acu-optional">ოფციური</span></label>
							<input type="text" id="anketa_responsible_person" name="anketa_responsible_person" value="<?php echo $v( 'anketa_responsible_person' ); ?>" />
						</div>
						<div class="acu-field">
							<label for="anketa_form_date">თარიღი <span class="acu-optional">ოფციური</span></label>
							<input type="date" id="anketa_form_date" name="anketa_form_date" value="<?php echo $v( 'anketa_form_date' ); ?>" />
						</div>
						<div class="acu-field">
							<label for="anketa_shop">მაღაზია <span class="acu-optional">ოფციური</span></label>
							<input type="text" id="anketa_shop" name="anketa_shop" value="<?php echo $v( 'anketa_shop' ); ?>" />
						</div>
					</div>
				</div>

				<!-- ── Section: Rules ── -->
				<div class="acu-section">
					<div class="acu-section__header">
						<span class="acu-section__icon">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
						</span>
						<span class="acu-section__label">წესები და პირობები</span>
					</div>
					<div class="acu-rules-block rules-wrap">
						<div class="rules-text"><?php echo wp_kses_post( $rules_html ); ?></div>
					</div>
				</div>

				<!-- ── Section: Consent ── -->
				<div class="acu-section">
					<div class="acu-section__header">
						<span class="acu-section__icon">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
						</span>
						<span class="acu-section__label">თანხმობა</span>
					</div>

					<div class="acu-consent-row club-anketa-sms-consent" data-context="registration">
						<span class="acu-consent-label">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline;vertical-align:-1px;margin-right:5px;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
							SMS შეტყობინებების მიღება
						</span>
						<div class="acu-consent-toggle">
							<input type="radio" name="anketa_sms_consent" id="acu_sms_yes" value="yes" <?php checked( $sms_old, 'yes' ); ?> />
							<label for="acu_sms_yes">დიახ</label>
							<input type="radio" name="anketa_sms_consent" id="acu_sms_no" value="no" <?php checked( $sms_old, 'no' ); ?> />
							<label for="acu_sms_no">არა</label>
						</div>
					</div>

					<div class="acu-consent-row club-anketa-sms-consent" data-context="registration">
						<span class="acu-consent-label">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline;vertical-align:-1px;margin-right:5px;flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.72 16.92z"/></svg>
							<?php esc_html_e( 'თანხმობა სატელეფონო ზარზე', 'acu' ); ?>
						</span>
						<div class="acu-consent-toggle">
							<input type="radio" name="anketa_call_consent" id="acu_call_yes" value="yes" <?php checked( $call_old, 'yes' ); ?> />
							<label for="acu_call_yes"><?php esc_html_e( 'დიახ', 'acu' ); ?></label>
							<input type="radio" name="anketa_call_consent" id="acu_call_no" value="no" <?php checked( $call_old, 'no' ); ?> />
							<label for="acu_call_no"><?php esc_html_e( 'არა', 'acu' ); ?></label>
						</div>
					</div>
				</div>

				<!-- ── Submit ── -->
				<div class="acu-submit-row">
					<button type="submit" class="acu-submit-btn submit-btn"><?php echo esc_html( $submit_label ); ?></button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private static function collect_form_data(): array {
		$fields = [
			'anketa_personal_id'        => 'text',
			'anketa_first_name'         => 'text',
			'anketa_last_name'          => 'text',
			'anketa_dob'                => 'date',
			'anketa_phone_local'        => 'tel',
			'anketa_address'            => 'text',
			'anketa_email'              => 'email',
			'anketa_card_no'            => 'text',
			'anketa_responsible_person' => 'text',
			'anketa_form_date'          => 'date',
			'anketa_shop'               => 'text',
			'anketa_sms_consent'        => 'text',
			'anketa_call_consent'       => 'text',
		];

		$data = [];
		foreach ( $fields as $key => $type ) {
			$raw        = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$data[$key] = self::sanitize_by_type( (string) $raw, $type );
		}
		return $data;
	}

	private static function validate_form_data( array $data ): void {
		$required = [
			'anketa_personal_id' => __( 'Personal ID is required.', 'acu' ),
			'anketa_first_name'  => __( 'First name is required.', 'acu' ),
			'anketa_last_name'   => __( 'Last name is required.', 'acu' ),
			'anketa_dob'         => __( 'Date of birth is required.', 'acu' ),
			'anketa_phone_local' => __( 'Phone number is required.', 'acu' ),
		];

		foreach ( $required as $key => $message ) {
			if ( $data[ $key ] === '' ) {
				self::$errors[] = $message;
			}
		}

		if ( $data['anketa_email'] !== '' && ! is_email( $data['anketa_email'] ) ) {
			self::$errors[] = __( 'Please enter a valid email address.', 'acu' );
		}

		// Validate personal ID format — skip in edit mode if the value hasn't changed
		// (prevents blocking staff from saving other fields when legacy dirty data exists).
		$stored_pid  = self::$edit_user_id > 0
			? (string) get_user_meta( self::$edit_user_id, '_acu_personal_id', true )
			: '';
		$pid_changed = $data['anketa_personal_id'] !== $stored_pid;
		if ( $data['anketa_personal_id'] !== '' && $pid_changed
			&& ! ACU_Helpers::validate_personal_id( $data['anketa_personal_id'] ) ) {
			self::$errors[] = __( 'Personal ID must be exactly 11 digits.', 'acu' );
		}

		if ( $data['anketa_dob'] !== '' ) {
			$dob_parsed = DateTime::createFromFormat( 'Y-m-d', $data['anketa_dob'] )
						?: DateTime::createFromFormat( 'd/m/Y', $data['anketa_dob'] )
						?: DateTime::createFromFormat( 'd.m.Y', $data['anketa_dob'] );
			if ( $dob_parsed === false ) {
				self::$errors[] = __( 'Date of birth format is invalid.', 'acu' );
			}
		}

		$local_digits = preg_replace( '/\D+/', '', (string) $data['anketa_phone_local'] );
		if ( ! preg_match( '/^\d{9}$/', $local_digits ) ) {
			self::$errors[] = __( 'Phone number must be exactly 9 digits.', 'acu' );
		}

		if ( $local_digits !== '' ) {
			if ( self::$edit_user_id === 0 ) {
				// Create mode: any existing login with this phone is a conflict
				if ( username_exists( $local_digits ) ) {
					self::$errors[] = __( 'This phone number is already registered.', 'acu' );
				}
			} else {
				// Edit mode: conflict only if another user owns this login
				$existing_login = get_user_by( 'login', $local_digits );
				if ( $existing_login && (int) $existing_login->ID !== self::$edit_user_id ) {
					self::$errors[] = __( 'This phone number is already registered.', 'acu' );
				}
			}
			// Check billing_phone meta, excluding the user being edited
			if ( ACU_Helpers::phone_exists_for_another_user( $local_digits, self::$edit_user_id ) ) {
				self::$errors[] = __( 'This phone number is already registered.', 'acu' );
			}
		}

		if ( $data['anketa_email'] !== '' ) {
			$existing_email_id = email_exists( $data['anketa_email'] );
			if ( $existing_email_id && (int) $existing_email_id !== self::$edit_user_id ) {
				self::$errors[] = __( 'This email is already registered.', 'acu' );
			}
		}
	}

	private static function sanitize_by_type( string $value, string $type ): string {
		$value = trim( $value );
		return match ( $type ) {
			'email' => sanitize_email( $value ),
			'date'  => preg_replace( '/[^0-9\-\.\/]/', '', $value ),
			'tel'   => preg_replace( '/[^0-9\+\-\s\(\)]/', '', $value ),
			default => sanitize_text_field( $value ),
		};
	}

	private static function default_rules_html(): string {
		return '<p><strong>Arttime-ის კლუბის წევრები სარგებლობენ შემდეგი უპირატესობით:</strong></p>
<ul>
<li>ბარათზე 500-5000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 5%</li>
<li>ბარათზე 5001-10000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 10%;</li>
<li>ბარათზე 10 000 ლარზე მეტის დაგროვების შემთხვევაში ფასდაკლება 15%.</li>
</ul>
<p>&nbsp;</p>
<p><strong>გთხოვთ გაითვალისწინოთ:</strong></p>
<ol>
<li>ართთაიმის კლუბის ბარათით გათვალისწინებული ფასდაკლება არ მოქმედებს ფასდაკლებელ პროდუქციაზე;</li>
<li>ფასდაკლებული პროდუქციის შეძენის შემთხვევაში ბარათზე მხოლოდ ქულები დაგერიცხებათ;</li>
<li>ფასდაკლება მოქმედებს, მაგრამ ქულები არ გერიცხებათ პროდუქციის სასაჩუქრე ვაუჩერით შემენისას;</li>
<li>სასაჩუქრე ვაუჩერის შეძენისას ფასდაკლება არ მოქმედებს, მაგრამ ქულები გროვდება;</li>
<li>დაგროვილი ქულები ბარათზე აისახება 2 სამუშაო დღის ვადაში;</li>
<li>გაითვალისწინეთ, წინამდებარე წესებით დადგენილი პირობები შეიძლება შეიცვალოს შპს „ართთაიმის" მიერ;</li>
<li>ხელმოწერით ვადასტურებ ჩემი პირადი მონაცემების სიზუსტეს და ბარათის მიღებას.</li>
</ol>';
	}
}
