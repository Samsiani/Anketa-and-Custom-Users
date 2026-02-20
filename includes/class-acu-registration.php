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
			// Only update email if a real (non-dummy) address was supplied
			if ( $data['anketa_email'] !== '' ) {
				$update_data['user_email'] = $data['anketa_email'];
			}
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
		<div class="club-anketa-form-wrap">
			<?php if ( $is_edit ) : ?>
			<div class="acu-edit-actions" style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'edit_user', $edit_user_id, get_permalink() ) ); ?>"><?php esc_html_e( 'Edit Anketa', 'acu' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'user_id', $edit_user_id, home_url( '/print-anketa/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print Anketa', 'acu' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'user_id' => $edit_user_id, 'terms_type' => 'sms' ], home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print SMS Terms', 'acu' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'user_id' => $edit_user_id, 'terms_type' => 'call' ], home_url( '/signature-terms/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print Phone Call Terms', 'acu' ); ?></a>
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
				<div class="club-anketa-hp">
					<label for="acu_security_field">Leave this empty</label>
					<input type="text" id="acu_security_field" name="acu_security_field" value="" autocomplete="off" tabindex="-1" />
				</div>

				<div class="row">
					<label class="label" for="anketa_personal_id">პირადი ნომერი *</label>
					<div class="field"><input type="text" id="anketa_personal_id" name="anketa_personal_id" required value="<?php echo $v( 'anketa_personal_id' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_first_name">სახელი *</label>
					<div class="field"><input type="text" id="anketa_first_name" name="anketa_first_name" required value="<?php echo $v( 'anketa_first_name' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_last_name">გვარი *</label>
					<div class="field"><input type="text" id="anketa_last_name" name="anketa_last_name" required value="<?php echo $v( 'anketa_last_name' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_dob">დაბადების თარიღი *</label>
					<div class="field"><input type="date" id="anketa_dob" name="anketa_dob" required value="<?php echo $v( 'anketa_dob' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label">ტელეფონის ნომერი *</label>
					<div class="field">
						<div class="phone-group phone-verify-group">
							<input class="phone-prefix" type="text" value="+995" readonly aria-label="Country code +995" />
							<input class="phone-local" type="tel" id="anketa_phone_local" name="anketa_phone_local"
								inputmode="numeric" pattern="[0-9]{9}" maxlength="9" placeholder="599620303"
								required value="<?php echo $v( 'anketa_phone_local' ); ?>" />
							<div class="phone-verify-container">
								<button type="button" class="phone-verify-btn" aria-label="<?php esc_attr_e( 'Verify phone', 'acu' ); ?>">
									<?php esc_html_e( 'Verify', 'acu' ); ?>
								</button>
								<span class="phone-verified-icon" style="display:none;" aria-label="<?php esc_attr_e( 'Phone verified', 'acu' ); ?>">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
								</span>
							</div>
						</div>
						<small class="help-text">9-ციფრიანი ნომერი, მაგ: 599620303</small>
					</div>
				</div>

				<div class="row">
					<label class="label" for="anketa_address">მისამართი</label>
					<div class="field"><input type="text" id="anketa_address" name="anketa_address" value="<?php echo $v( 'anketa_address' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_email">E-mail <span class="optional-note">(ოფციური, თუ საიტზე გსურთ რეგისტრაცია)</span></label>
					<div class="field"><input type="email" id="anketa_email" name="anketa_email" value="<?php echo $v( 'anketa_email' ); ?>" /></div>
				</div>

				<div class="rules-wrap">
					<div class="rules-title">წესები და პირობები</div>
					<div class="rules-text"><?php echo wp_kses_post( $rules_html ); ?></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_card_no">მივიღე ბარათი №</label>
					<div class="field"><input type="text" id="anketa_card_no" name="anketa_card_no" value="<?php echo $v( 'anketa_card_no' ); ?>" /></div>
				</div>

				<!-- SMS consent -->
				<div class="row club-anketa-sms-consent" data-context="registration">
					<span class="label">SMS შეტყობინებების მიღების თანხმობა</span>
					<div class="field sms-consent-options">
						<label style="margin-right:12px;"><input type="radio" name="anketa_sms_consent" value="yes" <?php checked( $sms_old, 'yes' ); ?> /> დიახ</label>
						<label><input type="radio" name="anketa_sms_consent" value="no" <?php checked( $sms_old, 'no' ); ?> /> არა</label>
					</div>
					<input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />
				</div>

				<!-- Call consent -->
				<div class="row club-anketa-sms-consent" data-context="registration">
					<span class="label"><?php esc_html_e( 'თანხმობა სატელეფონო ზარზე', 'acu' ); ?></span>
					<div class="field sms-consent-options">
						<label style="margin-right:12px;"><input type="radio" name="anketa_call_consent" value="yes" <?php checked( $call_old, 'yes' ); ?> /> <?php esc_html_e( 'დიახ', 'acu' ); ?></label>
						<label><input type="radio" name="anketa_call_consent" value="no" <?php checked( $call_old, 'no' ); ?> /> <?php esc_html_e( 'არა', 'acu' ); ?></label>
					</div>
				</div>

				<div class="row">
					<label class="label" for="anketa_responsible_person">პასუხისმგებელი პირი</label>
					<div class="field"><input type="text" id="anketa_responsible_person" name="anketa_responsible_person" value="<?php echo $v( 'anketa_responsible_person' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_form_date">თარიღი</label>
					<div class="field"><input type="date" id="anketa_form_date" name="anketa_form_date" value="<?php echo $v( 'anketa_form_date' ); ?>" /></div>
				</div>

				<div class="row">
					<label class="label" for="anketa_shop">მაღაზია</label>
					<div class="field"><input type="text" id="anketa_shop" name="anketa_shop" value="<?php echo $v( 'anketa_shop' ); ?>" /></div>
				</div>

				<div class="submit-row">
					<button type="submit" class="submit-btn"><?php echo esc_html( $submit_label ); ?></button>
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

		if ( $data['anketa_personal_id'] !== '' && ! ACU_Helpers::validate_personal_id( $data['anketa_personal_id'] ) ) {
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
