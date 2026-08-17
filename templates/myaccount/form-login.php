<?php
/**
 * WooCommerce login form — overridden by the ACU plugin.
 *
 * Single centred column: Register on top, Login below (WooCommerce ships them
 * side by side). Phone-login hint kept on the username label.
 *
 * @see https://woocommerce.com/document/template-structure/
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );

$acu_registration_enabled = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
?>
<div class="acu-auth" id="customer_login">

	<?php if ( $acu_registration_enabled ) : ?>
	<section class="acu-auth__block acu-auth__block--register">
		<div class="acu-auth__head">
			<h2 class="acu-auth__title"><?php esc_html_e( 'Register', 'woocommerce' ); ?></h2>
			<p class="acu-auth__sub"><?php esc_html_e( 'შექმენით ანგარიში და გახდით ართთაიმის კლუბის წევრი.', 'acu' ); ?></p>
		</div>

		<form method="post" class="woocommerce-form woocommerce-form-register register">
			<?php do_action( 'woocommerce_register_form_start' ); ?>
			<?php do_action( 'woocommerce_register_form' ); ?>
			<?php do_action( 'woocommerce_register_form_end' ); ?>

			<div class="woocommerce-FormRow form-row acu-submit-row">
				<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
				<button type="submit" class="woocommerce-Button button acu-submit-btn" name="register"
					value="<?php esc_attr_e( 'Register', 'woocommerce' ); ?>"><?php esc_html_e( 'Register', 'woocommerce' ); ?></button>
			</div>
		</form>
	</section>

	<div class="acu-auth__divider"><span><?php esc_html_e( 'უკვე გაქვთ ანგარიში?', 'acu' ); ?></span></div>
	<?php endif; ?>

	<section class="acu-auth__block acu-auth__block--login">
		<div class="acu-auth__head">
			<h2 class="acu-auth__title"><?php esc_html_e( 'Login', 'woocommerce' ); ?></h2>
		</div>

		<form class="woocommerce-form woocommerce-form-login login" method="post">
			<?php do_action( 'woocommerce_login_form_start' ); ?>

			<div class="acu-section acu-auth__card">
				<div class="acu-field">
					<label for="username"><?php esc_html_e( 'Username, email or phone', 'acu' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
					<input type="text" class="input-text" name="username" id="username" autocomplete="username" required
						value="<?php echo ! empty( $_POST['username'] ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" />
				</div>

				<div class="acu-field">
					<label for="password"><?php esc_html_e( 'Password', 'woocommerce' ); ?> <span class="acu-required" aria-hidden="true">*</span></label>
					<input class="input-text" type="password" name="password" id="password" autocomplete="current-password" required />
				</div>

				<?php do_action( 'woocommerce_login_form' ); ?>

				<div class="acu-auth__actions">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme acu-auth__remember">
						<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" />
						<span><?php esc_html_e( 'Remember me', 'woocommerce' ); ?></span>
					</label>
					<a class="acu-login-lost-pw" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Lost your password?', 'woocommerce' ); ?></a>
				</div>

				<div class="acu-submit-row">
					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<button type="submit" class="woocommerce-button button woocommerce-form-login__submit acu-submit-btn" name="login"
						value="<?php esc_attr_e( 'Log in', 'woocommerce' ); ?>"><?php esc_html_e( 'Log in', 'woocommerce' ); ?></button>
				</div>
			</div>

			<?php do_action( 'woocommerce_login_form_end' ); ?>
		</form>
	</section>

</div>
<?php
do_action( 'woocommerce_after_customer_login_form' );
