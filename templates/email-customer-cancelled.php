<?php
/**
 * Sent to the customer when a booking is cancelled — either the owner
 * cancelled from the admin, or the customer cancelled via their
 * one-time link.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! empty( $intro_html ) ) {
	echo $intro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

include __DIR__ . '/email-summary.php';

if ( ! empty( $outro_html ) ) {
	echo $outro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>

<p style="margin:16px 0 0 0;font-size:13px;color:#646970;">
	<?php
	printf(
		/* translators: %s: book-again link. */
		esc_html__( 'You can book another time at %s whenever you like.', 'timeslate' ),
		'<a href="' . esc_url( (string) ( $site_url ?? '' ) ) . '" style="color:#2271b1;">' . esc_html( (string) ( $site_name ?? '' ) ) . '</a>'
	);
	?>
</p>
