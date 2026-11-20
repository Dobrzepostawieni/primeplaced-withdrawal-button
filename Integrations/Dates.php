<?php
/**
 * One date and time format for the whole plugin.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

use DateTimeInterface;
use PrimePlaced\WithdrawalButton\Data\Settings;

/**
 * Class Dates
 *
 * Emails, PDF, the shop front and the panel show dates in the shop timezone
 * in one format. "Polish" gives "9 listopada 2026, 08:51" (24 h, no comma
 * after the month); "WordPress" uses the site date and time formats.
 * UTC stays in the database only.
 */
final class Dates {

	/**
	 * Date, e.g. "9 listopada 2026".
	 *
	 * @param int|DateTimeInterface $moment Unix timestamp or date.
	 * @return string
	 */
	public static function date( int|DateTimeInterface $moment ): string {
		return (string) wp_date( self::date_format(), self::timestamp( $moment ) );
	}

	/**
	 * Date and time, e.g. "9 listopada 2026, 08:51".
	 *
	 * @param int|DateTimeInterface $moment Unix timestamp or date.
	 * @return string
	 */
	public static function datetime( int|DateTimeInterface $moment ): string {
		return (string) wp_date( self::date_format(), self::timestamp( $moment ) ) . ', ' . (string) wp_date( self::time_format(), self::timestamp( $moment ) );
	}

	/**
	 * Date and time of a UTC database value ("Y-m-d H:i:s").
	 *
	 * @param string $gmt UTC date from the database.
	 * @return string
	 */
	public static function datetime_gmt( string $gmt ): string {
		return '' === $gmt ? '' : self::datetime( (int) strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * Date of a UTC database value ("Y-m-d H:i:s").
	 *
	 * @param string $gmt UTC date from the database.
	 * @return string
	 */
	public static function date_gmt( string $gmt ): string {
		return '' === $gmt ? '' : self::date( (int) strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * Whether the Polish 24 h format applies: the setting, or the site language when unset.
	 *
	 * @return bool
	 */
	public static function polish(): bool {
		$style = (string) ( new Settings() )->get( 'date_style' );
		if ( '' === $style ) {
			return str_starts_with( determine_locale(), 'pl' );
		}

		return 'polish' === $style;
	}

	/**
	 * Date format string.
	 *
	 * @return string
	 */
	private static function date_format(): string {
		return self::polish() ? 'j F Y' : (string) get_option( 'date_format', 'j F Y' );
	}

	/**
	 * Time format string.
	 *
	 * @return string
	 */
	private static function time_format(): string {
		return self::polish() ? 'H:i' : (string) get_option( 'time_format', 'H:i' );
	}

	/**
	 * Unix timestamp of a moment.
	 *
	 * @param int|DateTimeInterface $moment Moment.
	 * @return int
	 */
	private static function timestamp( int|DateTimeInterface $moment ): int {
		return $moment instanceof DateTimeInterface ? $moment->getTimestamp() : $moment;
	}
}
