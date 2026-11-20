<?php
/**
 * Status labels and WooCommerce status colours for admin screens.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Domain\Status;

/**
 * Class StatusView
 */
final class StatusView {

	/**
	 * Translated label.
	 *
	 * @param Status $status Status.
	 * @return string
	 */
	public static function label( Status $status ): string {
		return match ( $status ) {
			Status::New       => __( 'New', 'primeplaced-withdrawal-button' ),
			Status::InTransit => __( 'In transit', 'primeplaced-withdrawal-button' ),
			Status::Received  => __( 'Parcel received', 'primeplaced-withdrawal-button' ),
			Status::Refunded  => __( 'Completed, refunded', 'primeplaced-withdrawal-button' ),
			Status::Completed => __( 'Completed', 'primeplaced-withdrawal-button' ),
			Status::Rejected  => __( 'Rejected', 'primeplaced-withdrawal-button' ),
			Status::Cancelled => __( 'Cancelled', 'primeplaced-withdrawal-button' ),
		};
	}

	/**
	 * WooCommerce order-status class, so requests use the same colours as orders.
	 *
	 * @param Status $status Status.
	 * @return string
	 */
	public static function woocommerce_class( Status $status ): string {
		return match ( $status ) {
			Status::New                                     => 'status-on-hold',
			Status::InTransit                               => 'status-pending',
			Status::Received                                => 'status-processing',
			Status::Refunded, Status::Completed             => 'status-completed',
			Status::Rejected                                => 'status-failed',
			Status::Cancelled                               => 'status-cancelled',
		};
	}

	/**
	 * Status badge markup used by WooCommerce lists.
	 *
	 * @param string $status_key Stored status key.
	 * @return string
	 */
	public static function mark( string $status_key ): string {
		$status = Status::tryFrom( $status_key );
		if ( null === $status ) {
			return esc_html( $status_key );
		}

		return sprintf(
			'<mark class="order-status %1$s"><span>%2$s</span></mark>',
			esc_attr( self::woocommerce_class( $status ) ),
			esc_html( self::label( $status ) )
		);
	}
}
