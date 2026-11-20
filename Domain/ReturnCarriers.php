<?php
/**
 * Carriers a customer can pick when entering return shipping details.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Class ReturnCarriers
 *
 * The customer sends the parcel from their own country, so the list starts
 * with that country's common carriers and ends with the carriers that work
 * across Europe. The names are brands and stay untranslated; the form adds
 * "Other" itself, and the shop can change the list with the
 * ppwb_return_carriers filter.
 */
final class ReturnCarriers {

	/**
	 * Carriers that operate in most countries.
	 */
	public const INTERNATIONAL = array( 'DHL', 'DPD', 'GLS', 'UPS', 'FedEx' );

	/**
	 * Common carriers per country (ISO 3166-1 alpha-2).
	 */
	private const NATIONAL = array(
		'AT' => array( 'Österreichische Post' ),
		'BE' => array( 'bpost' ),
		'BG' => array( 'Bulgarian Posts', 'Econt', 'Speedy' ),
		'CA' => array( 'Canada Post' ),
		'CH' => array( 'Swiss Post' ),
		'CY' => array( 'Cyprus Post' ),
		'CZ' => array( 'Česká pošta', 'PPL', 'Packeta' ),
		'DE' => array( 'DHL', 'Hermes', 'Deutsche Post' ),
		'DK' => array( 'PostNord' ),
		'EE' => array( 'Omniva' ),
		'ES' => array( 'Correos', 'SEUR', 'InPost' ),
		'FI' => array( 'Posti' ),
		'FR' => array( 'Colissimo', 'Chronopost', 'Mondial Relay' ),
		'GB' => array( 'Royal Mail', 'Evri', 'InPost' ),
		'GR' => array( 'ELTA', 'ACS' ),
		'HR' => array( 'Hrvatska pošta' ),
		'HU' => array( 'Magyar Posta', 'Packeta' ),
		'IE' => array( 'An Post' ),
		'IT' => array( 'Poste Italiane', 'BRT', 'InPost' ),
		'LT' => array( 'Lietuvos paštas', 'Omniva' ),
		'LU' => array( 'Post Luxembourg' ),
		'LV' => array( 'Latvijas Pasts', 'Omniva' ),
		'MT' => array( 'MaltaPost' ),
		'NL' => array( 'PostNL' ),
		'NO' => array( 'Posten Bring' ),
		'PL' => array( 'InPost', 'DPD', 'DHL', 'GLS', 'Poczta Polska', 'ORLEN Paczka' ),
		'PT' => array( 'CTT', 'InPost' ),
		'RO' => array( 'Poșta Română', 'Fan Courier', 'Sameday' ),
		'SE' => array( 'PostNord' ),
		'SI' => array( 'Pošta Slovenije' ),
		'SK' => array( 'Slovenská pošta', 'Packeta' ),
		'US' => array( 'USPS' ),
	);

	/**
	 * Carriers for a country: national ones first, then the international ones, without duplicates.
	 *
	 * @param string $country Country code; '' or unknown gives only the international carriers.
	 * @return list<string>
	 */
	public static function for_country( string $country ): array {
		$national = self::NATIONAL[ strtoupper( trim( $country ) ) ] ?? array();

		return array_values( array_unique( array_merge( $national, self::INTERNATIONAL ) ) );
	}
}
