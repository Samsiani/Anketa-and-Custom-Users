<?php
/**
 * ACU_Settings — Unified admin settings page.
 *
 * Single page: Settings → Club Member Settings
 *
 * Sections:
 *  1. SMS Gateway
 *  2. Email Notifications
 *  3. Terms & Conditions
 *  4. Club Card
 *  5. Import / Export (delegates to ACU_Admin handlers)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Settings {

	public static function init(): void {
		add_action( 'admin_menu',  [ self::class, 'register_menu' ] );
		add_action( 'admin_init',  [ self::class, 'register_settings' ] );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'Club Member Settings', 'acu' ),
			__( 'Club Member Settings', 'acu' ),
			'manage_options',
			'acu-settings',
			[ self::class, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		// SMS Gateway
		register_setting( 'acu_settings_group', 'acu_sms_username',   [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'acu_settings_group', 'acu_sms_password',   [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'acu_settings_group', 'acu_sms_client_id',  [ 'type' => 'integer', 'sanitize_callback' => 'absint',              'default' => 0  ] );
		register_setting( 'acu_settings_group', 'acu_sms_service_id', [ 'type' => 'integer', 'sanitize_callback' => 'absint',              'default' => 0  ] );

		// Email
		register_setting( 'acu_settings_group', 'acu_admin_email',               [ 'type' => 'string',  'sanitize_callback' => 'sanitize_email',        'default' => '' ] );
		register_setting( 'acu_settings_group', 'acu_enable_email_notification', [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean',  'default' => false ] );

		// Terms
		register_setting( 'acu_settings_group', 'acu_terms_url',      [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw',                     'default' => '' ] );
		register_setting( 'acu_settings_group', 'acu_terms_html',     [ 'type' => 'string', 'sanitize_callback' => [ 'ACU_Helpers', 'sanitize_html' ], 'default' => '' ] );
		register_setting( 'acu_settings_group', 'acu_sms_terms_html', [ 'type' => 'string', 'sanitize_callback' => [ 'ACU_Helpers', 'sanitize_html' ], 'default' => '' ] );
		register_setting( 'acu_settings_group', 'acu_call_terms_html',[ 'type' => 'string', 'sanitize_callback' => [ 'ACU_Helpers', 'sanitize_html' ], 'default' => '' ] );

		// Club Card
		register_setting( 'acu_settings_group', 'acu_auto_apply_club', [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );

		// ---- Sections ----

		add_settings_section( 'acu_sms_section',   __( 'SMS Gateway (MS Group)', 'acu' ), [ self::class, 'sms_section_cb' ],   'acu-settings' );
		add_settings_section( 'acu_email_section', __( 'Email Notifications', 'acu' ),    [ self::class, 'email_section_cb' ], 'acu-settings' );
		add_settings_section( 'acu_terms_section', __( 'Terms & Conditions', 'acu' ),     [ self::class, 'terms_section_cb' ], 'acu-settings' );
		add_settings_section( 'acu_club_section',  __( 'Club Card', 'acu' ),              [ self::class, 'club_section_cb' ],  'acu-settings' );

		// SMS fields
		add_settings_field( 'acu_sms_username',   __( 'API Username', 'acu' ),   [ self::class, 'field_sms_username' ],   'acu-settings', 'acu_sms_section' );
		add_settings_field( 'acu_sms_password',   __( 'API Password', 'acu' ),   [ self::class, 'field_sms_password' ],   'acu-settings', 'acu_sms_section' );
		add_settings_field( 'acu_sms_client_id',  __( 'Client ID', 'acu' ),      [ self::class, 'field_sms_client_id' ],  'acu-settings', 'acu_sms_section' );
		add_settings_field( 'acu_sms_service_id', __( 'Service ID', 'acu' ),     [ self::class, 'field_sms_service_id' ], 'acu-settings', 'acu_sms_section' );

		// Email fields
		add_settings_field( 'acu_enable_email_notification', __( 'Enable notifications', 'acu' ), [ self::class, 'field_enable_email' ], 'acu-settings', 'acu_email_section' );
		add_settings_field( 'acu_admin_email', __( 'Notification email', 'acu' ), [ self::class, 'field_admin_email' ], 'acu-settings', 'acu_email_section' );

		// Terms fields
		add_settings_field( 'acu_terms_url',       __( 'Terms & Conditions URL', 'acu' ),          [ self::class, 'field_terms_url' ],       'acu-settings', 'acu_terms_section' );
		add_settings_field( 'acu_terms_html',      __( 'Default Terms HTML', 'acu' ),              [ self::class, 'field_terms_html' ],      'acu-settings', 'acu_terms_section' );
		add_settings_field( 'acu_sms_terms_html',  __( 'SMS Consent Terms HTML', 'acu' ),          [ self::class, 'field_sms_terms_html' ],  'acu-settings', 'acu_terms_section' );
		add_settings_field( 'acu_call_terms_html', __( 'Phone Call Consent Terms HTML', 'acu' ),   [ self::class, 'field_call_terms_html' ], 'acu-settings', 'acu_terms_section' );

		// Club card
		add_settings_field( 'acu_auto_apply_club', __( 'Auto-apply club card at checkout', 'acu' ), [ self::class, 'field_auto_apply_club' ], 'acu-settings', 'acu_club_section' );
	}

	// -------------------------------------------------------------------------
	// Section callbacks
	// -------------------------------------------------------------------------

	public static function sms_section_cb(): void {
		echo '<p>' . esc_html__( 'Configure your MS Group SMS API credentials for OTP verification.', 'acu' ) . '</p>';
	}

	public static function email_section_cb(): void {
		echo '<p>' . esc_html__( 'Configure where to send SMS consent change notifications.', 'acu' ) . '</p>';
	}

	public static function terms_section_cb(): void {
		echo '<p>' . esc_html__( 'Provide the Terms & Conditions content. If a URL is set it takes precedence on the print page fallback.', 'acu' ) . '</p>';
	}

	public static function club_section_cb(): void {
		echo '<p>' . esc_html__( 'Settings related to Club Card coupon.', 'acu' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers — SMS
	// -------------------------------------------------------------------------

	public static function field_sms_username(): void {
		$val = esc_attr( get_option( 'acu_sms_username', '' ) );
		echo '<input type="text" name="acu_sms_username" value="' . $val . '" class="regular-text" />';
	}

	public static function field_sms_password(): void {
		$val = esc_attr( get_option( 'acu_sms_password', '' ) );
		echo '<input type="password" name="acu_sms_password" value="' . $val . '" class="regular-text" />';
	}

	public static function field_sms_client_id(): void {
		$val = esc_attr( get_option( 'acu_sms_client_id', '' ) );
		echo '<input type="number" name="acu_sms_client_id" value="' . $val . '" class="regular-text" />';
	}

	public static function field_sms_service_id(): void {
		$val = esc_attr( get_option( 'acu_sms_service_id', '' ) );
		echo '<input type="number" name="acu_sms_service_id" value="' . $val . '" class="regular-text" />';
	}

	// -------------------------------------------------------------------------
	// Field renderers — Email
	// -------------------------------------------------------------------------

	public static function field_enable_email(): void {
		$val = get_option( 'acu_enable_email_notification', false );
		echo '<label><input type="checkbox" name="acu_enable_email_notification" value="1" ' . checked( 1, $val, false ) . ' /> ';
		esc_html_e( 'Send an email when SMS consent changes.', 'acu' );
		echo '</label>';
	}

	public static function field_admin_email(): void {
		$val = esc_attr( get_option( 'acu_admin_email', '' ) );
		echo '<input type="email" name="acu_admin_email" value="' . $val . '" class="regular-text" placeholder="admin@example.com" />';
		echo ' <button type="button" class="button" id="acu-test-email-btn">' . esc_html__( 'Send Test Email', 'acu' ) . '</button>';
		echo '<span id="acu-test-email-status" style="margin-left:10px;"></span>';
		// Inline script for test email button
		$nonce    = wp_create_nonce( 'acu_test_email' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		echo '<script>
		(function(){
			var btn=document.getElementById("acu-test-email-btn");
			var status=document.getElementById("acu-test-email-status");
			if(!btn)return;
			btn.addEventListener("click",function(){
				btn.disabled=true;status.textContent=' . wp_json_encode( __( 'Sending…', 'acu' ) ) . ';
				var fd=new FormData();
				fd.append("action","acu_test_email");
				fd.append("_nonce",' . wp_json_encode( $nonce ) . ');
				fetch(' . wp_json_encode( $ajax_url ) . ',{method:"POST",body:fd,credentials:"same-origin"})
					.then(function(r){return r.json();})
					.then(function(r){
						status.textContent=r.success?(r.data.message||"OK"):(r.data.message||"Error");
						status.style.color=r.success?"#2e7d32":"#c62828";
						btn.disabled=false;
					}).catch(function(){status.textContent="Request failed";btn.disabled=false;});
			});
		})();
		</script>';
	}

	// -------------------------------------------------------------------------
	// Field renderers — Terms
	// -------------------------------------------------------------------------

	public static function field_terms_url(): void {
		$val = esc_url( get_option( 'acu_terms_url', '' ) );
		echo '<input type="url" name="acu_terms_url" value="' . $val . '" class="regular-text" placeholder="https://example.com/terms" />';
		echo '<p class="description">' . esc_html__( 'Used as fallback if editor content is empty.', 'acu' ) . '</p>';

		// Show WC terms page URL if detected
		$wc_page_id = get_option( 'woocommerce_terms_page_id' );
		if ( $wc_page_id ) {
			$wc_url = get_permalink( (int) $wc_page_id );
			if ( $wc_url ) {
				echo '<p class="description">' . sprintf(
					esc_html__( 'WooCommerce Terms page detected (auto-fallback): %s', 'acu' ),
					'<a href="' . esc_url( $wc_url ) . '" target="_blank">' . esc_html( $wc_url ) . '</a>'
				) . '</p>';
			}
		}
	}

	public static function field_terms_html(): void {
		$val = get_option( 'acu_terms_html', '' );
		echo '<p class="description">' . esc_html__( 'Default Terms & Conditions HTML (for print page and registration form).', 'acu' ) . '</p>';
		wp_editor( $val, 'acu_terms_html', [
			'textarea_name' => 'acu_terms_html',
			'media_buttons' => true,
			'textarea_rows' => 14,
			'editor_height' => 320,
		] );
	}

	public static function field_sms_terms_html(): void {
		$val = get_option( 'acu_sms_terms_html', '' );
		echo '<p class="description">' . esc_html__( 'Content for Print SMS Terms page (/signature-terms/?terms_type=sms).', 'acu' ) . '</p>';
		wp_editor( $val, 'acu_sms_terms_html', [
			'textarea_name' => 'acu_sms_terms_html',
			'media_buttons' => true,
			'textarea_rows' => 14,
			'editor_height' => 320,
		] );
	}

	public static function field_call_terms_html(): void {
		$val = get_option( 'acu_call_terms_html', '' );
		echo '<p class="description">' . esc_html__( 'Content for Print Phone Call Terms page (/signature-terms/?terms_type=call).', 'acu' ) . '</p>';
		wp_editor( $val, 'acu_call_terms_html', [
			'textarea_name' => 'acu_call_terms_html',
			'media_buttons' => true,
			'textarea_rows' => 14,
			'editor_height' => 320,
		] );
	}

	// -------------------------------------------------------------------------
	// Field renderers — Club Card
	// -------------------------------------------------------------------------

	public static function field_auto_apply_club(): void {
		$val = (bool) get_option( 'acu_auto_apply_club', false );
		echo '<label><input type="checkbox" name="acu_auto_apply_club" value="1" ' . checked( $val, true, false ) . ' /> ';
		esc_html_e( 'Automatically apply the saved Club Card coupon (once per session).', 'acu' );
		echo '</label>';
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Club Member Settings', 'acu' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'acu_settings_group' );
				do_settings_sections( 'acu-settings' );
				submit_button();
				?>
			</form>

			<?php // Delegate import/export tools to ACU_Admin ?>
			<?php do_action( 'acu_settings_page_tools' ); ?>

			<hr />
			<h2><?php esc_html_e( 'Available Shortcodes', 'acu' ); ?></h2>
			<ul>
				<li><code>[club_anketa_form]</code> — <?php esc_html_e( 'Anketa registration form.', 'acu' ); ?></li>
				<li><code>[user_data_check]</code> — <?php esc_html_e( 'Staff user data search (email/phone/personal ID/club card).', 'acu' ); ?></li>
				<li><code>[acm_print_terms_button]</code> — <?php esc_html_e( 'Print Terms button. Attrs: label, class, type (default/sms/call).', 'acu' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Print pages: /print-anketa/?user_id=ID | /signature-terms/?user_id=ID&terms_type=sms|call', 'acu' ); ?></p>
		</div>
		<?php
	}
}
