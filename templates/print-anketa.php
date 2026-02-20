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
$shop        = $meta( '_acu_shop' );

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

// Render boxed digit cells
$boxed = function ( string $text, int $boxes = 11 ): string {
	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$cells = [];
	for ( $i = 0; $i < $boxes; $i++ ) {
		$cells[] = isset( $chars[ $i ] ) ? esc_html( $chars[ $i ] ) : '&nbsp;';
	}
	return '<div class="boxes boxes-' . $boxes . '"><span>' . implode( '</span><span>', $cells ) . '</span></div>';
};

$sms_terms_link  = esc_url( add_query_arg( [ 'user_id' => $user_id, 'terms_type' => 'sms' ],  home_url( '/signature-terms/' ) ) );
$call_terms_link = esc_url( add_query_arg( [ 'user_id' => $user_id, 'terms_type' => 'call' ], home_url( '/signature-terms/' ) ) );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__( 'Print Anketa', 'acu' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( ACU_URL . 'assets/css/print.css?v=' . urlencode( ACU_VERSION ) ); ?>" media="all" />
</head>
<body>
<div class="print-actions">
	<button onclick="window.print()"><?php echo esc_html__( 'Print Anketa', 'acu' ); ?></button>
	<a class="button button-secondary print-terms-btn" href="<?php echo $sms_terms_link; ?>"><?php echo esc_html__( 'Print SMS Terms', 'acu' ); ?></a>
	<a class="button button-secondary print-terms-btn" href="<?php echo $call_terms_link; ?>"><?php echo esc_html__( 'Print Phone Call Terms', 'acu' ); ?></a>
</div>

<div class="page">
	<h1 class="title"><?php echo esc_html( 'გახდი შპს "ართთაიმის" ს/კ 202356672 კლუბის წევრი!' ); ?></h1>

	<div class="row">
		<div class="label">პირადი ნომერი</div>
		<div class="value value-boxes">
			<?php echo $boxed( $personal_id, 11 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>

	<div class="row">
		<div class="label">სახელი</div>
		<div class="value value-line"><?php echo esc_html( $first_name ); ?></div>
	</div>

	<div class="row">
		<div class="label">გვარი</div>
		<div class="value value-line"><?php echo esc_html( $last_name ); ?></div>
	</div>

	<div class="row">
		<div class="label">დაბადების თარიღი</div>
		<div class="value value-line"><?php echo esc_html( $dob ); ?></div>
	</div>

	<div class="row">
		<div class="label">ტელეფონის ნომერი</div>
		<div class="value value-line"><?php echo esc_html( $phone ); ?></div>
	</div>

	<div class="row">
		<div class="label">მისამართი</div>
		<div class="value value-line"><?php echo esc_html( $address_1 ); ?></div>
	</div>

	<div class="row">
		<div class="label">E-mail</div>
		<div class="value value-line"><?php echo str_ends_with( $email, '@no-email.local' ) ? '' : esc_html( $email ); ?></div>
	</div>

	<div class="rules">
		<div class="rules-title">წესები და პირობები</div>
		<div class="rules-inner">
			<?php
			$rules_html = (string) get_option( 'acu_terms_html', '' );
			if ( $rules_html !== '' ) {
				echo wp_kses_post( $rules_html );
			} else {
				$default_rules = '
<p><strong>Arttime-ის კლუბის წევრები სარგებლობენ შემდეგი უპირატესობით:</strong></p>
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
<li>ფასდაკლება მოქმედებს, მაგრამ ქულები არ გერიცხებათ პროდუქციის სასაჩუქრე ვაუჩერით შემენისას</li>
<li>სასაჩუქრე ვაუჩერის შეძენისას ფასდაკლება არ მოქმედებს, მაგრამ ქულები გროვდება:</li>
<li>დაგროვილი ქულები ბარათზე აისახება 2 სამუშაო დღის ვადაში;</li>
<li>გაითვალისწინეთ, წინამდებარე წესებით დადგენილი პირობები შეიძლება შეიცვალოს შპს „ართთაიმის" მიერ, რომელიც სავალდებულო იქნება ბარათების პროექტში ჩართული მომხმარებლებისთვის.</li>
<li>ხელმოწერით ვადასტურებ ჩემი პირადი მონაცემების სიზუსტეს და ბარათის მიღებას</li>
</ol>';
				echo wp_kses_post( apply_filters( 'acu_rules_text', $default_rules ) );
			}
			?>
		</div>
	</div>

	<div class="row">
		<div class="label">მივიღე ბარათი №</div>
		<div class="value value-boxes">
			<?php echo $boxed( $card_no, 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>

	<div class="row">
		<div class="label">პასუხისმგებელი პირი</div>
		<div class="value value-line"><?php echo esc_html( $responsible ); ?></div>
	</div>

	<div class="row">
		<div class="label">თარიღი</div>
		<div class="value value-line"><?php echo esc_html( $form_date ); ?></div>
	</div>

	<div class="row">
		<div class="label">მაღაზია</div>
		<div class="value value-line"><?php echo esc_html( $shop ); ?></div>
	</div>

	<div class="row signature-row no-break">
		<div class="label">მომხმარებლის ხელმოწერა</div>
		<div class="value value-line"></div>
	</div>
</div>
</body>
</html>
