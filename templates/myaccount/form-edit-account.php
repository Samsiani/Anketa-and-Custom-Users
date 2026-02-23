<?php
/**
 * WooCommerce account edit form — overridden to add custom ACU fields.
 *
 * Fields added: personal ID, phone (with OTP verify), SMS consent,
 * call consent, terms agreement, club card.
 *
 * @see https://woocommerce.com/document/template-structure/
 */
defined( 'ABSPATH' ) || exit;

$user_id      = get_current_user_id();
$current_user = wp_get_current_user();

$sms_consent  = ACU_Helpers::get_sms_consent( $user_id );
if ( $sms_consent === '' ) {
	$sms_consent = 'yes';
}
$call_consent = ACU_Helpers::get_call_consent( $user_id );
if ( $call_consent === '' ) {
	$call_consent = 'yes';
}

$terms_text_html = ACU_Helpers::get_terms_content_html( 'default' );
$terms_url       = ACU_Helpers::get_terms_url();
$print_url       = home_url( '/signature-terms/' );

$club_card = (string) get_user_meta( $user_id, '_acu_club_card_coupon', true );
$has_club  = $club_card !== '';

do_action( 'woocommerce_before_edit_account_form' );
?>
<form class="woocommerce-EditAccountForm edit-account" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?>>
	<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

	<!-- ── Card 1: Name + Email ── -->
	<div class="acu-section">
		<div class="acu-section__header">
			<span class="acu-section__icon">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
			</span>
			<span class="acu-section__label"><?php esc_html_e( 'Account Details', 'acu' ); ?></span>
		</div>

		<div class="acu-grid-2">
			<p class="form-row">
				<label for="account_first_name"><?php esc_html_e( 'First name', 'woocommerce' ); ?> <span class="required">*</span></label>
				<input type="text" class="input-text" name="account_first_name" id="account_first_name"
					autocomplete="given-name" value="<?php echo esc_attr( $current_user->first_name ); ?>" />
			</p>
			<p class="form-row">
				<label for="account_last_name"><?php esc_html_e( 'Last name', 'woocommerce' ); ?> <span class="required">*</span></label>
				<input type="text" class="input-text" name="account_last_name" id="account_last_name"
					autocomplete="family-name" value="<?php echo esc_attr( $current_user->last_name ); ?>" />
			</p>
		</div>

		<div style="margin-top:10px;">
			<p class="form-row">
				<label for="account_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?> <span class="required">*</span></label>
				<input type="email" class="input-text" name="account_email" id="account_email"
					autocomplete="email" value="<?php echo esc_attr( $current_user->user_email ); ?>" />
			</p>
		</div>

		<!-- Password sub-section -->
		<div class="acu-pw-divider">
			<p class="acu-pw-divider-label"><?php esc_html_e( 'Password change', 'woocommerce' ); ?></p>
			<div class="acu-grid-3">
				<p class="form-row" style="display:flex;flex-direction:column;height:100%;margin:0;">
					<label for="password_current" style="flex:1;margin-bottom:4px;"><?php esc_html_e( 'Current password', 'woocommerce' ); ?><br><span class="acu-optional"><?php esc_html_e( 'leave blank to keep unchanged', 'acu' ); ?></span></label>
					<input type="password" class="input-text" name="password_current" id="password_current" autocomplete="off" />
				</p>
				<p class="form-row" style="display:flex;flex-direction:column;height:100%;margin:0;">
					<label for="password_1" style="flex:1;margin-bottom:4px;"><?php esc_html_e( 'New password', 'woocommerce' ); ?><br><span class="acu-optional"><?php esc_html_e( 'leave blank to keep unchanged', 'acu' ); ?></span></label>
					<input type="password" class="input-text" name="password_1" id="password_1" autocomplete="off" />
				</p>
				<p class="form-row" style="display:flex;flex-direction:column;height:100%;margin:0;">
					<label for="password_2" style="flex:1;margin-bottom:4px;"><?php esc_html_e( 'Confirm new password', 'woocommerce' ); ?></label>
					<input type="password" class="input-text" name="password_2" id="password_2" autocomplete="off" />
				</p>
			</div>
		</div>
	</div>

	<!-- ── Card 2: Phone + Personal ID ── -->
	<div class="acu-section">
		<div class="acu-section__header">
			<span class="acu-section__icon">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.72 16.92z"/></svg>
			</span>
			<span class="acu-section__label"><?php esc_html_e( 'Contact', 'acu' ); ?></span>
		</div>
		<div class="acu-grid-2">
			<p class="form-row">
				<label for="account_phone"><?php esc_html_e( 'Phone number', 'acu' ); ?> <span class="required">*</span></label>
				<input type="tel" name="account_phone" id="account_phone" class="input-text"
					value="<?php echo esc_attr( (string) get_user_meta( $user_id, 'billing_phone', true ) ); ?>"
					autocomplete="tel" inputmode="tel"
					placeholder="<?php esc_attr_e( 'e.g. 599123456', 'acu' ); ?>" />
			</p>
			<p class="form-row">
				<label for="account_personal_id"><?php esc_html_e( 'Personal ID Number', 'acu' ); ?> <span class="acu-optional"><?php esc_html_e( 'optional', 'acu' ); ?></span></label>
				<input type="text" name="account_personal_id" id="account_personal_id" class="input-text"
					value="<?php echo esc_attr( (string) get_user_meta( $user_id, '_acu_personal_id', true ) ); ?>"
					inputmode="numeric" pattern="^\d{11}$" maxlength="11"
					placeholder="<?php esc_attr_e( '11 digits', 'acu' ); ?>" />
			</p>
		</div>
	</div>

	<!-- ── Card 3: Consents ── -->
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
				<input type="radio" name="account_sms_consent" id="acu_acct_sms_yes" value="yes" <?php checked( $sms_consent, 'yes' ); ?> class="sms-consent-radio" />
				<label for="acu_acct_sms_yes"><?php esc_html_e( 'Yes', 'acu' ); ?></label>
				<input type="radio" name="account_sms_consent" id="acu_acct_sms_no" value="no" <?php checked( $sms_consent, 'no' ); ?> class="sms-consent-radio" />
				<label for="acu_acct_sms_no"><?php esc_html_e( 'No', 'acu' ); ?></label>
			</div>
		</div>

		<div class="acu-consent-row">
			<span class="acu-consent-label"><?php esc_html_e( 'Phone call consent', 'acu' ); ?></span>
			<div class="acu-consent-toggle">
				<input type="radio" name="account_call_consent" id="acu_acct_call_yes" value="yes" <?php checked( $call_consent, 'yes' ); ?> class="call-consent-radio" />
				<label for="acu_acct_call_yes"><?php esc_html_e( 'Yes', 'acu' ); ?></label>
				<input type="radio" name="account_call_consent" id="acu_acct_call_no" value="no" <?php checked( $call_consent, 'no' ); ?> class="call-consent-radio" />
				<label for="acu_acct_call_no"><?php esc_html_e( 'No', 'acu' ); ?></label>
			</div>
		</div>
	</div>

	<!-- ── Card 4: Club Card ── -->
	<div class="acu-section">
		<div class="acu-section__header">
			<span class="acu-section__icon">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
			</span>
			<span class="acu-section__label"><?php esc_html_e( 'Club Card', 'acu' ); ?></span>
		</div>
		<div class="acu-club-card-inline">
			<div>
				<div class="acu-club-card-row">
					<label>
						<input type="checkbox" id="acu_has_club_card_account" name="acu_has_club_card" value="1"
							<?php checked( $has_club ); ?> data-acu-toggle data-target="#acu_club_card_account" />
						<?php esc_html_e( 'I have a Club Card', 'acu' ); ?>
					</label>
					<span class="wcu-help"><?php esc_html_e( 'Check to enter your card code', 'acu' ); ?></span>
				</div>
			</div>
			<div id="acu_club_card_account">
				<p class="form-row" style="margin:0;">
					<label for="account_club_card"><?php esc_html_e( 'Card code', 'acu' ); ?> <span class="acu-optional"><?php esc_html_e( 'optional', 'acu' ); ?></span></label>
					<input type="text" name="account_club_card" id="account_club_card" class="input-text"
						value="<?php echo esc_attr( $club_card ); ?>" <?php echo $has_club ? '' : 'disabled'; ?> />
				</p>
			</div>
		</div>
	</div>

	<!-- ── Card 5: Terms & Conditions ── -->
	<div class="acu-section">
		<div class="acu-section__header">
			<span class="acu-section__icon">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
			</span>
			<span class="acu-section__label"><?php esc_html_e( 'Terms &amp; Conditions', 'acu' ); ?></span>
		</div>
		<div class="acu-tc-row">
			<label>
				<input type="checkbox" name="acu_terms_agree" id="acu_terms_agree" value="1"
					<?php checked( (bool) get_user_meta( $user_id, '_acu_terms_accepted', true ) ); ?> />
				<?php esc_html_e( 'I agree to the terms and conditions', 'acu' ); ?>
			</label>
			<div class="wcu-terms-actions">
				<?php if ( $terms_url ) : ?>
					<a class="wcu-link" href="<?php echo esc_url( $terms_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read full T&C', 'acu' ); ?></a>
				<?php elseif ( $terms_text_html ) : ?>
					<details class="wcu-terms-details">
						<summary><?php esc_html_e( 'Read full T&C', 'acu' ); ?></summary>
						<div class="wcu-terms-body" style="margin-top:6px;">
							<?php echo wp_kses_post( $terms_text_html ); ?>
						</div>
					</details>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />

	<?php do_action( 'woocommerce_edit_account_form' ); ?>

	<div class="acu-save-row">
		<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
		<input type="hidden" name="action" value="save_account_details" />
		<button type="submit" class="acu-save-btn" name="save_account_details"
			value="<?php esc_attr_e( 'Save changes', 'woocommerce' ); ?>"><?php esc_html_e( 'Save changes', 'woocommerce' ); ?></button>
	</div>

	<?php do_action( 'woocommerce_edit_account_form_end' ); ?>
</form>
<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
