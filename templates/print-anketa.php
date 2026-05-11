<?php
/**
 * Printable Anketa form
 * URL: /print-anketa/?user_id=123
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
$user    = $user_id ? get_user_by( 'ID', $user_id ) : false;
if ( ! $user ) {
	status_header( 404 );
	wp_die( esc_html__( 'User not found.', 'acu' ) );
}

// Staff-only: print pages expose full user PII
if ( ! ACU_Helpers::current_user_can_manage_members() ) {
	status_header( 403 );
	wp_die( esc_html__( 'Access denied.', 'acu' ) );
}

$meta = function ( string $key, string $default = '' ) use ( $user_id ): string {
	$v = (string) get_user_meta( $user_id, $key, true );
	return $v !== '' ? $v : $default;
};

$first_name  = $user->first_name;
$last_name   = $user->last_name;
$personal_id = $meta( '_acu_personal_id' );
$dob         = $meta( '_acu_dob' );
$billing_raw = (string) get_user_meta( $user_id, 'billing_phone', true );
$address_1   = (string) get_user_meta( $user_id, 'billing_address_1', true );
$email       = $user->user_email;
$card_no     = $meta( '_acu_card_no' );
$responsible = $meta( '_acu_responsible_person' );
$form_date   = $meta( '_acu_form_date' );
$shop         = $meta( '_acu_shop' );

// Format phone: prefer "+995 9digits"
$format_phone = function ( string $raw ): string {
	$digits = preg_replace( '/\D+/', '', $raw );
	if ( preg_match( '/^995(\d{9})$/', $digits, $m ) ) {
		return '+995 ' . $m[1];
	}
	if ( preg_match( '/^\d{9}$/', $digits ) ) {
		return '+995 ' . $digits;
	}
	return trim( $raw );
};
$phone = $format_phone( $billing_raw );

// Format date to dd-mm-yyyy regardless of stored format
$format_date = function ( string $raw ): string {
	if ( $raw === '' ) {
		return $raw;
	}
	$formats = [ 'Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y', 'm/d/Y', 'Y/m/d' ];
	foreach ( $formats as $fmt ) {
		$dt = DateTime::createFromFormat( $fmt, $raw );
		if ( $dt && $dt->format( $fmt ) === $raw ) {
			return $dt->format( 'd-m-Y' );
		}
	}
	return $raw; // Return as-is if format is unrecognised
};

// Render boxed digit cells
$boxed = function ( string $text, int $boxes = 11 ): string {
	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$cells = [];
	for ( $i = 0; $i < $boxes; $i++ ) {
		$cells[] = isset( $chars[ $i ] ) ? esc_html( $chars[ $i ] ) : '&nbsp;';
	}
	return '<div class="boxes boxes-' . $boxes . '"><span>' . implode( '</span><span>', $cells ) . '</span></div>';
};

// Derived display values used for empty-checks
$email_display = str_ends_with( $email, '@no-email.local' ) ? '' : $email;

$sms_terms_link  = esc_url( add_query_arg( [ 'user_id' => $user_id, 'terms_type' => 'sms' ],  home_url( '/signature-terms/' ) ) );
$call_terms_link = esc_url( add_query_arg( [ 'user_id' => $user_id, 'terms_type' => 'call' ], home_url( '/signature-terms/' ) ) );

// Edit Anketa URL: find the page hosting [club_anketa_form].
$anketa_page_id = ACU_Helpers::find_anketa_page_id();
$edit_anketa_url = $anketa_page_id
	? esc_url( add_query_arg( 'edit_user', $user_id, get_permalink( (int) $anketa_page_id ) ) )
	: '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__( 'Print Anketa', 'acu' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( ACU_URL . 'assets/css/print.css?v=' . urlencode( ACU_VERSION ) ); ?>" media="all" />
</head>
<body>
<?php
$printer_icon = '<svg class="acu-print-icon" viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>';
?>
<div class="print-actions">
	<button onclick="window.print()"><?php echo $printer_icon; // safe inline SVG ?><span><?php echo esc_html__( 'Print Anketa', 'acu' ); ?></span></button>
	<a class="button button-secondary print-terms-btn" href="<?php echo $sms_terms_link; ?>"><?php echo esc_html__( 'SMS Terms', 'acu' ); ?></a>
	<a class="button button-secondary print-terms-btn" href="<?php echo $call_terms_link; ?>"><?php echo esc_html__( 'Call Terms', 'acu' ); ?></a>
	<?php if ( $edit_anketa_url !== '' ) : ?>
	<a class="button button-secondary print-terms-btn" href="<?php echo $edit_anketa_url; ?>"><?php echo esc_html__( 'Edit Anketa', 'acu' ); ?></a>
	<?php endif; ?>
</div>

<div class="page">
	<h1 class="title"><?php echo esc_html__( 'გახდი შპს "ართთაიმის" ს/კ 202356672 კლუბის წევრი!', 'acu' ); ?></h1>

	<?php if ( $personal_id !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'პირადი ნომერი', 'acu' ); ?></div>
		<div class="value value-boxes">
			<?php echo $boxed( $personal_id, 11 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( trim( $first_name ) !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'სახელი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $first_name ); ?></div>
	</div>
	<?php endif; ?>

	<?php if ( trim( $last_name ) !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'გვარი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $last_name ); ?></div>
	</div>
	<?php endif; ?>

	<?php if ( $dob !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'დაბადების თარიღი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $format_date( $dob ) ); ?></div>
	</div>
	<?php endif; ?>

	<?php if ( $phone !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'ტელეფონის ნომერი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $phone ); ?></div>
	</div>
	<?php endif; ?>

	<div class="row">
		<div class="label"><?php esc_html_e( 'მისამართი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $address_1 ); ?></div>
	</div>

	<div class="row">
		<div class="label"><?php esc_html_e( 'E-mail', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $email_display ); ?></div>
	</div>

	<div class="rules">
		<div class="rules-title"><?php esc_html_e( 'წესები და პირობები', 'acu' ); ?></div>
		<div class="rules-inner">
			<?php
			echo wp_kses_post( __( '<p><strong>Arttime-ის კლუბის წევრები სარგებლობენ შემდეგი უპირატესობით:</strong></p>
<ul>
<li>ბარათზე 500-5000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 5%</li>
<li>ბარათზე 5001-10000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 10%;</li>
<li>ბარათზე 10 000 ლარზე მეტის დაგროვების შემთხვევაში ფასდაკლება 15%.</li>
</ul>
<p>&nbsp;</p>
<p><strong>გთხოვთ გაითვალისწინოთ:</strong></p>
<ol>
<li>ართთაიმის კლუბის ბარათით გათვალისწინებული ფასდაკლება არ მოქმედებს ფასდაკლებელ პროდუქციაზე;</li>
<li>ფასდაკლებული პროდუქციის შეძენის შემთხვევაში ბარათზე მხოლოდ ქულები დაგერიცხებათ;</li>
<li>ფასდაკლება მოქმედებს, მაგრამ ქულები არ გერიცხებათ პროდუქციის სასაჩუქრე ვაუჩერით შემენისას;</li>
<li>სასაჩუქრე ვაუჩერის შეძენისას ფასდაკლება არ მოქმედებს, მაგრამ ქულები გროვდება;</li>
<li>დაგროვილი ქულები ბარათზე აისახება 2 სამუშაო დღის ვადაში;</li>
<li>გაითვალისწინეთ, წინამდებარე წესებით დადგენილი პირობები შეიძლება შეიცვალოს შპს „ართთაიმის" მიერ;</li>
<li>ხელმოწერით ვადასტურებ ჩემი პირადი მონაცემების სიზუსტეს და ბარათის მიღებას.</li>
</ol>', 'acu' ) );
			?>
		</div>
	</div>

	<?php if ( $card_no !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'მივიღე ბარათი №', 'acu' ); ?></div>
		<div class="value value-boxes">
			<?php echo $boxed( $card_no, 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $responsible !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'პასუხისმგებელი პირი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $responsible ); ?></div>
	</div>
	<?php endif; ?>

	<?php if ( $form_date !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'თარიღი', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $format_date( $form_date ) ); ?></div>
	</div>
	<?php endif; ?>

	<?php if ( $shop !== '' ) : ?>
	<div class="row">
		<div class="label"><?php esc_html_e( 'მაღაზია', 'acu' ); ?></div>
		<div class="value value-line"><?php echo esc_html( $shop ); ?></div>
	</div>
	<?php endif; ?>

	<div class="row signature-row no-break">
		<div class="label"><?php esc_html_e( 'მომხმარებლის ხელმოწერა', 'acu' ); ?></div>
		<div class="value value-line"></div>
	</div>
</div>
</body>
</html>
