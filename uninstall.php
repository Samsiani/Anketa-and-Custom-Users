<?php
/**
 * Uninstall handler — runs when admin deletes the plugin from WP admin.
 * Drops the external phones table and purges all acu_ options and transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop external phones table
$table = $wpdb->prefix . 'acu_external_phones';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

// Delete all acu_ options
$option_keys = [
	'acu_sms_username',
	'acu_sms_password',
	'acu_sms_client_id',
	'acu_sms_service_id',
	'acu_admin_email',
	'acu_enable_email_notification',
	'acu_terms_url',
	'acu_terms_html',
	'acu_sms_terms_html',
	'acu_call_terms_html',
	'acu_auto_apply_club',
	'acu_db_version',
	'acu_migration_version',
];
foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// Delete all acu_ transients (search by prefix)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_acu_%'
	    OR option_name LIKE '_transient_timeout_acu_%'"
);
