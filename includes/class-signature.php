<?php
/**
 * Turns the log of a failed action into a signature that repeats of the same error share.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the failure out of an action's log lines and normalises it.
 *
 * Nothing here touches WordPress or the database, so the unit tests can run it on its own.
 */
class Signature {

	/**
	 * Characters kept of a cleaned message.
	 */
	const MAX_LENGTH = 300;

	/**
	 * Finds the log line that says why an action failed.
	 *
	 * Action Scheduler writes lifecycle lines ("action created", "action started via WP Cron") around the
	 * failure. The log is read from the newest line back. The patterns match Action Scheduler's English
	 * messages; on a site in another language the newest line that is not a known lifecycle line is used.
	 *
	 * @param string[] $messages Log messages, oldest first.
	 * @return array{kind: string, text: string, stopped: bool} Kind is one of exception, fatal, timeout,
	 *                                                          next-instance, logged or none.
	 */
	public static function failure_of( array $messages ) {
		$stopped = false;

		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$text = trim( (string) $messages[ $i ] );
			if ( '' === $text ) {
				continue;
			}
			if ( preg_match( '/^This action appears to be consistently failing/i', $text ) ) {
				$stopped = true;
				continue;
			}
			if ( preg_match( '/^action (?:created|canceled|cancelled|reset|started|complete|completed|ignored)\b/i', $text ) ) {
				continue;
			}
			if ( preg_match( '/^action failed(?: via [^:]*)?:\s*(.*)$/is', $text, $m ) ) {
				return array(
					'kind'    => 'exception',
					'text'    => $m[1],
					'stopped' => $stopped,
				);
			}
			if ( preg_match( '/^unexpected shutdown:\s*(?:PHP Fatal error:?\s*)?(.*)$/is', $text, $m ) ) {
				return array(
					'kind'    => 'fatal',
					'text'    => $m[1],
					'stopped' => $stopped,
				);
			}
			if ( preg_match( '/^action (?:was in-progress for at least (\d+) seconds without completing|marked as failed after (\d+) seconds)/i', $text, $m ) ) {
				$seconds = '' !== $m[1] ? $m[1] : ( isset( $m[2] ) ? $m[2] : '' );
				return array(
					'kind'    => 'timeout',
					'text'    => 'Still running after ' . $seconds . ' seconds, so Action Scheduler marked it as failed.',
					'stopped' => $stopped,
				);
			}
			if ( preg_match( '/^There was a failure scheduling the next instance of this action:\s*(.*)$/is', $text, $m ) ) {
				return array(
					'kind'    => 'next-instance',
					'text'    => 'Could not schedule the next instance: ' . $m[1],
					'stopped' => $stopped,
				);
			}
			return array(
				'kind'    => 'logged',
				'text'    => $text,
				'stopped' => $stopped,
			);
		}

		return array(
			'kind'    => 'none',
			'text'    => '',
			'stopped' => $stopped,
		);
	}

	/**
	 * Removes what should not be shown or copied into a support ticket.
	 *
	 * Cuts the stack trace, removes control and invisible characters, makes WordPress paths relative to the
	 * WordPress folder and keeps only the file name of other paths, and replaces email addresses, public IP
	 * addresses and URL query strings.
	 *
	 * @param string $text A failure message.
	 * @return string
	 */
	public static function clean( $text ) {
		$text  = (string) $text;
		$parts = preg_split( '/\s*(?:Stack trace:|\n#0 )/', $text, 2 );
		$text  = $parts ? $parts[0] : $text;

		// Control characters, zero-width and text-direction marks, line separators and the byte order mark.
		$text = preg_replace( '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8B-\x8F\xA8-\xAE]|\xE2\x81[\xA0-\xAF]|\xEF\xBB\xBF/', ' ', $text );

		// Windows paths become forward-slash paths first.
		$text = preg_replace_callback(
			'~[A-Za-z]:\\\\[^\s\'"()<>]*~',
			function ( $m ) {
				return str_replace( '\\', '/', $m[0] );
			},
			$text
		);

		// URL query strings and fragments; credentials in URLs.
		$text = preg_replace( '~\b((?:https?|ftps?)://)[^\s/@\'"<>]+@~i', '$1', $text );
		$text = preg_replace( '~\b((?:https?|ftps?)://[^\s?#\'"<>()]+)[?#][^\s\'"<>()]*~i', '$1?<query>', $text );

		// A path starts at the beginning of the text or after a space, bracket, quote, "=" or ",".
		$start = '(?<=^|[\s(\[{\'"=,])';
		$drive = '(?:[A-Za-z]:)?';
		$text  = preg_replace( '~' . $start . $drive . '/(?:[^\s\'"()<>:]*?/)?(?=(?:wp-content|wp-includes|wp-admin)/)~', '', $text );
		$text  = preg_replace( '~' . $start . $drive . '/(?:[^\s\'"()<>:]*/)?(?=(?:wp-[a-z-]+|index|xmlrpc)\.php\b)~', '', $text );
		$text  = preg_replace( '~' . $start . $drive . '/(?:[^\s\'"()<>:]*/)?([^\s\'"()<>:/]+\.php)\b~', '.../$1', $text );

		$text = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '<email>', $text );
		$text = preg_replace_callback(
			'/(?<![\d.])\d{1,3}(?:\.\d{1,3}){3}(?![\d.])/',
			function ( $m ) {
				return self::is_public_ip( $m[0] ) ? '<ip>' : $m[0];
			},
			$text
		);

		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( self::length( $text ) > self::MAX_LENGTH ) {
			$text = self::cut( $text, self::MAX_LENGTH - 3 ) . '...';
		}
		return $text;
	}

	/**
	 * The part of a cleaned message that stays the same between repeats.
	 *
	 * Dates, times, UUIDs, hashes, amounts, quoted values that are not identifiers, record ids and other
	 * numbers become placeholders. Error codes and HTTP status codes stay, because they tell causes apart.
	 *
	 * @param string $message A message from clean().
	 * @return string
	 */
	public static function template( $message ) {
		$out = (string) $message;
		$out = preg_replace( '/\b\d{4}-\d{2}-\d{2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:\s?(?:Z|UTC|GMT|[+-]\d{2}:?\d{2}))?)?\b/', '<time>', $out );
		$out = preg_replace( '/\b\d{1,2}:\d{2}(?::\d{2})?(?:\s?[AP]M)?\b/i', '<time>', $out );
		$out = preg_replace( '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $out );
		$out = preg_replace( '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{16,}\b/i', '<hash>', $out );

		$currency = '(?:USD|EUR|GBP|PKR|INR|AUD|CAD|JPY|CHF|SEK|NOK|DKK|NZD|ZAR|BRL|MXN|AED|SAR)';
		$out      = preg_replace( '/(?:[$\x{20AC}\x{00A3}\x{00A5}\x{20B9}]\s?|\b' . $currency . '\s?)\d[\d,]*(?:\.\d+)?\b|\b\d[\d,]*(?:\.\d+)?\s?(?:' . $currency . '\b|[\x{20AC}\x{00A3}])/u', '<amount>', $out );

		// A quoted value that is not an identifier ("Invalid email 'x y z'"). An opening quote never follows a
		// letter, digit or ")", which keeps "doesn't" out of it.
		$out = preg_replace_callback(
			'/(?<![\w)])(["\'])((?:(?!\1)[^\\\\]|\\\\.)*)\1/',
			function ( $m ) {
				if ( preg_match( '/^[\w$\\\\.:\-\/\[\]>]{1,60}$/', $m[2] ) || preg_match( '/^<\w+>$/', $m[2] ) ) {
					return $m[0];
				}
				return $m[1] . '<str>' . $m[1];
			},
			$out
		);

		$out = preg_replace_callback(
			'/(?<![\w<$.\\\\-])(\d+(?:\.\d+)*)(ms|s|sec|secs|kb|mb|gb|b)?(?![\w>])/i',
			function ( $m ) use ( $out ) {
				return self::number_token( $m, $out );
			},
			$out,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		// A list of values ("IN (1, 2, 3)") becomes its first placeholder, whatever its length.
		$out = preg_replace( '/(<n>|<id>|\'<str>\'|"<str>")(?:\s*,\s*(?:<n>|<id>|\'<str>\'|"<str>"))+/', '$1', $out );

		return trim( preg_replace( '/\s+/u', ' ', $out ) );
	}

	/**
	 * Decides what one number in a message becomes.
	 *
	 * @param array  $m    Match with offsets (PREG_OFFSET_CAPTURE).
	 * @param string $text The text being matched.
	 * @return string
	 */
	private static function number_token( array $m, $text ) {
		$match  = $m[0][0];
		$offset = $m[0][1];
		$digits = $m[1][0];
		$unit   = isset( $m[2] ) && -1 !== $m[2][1] ? $m[2][0] : '';
		$before = substr( $text, max( 0, $offset - 30 ), min( 30, $offset ) );
		$after  = substr( $text, $offset + strlen( $match ), 40 );

		if ( '' === $unit && preg_match( '/^\d{1,5}$/', $digits ) ) {
			// A code after words such as error, status or HTTP stays, as in cURL error 28 or HTTP 429.
			if ( preg_match( '/(?:\berror|\berrno|\bcode|\bstatus|\bhttp(?:\/[\d.]+)?|\bresponse|\breturned|\bsqlstate\[\w+\])\s*[:#=]?\s*\[?$/i', $before ) ) {
				return $match;
			}
			if ( preg_match( '/^[1-5]\d\d$/', $digits ) && preg_match( '/^\s+(?:OK|Created|Accepted|No Content|Moved|Found|Not Modified|Bad Request|Unauthori[sz]ed|Payment Required|Forbidden|Not Found|Method Not Allowed|Not Acceptable|Request Time-?out|Conflict|Gone|Payload Too Large|Unprocessable|Too Many Requests|Internal Server Error|Not Implemented|Bad Gateway|Service Unavailable|Gateway Time-?out)\b/i', $after ) ) {
				return $match;
			}
			if ( strlen( $digits ) >= 3 && preg_match( '/\[$/', $before ) && preg_match( '/^\]/', $after ) ) {
				return $match;
			}
		}
		if ( preg_match( '/HTTP\/$/i', $before ) || preg_match( '/\bargument\s*#$/i', $before ) || preg_match( '/\bon line\s*$/i', $before ) ) {
			return $match;
		}
		if ( preg_match( '/#\s*$/', $before ) || preg_match( '/(?:\b(?:order|subscription|user|customer|product|post|item|webhook|action|refund|payment|invoice|coupon|variation|term|comment|job|task|transaction|charge|list|member|booking|ticket|entry|form|lead|contact|id)s?(?:_id)?)\s*(?:#|no\.?|number|[:=])?\s*$/i', $before ) ) {
			return '<id>';
		}
		return '<n>' . $unit;
	}

	/**
	 * True for an IPv4 address that is not loopback, private, link-local, shared or reserved.
	 *
	 * @param string $address IPv4 address.
	 * @return bool
	 */
	public static function is_public_ip( $address ) {
		$parts = array_map( 'intval', explode( '.', $address ) );
		if ( 4 !== count( $parts ) || max( $parts ) > 255 ) {
			return false;
		}
		list( $a, $b ) = $parts;
		if ( 10 === $a || 127 === $a || 0 === $a || $a >= 224 ) {
			return false;
		}
		if ( ( 169 === $a && 254 === $b ) || ( 172 === $a && $b >= 16 && $b <= 31 ) || ( 192 === $a && 168 === $b ) || ( 100 === $a && $b >= 64 && $b <= 127 ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * The first characters of a text.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters to keep.
	 * @return string
	 */
	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}
}
