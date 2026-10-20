<?php
/**
 * Small formatting helpers.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Durations and sizes in words.
 */
class Format {

	/**
	 * A duration in the largest whole unit: "45 seconds", "3 minutes", "5 hours", "2 days".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < MINUTE_IN_SECONDS ) {
			/* translators: %s: number of seconds. */
			return sprintf( _n( '%s second', '%s seconds', $seconds, 'queue-triage-for-action-scheduler' ), number_format_i18n( $seconds ) );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$n = (int) floor( $seconds / MINUTE_IN_SECONDS );
			/* translators: %s: number of minutes. */
			return sprintf( _n( '%s minute', '%s minutes', $n, 'queue-triage-for-action-scheduler' ), number_format_i18n( $n ) );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$n = (int) floor( $seconds / HOUR_IN_SECONDS );
			/* translators: %s: number of hours. */
			return sprintf( _n( '%s hour', '%s hours', $n, 'queue-triage-for-action-scheduler' ), number_format_i18n( $n ) );
		}
		$n = (int) floor( $seconds / DAY_IN_SECONDS );
		/* translators: %s: number of days. */
		return sprintf( _n( '%s day', '%s days', $n, 'queue-triage-for-action-scheduler' ), number_format_i18n( $n ) );
	}

	/**
	 * Bytes as KB, MB or GB.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$last  = count( $units ) - 1;
		$i     = 0;
		$value = (float) $bytes;
		while ( $value >= 1024 && $i < $last ) {
			$value /= 1024;
			++$i;
		}
		return number_format_i18n( $value, $i > 0 && $value < 10 ? 1 : 0 ) . ' ' . $units[ $i ];
	}

	/**
	 * A number with the site's separators.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	public static function number( $n ) {
		return number_format_i18n( (int) $n );
	}
}
