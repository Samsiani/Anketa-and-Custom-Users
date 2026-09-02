<?php
/**
 * ACU_Consent_Lock — one-shot SMS / call consent, enforced at the user-meta layer.
 *
 * Once `_sms_consent` or `_call_consent` holds a Yes/No it can never be changed
 * or removed: not from My Account, not from the staff Anketa form, not by the
 * CSV import, REST, wp-cli `user meta update`, or any other plugin. The UI locks
 * (ACU_Helpers::is_*_consent_locked) exist for a clear experience; this class is
 * the actual guarantee.
 *
 * A phone present in the uploaded SMS whitelist (`acu_external_phones`) counts
 * as a recorded SMS "yes", so such a user can only ever be set to "yes".
 *
 * Deliberate override (data correction by an admin):
 *   add_filter( 'acu_consent_lock_enabled', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Consent_Lock {

	private const KEYS = [ '_sms_consent', '_call_consent' ];

	public static function init(): void {
		add_filter( 'update_user_metadata', [ self::class, 'guard_write' ],  10, 5 );
		add_filter( 'add_user_metadata',    [ self::class, 'guard_write' ],  10, 5 );
		add_filter( 'delete_user_metadata', [ self::class, 'guard_delete' ], 10, 5 );
	}

	/**
	 * Short-circuit update_user_meta() / add_user_meta() when the value would
	 * change a consent that is already recorded. Same-value writes pass through.
	 *
	 * @param mixed  $check      Null to proceed, non-null to short-circuit.
	 * @param int    $user_id    User being written.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return mixed
	 */
	public static function guard_write( $check, $user_id, $meta_key, $meta_value ) {
		if ( null !== $check || ! in_array( $meta_key, self::KEYS, true ) ) {
			return $check;
		}

		$current = self::current( (int) $user_id, $meta_key );
		if ( $current === '' || ! self::enabled( (int) $user_id, $meta_key ) ) {
			return $check;
		}

		$new = is_string( $meta_value ) ? strtolower( trim( $meta_value ) ) : '';

		return $new === $current ? $check : false;
	}

	/**
	 * Deleting a recorded consent would reopen the one-time choice — block it.
	 */
	public static function guard_delete( $check, $user_id, $meta_key ) {
		if ( null !== $check || ! in_array( $meta_key, self::KEYS, true ) ) {
			return $check;
		}
		if ( self::current( (int) $user_id, $meta_key ) === '' || ! self::enabled( (int) $user_id, $meta_key ) ) {
			return $check;
		}
		return false;
	}

	/**
	 * The value the lock protects: 'yes' / 'no', or '' when nothing is recorded yet.
	 * For SMS this includes the whitelist-derived "yes".
	 */
	private static function current( int $user_id, string $meta_key ): string {
		return '_sms_consent' === $meta_key
			? ACU_Helpers::effective_sms_consent( $user_id )
			: ACU_Helpers::get_call_consent( $user_id );
	}

	private static function enabled( int $user_id, string $meta_key ): bool {
		/**
		 * Filter whether the consent lock is enforced for this write.
		 *
		 * @param bool   $enabled  Default true.
		 * @param int    $user_id  User being written.
		 * @param string $meta_key `_sms_consent` or `_call_consent`.
		 */
		return (bool) apply_filters( 'acu_consent_lock_enabled', true, $user_id, $meta_key );
	}
}
