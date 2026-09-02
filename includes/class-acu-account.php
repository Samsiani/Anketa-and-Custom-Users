<?php
/**
 * ACU_Account — My Account hooks, consent fields, template overrides.
 *
 * Overrides WC templates: myaccount/form-login.php and myaccount/form-edit-account.php.
 * Hooks into woocommerce_register_form (WC registration), woocommerce_created_customer,
 * woocommerce_edit_account_form, and woocommerce_save_account_details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Account {

	public static function init(): void {
		// Template override
		add_filter( 'woocommerce_locate_template', [ self::class, 'locate_template' ], 10, 3 );

		// Asset enqueue (account page)
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_scripts' ], 20 );

		// WC registration form additions
		add_action( 'woocommerce_register_form', [ self::class, 'registration_form_fields' ] );
		add_action( 'woocommerce_created_customer', [ self::class, 'created_customer' ] );

		// My Account edit
		add_action( 'woocommerce_edit_account_form', [ self::class, 'edit_account_form_fields' ] );
		add_action( 'woocommerce_save_account_details', [ self::class, 'save_account_details' ] );

		// Remove account_display_name from required fields
		add_filter( 'woocommerce_save_account_details_required_fields', [ self::class, 'remove_display_name_required' ], 20 );

		// Dismiss consent banner (AJAX — no capability required, just nonce)
		add_action( 'wp_ajax_acu_dismiss_consent_notice',        [ self::class, 'ajax_dismiss_consent_notice' ] );
		add_action( 'wp_ajax_nopriv_acu_dismiss_consent_notice', [ self::class, 'ajax_dismiss_consent_notice' ] );

		// WooCommerce billing address phone verification
		add_filter( 'woocommerce_form_field_tel', [ self::class, 'modify_phone_field_html' ], 20, 4 );
		add_action( 'woocommerce_after_edit_address_form_billing', [ self::class, 'after_billing_address_form' ] );
		add_action( 'woocommerce_save_account_details_errors', [ self::class, 'validate_account_phone' ], 10, 1 );

		// My Account dashboard — one-time consent update prompt (legacy users) + club card info panel
		add_action( 'woocommerce_account_dashboard', [ self::class, 'render_consent_update_notice' ], 5 );
		add_action( 'woocommerce_account_dashboard', [ self::class, 'render_dashboard_club_card' ], 10 );

		// WC registration: auto-fill email from phone (must run before WC processes the POST)
		add_action( 'init', [ self::class, 'maybe_set_registration_email' ], 5 );
		// WC registration: validate phone format + uniqueness
		add_action( 'woocommerce_register_post', [ self::class, 'validate_registration_fields' ], 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Template override
	// -------------------------------------------------------------------------

	public static function locate_template( string $template, string $name, string $path ): string {
		$targets = [
			'myaccount/form-login.php',
			'myaccount/form-edit-account.php',
		];
		if ( in_array( $name, $targets, true ) ) {
			$file = ACU_DIR . 'templates/' . $name;
			if ( file_exists( $file ) ) {
				return $file;
			}
		}
		return $template;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue_scripts(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		wp_enqueue_style( 'acu-account',   ACU_URL . 'assets/css/account.css',   [], ACU_VERSION );
		wp_enqueue_style( 'acu-frontend',  ACU_URL . 'assets/css/frontend.css',  [], ACU_VERSION );
		wp_enqueue_script( 'acu-account', ACU_URL . 'assets/js/account.js', [], ACU_VERSION, true );
		wp_localize_script( 'acu-account', 'acuAccount', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'acu_dismiss_consent' ),
		] );
	}

	// -------------------------------------------------------------------------
	// WC Registration form (My Account → Register tab)
	// -------------------------------------------------------------------------

	public static function registration_form_fields(): void {
		wp_enqueue_style( 'acu-frontend', ACU_URL . 'assets/css/frontend.css', [], ACU_VERSION );

		$first_name   = isset( $_POST['account_first_name'] ) ? esc_attr( wp_unslash( (string) $_POST['account_first_name'] ) ) : '';
		$last_name    = isset( $_POST['account_last_name'] )  ? esc_attr( wp_unslash( (string) $_POST['account_last_name'] ) )  : '';
		$personal_id  = isset( $_POST['_acu_personal_id'] )   ? esc_attr( wp_unslash( (string) $_POST['_acu_personal_id'] ) )   : '';
		$phone     = isset( $_POST['billing_phone'] ) ? esc_attr( wp_unslash( (string) $_POST['billing_phone'] ) ) : '';
		$email_raw = isset( $_POST['email'] ) ? (string) wp_unslash( $_POST['email'] ) : '';
		$email_val = str_ends_with( $email_raw, '@no-email.local' ) ? '' : esc_attr( sanitize_email( $email_raw ) );

		$terms_html = ACU_Helpers::get_terms_content_html();
		$terms_url  = ACU_Helpers::get_terms_url();
		?>
		<div class="acu-reg-fields">

			<!-- ── Card 1: Account Details ── -->
			<div class="acu-section">
				<div class="acu-section__header">
					<span class="acu-section__icon">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
					</span>
					<span class="acu-section__label"><?php esc_html_e( 'Account Details', 'acu' ); ?></span>
				</div>
				<div class="acu-grid-2">
					<div class="acu-field">
						<label for="reg_first_name"><?php esc_html_e( 'First name', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
						<input type="text" class="input-text" name="account_first_name" id="reg_first_name" value="<?php echo $first_name; ?>" autocomplete="given-name" required />
					</div>
					<div class="acu-field">
						<label for="reg_last_name"><?php esc_html_e( 'Last name', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
						<input type="text" class="input-text" name="account_last_name" id="reg_last_name" value="<?php echo $last_name; ?>" autocomplete="family-name" required />
					</div>
					<div class="acu-field">
						<label for="reg_personal_id"><?php esc_html_e( 'Personal ID', 'acu' ); ?> <span class="acu-optional"><?php esc_html_e( 'optional', 'acu' ); ?></span></label>
						<input type="text" class="input-text" name="_acu_personal_id" id="reg_personal_id" value="<?php echo $personal_id; ?>"
							inputmode="numeric" maxlength="11" placeholder="<?php esc_attr_e( '11 digits', 'acu' ); ?>" />
					</div>
					<div class="acu-field">
						<label for="reg_billing_phone"><?php esc_html_e( 'Phone', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
						<input type="tel" class="input-text" name="billing_phone" id="reg_billing_phone" value="<?php echo $phone; ?>"
							placeholder="<?php esc_attr_e( 'e.g. 599 123 456', 'acu' ); ?>" inputmode="tel" required />
					</div>
					<div class="acu-field">
						<label for="reg_email"><?php esc_html_e( 'Email address', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
						<input type="email" class="input-text" name="email" id="reg_email"
							value="<?php echo $email_val; ?>"
							autocomplete="email" placeholder="<?php esc_attr_e( 'e.g. name@example.com', 'acu' ); ?>" required />
					</div>
					<div class="acu-field">
						<label for="reg_password"><?php esc_html_e( 'Password', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
						<input type="password" class="input-text" name="password" id="reg_password"
							autocomplete="new-password" placeholder="<?php esc_attr_e( 'Choose a password', 'acu' ); ?>" required />
					</div>
				</div>
			</div>

			<!-- ── Card 2: Notifications consent ── -->
			<?php
			$acu_sms_def  = ( isset( $_POST['acu_reg_sms_consent'] ) && 'no' === strtolower( (string) wp_unslash( $_POST['acu_reg_sms_consent'] ) ) ) ? 'no' : 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$acu_call_def = ( isset( $_POST['acu_reg_call_consent'] ) && 'no' === strtolower( (string) wp_unslash( $_POST['acu_reg_call_consent'] ) ) ) ? 'no' : 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			?>
			<div class="acu-section">
				<div class="acu-section__header">
					<span class="acu-section__icon">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					</span>
					<span class="acu-section__label"><?php esc_html_e( 'Notifications', 'acu' ); ?></span>
				</div>

				<div class="acu-consent-row">
					<span class="acu-consent-label"><?php esc_html_e( 'SMS notifications', 'acu' ); ?></span>
					<div class="acu-consent-toggle">
						<input type="radio" name="acu_reg_sms_consent" id="acu_reg_sms_yes" value="yes" <?php checked( $acu_sms_def, 'yes' ); ?> />
						<label for="acu_reg_sms_yes"><?php esc_html_e( 'Yes', 'acu' ); ?></label>
						<input type="radio" name="acu_reg_sms_consent" id="acu_reg_sms_no" value="no" <?php checked( $acu_sms_def, 'no' ); ?> />
						<label for="acu_reg_sms_no"><?php esc_html_e( 'No', 'acu' ); ?></label>
					</div>
				</div>

				<div class="acu-consent-row">
					<span class="acu-consent-label"><?php esc_html_e( 'Phone call consent', 'acu' ); ?></span>
					<div class="acu-consent-toggle">
						<input type="radio" name="acu_reg_call_consent" id="acu_reg_call_yes" value="yes" <?php checked( $acu_call_def, 'yes' ); ?> />
						<label for="acu_reg_call_yes"><?php esc_html_e( 'Yes', 'acu' ); ?></label>
						<input type="radio" name="acu_reg_call_consent" id="acu_reg_call_no" value="no" <?php checked( $acu_call_def, 'no' ); ?> />
						<label for="acu_reg_call_no"><?php esc_html_e( 'No', 'acu' ); ?></label>
					</div>
				</div>
			</div>

			<!-- ── Card 3: Terms & Conditions ── -->
			<div class="acu-section">
				<div class="acu-section__header">
					<span class="acu-section__icon">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
					</span>
					<span class="acu-section__label"><?php esc_html_e( 'Terms &amp; Conditions', 'acu' ); ?></span>
				</div>
				<div class="acu-tc-row">
					<label>
						<input type="checkbox" name="acu_terms_agree" id="acu_terms_agree" value="1" required <?php checked( isset( $_POST['acu_terms_agree'] ) ); ?> />
						<?php if ( $terms_url ) : ?>
							<?php esc_html_e( 'I agree to the', 'acu' ); ?> <a class="wcu-link" href="<?php echo esc_url( $terms_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'terms and conditions', 'acu' ); ?></a>
						<?php elseif ( $terms_html ) : ?>
							<?php esc_html_e( 'I agree to the', 'acu' ); ?>
							<details class="wcu-terms-details" style="display:inline;">
								<summary class="wcu-link" style="display:inline;cursor:pointer;"><?php esc_html_e( 'terms and conditions', 'acu' ); ?></summary>
								<div class="wcu-terms-body"><?php echo wp_kses_post( $terms_html ); ?></div>
							</details>
						<?php else : ?>
							<?php esc_html_e( 'I agree to the terms and conditions', 'acu' ); ?>
						<?php endif; ?>
					</label>
				</div>
			</div>

		</div><!-- /.acu-reg-fields -->
		<?php
	}

	// -------------------------------------------------------------------------
	// WC registration: auto-fill email + username from phone
	// -------------------------------------------------------------------------

	/**
	 * Runs on 'init' priority 5 — before WC_Form_Handler::process_registration() (priority 20).
	 * Email is a required field and is no longer auto-filled. When WooCommerce requires a manually
	 * entered username, the normalized phone number is used as the username fallback.
	 */
	public static function maybe_set_registration_email(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['register'], $_POST['woocommerce-register-nonce'] ) ) {
			return;
		}

		$phone = isset( $_POST['billing_phone'] )
			? ACU_Helpers::normalize_phone( sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) )
			: '';

		if ( strlen( $phone ) !== 9 ) {
			return;
		}

		// If WC is set to require a manually entered username, provide the phone as fallback.
		if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) && empty( $_POST['username'] ) ) {
			$_POST['username'] = $phone;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	// -------------------------------------------------------------------------
	// WC registration: server-side phone validation
	// -------------------------------------------------------------------------

	/**
	 * Fires as part of WC's registration validation pipeline.
	 * Validates phone format and checks it is not already in use.
	 *
	 * @param string    $username         Supplied username (may be empty if auto-generated).
	 * @param string    $email            Email address (already set, possibly dummy).
	 * @param \WP_Error $validation_errors Error bag — add errors here to block registration.
	 */
	public static function validate_registration_fields( string $username, string $email, \WP_Error $validation_errors ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$phone = isset( $_POST['billing_phone'] )
			? ACU_Helpers::normalize_phone( sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Required fields (Personal ID is the only optional field on the form).
		$acu_first = isset( $_POST['account_first_name'] ) ? trim( (string) wp_unslash( $_POST['account_first_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$acu_last  = isset( $_POST['account_last_name'] )  ? trim( (string) wp_unslash( $_POST['account_last_name'] ) )  : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $acu_first === '' ) {
			$validation_errors->add( 'first_name_required', __( 'First name is required.', 'acu' ) );
		}
		if ( $acu_last === '' ) {
			$validation_errors->add( 'last_name_required', __( 'Last name is required.', 'acu' ) );
		}
		if ( empty( $_POST['acu_terms_agree'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation_errors->add( 'terms_required', __( 'You must agree to the terms and conditions to register.', 'acu' ) );
		}

		if ( $phone === '' || strlen( $phone ) !== 9 ) {
			$validation_errors->add( 'phone_required', __( 'A valid 9-digit phone number is required.', 'acu' ) );
			return;
		}

		if ( ACU_Helpers::phone_exists_for_another_user( $phone, 0 ) ) {
			$validation_errors->add( 'phone_exists', __( 'This phone number is already registered.', 'acu' ) );
		}
	}

	// -------------------------------------------------------------------------
	// WC customer created (registration)
	// -------------------------------------------------------------------------

	public static function created_customer( int $customer_id ): void {
		// Name fields (not handled by WC core for custom forms)
		$name_update = [ 'ID' => $customer_id ];
		if ( isset( $_POST['account_first_name'] ) ) {
			$name_update['first_name'] = sanitize_text_field( wp_unslash( $_POST['account_first_name'] ) );
		}
		if ( isset( $_POST['account_last_name'] ) ) {
			$name_update['last_name'] = sanitize_text_field( wp_unslash( $_POST['account_last_name'] ) );
		}
		if ( count( $name_update ) > 1 ) {
			wp_update_user( $name_update );
		}

		if ( isset( $_POST['billing_phone'] ) ) {
			$phone = ACU_Helpers::normalize_phone( sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) );
			update_user_meta( $customer_id, 'billing_phone', $phone );
		}
		if ( isset( $_POST['_acu_personal_id'] ) ) {
			update_user_meta( $customer_id, '_acu_personal_id', sanitize_text_field( wp_unslash( $_POST['_acu_personal_id'] ) ) );
		}
		if ( isset( $_POST['acu_terms_agree'] ) ) {
			update_user_meta( $customer_id, '_acu_terms_accepted', current_time( 'mysql' ) );
		}

		// SMS / Call consent — default ON unless explicitly set to "no" on the registration form.
		$acu_sms  = ( isset( $_POST['acu_reg_sms_consent'] ) && 'no' === strtolower( sanitize_text_field( wp_unslash( $_POST['acu_reg_sms_consent'] ) ) ) ) ? 'no' : 'yes';
		$acu_call = ( isset( $_POST['acu_reg_call_consent'] ) && 'no' === strtolower( sanitize_text_field( wp_unslash( $_POST['acu_reg_call_consent'] ) ) ) ) ? 'no' : 'yes';
		update_user_meta( $customer_id, '_sms_consent', $acu_sms );
		update_user_meta( $customer_id, '_call_consent', $acu_call );
		ACU_Helpers::maybe_send_consent_notification( $customer_id, '', $acu_sms, 'registration' );

		ACU_Helpers::link_coupon_to_user( $customer_id );
	}

	// -------------------------------------------------------------------------
	// Edit account form fields (rendered inside WC template)
	// -------------------------------------------------------------------------

	public static function edit_account_form_fields(): void {
		// This hook fires INSIDE the template. The template handles the rest.
		// Additional fields if needed can go here; the template reads meta directly.
	}

	// -------------------------------------------------------------------------
	// Save account details
	// -------------------------------------------------------------------------

	public static function save_account_details( int $user_id ): void {
		if ( isset( $_POST['account_phone'] ) ) {
			$phone = ACU_Helpers::normalize_phone( sanitize_text_field( wp_unslash( $_POST['account_phone'] ) ) );
			update_user_meta( $user_id, 'billing_phone', $phone );
		}
		if ( isset( $_POST['account_personal_id'] ) ) {
			update_user_meta( $user_id, '_acu_personal_id', sanitize_text_field( wp_unslash( $_POST['account_personal_id'] ) ) );
		}
		if ( isset( $_POST['account_club_card'] ) || isset( $_POST['wcu_has_club_card'] ) ) {
			$cc = isset( $_POST['account_club_card'] ) ? sanitize_text_field( wp_unslash( $_POST['account_club_card'] ) ) : '';
			update_user_meta( $user_id, '_acu_club_card_coupon', $cc );
		}

		// Terms acceptance timestamp
		if ( isset( $_POST['acu_terms_agree'] ) ) {
			update_user_meta( $user_id, '_acu_terms_accepted', current_time( 'mysql' ) );
		} else {
			delete_user_meta( $user_id, '_acu_terms_accepted' );
		}

		// One-time notification consent. Each toggle may be set exactly once (legacy
		// users with a blank value); a recorded value is final. A locked toggle renders
		// disabled with no `name`, and its posted value — forged or stale — is ignored
		// here. ACU_Consent_Lock blocks the meta write as well.
		$sms  = isset( $_POST['account_sms_consent'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['account_sms_consent'] ) ) ) : '';
		$call = isset( $_POST['account_call_consent'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['account_call_consent'] ) ) ) : '';

		$saved = false;
		if ( in_array( $sms, [ 'yes', 'no' ], true ) && ! ACU_Helpers::is_sms_consent_locked( $user_id ) ) {
			update_user_meta( $user_id, '_sms_consent', $sms );
			$saved = true;
		}
		if ( in_array( $call, [ 'yes', 'no' ], true ) && ! ACU_Helpers::is_call_consent_locked( $user_id ) ) {
			update_user_meta( $user_id, '_call_consent', $call );
			$saved = true;
		}
		if ( $saved ) {
			ACU_Helpers::maybe_send_consent_notification( $user_id, '', $sms, 'account_update' );
		}

		ACU_Helpers::link_coupon_to_user( $user_id );
	}

	// -------------------------------------------------------------------------
	// Remove display_name from required account fields
	// -------------------------------------------------------------------------

	public static function remove_display_name_required( array $fields ): array {
		unset( $fields['account_display_name'] );
		return $fields;
	}

	// -------------------------------------------------------------------------
	// Phone field modify (billing address page)
	// -------------------------------------------------------------------------

	public static function modify_phone_field_html( string $field, string $key, array $args, $value ): string {
		if ( $key !== 'billing_phone' ) {
			return $field;
		}
		$is_account = function_exists( 'is_account_page' ) && is_account_page();

		if ( ! $is_account ) {
			return $field; // Only modify on account pages (checkout uses Verification on Demand)
		}

		$btn_html  = '<div class="phone-verify-container">';
		$btn_html .= '<button type="button" class="phone-verify-btn">' . esc_html__( 'Verify', 'acu' ) . '</button>';
		$btn_html .= '<span class="phone-verified-icon" style="display:none;">';
		$btn_html .= '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
		$btn_html .= '</span></div>';

		return $field . $btn_html;
	}

	public static function after_billing_address_form(): void {
		?>
		<script>
		(function(){
			var form = document.querySelector('form.woocommerce-EditAccountForm, form.edit-address');
			if (form && !form.querySelector('.otp-verification-token')) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'otp_verification_token';
				input.value = '';
				input.className = 'otp-verification-token';
				form.appendChild(input);
			}
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Validate phone on account save
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// AJAX: dismiss consent notice banner
	// -------------------------------------------------------------------------

	public static function ajax_dismiss_consent_notice(): void {
		check_ajax_referer( 'acu_dismiss_consent', 'nonce' );
		// Banner is dismissed client-side; no persistent server state needed.
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Validate phone on account save
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// My Account dashboard: one-time consent update prompt
	// -------------------------------------------------------------------------

	/**
	 * Legacy customers (registered before the consent fields existed) have blank
	 * SMS/Call consent. Show a prominent banner inviting them to confirm their
	 * notification preferences once. Hidden as soon as both consents are set.
	 */
	public static function render_consent_update_notice(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! ACU_Helpers::account_needs_consent_update( $user_id ) ) {
			return;
		}

		$edit_url = wc_get_account_endpoint_url( 'edit-account' ) . '#acu-consent';

		echo '<div class="acu-card-banner acu-consent-banner">'
			. '<div class="acu-card-banner__left">'
				. '<span class="acu-card-banner__icon">&#9888;</span>'
				. '<div>'
					. '<div class="acu-card-banner__title">' . esc_html__( 'ACTION REQUIRED', 'acu' ) . '</div>'
					. '<div class="acu-card-banner__code">' . esc_html__( 'Please confirm your notification preferences', 'acu' ) . '</div>'
				. '</div>'
			. '</div>'
			. '<div class="acu-card-banner__right">'
				. '<a class="acu-consent-banner__btn" href="' . esc_url( $edit_url ) . '">'
					. esc_html__( 'Update your account', 'acu' )
				. '</a>'
			. '</div>'
			. '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// -------------------------------------------------------------------------
	// My Account dashboard: club card info
	// -------------------------------------------------------------------------

	public static function render_dashboard_club_card(): void {
		$user_id     = get_current_user_id();
		$coupon_code = (string) get_user_meta( $user_id, '_acu_club_card_coupon', true );

		if ( $coupon_code === '' ) {
			return;
		}

		// Load the WC coupon to get the current discount amount.
		$coupon   = new WC_Coupon( $coupon_code );
		$amount   = $coupon->get_amount();
		$discount = $amount > 0 ? (float) $amount : 0.0;

		$badge_html = '';
		if ( $discount > 0 ) {
			$cake = ACU_Helpers::is_club_birthday_boost( $discount, null, $coupon_code )
				? ACU_Helpers::club_birthday_badge_html()
				: '';
			$badge_html = '<div class="acu-card-banner__right">'
				. '<span class="acu-card-banner__badge">'
				/* translators: %s: percentage discount */
				. esc_html( sprintf( __( '%s%% OFF', 'acu' ), wc_format_decimal( $discount, 0 ) ) )
				. '</span>'
				. $cake
				. '</div>';
		}

		echo '<div class="acu-card-banner">'
			. '<div class="acu-card-banner__left">'
				. '<span class="acu-card-banner__icon">&#10022;</span>'
				. '<div>'
					. '<div class="acu-card-banner__title">' . esc_html__( 'ARTTIME CLUB', 'acu' ) . '</div>'
					. '<div class="acu-card-banner__code">' . esc_html( $coupon_code ) . '</div>'
				. '</div>'
			. '</div>'
			. $badge_html
			. '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// -------------------------------------------------------------------------
	// Validate phone on account save
	// -------------------------------------------------------------------------

	public static function validate_account_phone( \WP_Error $errors ): void {
		if ( ! isset( $_POST['account_phone'] ) ) {
			return;
		}
		$phone        = sanitize_text_field( wp_unslash( $_POST['account_phone'] ) );
		$phone_digits = ACU_Helpers::normalize_phone( $phone );

		if ( strlen( $phone_digits ) !== 9 ) {
			return;
		}

		$user_id        = get_current_user_id();
		$verified_phone = (string) get_user_meta( $user_id, '_acu_verified_phone', true );

		if ( $phone_digits !== $verified_phone ) {
			$token = isset( $_POST['otp_verification_token'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_verification_token'] ) ) : '';
			if ( ! ACU_OTP::is_phone_verified( $phone_digits, $token ) ) {
				$errors->add( 'acu_phone_verification', __( 'Phone verification required. Please verify your new phone number.', 'acu' ) );
			} else {
				update_user_meta( $user_id, '_acu_verified_phone', $phone_digits );
			}
		}
	}
}
