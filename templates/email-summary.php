<?php
/**
 * Booking summary block — rendered inside every email template body.
 * Kept as a partial so tweaks to the summary style stay in one place.
 *
 * Expects `$name`, `$date_long`, `$time_pretty`, `$people`, plus optional
 * `$status_label`, `$notes` via the caller's extracted scope.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$timeslate_people_text = sprintf(
	/* translators: %d: number of people. */
	_n( '%d person', '%d people', (int) ( $people ?? 0 ), 'timeslate' ),
	(int) ( $people ?? 0 )
);

?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;background:#f8f8f8;border-radius:10px;">
	<tr>
		<td style="padding:16px 20px;font-size:14px;color:#1e1e1e;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
				<tr>
					<td style="padding:4px 0;color:#646970;width:110px;"><?php esc_html_e( 'Name', 'timeslate' ); ?></td>
					<td style="padding:4px 0;font-weight:500;"><?php echo esc_html( (string) ( $name ?? '' ) ); ?></td>
				</tr>
				<tr>
					<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'Date', 'timeslate' ); ?></td>
					<td style="padding:4px 0;font-weight:500;"><?php echo esc_html( (string) ( $date_long ?? '' ) ); ?></td>
				</tr>
				<tr>
					<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'Time', 'timeslate' ); ?></td>
					<td style="padding:4px 0;font-weight:500;"><?php echo esc_html( (string) ( $time_pretty ?? '' ) ); ?></td>
				</tr>
				<tr>
					<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'People', 'timeslate' ); ?></td>
					<td style="padding:4px 0;font-weight:500;"><?php echo esc_html( $timeslate_people_text ); ?></td>
				</tr>
				<?php if ( ! empty( $status_label ) ) : ?>
				<tr>
					<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'Status', 'timeslate' ); ?></td>
					<td style="padding:4px 0;font-weight:500;"><?php echo esc_html( (string) $status_label ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( ! empty( $notes ) ) : ?>
				<tr>
					<td style="padding:4px 0;color:#646970;vertical-align:top;"><?php esc_html_e( 'Notes', 'timeslate' ); ?></td>
					<td style="padding:4px 0;color:#1e1e1e;"><?php echo wp_kses( nl2br( esc_html( (string) $notes ) ), array( 'br' => array() ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>
