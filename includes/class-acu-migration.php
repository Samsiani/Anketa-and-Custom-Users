<?php
/**
 * ACU_Migration — One-time data migration from legacy plugins.
 *
 * Runs on activation (idempotent via version check).
 * Does NOT delete old options (safe rollback).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Migration {

	const MIGRATION_VERSION = '1.0';

	public static function run(): void {
		if ( get_option( 'acu_migration_version', '' ) === self::MIGRATION_VERSION ) {
			return; // Already migrated
		}

		self::migrate_options();
		self::migrate_user_meta();
		self::migrate_db_table();

		update_option( 'acu_migration_version', self::MIGRATION_VERSION );
	}

	// -------------------------------------------------------------------------
	// Options migration
	// -------------------------------------------------------------------------

	private static function migrate_options(): void {
		$map = [
			// SMS credentials (from Anketa)
			'club_anketa_sms_username'              => 'acu_sms_username',
			'club_anketa_sms_password'              => 'acu_sms_password',
			'club_anketa_sms_client_id'             => 'acu_sms_client_id',
			'club_anketa_sms_service_id'            => 'acu_sms_service_id',
			// Email (prefer Anketa; WCU as fallback)
			'club_anketa_notification_email'        => 'acu_admin_email',
			'club_anketa_enable_email_notification' => 'acu_enable_email_notification',
			// Terms
			'club_anketa_terms_url'                 => 'acu_terms_url',
			'club_anketa_terms_html'                => 'acu_terms_html',
			'club_anketa_sms_terms_html'            => 'acu_sms_terms_html',
			'club_anketa_call_terms_html'           => 'acu_call_terms_html',
		];

		foreach ( $map as $old_key => $new_key ) {
			// Only migrate if new option not already set
			if ( get_option( $new_key, '__not_set__' ) === '__not_set__' ) {
				$old_val = get_option( $old_key );
				if ( $old_val !== false ) {
					update_option( $new_key, $old_val );
				}
			}
		}

		// WCU-only options (fallback if Anketa values not present)
		$wcu_map = [
			'wcu_admin_email'    => 'acu_admin_email',
			'wcu_terms_url'      => 'acu_terms_url',
			'wcu_auto_apply_club'=> 'acu_auto_apply_club',
		];

		foreach ( $wcu_map as $old_key => $new_key ) {
			if ( get_option( $new_key, '__not_set__' ) === '__not_set__' ) {
				$old_val = get_option( $old_key );
				if ( $old_val !== false ) {
					update_option( $new_key, $old_val );
				}
			}
		}

		// WCU terms text → acu_terms_html (if Anketa HTML not migrated)
		if ( get_option( 'acu_terms_html', '' ) === '' ) {
			$wcu_text = get_option( 'wcu_terms_text', '' );
			if ( $wcu_text !== '' ) {
				update_option( 'acu_terms_html', $wcu_text );
			}
		}
	}

	// -------------------------------------------------------------------------
	// User meta migration
	// -------------------------------------------------------------------------

	private static function migrate_user_meta(): void {
		global $wpdb;

		$meta_map = [
			'_personal_id'         => '_acu_personal_id',
			'_club_card_coupon'    => '_acu_club_card_coupon',
			'_wcu_terms_accepted'  => '_acu_terms_accepted',
			'_verified_phone_number' => '_acu_verified_phone',
			'_anketa_personal_id'  => '_acu_personal_id',
			'_anketa_dob'          => '_acu_dob',
			'_anketa_card_no'      => '_acu_card_no',
			'_anketa_responsible_person' => '_acu_responsible_person',
			'_anketa_form_date'    => '_acu_form_date',
			'_anketa_shop'         => '_acu_shop',
		];

		foreach ( $meta_map as $old_key => $new_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} um
					 INNER JOIN {$wpdb->usermeta} um2 ON um2.user_id = um.user_id AND um2.meta_key = %s
					 SET um.meta_key = %s
					 WHERE um.meta_key = %s
					   AND NOT EXISTS (
					       SELECT 1 FROM (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s) x
					       WHERE x.user_id = um.user_id
					   )",
					$old_key, $new_key, $old_key, $new_key
				)
			);

			// Simpler update: rename old key to new where new doesn't exist
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = %s
					 WHERE meta_key = %s
					   AND user_id NOT IN (
					       SELECT user_id FROM (
					           SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s
					       ) AS sub
					   )",
					$new_key,
					$old_key,
					$new_key
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// DB table migration
	// -------------------------------------------------------------------------

	private static function migrate_db_table(): void {
		global $wpdb;

		$old_table = $wpdb->prefix . 'club_anketa_external_phones';
		$new_table = $wpdb->prefix . 'acu_external_phones';

		// Check old table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table )
		);

		if ( ! $old_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "INSERT IGNORE INTO `{$new_table}` (phone, created_at) SELECT phone, created_at FROM `{$old_table}`" );
	}
}
