<?php
/**
 * ACU_Checkout — Coupon auto-apply at checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Checkout {

	public static function init(): void {
		add_action( 'woocommerce_checkout_order_processed',   [ self::class, 'after_order_processed' ] );
		add_action( 'woocommerce_before_calculate_totals',    [ self::class, 'auto_apply_club_card' ] );
	}

	// -------------------------------------------------------------------------
	// After order processed
	// -------------------------------------------------------------------------

	public static function after_order_processed( int $order_id ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		ACU_Helpers::link_coupon_to_user( get_current_user_id() );
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
