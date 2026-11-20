<?php
/**
 * Read access to the ppwb_settings option with defaults.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

use PrimePlaced\WithdrawalButton\Domain\RequestNumber;
use PrimePlaced\WithdrawalButton\Domain\ReturnReasons;
use PrimePlaced\WithdrawalButton\Domain\WithdrawalDeadline;
use PrimePlaced\WithdrawalButton\Domain\WithdrawalPeriod;

/**
 * Class Settings
 *
 * Writes will go through a locked SettingsRepository
 * once the settings screen exists; this class only reads.
 */
final class Settings {

	public const OPTION = 'ppwb_settings';

	public const FORM_PAGE_OPTION = 'ppwb_form_page_id';

	/**
	 * Default values.
	 *
	 * @return array<string, int|string|bool>
	 */
	public static function defaults(): array {
		return array(
			'withdrawal_days'            => WithdrawalPeriod::MIN_DAYS,
			'deadline_from'              => WithdrawalDeadline::FROM_COMPLETED,
			'delivery_days'              => WithdrawalDeadline::DEFAULT_DELIVERY_DAYS,
			'unfulfilled_days'           => WithdrawalDeadline::DEFAULT_UNFULFILLED_DAYS,
			'number_format'              => RequestNumber::DEFAULT_FORMAT,
			'counter_scope'              => 'yearly',
			'counter_start'              => 1,
			'delete_data_on_uninstall'   => false,
			'return_company'             => '',
			'return_street'              => '',
			'return_postcode'            => '',
			'return_city'                => '',
			'return_country'             => '',
			'seller_name'                => '',
			'date_style'                 => '',
			'shop_tax_id'                => '',
			'return_shipping_paid_by'    => 'customer',
			'cheapest_shipping'          => '',
			'late_requests'              => 'block',
			'before_shipment'            => 'cancel',
			'refund_reminder_days'       => 3,
			'button_in_footer'           => 'yes',
			'button_in_account'          => 'yes',
			'button_in_completed_email'  => 'yes',
			'button_in_processing_email' => 'yes',
			'exclusion_notice'           => 'yes',
			'order_notes'                => 'key',
			'business_orders'            => 'consumer',
			'button_label'               => '',
			'shop_email'                 => '',
			'restock_default'            => 'yes',
			'refund_email'               => 'woocommerce',
			'wizard_done'                => 'no',
			'reason_list'                => 'yes',
			'return_reasons'             => '',
		);
	}

	/**
	 * Returns one setting, falling back to its default.
	 *
	 * @param string $key Setting key.
	 * @return int|string|bool
	 */
	public function get( string $key ): int|string|bool {
		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		$value    = is_array( $stored ) && array_key_exists( $key, $stored ) ? $stored[ $key ] : ( $defaults[ $key ] ?? '' );

		return is_int( $value ) || is_string( $value ) || is_bool( $value ) ? $value : ( $defaults[ $key ] ?? '' );
	}

	/**
	 * Boolean setting; accepts WooCommerce checkbox values ("yes"/"no") and booleans.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function flag( string $key ): bool {
		$value = $this->get( $key );

		return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Withdrawal period configured by the shop.
	 *
	 * @return WithdrawalPeriod
	 */
	public function withdrawal_period(): WithdrawalPeriod {
		return new WithdrawalPeriod( (int) $this->get( 'withdrawal_days' ) );
	}

	/**
	 * Deadline rules configured by the shop.
	 *
	 * @return WithdrawalDeadline
	 */
	public function withdrawal_deadline(): WithdrawalDeadline {
		return new WithdrawalDeadline(
			$this->withdrawal_period(),
			(int) $this->get( 'delivery_days' ),
			(int) $this->get( 'unfulfilled_days' ),
			WithdrawalDeadline::FROM_PAID === $this->get( 'deadline_from' ) ? WithdrawalDeadline::FROM_PAID : WithdrawalDeadline::FROM_COMPLETED
		);
	}

	/**
	 * ID of the page holding the [ppwb_form] shortcode, created on activation.
	 *
	 * @return int
	 */
	public function form_page_id(): int {
		return (int) get_option( self::FORM_PAGE_OPTION, 0 );
	}

	/**
	 * Label of the withdrawal button: the shop's text or the statutory default.
	 *
	 * @return string
	 */
	public function button_label(): string {
		$label = trim( (string) $this->get( 'button_label' ) );

		return '' !== $label ? $label : __( 'Withdraw from contract here', 'primeplaced-withdrawal-button' );
	}

	/**
	 * Address for shop notifications: the setting, or the site admin email.
	 *
	 * @return string
	 */
	public function shop_email(): string {
		$email = sanitize_email( (string) $this->get( 'shop_email' ) );

		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Seller name for the PDF and the return address: the setting, or the site name.
	 *
	 * @return string
	 */
	public function seller_name(): string {
		$name = trim( (string) $this->get( 'seller_name' ) );

		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	/**
	 * Store address from WooCommerce > Settings > General.
	 *
	 * @return array{company: string, street: string, postcode: string, city: string, country: string}
	 */
	public function store_address_parts(): array {
		$country = (string) get_option( 'woocommerce_default_country', '' );

		return array(
			'company'  => $this->seller_name(),
			'street'   => trim( (string) get_option( 'woocommerce_store_address' ) . ' ' . (string) get_option( 'woocommerce_store_address_2' ) ),
			'postcode' => trim( (string) get_option( 'woocommerce_store_postcode' ) ),
			'city'     => trim( (string) get_option( 'woocommerce_store_city' ) ),
			'country'  => strtoupper( (string) strtok( $country, ':' ) ),
		);
	}

	/**
	 * Return address as separate fields: the plugin fields,
	 * or the WooCommerce store address when street and city are empty.
	 * Carrier integrations use these parts.
	 *
	 * @return array{company: string, street: string, postcode: string, city: string, country: string}
	 */
	public function return_address_parts(): array {
		$own = array(
			'company'  => trim( (string) $this->get( 'return_company' ) ),
			'street'   => trim( (string) $this->get( 'return_street' ) ),
			'postcode' => trim( (string) $this->get( 'return_postcode' ) ),
			'city'     => trim( (string) $this->get( 'return_city' ) ),
			'country'  => strtoupper( trim( (string) $this->get( 'return_country' ) ) ),
		);

		if ( '' === $own['street'] && '' === $own['city'] ) {
			return $this->store_address_parts();
		}

		$store = $this->store_address_parts();

		return array(
			'company'  => '' !== $own['company'] ? $own['company'] : $store['company'],
			'street'   => $own['street'],
			'postcode' => $own['postcode'],
			'city'     => $own['city'],
			'country'  => '' !== $own['country'] ? $own['country'] : $store['country'],
		);
	}

	/**
	 * Whether customers get a usable return address; a street is enough.
	 *
	 * @return bool
	 */
	public function has_return_address(): bool {
		return '' !== $this->return_address_parts()['street'];
	}

	/**
	 * Return address in one line, '' when the shop has not set any.
	 *
	 * @return string
	 */
	public function return_address(): string {
		return $this->has_return_address() ? self::format_address( $this->return_address_parts() ) : '';
	}

	/**
	 * Return address for customers, or a sentence that it follows separately.
	 *
	 * @return string
	 */
	public function return_address_text(): string {
		$address = $this->return_address();

		return '' !== $address ? $address : __( 'the address we will send you in a separate message', 'primeplaced-withdrawal-button' );
	}

	/**
	 * One-line address: company, street, postcode city, country (only when not the shop country).
	 *
	 * @param array{company: string, street: string, postcode: string, city: string, country: string} $parts Address.
	 * @return string
	 */
	public static function format_address( array $parts ): string {
		$base    = strtoupper( (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) );
		$country = '';
		if ( '' !== $parts['country'] && $parts['country'] !== $base && function_exists( 'WC' ) ) {
			$country = (string) ( WC()->countries->get_countries()[ $parts['country'] ] ?? $parts['country'] );
		}

		return implode(
			', ',
			array_filter(
				array(
					$parts['company'],
					$parts['street'],
					trim( $parts['postcode'] . ' ' . $parts['city'] ),
					$country,
				)
			)
		);
	}

	/**
	 * Seller details for the confirmation PDF: name, store address, tax ID, email.
	 *
	 * @return array{name: string, address: string, tax_id: string, email: string}
	 */
	public function shop_details(): array {
		$store            = $this->store_address_parts();
		$store['company'] = '';
		$base             = $store['country'];
		$address          = self::format_address( $store );
		if ( '' !== $base && function_exists( 'WC' ) ) {
			$name    = (string) ( WC()->countries->get_countries()[ $base ] ?? $base );
			$address = '' === $address ? $name : $address . ', ' . $name;
		}

		return array(
			'name'    => $this->seller_name(),
			'address' => $address,
			'tax_id'  => trim( (string) $this->get( 'shop_tax_id' ) ),
			'email'   => (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
		);
	}

	/**
	 * Reasons the customer can pick, code => label; empty when the shop turned
	 * the list off. An empty setting uses the default list.
	 *
	 * @return array<string, string>
	 */
	public function return_reasons(): array {
		if ( ! $this->flag( 'reason_list' ) ) {
			return array();
		}

		$custom = trim( (string) $this->get( 'return_reasons' ) );
		if ( '' === $custom ) {
			return self::default_return_reasons();
		}

		return ReturnReasons::parse( $custom, static fn ( string $label ): string => sanitize_title( $label ) );
	}

	/**
	 * Label of a stored reason code; the code itself when the shop removed it.
	 *
	 * @param string $code Stored code.
	 * @return string
	 */
	public function return_reason_label( string $code ): string {
		if ( ReturnReasons::OTHER === $code ) {
			return __( 'Other', 'primeplaced-withdrawal-button' );
		}

		return ( $this->return_reasons() + self::default_return_reasons() )[ $code ] ?? $code;
	}

	/**
	 * Built-in reasons with stable codes.
	 *
	 * @return array<string, string>
	 */
	public static function default_return_reasons(): array {
		return array(
			'too_small'        => __( 'Too small', 'primeplaced-withdrawal-button' ),
			'too_large'        => __( 'Too large', 'primeplaced-withdrawal-button' ),
			'not_as_described' => __( 'Different from the description or photos', 'primeplaced-withdrawal-button' ),
			'damaged'          => __( 'Damaged or faulty', 'primeplaced-withdrawal-button' ),
			'wrong_item'       => __( 'Wrong product delivered', 'primeplaced-withdrawal-button' ),
			'changed_mind'     => __( 'Changed my mind', 'primeplaced-withdrawal-button' ),
		);
	}

	/**
	 * Sentence about who pays for sending the products back (Art. 34).
	 *
	 * @return string
	 */
	public function return_cost_text(): string {
		return 'shop' === $this->get( 'return_shipping_paid_by' )
			? __( 'We cover the cost of sending the products back.', 'primeplaced-withdrawal-button' )
			: __( 'You cover the cost of sending the products back.', 'primeplaced-withdrawal-button' );
	}
}
