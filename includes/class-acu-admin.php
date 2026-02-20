<?php
/**
 * ACU_Admin — WP admin integration.
 *
 * - Single set of user list columns: acu_phone, acu_sms, acu_call
 * - CSV import: SMS consent (by phone)
 * - CSV import: external phone whitelist
 * - CSV export: users with phone + consent data
 * - Download import example CSV
 * - AJAX: acu_bulk_link — link club card coupons to all users (100/batch)
 * - AJAX: acu_test_email — send test email
 * - Settings page tools rendered via acu_settings_page_tools action
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Admin {

	public static function init(): void {
		// User list columns
		add_filter( 'manage_users_columns',       [ self::class, 'add_user_columns' ] );
		add_filter( 'manage_users_custom_column', [ self::class, 'render_user_column' ], 10, 3 );

		// Settings page tools section
		add_action( 'acu_settings_page_tools', [ self::class, 'render_tools_section' ] );

		// Admin enqueue (settings page only)
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_admin_assets' ] );

		// admin-post.php handlers
		add_action( 'admin_post_acu_export_users',             [ self::class, 'handle_export_users' ] );
		add_action( 'admin_post_acu_download_import_example',  [ self::class, 'handle_download_import_example' ] );

		// AJAX handlers
		add_action( 'wp_ajax_acu_bulk_link',  [ self::class, 'ajax_bulk_link' ] );
		add_action( 'wp_ajax_acu_test_email', [ self::class, 'ajax_test_email' ] );
	}

	// -------------------------------------------------------------------------
	// User list columns
	// -------------------------------------------------------------------------

	public static function add_user_columns( array $columns ): array {
		$columns['acu_phone'] = __( 'Phone Number', 'acu' );
		$columns['acu_sms']   = __( 'SMS Accept', 'acu' );
		$columns['acu_call']  = __( 'Call Accept', 'acu' );
		return $columns;
	}

	public static function render_user_column( string $out, string $name, int $user_id ): string {
		if ( $name === 'acu_phone' ) {
			return esc_html( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		}
		if ( $name === 'acu_sms' ) {
			return self::consent_badge( ACU_Helpers::get_sms_consent( $user_id ) );
		}
		if ( $name === 'acu_call' ) {
			return self::consent_badge( ACU_Helpers::get_call_consent( $user_id ) );
		}
		return $out;
	}

	private static function consent_badge( string $consent ): string {
		if ( $consent === 'yes' ) {
			return '<span style="color:#2e7d32;font-weight:600;">' . esc_html__( 'Yes', 'acu' ) . '</span>';
		}
		if ( $consent === 'no' ) {
			return '<span style="color:#c62828;font-weight:600;">' . esc_html__( 'No', 'acu' ) . '</span>';
		}
		return '<span style="color:#616161;">' . esc_html__( '(blank)', 'acu' ) . '</span>';
	}

	// -------------------------------------------------------------------------
	// Admin assets (settings page only)
	// -------------------------------------------------------------------------

	public static function enqueue_admin_assets( string $hook ): void {
		if ( $hook !== 'settings_page_acu-settings' ) {
			return;
		}
		wp_enqueue_script( 'acu-admin', ACU_URL . 'assets/js/admin.js', [], ACU_VERSION, true );
		wp_localize_script( 'acu-admin', 'acuAdmin', [
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'bulk_nonce'    => wp_create_nonce( 'acu_bulk_link' ),
			'i18n'          => [
				'processing'    => __( 'Processing…', 'acu' ),
				'completed'     => __( 'Completed!', 'acu' ),
				'error'         => __( 'Error occurred.', 'acu' ),
				'request_failed'=> __( 'Request failed.', 'acu' ),
				'users_processed'=> __( 'users processed', 'acu' ),
				'coupons_linked' => __( 'coupons linked', 'acu' ),
				'errors_label'   => __( 'errors', 'acu' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Settings page tools section (hooked via acu_settings_page_tools)
	// -------------------------------------------------------------------------

	public static function render_tools_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle SMS consent import
		if ( isset( $_POST['acu_run_import'] ) ) {
			check_admin_referer( 'acu_import_sms', 'acu_import_nonce' );
			self::handle_sms_consent_import();
		}

		// Handle external phones import
		if ( isset( $_POST['acu_run_external_import'] ) ) {
			check_admin_referer( 'acu_import_external', 'acu_external_nonce' );
			self::handle_external_phones_import();
		}

		// Handle clear external phones
		if ( isset( $_POST['acu_clear_external_phones'] ) ) {
			check_admin_referer( 'acu_clear_external', 'acu_clear_nonce' );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acu_external_phones" );
			add_settings_error( 'acu_tools', 'clear_success', __( 'External phone database cleared.', 'acu' ), 'success' );
		}

		settings_errors( 'acu_tools' );

		global $wpdb;
		$external_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}acu_external_phones" ); // phpcs:ignore

		$export_nonce  = wp_create_nonce( 'acu_export_users' );
		$example_nonce = wp_create_nonce( 'acu_download_import_example' );
		$export_url    = add_query_arg( [ 'action' => 'acu_export_users', '_wpnonce' => $export_nonce ], admin_url( 'admin-post.php' ) );
		$example_url   = add_query_arg( [ 'action' => 'acu_download_import_example', '_wpnonce' => $example_nonce ], admin_url( 'admin-post.php' ) );
		?>
		<hr/>
		<h2><?php esc_html_e( 'Bulk Import: Set SMS Consent from CSV', 'acu' ); ?></h2>
		<p><?php esc_html_e( 'Upload a CSV with the first column being a phone number. Matched users will have their SMS consent updated.', 'acu' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $example_url ); ?>"><?php esc_html_e( 'Download import example CSV', 'acu' ); ?></a></p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'acu_import_sms', 'acu_import_nonce' ); ?>
			<p>
				<label for="acu_csv_file"><strong><?php esc_html_e( 'CSV File', 'acu' ); ?></strong></label><br/>
				<input type="file" id="acu_csv_file" name="acu_csv_file" accept=".csv,text/csv" required />
			</p>
			<p>
				<strong><?php esc_html_e( 'Set consent status to:', 'acu' ); ?></strong><br/>
				<label><input type="radio" name="acu_import_consent" value="yes" checked/> <?php esc_html_e( 'Yes', 'acu' ); ?></label>
				<label style="margin-left:1em;"><input type="radio" name="acu_import_consent" value="no"/> <?php esc_html_e( 'No', 'acu' ); ?></label>
			</p>
			<?php submit_button( __( 'Run Import', 'acu' ), 'primary', 'acu_run_import' ); ?>
		</form>

		<hr/>
		<h2><?php esc_html_e( 'Export Users (CSV)', 'acu' ); ?></h2>
		<p><?php esc_html_e( 'Exports users with phone & SMS consent (only users with a saved phone number).', 'acu' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export users CSV', 'acu' ); ?></a></p>

		<hr/>
		<h2><?php esc_html_e( 'External Phone Database (SMS Consent Whitelist)', 'acu' ); ?></h2>
		<p><?php esc_html_e( 'Import phone numbers of non-registered users who have given SMS consent. Searchable via the user data check shortcode.', 'acu' ); ?></p>
		<p><strong><?php printf( esc_html__( 'Current entries in database: %d', 'acu' ), $external_count ); ?></strong></p>

		<form method="post" enctype="multipart/form-data" style="margin-bottom:1em;">
			<?php wp_nonce_field( 'acu_import_external', 'acu_external_nonce' ); ?>
			<p>
				<label for="acu_external_csv_file"><strong><?php esc_html_e( 'CSV File (phone numbers)', 'acu' ); ?></strong></label><br/>
				<input type="file" id="acu_external_csv_file" name="acu_external_csv_file" accept=".csv,text/csv" required />
			</p>
			<?php submit_button( __( 'Import External Phones', 'acu' ), 'primary', 'acu_run_external_import', false ); ?>
		</form>

		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all external phone numbers?', 'acu' ) ); ?>');">
			<?php wp_nonce_field( 'acu_clear_external', 'acu_clear_nonce' ); ?>
			<?php submit_button( __( 'Clear All External Phones', 'acu' ), 'secondary', 'acu_clear_external_phones', false ); ?>
		</form>

		<hr/>
		<h2><?php esc_html_e( 'Link Club Cards to All Users', 'acu' ); ?></h2>
		<p><?php esc_html_e( 'Scan all users and link Club Card coupons from ERP Sync by matching phone numbers. Processes 100 users per batch.', 'acu' ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="acu-bulk-link-btn"><?php esc_html_e( 'Link Club Cards to All Users', 'acu' ); ?></button>
		</p>
		<div id="acu-bulk-link-status" style="display:none;margin-top:10px;">
			<div style="background:#f0f0f1;border:1px solid #c3c4c7;padding:12px 16px;border-radius:4px;">
				<p id="acu-bulk-link-progress" style="margin:0 0 8px;font-weight:600;"></p>
				<div style="background:#ddd;border-radius:3px;height:20px;overflow:hidden;">
					<div id="acu-bulk-link-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s;"></div>
				</div>
				<p id="acu-bulk-link-stats" style="margin:8px 0 0;color:#50575e;"></p>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// CSV import: SMS consent
	// -------------------------------------------------------------------------

	private static function handle_sms_consent_import(): void {
		if ( ! isset( $_FILES['acu_csv_file'] ) || $_FILES['acu_csv_file']['error'] !== UPLOAD_ERR_OK ) {
			add_settings_error( 'acu_tools', 'no_file', __( 'Please select a file to upload.', 'acu' ), 'error' );
			return;
		}

		$ext = strtolower( pathinfo( (string) $_FILES['acu_csv_file']['name'], PATHINFO_EXTENSION ) );
		if ( $ext !== 'csv' ) {
			add_settings_error( 'acu_tools', 'invalid_ext', __( 'Please upload a CSV file.', 'acu' ), 'error' );
			return;
		}

		$consent_value = ( isset( $_POST['acu_import_consent'] ) && $_POST['acu_import_consent'] === 'no' ) ? 'no' : 'yes';
		$file_tmp      = (string) $_FILES['acu_csv_file']['tmp_name'];
		$handle        = fopen( $file_tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			add_settings_error( 'acu_tools', 'file_error', __( 'Unable to read file.', 'acu' ), 'error' );
			return;
		}

		$updated = 0;
		$skipped = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( empty( $row[0] ) ) {
				continue;
			}
			$norm = ACU_Helpers::normalize_phone( (string) $row[0] );
			if ( $norm === '' || strlen( $norm ) !== 9 ) {
				$skipped++;
				continue;
			}
			$users = get_users( [
				'meta_key'    => 'billing_phone',
				'meta_value'  => $norm,
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'ids',
			] );
			if ( ! empty( $users ) ) {
				update_user_meta( (int) $users[0], '_sms_consent', $consent_value );
				$updated++;
			} else {
				$skipped++;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		add_settings_error(
			'acu_tools',
			'import_success',
			sprintf(
				/* translators: 1: updated count, 2: skipped count */
				__( 'Import completed. Updated: %1$d, Skipped: %2$d', 'acu' ),
				$updated,
				$skipped
			),
			'success'
		);
	}

	// -------------------------------------------------------------------------
	// CSV import: external phones
	// -------------------------------------------------------------------------

	private static function handle_external_phones_import(): void {
		if ( ! isset( $_FILES['acu_external_csv_file'] ) || $_FILES['acu_external_csv_file']['error'] !== UPLOAD_ERR_OK ) {
			add_settings_error( 'acu_tools', 'no_file', __( 'Please select a file to upload.', 'acu' ), 'error' );
			return;
		}

		$ext = strtolower( pathinfo( (string) $_FILES['acu_external_csv_file']['name'], PATHINFO_EXTENSION ) );
		if ( $ext !== 'csv' ) {
			add_settings_error( 'acu_tools', 'invalid_ext', __( 'Please upload a CSV file.', 'acu' ), 'error' );
			return;
		}

		$file_tmp = (string) $_FILES['acu_external_csv_file']['tmp_name'];
		$handle   = fopen( $file_tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			add_settings_error( 'acu_tools', 'file_error', __( 'Unable to read file.', 'acu' ), 'error' );
			return;
		}

		$imported = 0;
		$skipped  = 0;
		$batch    = [];

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( empty( $row[0] ) ) {
				continue;
			}
			$norm = ACU_Helpers::normalize_phone( (string) $row[0] );
			if ( $norm !== '' && strlen( $norm ) === 9 ) {
				$batch[] = $norm;
				if ( count( $batch ) >= 1000 ) {
					$imported += self::batch_insert_external_phones( $batch );
					$batch     = [];
				}
			} else {
				$skipped++;
			}
		}
		if ( ! empty( $batch ) ) {
			$imported += self::batch_insert_external_phones( $batch );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		add_settings_error(
			'acu_tools',
			'import_success',
			sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Import completed. Imported: %1$d, Skipped: %2$d', 'acu' ),
				$imported,
				$skipped
			),
			'success'
		);
	}

	private static function batch_insert_external_phones( array $phones ): int {
		global $wpdb;
		if ( empty( $phones ) ) {
			return 0;
		}
		$table        = $wpdb->prefix . 'acu_external_phones';
		$placeholders = implode( ',', array_fill( 0, count( $phones ), '(%s)' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (phone) VALUES {$placeholders}", $phones ) );
		return $wpdb->rows_affected;
	}

	// -------------------------------------------------------------------------
	// admin-post.php: export users CSV
	// -------------------------------------------------------------------------

	public static function handle_export_users(): void {
		check_admin_referer( 'acu_export_users' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'acu' ) );
		}

		$filename = 'acu-users-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM for Excel
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, [ 'user_id', 'email', 'first_name', 'last_name', 'phone', 'sms_consent', 'call_consent' ] );

		$paged    = 1;
		$per_page = 100;

		do {
			$users = get_users( [
				'number'  => $per_page,
				'paged'   => $paged,
				'fields'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'meta_key'     => 'billing_phone',
				'meta_compare' => '!=',
				'meta_value'   => '',
			] );

			foreach ( $users as $uid ) {
				$user = get_userdata( (int) $uid );
				if ( ! $user ) {
					continue;
				}

				$sms_consent  = ACU_Helpers::get_sms_consent( (int) $uid );
				$call_consent = ACU_Helpers::get_call_consent( (int) $uid );

				if ( $sms_consent !== 'yes' && $call_consent !== 'yes' ) {
					continue;
				}

				$raw_phone = ACU_Helpers::get_user_phone( (int) $uid );
				$phone_9   = ACU_Helpers::normalize_phone( $raw_phone );

				fputcsv( $out, [
					$uid,
					$user->user_email,
					$user->first_name,
					$user->last_name,
					$phone_9,
					$sms_consent,
					$call_consent,
				] );
			}
			$paged++;
		} while ( count( $users ) === $per_page );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// -------------------------------------------------------------------------
	// admin-post.php: download import example CSV
	// -------------------------------------------------------------------------

	public static function handle_download_import_example(): void {
		check_admin_referer( 'acu_download_import_example' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'acu' ) );
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="acu-import-example.csv"' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, [ 'phone' ] );
		fputcsv( $out, [ '555123456' ] );
		fputcsv( $out, [ '+995599123456' ] );
		fputcsv( $out, [ '577000000' ] );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: bulk link club cards
	// -------------------------------------------------------------------------

	public static function ajax_bulk_link(): void {
		check_ajax_referer( 'acu_bulk_link', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'acu' ) ] );
		}

		$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$per_page = 100;

		$query    = new WP_User_Query( [
			'number'  => $per_page,
			'offset'  => $offset,
			'fields'  => 'ids',
			'orderby' => 'ID',
			'order'   => 'ASC',
		] );

		$user_ids  = $query->get_results();
		$total     = $query->get_total();
		$linked    = 0;

		foreach ( $user_ids as $uid ) {
			if ( ACU_Helpers::link_coupon_to_user( (int) $uid ) ) {
				$linked++;
			}
		}

		$processed = $offset + count( $user_ids );
		$done      = $processed >= $total;

		wp_send_json_success( [
			'processed' => $processed,
			'linked'    => $linked,
			'total'     => $total,
			'done'      => $done,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: test email
	// -------------------------------------------------------------------------

	public static function ajax_test_email(): void {
		check_ajax_referer( 'acu_test_email', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'acu' ) ] );
		}

		$admin_email = ACU_Helpers::get_admin_notify_email();
		if ( ! $admin_email ) {
			wp_send_json_error( [ 'message' => __( 'No valid admin email configured.', 'acu' ) ] );
		}

		$sent = wp_mail(
			$admin_email,
			__( 'ACU Plugin – Test Email', 'acu' ),
			__( 'This is a test email from the Anketa & Custom Users plugin. If you received this, your SMTP configuration is working correctly.', 'acu' )
		);

		if ( $sent ) {
			wp_send_json_success( [ 'message' => sprintf( __( 'Test email sent to %s.', 'acu' ), $admin_email ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to send test email. Please check your SMTP settings.', 'acu' ) ] );
		}
	}
}
