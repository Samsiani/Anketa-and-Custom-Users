<?php
/**
 * ACU_Checkout — Checkout OTP validation, consent fields, coupon auto-apply.
 *
 * Implements "Verification on Demand": no visible verify button on checkout;
 * JS intercepts #place_order click and triggers OTP modal if unverified.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Checkout {

	public static function init(): void {
		add_action( 'woocommerce_review_order_before_submit', [ self::class, 'checkout_consent_fields' ] );
		add_action( 'woocommerce_checkout_process',           [ self::class, 'validate_checkout_phone' ] );
		add_action( 'woocommerce_checkout_order_processed',   [ self::class, 'after_order_processed' ] );
		add_action( 'woocommerce_before_calculate_totals',    [ self::class, 'auto_apply_club_card' ] );
	}

	// -------------------------------------------------------------------------
	// Render consent fields at checkout (if user hasn't given them yet)
	// -------------------------------------------------------------------------

	public static function checkout_consent_fields(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id     = get_current_user_id();
		$sms_consent = ACU_Helpers::get_sms_consent( $user_id );

		if ( $sms_consent === 'yes' ) {
			return; // Already consented — nothing to show
		}

		$call_consent = ACU_Helpers::get_call_consent( $user_id );
		?>
		<div class="acu-checkout-consent">
			<div class="wcu-inline-control wcu-inline-control--center wcu-inline-control--highlight" style="width:100%;margin-bottom:12px;">
				<span class="wcu-inline-control__label"><?php esc_html_e( 'SMS შეტყობინებების მიღების თანხმობა', 'acu' ); ?></span>
				<div class="wcu-radio-inline">
					<label><input type="radio" name="anketa_sms_consent" value="yes" <?php checked( $sms_consent, 'yes' ); ?> class="sms-consent-radio" /> <?php esc_html_e( 'დიახ', 'acu' ); ?></label>
					<label><input type="radio" name="anketa_sms_consent" value="no"  <?php checked( $sms_consent, 'no' );  ?> class="sms-consent-radio" /> <?php esc_html_e( 'არა', 'acu' ); ?></label>
				</div>
			</div>
			<div class="wcu-inline-control wcu-inline-control--center wcu-inline-control--highlight" style="width:100%;margin-bottom:12px;">
				<span class="wcu-inline-control__label"><?php esc_html_e( 'თანხმობა სატელეფონო ზარზე', 'acu' ); ?></span>
				<div class="wcu-radio-inline">
					<label><input type="radio" name="anketa_call_consent" value="yes" <?php checked( $call_consent, 'yes' ); ?> class="call-consent-radio" /> <?php esc_html_e( 'დიახ', 'acu' ); ?></label>
					<label><input type="radio" name="anketa_call_consent" value="no"  <?php checked( $call_consent, 'no' );  ?> class="call-consent-radio" /> <?php esc_html_e( 'არა', 'acu' ); ?></label>
				</div>
			</div>
			<input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Validate phone OTP on checkout submit
	// -------------------------------------------------------------------------

	public static function validate_checkout_phone(): void {
		if ( ! isset( $_POST['billing_phone'] ) ) {
			return;
		}

		$phone        = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
		$phone_digits = ACU_Helpers::normalize_phone( $phone );

		if ( strlen( $phone_digits ) !== 9 ) {
			return; // Let WC handle invalid phone format
		}

		// If logged in and phone matches already-verified phone, skip check
		if ( is_user_logged_in() ) {
			$verified = (string) get_user_meta( get_current_user_id(), '_acu_verified_phone', true );
			if ( $phone_digits === $verified ) {
				return;
			}
		}

		$token = isset( $_POST['otp_verification_token'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_verification_token'] ) ) : '';

		if ( ! ACU_OTP::is_phone_verified( $phone_digits, $token ) ) {
			wc_add_notice( __( 'Phone verification required. Please verify your phone number before placing the order.', 'acu' ), 'error' );
		}
	}

	// -------------------------------------------------------------------------
	// After order processed
	// -------------------------------------------------------------------------

	public static function after_order_processed( int $order_id ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Save consent if provided at checkout
		if ( isset( $_POST['anketa_sms_consent'] ) ) {
			$old_sms = ACU_Helpers::get_sms_consent( $user_id );
			$new_sms = strtolower( sanitize_text_field( wp_unslash( $_POST['anketa_sms_consent'] ) ) );
			if ( in_array( $new_sms, [ 'yes', 'no' ], true ) ) {
				update_user_meta( $user_id, '_sms_consent', $new_sms );
				ACU_Helpers::maybe_send_consent_notification( $user_id, $old_sms, $new_sms, 'checkout' );
			}
		}
		if ( isset( $_POST['anketa_call_consent'] ) ) {
			$call = strtolower( sanitize_text_field( wp_unslash( $_POST['anketa_call_consent'] ) ) );
			if ( in_array( $call, [ 'yes', 'no' ], true ) ) {
				update_user_meta( $user_id, '_call_consent', $call );
			}
		}

		ACU_Helpers::link_coupon_to_user( $user_id );
	}

	// -------------------------------------------------------------------------
	// Auto-apply club card coupon at checkout
	// -------------------------------------------------------------------------

	public static function auto_apply_club_card( \WC_Cart $cart ): void {
		if ( ! get_option( 'acu_auto_apply_club', false ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return; // Don't run on totals recalculation AJAX
		}

		$user_id     = get_current_user_id();
		$coupon_code = (string) get_user_meta( $user_id, '_acu_club_card_coupon', true );
		if ( $coupon_code === '' ) {
			return;
		}

		// Check session to avoid re-applying each recalculation
		$session_key = 'acu_club_applied_' . $user_id;
		if ( WC()->session && WC()->session->get( $session_key ) ) {
			return;
		}

		// Apply if not already applied
		if ( ! $cart->has_discount( $coupon_code ) ) {
			$cart->apply_coupon( $coupon_code );
			if ( WC()->session ) {
				WC()->session->set( $session_key, true );
			}
		}
	}
}
