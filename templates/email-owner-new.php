<?php
/**
 * Sent to every address in the notify_emails setting whenever a new
 * booking is created. Shows all customer details plus a deep link into
 * wp-admin for one-click review / confirmation.
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
?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0 0;">
	<tr>
		<td style="padding:4px 0;color:#646970;width:110px;font-size:14px;"><?php esc_html_e( 'Email', 'timeslate' ); ?></td>
		<td style="padding:4px 0;font-size:14px;">
			<a href="mailto:<?php echo esc_attr( (string) ( $email ?? '' ) ); ?>" style="color:#2271b1;text-decoration:none;">
				<?php echo esc_html( (string) ( $email ?? '' ) ); ?>
			</a>
		</td>
	</tr>
	<tr>
		<td style="padding:4px 0;color:#646970;font-size:14px;"><?php esc_html_e( 'Phone', 'timeslate' ); ?></td>
		<td style="padding:4px 0;font-size:14px;">
			<?php echo esc_html( (string) ( $phone ?? '' ) ); ?>
		</td>
	</tr>
</table>

<?php
if ( ! empty( $outro_html ) ) {
	echo $outro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>

<?php if ( ! empty( $admin_url ) ) : ?>
<p style="margin:20px 0 0 0;">
	<a href="<?php echo esc_url( (string) $admin_url ); ?>" style="display:inline-block;padding:10px 18px;background:#2c5d3f;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:500;">
		<?php esc_html_e( 'Open in admin', 'timeslate' ); ?>
	</a>
</p>
<?php endif; ?>
