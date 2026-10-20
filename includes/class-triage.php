<?php
/**
 * Fixed rules that label a group of failed actions.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Labels a failure signature as "fix first", "retry candidate" or "review".
 *
 * The rules read the error text and the hook name only. They cannot see whether a remote service is back,
 * whether a payment went through or whether a callback is safe to run twice, so no group is ever called
 * safe: the best a group gets is "retry candidate, check first", and hooks that may move money or send a
 * message never get it.
 */
class Triage {

	const FIX    = 'fix';
	const RETRY  = 'retry';
	const REVIEW = 'review';

	/**
	 * Causes, checked in order. The first pattern that matches decides the cause.
	 *
	 * @return array<string, array{label: string, pattern: string}>
	 */
	public static function rules() {
		return array(
			'missing-callback' => array(
				'label'   => self::FIX,
				'pattern' => '/will not be executed as no callbacks are registered/i',
			),
			'resource-limit'   => array(
				'label'   => self::FIX,
				'pattern' => '/Allowed memory size of|Maximum execution time of|Out of memory/i',
			),
			'code-error'       => array(
				'label'   => self::FIX,
				'pattern' => '/\b(?:TypeError|ArgumentCountError|ParseError|ValueError|DivisionByZeroError|ArithmeticError)\b|\bUncaught (?:Error|Exception)\b|Call to (?:undefined|a member function|private|protected)|Class "?[\w\\\\]+"? not found|Interface "?[\w\\\\]+"? not found|Cannot redeclare|must be of type|Undefined (?:index|array key|variable|property|offset|constant)|Attempt to read property|Cannot access offset/i',
			),
			'database-busy'    => array(
				'label'   => self::RETRY,
				'pattern' => '/Deadlock found|Lock wait timeout exceeded|MySQL server has gone away|Too many connections|Lost connection to MySQL/i',
			),
			'database'         => array(
				'label'   => self::FIX,
				'pattern' => '/WordPress database error|SQLSTATE\[|Duplicate entry|Unknown column|Table \S+ doesn\'t exist|Data too long for column/i',
			),
			'auth'             => array(
				'label'   => self::FIX,
				'pattern' => '/\b(?:HTTP|status|code|error|response)\s*[:#=]?\s*(?:401|403)\b|\b(?:401|403) (?:Unauthori[sz]ed|Forbidden)\b|unauthori[sz]ed|\bforbidden\b|invalid[ _-]?(?:api[ _-]?)?key|api key (?:is )?(?:invalid|missing|expired)|authentication (?:failed|required|error)|could not authenticate|not authenticated|access denied|invalid[ _-]?(?:access[ _-]?)?token|token (?:has )?expired|expired token|invalid[ _-]?credentials|invalid_grant|invalid[ _-]?signature|permission denied/i',
			),
			'rate-limit'       => array(
				'label'   => self::RETRY,
				'pattern' => '/\b429\b|too many requests|rate[ _-]?limit|throttl/i',
			),
			'network'          => array(
				'label'   => self::RETRY,
				'pattern' => '/cURL error (?:5|6|7|28|35|52|55|56)\b|timed? ?out|connection (?:refused|reset|timed out|closed)|could not resolve host|name or service not known|temporary failure in name resolution|SSL connect error|empty reply from server|failed to connect|network is unreachable|operation timed out/i',
			),
			'remote-server'    => array(
				'label'   => self::RETRY,
				'pattern' => '/\b(?:HTTP|status|code|error|response)\s*[:#=]?\s*(?:500|502|503|504)\b|\b(?:500|502|503|504) (?:Internal Server Error|Bad Gateway|Service Unavailable|Gateway Time-?out)\b|Internal Server Error|Bad Gateway|Service (?:Temporarily )?Unavailable|Gateway Time-?out/i',
			),
			'missing-data'     => array(
				'label'   => self::FIX,
				'pattern' => '/\b404\b|not found|does not exist|no longer exists|could not find|cannot find|unable to find|no such|invalid (?:order|product|post|user|customer|subscription|coupon|variation)\b/i',
			),
			'invalid-data'     => array(
				'label'   => self::FIX,
				'pattern' => '/\b(?:400|409|422)\b|invalid|malformed|is required|must be|cannot be empty|unprocessable|validation (?:failed|error)/i',
			),
		);
	}

	/**
	 * Hook names that suggest a rerun could charge, refund or message someone a second time.
	 *
	 * @return string Regular expression.
	 */
	public static function side_effect_pattern() {
		return '/pay(?:ment)?|renew|refund|charge|capture|invoice|receipt|payout|transfer|checkout|order|email|mail|notif|sms|whatsapp|push|webhook|subscription/i';
	}

	/**
	 * Labels one failure signature.
	 *
	 * @param string $hook    Action hook.
	 * @param string $kind    Failure kind from Signature::failure_of().
	 * @param string $message Cleaned failure message.
	 * @return array{label: string, cause: string, side_effects: bool}
	 */
	public static function classify( $hook, $kind, $message ) {
		$side_effects = (bool) preg_match( self::side_effect_pattern(), (string) $hook );

		if ( 'timeout' === $kind ) {
			// The callback ran for minutes and may have done part of its work before PHP stopped.
			return array(
				'label'        => self::REVIEW,
				'cause'        => 'runner-timeout',
				'side_effects' => $side_effects,
			);
		}
		if ( 'none' === $kind || '' === trim( (string) $message ) ) {
			return array(
				'label'        => self::REVIEW,
				'cause'        => 'unknown',
				'side_effects' => $side_effects,
			);
		}

		$cause = 'unknown';
		$label = self::REVIEW;
		foreach ( self::rules() as $name => $rule ) {
			if ( preg_match( $rule['pattern'], $message ) ) {
				$cause = $name;
				$label = $rule['label'];
				break;
			}
		}
		if ( 'unknown' === $cause && 'fatal' === $kind ) {
			$cause = 'code-error';
			$label = self::FIX;
		}
		if ( self::RETRY === $label && ( $side_effects || 'next-instance' === $kind ) ) {
			$label = self::REVIEW;
		}

		return array(
			'label'        => $label,
			'cause'        => $cause,
			'side_effects' => $side_effects,
		);
	}
}
