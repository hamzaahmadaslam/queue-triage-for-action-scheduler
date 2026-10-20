<?php
/**
 * Tests for the labelling rules.
 *
 * @package QueueTriage
 */

use PHPUnit\Framework\TestCase;
use QueueTriage\Triage;

/**
 * Triage tests.
 */
class TriageTest extends TestCase {

	/**
	 * Cases: message, expected cause, expected label, for a hook with no side effects in its name.
	 *
	 * @return array
	 */
	public function messages() {
		return array(
			array( 'Scheduled action for acme_sync will not be executed as no callbacks are registered.', 'missing-callback', Triage::FIX ),
			array( 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes)', 'resource-limit', Triage::FIX ),
			array( 'Maximum execution time of 30 seconds exceeded', 'resource-limit', Triage::FIX ),
			array( 'acme_row(): Argument #1 ($post) must be of type WP_Post, null given', 'code-error', Triage::FIX ),
			array( 'Argument 1 passed to acme_row() must be an instance of WP_Post, null given, called in a.php on line 45', 'code-error', Triage::FIX ),
			array( 'Too few arguments to function acme_row(), 0 passed and exactly 1 expected', 'code-error', Triage::FIX ),
			array( 'Call to undefined function acme_missing()', 'code-error', Triage::FIX ),
			array( 'Call to a member function get_id() on bool', 'code-error', Triage::FIX ),
			array( 'Class "Acme\\Client" not found', 'code-error', Triage::FIX ),
			array( 'Deadlock found when trying to get lock; try restarting transaction', 'database-busy', Triage::RETRY ),
			array( 'WordPress database error Unknown column \'x\' in \'field list\'', 'database', Triage::FIX ),
			array( 'Duplicate entry \'<n>\' for key \'PRIMARY\'', 'database', Triage::FIX ),
			array( 'HTTP 401 Unauthorized', 'auth', Triage::FIX ),
			array( 'Remote API returned status 403', 'auth', Triage::FIX ),
			array( 'Invalid API key provided', 'auth', Triage::FIX ),
			array( 'SMTP Error: Could not authenticate.', 'auth', Triage::FIX ),
			array( 'The access token has expired', 'auth', Triage::FIX ),
			array( 'HTTP 429 Too Many Requests', 'rate-limit', Triage::RETRY ),
			array( 'Rate limit reached, retry later', 'rate-limit', Triage::RETRY ),
			array( 'cURL error 28: Operation timed out after <n> milliseconds', 'network', Triage::RETRY ),
			array( 'cURL error 6: Could not resolve host: api.example.com', 'network', Triage::RETRY ),
			array( 'Connection refused', 'network', Triage::RETRY ),
			array( 'Remote server returned 503 Service Unavailable', 'remote-server', Triage::RETRY ),
			array( 'Bad Gateway', 'remote-server', Triage::RETRY ),
			array( 'Order #<id> not found', 'missing-data', Triage::FIX ),
			array( 'Product does not exist', 'missing-data', Triage::FIX ),
			array( 'Invalid email \'<str>\' in row <n>', 'invalid-data', Triage::FIX ),
			array( 'Field "sku" is required', 'invalid-data', Triage::FIX ),
			array( 'Unexpected response from the partner feed', 'unknown', Triage::REVIEW ),
		);
	}

	/**
	 * Each message gets the expected cause and label.
	 *
	 * @dataProvider messages
	 *
	 * @param string $message Message.
	 * @param string $cause   Expected cause.
	 * @param string $label   Expected label.
	 */
	public function test_message_rules( $message, $cause, $label ) {
		$result = Triage::classify( 'acme_sync_contact', 'exception', $message );
		$this->assertSame( $cause, $result['cause'] );
		$this->assertSame( $label, $result['label'] );
	}

	/**
	 * Hooks with side effects never get retry.
	 */
	public function test_hooks_with_side_effects_never_get_retry() {
		foreach ( array( 'woocommerce_scheduled_subscription_payment', 'acme_send_receipt_email', 'acme_capture_order', 'woocommerce_deliver_webhook_async', 'acme_process_refund', 'acme_sms_notification' ) as $hook ) {
			$result = Triage::classify( $hook, 'exception', 'cURL error 28: Operation timed out' );
			$this->assertSame( Triage::REVIEW, $result['label'], $hook );
			$this->assertSame( 'network', $result['cause'], $hook );
			$this->assertTrue( $result['side_effects'], $hook );
		}
	}

	/**
	 * Side effects do not soften fix first.
	 */
	public function test_side_effects_do_not_soften_fix_first() {
		$result = Triage::classify( 'acme_capture_payment', 'exception', 'Invalid API key provided' );
		$this->assertSame( Triage::FIX, $result['label'] );
	}

	/**
	 * A runner timeout is review.
	 */
	public function test_a_runner_timeout_is_review() {
		$result = Triage::classify( 'acme_sync_contact', 'timeout', 'Still running after 300 seconds' );
		$this->assertSame( 'runner-timeout', $result['cause'] );
		$this->assertSame( Triage::REVIEW, $result['label'] );
	}

	/**
	 * An unknown fatal is a code error.
	 */
	public function test_an_unknown_fatal_is_a_code_error() {
		$result = Triage::classify( 'acme_sync_contact', 'fatal', 'Something odd in wp-content/plugins/acme/a.php on line 9' );
		$this->assertSame( 'code-error', $result['cause'] );
		$this->assertSame( Triage::FIX, $result['label'] );
	}

	/**
	 * No message is review.
	 */
	public function test_no_message_is_review() {
		$this->assertSame( Triage::REVIEW, Triage::classify( 'acme_sync_contact', 'none', '' )['label'] );
		$this->assertSame( Triage::REVIEW, Triage::classify( 'acme_sync_contact', 'exception', '   ' )['label'] );
	}

	/**
	 * A failed next instance is never retry.
	 */
	public function test_a_failed_next_instance_is_never_retry() {
		$result = Triage::classify( 'acme_sync_contact', 'next-instance', 'Could not schedule the next instance: Lock wait timeout exceeded' );
		$this->assertSame( Triage::REVIEW, $result['label'] );
	}
}
