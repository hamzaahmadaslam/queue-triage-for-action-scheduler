<?php
/**
 * Unit test bootstrap: just enough of WordPress for the classes that do not touch the database.
 *
 * @package QueueTriage
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'GB_IN_BYTES', 1073741824 );

/**
 * Stand-in for __().
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

/**
 * Stand-in for _n().
 *
 * @param string $single Singular.
 * @param string $plural Plural.
 * @param int    $number Number.
 * @param string $domain Text domain.
 * @return string
 */
function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

/**
 * Stand-in for number_format_i18n().
 *
 * @param float $number   Number.
 * @param int   $decimals Decimals.
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( $number, $decimals );
}

/**
 * Stand-in for get_bloginfo().
 *
 * @param string $show Field.
 * @return string
 */
function get_bloginfo( $show = '' ) {
	return '7.1.3';
}

require dirname( __DIR__, 2 ) . '/includes/class-format.php';
require dirname( __DIR__, 2 ) . '/includes/class-signature.php';
require dirname( __DIR__, 2 ) . '/includes/class-triage.php';
require dirname( __DIR__, 2 ) . '/includes/class-report.php';
