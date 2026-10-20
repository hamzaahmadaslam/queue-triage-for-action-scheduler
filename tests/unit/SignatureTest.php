<?php
/**
 * Tests for reading and normalising failure messages.
 *
 * @package QueueTriage
 */

use PHPUnit\Framework\TestCase;
use QueueTriage\Signature;

/**
 * Signature tests.
 */
class SignatureTest extends TestCase {

	/**
	 * Reads the exception behind lifecycle lines.
	 */
	public function test_reads_the_exception_behind_lifecycle_lines() {
		$failure = Signature::failure_of(
			array(
				'action created',
				'action started via WP Cron',
				'action failed via WP Cron: cURL error 28: Operation timed out',
			)
		);
		$this->assertSame( 'exception', $failure['kind'] );
		$this->assertSame( 'cURL error 28: Operation timed out', $failure['text'] );
		$this->assertFalse( $failure['stopped'] );
	}

	/**
	 * Reads an exception without a context.
	 */
	public function test_reads_an_exception_without_a_context() {
		$failure = Signature::failure_of( array( 'action failed: Boom' ) );
		$this->assertSame( 'Boom', $failure['text'] );
	}

	/**
	 * Reads a fatal error.
	 */
	public function test_reads_a_fatal_error() {
		$failure = Signature::failure_of( array( 'action started via Async Request', 'unexpected shutdown: PHP Fatal error Allowed memory size of 268435456 bytes exhausted in /srv/www/wp-content/plugins/acme/a.php on line 12' ) );
		$this->assertSame( 'fatal', $failure['kind'] );
		$this->assertStringStartsWith( 'Allowed memory size', $failure['text'] );
	}

	/**
	 * Reads both timeout wordings.
	 */
	public function test_reads_both_timeout_wordings() {
		$new = Signature::failure_of( array( 'action was in-progress for at least 300 seconds without completing and has been marked as failed. Check server, PHP and database error logs to diagnose cause.' ) );
		$old = Signature::failure_of( array( 'action marked as failed after 300 seconds. Unknown error occurred. Check server, PHP and database error logs to diagnose cause.' ) );
		$this->assertSame( 'timeout', $new['kind'] );
		$this->assertSame( 'timeout', $old['kind'] );
		$this->assertStringContainsString( '300 seconds', $old['text'] );
	}

	/**
	 * Notes when a recurring action was stopped.
	 */
	public function test_notes_when_a_recurring_action_was_stopped() {
		$failure = Signature::failure_of(
			array(
				'action failed via WP Cron: Bad gateway',
				'This action appears to be consistently failing. A new instance will not be scheduled.',
			)
		);
		$this->assertTrue( $failure['stopped'] );
		$this->assertSame( 'Bad gateway', $failure['text'] );
	}

	/**
	 * Reads a next instance failure.
	 */
	public function test_reads_a_next_instance_failure() {
		$failure = Signature::failure_of( array( 'There was a failure scheduling the next instance of this action: Invalid schedule' ) );
		$this->assertSame( 'next-instance', $failure['kind'] );
	}

	/**
	 * Takes another message when it is not a known format.
	 */
	public function test_takes_another_message_when_it_is_not_a_known_format() {
		$failure = Signature::failure_of( array( 'action created', 'Aktion fehlgeschlagen: Zeitüberschreitung' ) );
		$this->assertSame( 'logged', $failure['kind'] );
		$this->assertSame( 'Aktion fehlgeschlagen: Zeitüberschreitung', $failure['text'] );
	}

	/**
	 * No message at all.
	 */
	public function test_no_message_at_all() {
		$this->assertSame( 'none', Signature::failure_of( array( 'action created', '' ) )['kind'] );
		$this->assertSame( 'none', Signature::failure_of( array() )['kind'] );
	}

	/**
	 * Clean cuts the stack trace.
	 */
	public function test_clean_cuts_the_stack_trace() {
		$clean = Signature::clean( "Uncaught Error: x in /a/b.php:3\nStack trace:\n#0 /a/c.php(9): f()\n#1 {main}" );
		$this->assertStringNotContainsString( 'Stack trace', $clean );
		$this->assertStringNotContainsString( '#0', $clean );
	}

	/**
	 * Clean makes WordPress paths relative.
	 */
	public function test_clean_makes_wordpress_paths_relative() {
		$clean = Signature::clean( 'Error in /var/www/example/public_html/wp-content/plugins/acme/acme.php on line 4' );
		$this->assertSame( 'Error in wp-content/plugins/acme/acme.php on line 4', $clean );
	}

	/**
	 * Clean handles windows paths.
	 */
	public function test_clean_handles_windows_paths() {
		$clean = Signature::clean( 'Error in C:\\sites\\shop\\wp-content\\plugins\\acme\\acme.php on line 4' );
		$this->assertSame( 'Error in wp-content/plugins/acme/acme.php on line 4', $clean );
	}

	/**
	 * Clean keeps only the file name of other paths.
	 */
	public function test_clean_keeps_only_the_file_name_of_other_paths() {
		$clean = Signature::clean( 'Failed opening /home/user1/private/keys.php for reading' );
		$this->assertSame( 'Failed opening .../keys.php for reading', $clean );
	}

	/**
	 * Clean keeps root files relative.
	 */
	public function test_clean_keeps_root_files_relative() {
		$this->assertSame( 'in wp-load.php', Signature::clean( 'in /srv/site/wp-load.php' ) );
	}

	/**
	 * Clean removes emails queries and url credentials.
	 */
	public function test_clean_removes_emails_queries_and_url_credentials() {
		$clean = Signature::clean( 'Sending to jane.doe@example.com failed: https://user:secret@api.example.com/v1/send?token=abc&to=jane#frag' );
		$this->assertStringNotContainsString( 'jane', $clean );
		$this->assertStringNotContainsString( 'secret', $clean );
		$this->assertStringNotContainsString( 'token', $clean );
		$this->assertStringContainsString( '<email>', $clean );
		$this->assertStringContainsString( 'https://api.example.com/v1/send?<query>', $clean );
	}

	/**
	 * Clean leaves url paths alone.
	 */
	public function test_clean_leaves_url_paths_alone() {
		$this->assertSame( 'GET https://api.example.com/v2/orders.php returned 500', Signature::clean( 'GET https://api.example.com/v2/orders.php returned 500' ) );
	}

	/**
	 * Clean replaces public ips only.
	 */
	public function test_clean_replaces_public_ips_only() {
		$clean = Signature::clean( 'Connect to 203.0.113.9 via 10.0.0.5 and 127.0.0.1 failed' );
		$this->assertSame( 'Connect to <ip> via 10.0.0.5 and 127.0.0.1 failed', $clean );
	}

	/**
	 * Clean removes control and invisible characters.
	 */
	public function test_clean_removes_control_and_invisible_characters() {
		$clean = Signature::clean( "Bad\x1b[31m value\xE2\x80\x8B here\xE2\x80\xAE" );
		$this->assertSame( 'Bad [31m value here', $clean );
	}

	/**
	 * Clean shortens long messages.
	 */
	public function test_clean_shortens_long_messages() {
		$clean = Signature::clean( str_repeat( 'a', 400 ) );
		$this->assertSame( Signature::MAX_LENGTH, mb_strlen( $clean ) );
		$this->assertStringEndsWith( '...', $clean );
	}

	/**
	 * Template replaces values that change between repeats.
	 */
	public function test_template_replaces_values_that_change_between_repeats() {
		$a = Signature::template( 'Order #1043 for $49.99 failed at 2026-10-19 08:12:44 (ref 3f2a9c1e7b4d5a6f8e9d0c1b)' );
		$b = Signature::template( 'Order #2210 for $5.00 failed at 2026-10-20 23:01:02 (ref 9a8b7c6d5e4f3a2b1c0d9e8f)' );
		$this->assertSame( $a, $b );
		$this->assertSame( 'Order #<id> for <amount> failed at <time> (ref <hash>)', $a );
	}

	/**
	 * Template keeps error and status codes.
	 */
	public function test_template_keeps_error_and_status_codes() {
		$this->assertSame( 'cURL error 28: Operation timed out after <n> milliseconds', Signature::template( 'cURL error 28: Operation timed out after 10001 milliseconds' ) );
		$this->assertSame( 'HTTP 429 Too Many Requests', Signature::template( 'HTTP 429 Too Many Requests' ) );
		$this->assertSame( 'Remote server returned 503 Service Unavailable', Signature::template( 'Remote server returned 503 Service Unavailable' ) );
		$this->assertSame( 'SQLSTATE[HY000] [2002] Connection refused', Signature::template( 'SQLSTATE[HY000] [2002] Connection refused' ) );
	}

	/**
	 * Template keeps argument positions and line numbers.
	 */
	public function test_template_keeps_argument_positions_and_line_numbers() {
		$this->assertSame(
			'f(): Argument #2 ($x) must be of type int, string given, called in a.php on line 88',
			Signature::template( 'f(): Argument #2 ($x) must be of type int, string given, called in a.php on line 88' )
		);
	}

	/**
	 * Template replaces quoted values but keeps identifiers.
	 */
	public function test_template_replaces_quoted_values_but_keeps_identifiers() {
		$this->assertSame( "Invalid email '<str>' in field 'billing_email'", Signature::template( "Invalid email 'not an address' in field 'billing_email'" ) );
		$this->assertSame( "doesn't exist", Signature::template( "doesn't exist" ) );
	}

	/**
	 * Template merges value lists.
	 */
	public function test_template_merges_value_lists() {
		$this->assertSame( 'IN (<n>)', Signature::template( 'IN (1, 2, 3, 4)' ) );
	}

	/**
	 * Template replaces uuids and record ids.
	 */
	public function test_template_replaces_uuids_and_record_ids() {
		$this->assertSame( 'Job <uuid> for user <id>', Signature::template( 'Job 123e4567-e89b-12d3-a456-426614174000 for user 77' ) );
	}

	/**
	 * Public ip ranges.
	 */
	public function test_public_ip_ranges() {
		$this->assertTrue( Signature::is_public_ip( '8.8.8.8' ) );
		foreach ( array( '10.1.2.3', '172.16.0.1', '192.168.1.1', '127.0.0.1', '169.254.1.1', '100.64.0.1', '0.0.0.0', '224.0.0.1', '999.1.1.1' ) as $ip ) {
			$this->assertFalse( Signature::is_public_ip( $ip ), $ip );
		}
	}
}
