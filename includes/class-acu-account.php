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

		// My Account dashboard — club card info panel
		add_action( 'woocommerce_account_dashboard', [ self::class, 'render_dashboard_club_card' ] );
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
		$phone        = isset( $_POST['billing_phone'] )       ? esc_attr( wp_unslash( (string) $_POST['billing_phone'] ) )       : '';
		$sms_consent  = isset( $_POST['_sms_consent'] )        ? strtolower( (string) wp_unslash( $_POST['_sms_consent'] ) ) : 'yes';
		$call_consent = isset( $_POST['_call_consent'] )       ? strtolower( (string) wp_unslash( $_POST['_call_consent'] ) ) : 'yes';
		if ( ! in_array( $sms_consent,  [ 'yes', 'no' ], true ) ) $sms_consent  = 'yes';
		if ( ! in_array( $call_consent, [ 'yes', 'no' ], true ) ) $call_consent = 'yes';

		$terms_html = ACU_Helpers::get_terms_content_html();
		$terms_url  = ACU_Helpers::get_terms_url();
		$print_url  = home_url( '/signature-terms/' );
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
						<input type="text" class="input-text" name="account_first_name" id="reg_first_name" value="<?php echo $first_name; ?>" autocomplete="given-name" />
					</div>
					<div class="acu-field">
						<label for="reg_last_name"><?php esc_html_e( 'Last name', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
						<input type="text" class="input-text" name="account_last_name" id="reg_last_name" value="<?php echo $last_name; ?>" autocomplete="family-name" />
					</div>
					<div class="acu-field">
						<label for="reg_personal_id"><?php esc_html_e( 'Personal ID', 'acu' ); ?> <span class="acu-optional"><?php esc_html_e( 'optional', 'acu' ); ?></span></label>
						<input type="text" class="input-text" name="_acu_personal_id" id="reg_personal_id" value="<?php echo $personal_id; ?>"
							inputmode="numeric" maxlength="11" placeholder="<?php esc_attr_e( '11 digits', 'acu' ); ?>" />
					</div>
					<div class="acu-field acu-phone-field">
						<div class="acu-phone-label-row">
							<label for="reg_billing_phone"><?php esc_html_e( 'Phone', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
							<span class="acu-optional"><?php esc_html_e( 'ვერიფიკაცია სავალდებულოა', 'acu' ); ?></span>
						</div>
						<input type="tel" class="input-text" name="billing_phone" id="reg_billing_phone" value="<?php echo $phone; ?>"
							placeholder="<?php esc_attr_e( 'e.g. 599 123 456', 'acu' ); ?>" inputmode="tel" />
					</div>
				</div>
			</div>

			<!-- ── Card 2: Notifications (consent) ── -->
			<div class="acu-section">
				<div class="acu-section__header">
					<span class="acu-section__icon">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					</span>
					<span class="acu-section__label"><?php esc_html_e( 'Notifications', 'acu' ); ?></span>
				</div>
				<div class="acu-consent-row">
					<span class="acu-consent-label">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline;vertical-align:-1px;margin-right:5px;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
						<?php esc_html_e( 'SMS notifications', 'acu' ); ?>
					</span>
					<div class="acu-consent-toggle">
						<input type="radio" name="_sms_consent" id="reg_sms_yes" value="yes" <?php checked( $sms_consent, 'yes' ); ?> />
						<label for="reg_sms_yes"><?php esc_html_e( 'Yes', 'acu' ); ?></label>
						<input type="radio" name="_sms_consent" id="reg_sms_no" value="no" <?php checked( $sms_consent, 'no' ); ?> />
						<label for="reg_sms_no"><?php esc_html_e( 'No', 'acu' ); ?></label>
					</div>
				</div>
				<div class="acu-consent-row">
					<span class="acu-consent-label">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline;vertical-align:-1px;margin-right:5px;flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.72 16.92z"/></svg>
						<?php esc_html_e( 'Phone call consent', 'acu' ); ?>
					</span>
					<div class="acu-consent-toggle">
						<input type="radio" name="_call_consent" id="reg_call_yes" value="yes" <?php checked( $call_consent, 'yes' ); ?> />
						<label for="reg_call_yes"><?php esc_html_e( 'Yes', 'acu' ); ?></label>
						<input type="radio" name="_call_consent" id="reg_call_no" value="no" <?php checked( $call_consent, 'no' ); ?> />
						<label for="reg_call_no"><?php esc_html_e( 'No', 'acu' ); ?></label>
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
						<input type="checkbox" name="acu_terms_agree" id="acu_terms_agree" value="1" <?php checked( isset( $_POST['acu_terms_agree'] ) ); ?> />
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
		<input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />
		<?php
	}

	// -------------------------------------------------------------------------
	// WC customer created (registration)
	// -------------------------------------------------------------------------

	public static function created_customer( int $customer_id ): void {
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

		$sms = isset( $_POST['_sms_consent'] ) ? strtolower( (string) wp_unslash( $_POST['_sms_consent'] ) ) : '';
		if ( in_array( $sms, [ 'yes', 'no' ], true ) ) {
			update_user_meta( $customer_id, '_sms_consent', $sms );
			ACU_Helpers::maybe_send_consent_notification( $customer_id, '', $sms, 'wc_registration' );
		}

		$call = isset( $_POST['_call_consent'] ) ? strtolower( (string) wp_unslash( $_POST['_call_consent'] ) ) : '';
		if ( in_array( $call, [ 'yes', 'no' ], true ) ) {
			update_user_meta( $customer_id, '_call_consent', $call );
		}

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

		// Call consent
		if ( isset( $_POST['account_call_consent'] ) ) {
			$call = strtolower( sanitize_text_field( wp_unslash( $_POST['account_call_consent'] ) ) );
			if ( in_array( $call, [ 'yes', 'no' ], true ) ) {
				update_user_meta( $user_id, '_call_consent', $call );
			}
		}

		// SMS consent (requires OTP verification if changing from no→yes)
		if ( isset( $_POST['account_sms_consent'] ) ) {
			$old_sms = ACU_Helpers::get_sms_consent( $user_id );
			$new_sms = strtolower( sanitize_text_field( wp_unslash( $_POST['account_sms_consent'] ) ) );

			if ( in_array( $new_sms, [ 'yes', 'no' ], true ) ) {
				// If enabling SMS consent, require phone verification
				if ( $new_sms === 'yes' && $old_sms !== 'yes' ) {
					$phone_digits = ACU_Helpers::normalize_phone( ACU_Helpers::get_user_phone( $user_id ) );
					$token        = isset( $_POST['otp_verification_token'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_verification_token'] ) ) : '';

					if ( $phone_digits !== '' && ! ACU_OTP::is_phone_verified( $phone_digits, $token ) ) {
						if ( function_exists( 'wc_add_notice' ) ) {
							wc_add_notice( __( 'Phone verification required to enable SMS notifications.', 'acu' ), 'error' );
						}
						return;
					}
					update_user_meta( $user_id, '_acu_verified_phone', $phone_digits );
				}

				if ( $new_sms === 'no' ) {
					delete_user_meta( $user_id, '_acu_verified_phone' );
				}

				update_user_meta( $user_id, '_sms_consent', $new_sms );
				ACU_Helpers::maybe_send_consent_notification( $user_id, $old_sms, $new_sms, 'account_update' );
			}
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
			$badge_html = '<div class="acu-card-banner__right">'
				. '<span class="acu-card-banner__badge">' . esc_html( wc_format_decimal( $discount, 0 ) ) . '% OFF</span>'
				. '</div>';
		}

		echo '<div class="acu-card-banner">'
			. '<div class="acu-card-banner__left">'
				. '<span class="acu-card-banner__icon">&#10022;</span>'
				. '<div>'
					. '<div class="acu-card-banner__title">ARTTIME CLUB</div>'
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
