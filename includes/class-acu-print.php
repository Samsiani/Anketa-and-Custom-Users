<?php
/**
 * ACU_Print — Virtual print pages via rewrite rules.
 *
 * Registers:
 *  /print-anketa/?user_id=ID          → templates/print-anketa.php
 *  /signature-terms/?user_id=ID&terms_type=sms|call|default → templates/signature-terms.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACU_Print {

	public static function init(): void {
		add_action( 'init',             [ self::class, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',       [ self::class, 'add_query_vars' ] );
		add_filter( 'template_include', [ self::class, 'template_include' ] );
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	public static function register_rewrite_rules(): void {
		add_rewrite_rule( '^print-anketa/?$',    'index.php?acu_print_page=anketa',          'top' );
		add_rewrite_rule( '^signature-terms/?$', 'index.php?acu_print_page=signature-terms', 'top' );
	}

	public static function add_query_vars( array $vars ): array {
		$vars[] = 'acu_print_page';
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Template loader
	// -------------------------------------------------------------------------

	public static function template_include( string $template ): string {
		$page = (string) get_query_var( 'acu_print_page', '' );

		if ( $page === 'anketa' ) {
			$file = ACU_DIR . 'templates/print-anketa.php';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		if ( $page === 'signature-terms' ) {
			$file = ACU_DIR . 'templates/signature-terms.php';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		return $template;
	}
}
