<?php
/**
 * Tests for grouping, checks and the plain-text report.
 *
 * @package QueueTriage
 */

use PHPUnit\Framework\TestCase;
use QueueTriage\Report;
use QueueTriage\Triage;

/**
 * Report tests.
 */
class ReportTest extends TestCase {

	const NOW = 1792450000;

	/**
	 * A collected-data array for a site where everything is fine.
	 *
	 * @param array $changes Values to replace, by top-level key.
	 * @return array
	 */
	private function data( array $changes = array() ) {
		$data = array(
			'now'         => self::NOW,
			'environment' => array(
				'loaded'        => true,
				'version'       => '4.1.0',
				'store'         => 'ActionScheduler_DBStore',
				'custom_tables' => true,
				'source'        => 'WooCommerce',
				'sources'       => array(),
			),
			'runner'      => array(
				'wp_cron_disabled'   => false,
				'alternate_wp_cron'  => false,
				'next_run'           => self::NOW + 30,
				'batch_size'         => 25,
				'concurrent_batches' => 1,
				'time_limit'         => 30,
				'async_filtered_off' => false,
			),
			'retention'   => array(
				'period'         => 2678400,
				'failed_period'  => 8035200,
				'failed_cleanup' => true,
				'statuses'       => array( 'complete', 'canceled' ),
			),
			'tables'      => array( 'actions' => 'wp_actionscheduler_actions' ),
			'has_tables'  => true,
			'storage'     => array(
				'actions' => array(
					'rows'  => 120,
					'bytes' => 2097152,
					'free'  => 0,
				),
			),
			'counts'      => array(
				'complete' => 100,
				'pending'  => 20,
			),
			'past_due'    => array(
				'count'    => 0,
				'over_day' => 0,
				'oldest'   => null,
				'hooks'    => array(),
			),
			'failed'      => array(
				'recent'  => 0,
				'sample'  => 500,
				'actions' => array(),
			),
			'running'     => array(
				'count'  => 0,
				'long'   => 0,
				'oldest' => null,
			),
			'claims'      => array(
				'count'  => 0,
				'stale'  => 0,
				'oldest' => null,
			),
			'activity'    => array(
				'last_log'      => self::NOW - 40,
				'last_complete' => self::NOW - 45,
			),
			'oldest'      => array(
				'complete' => self::NOW - 20 * 86400,
				'canceled' => null,
				'failed'   => null,
			),
		);
		return array_replace( $data, $changes );
	}

	/**
	 * A failed action as the reader returns it.
	 *
	 * @param int    $id      Action id.
	 * @param string $hook    Hook.
	 * @param string $message Log message.
	 * @param array  $extra   Other fields.
	 * @return array
	 */
	private function failed( $id, $hook, $message, array $extra = array() ) {
		return array_replace(
			array(
				'id'        => $id,
				'hook'      => $hook,
				'group'     => '',
				'scheduled' => self::NOW - 3600,
				'attempted' => self::NOW - $id,
				'recurring' => false,
				'messages'  => array( 'action created', 'action started via WP Cron', $message ),
			),
			$extra
		);
	}

	/**
	 * Failures that differ in values share a group.
	 */
	public function test_failures_that_differ_in_values_share_a_group() {
		$groups = Report::groups(
			array(
				$this->failed( 1, 'acme_sync', 'action failed via WP Cron: Order #1043 not found' ),
				$this->failed( 2, 'acme_sync', 'action failed via WP Cron: Order #2210 not found', array( 'group' => 'acme' ) ),
				$this->failed( 3, 'acme_sync', 'action failed via WP Cron: cURL error 28: timed out' ),
				$this->failed( 4, 'acme_other', 'action failed via WP Cron: Order #7 not found' ),
			)
		);
		$this->assertCount( 3, $groups );
		$this->assertSame( 'acme_sync', $groups[0]['hook'] );
		$this->assertSame( 2, $groups[0]['count'] );
		$this->assertSame( 'Order #<id> not found', $groups[0]['signature'] );
		$this->assertSame( array( 'acme' ), $groups[0]['groups'] );
		$this->assertSame( array( 1, 2 ), $groups[0]['ids'] );
		$this->assertSame( self::NOW - 1, $groups[0]['latest'] );
		$this->assertSame( self::NOW - 2, $groups[0]['earliest'] );
		$this->assertSame( Triage::FIX, $groups[0]['label'] );
	}

	/**
	 * Groups count recurring and stopped actions.
	 */
	public function test_groups_count_recurring_and_stopped_actions() {
		$groups = Report::groups(
			array(
				$this->failed(
					1,
					'acme_feed',
					'action failed via WP Cron: Bad Gateway',
					array(
						'recurring' => true,
						'messages'  => array( 'action failed via WP Cron: Bad Gateway', 'This action appears to be consistently failing. A new instance will not be scheduled.' ),
					)
				),
			)
		);
		$this->assertSame( 1, $groups[0]['recurring'] );
		$this->assertTrue( $groups[0]['stopped'] );
		$this->assertNotEmpty( Report::group_notes( $groups[0] ) );
	}

	/**
	 * An action with no failure message has its own group.
	 */
	public function test_an_action_with_no_failure_message_has_its_own_group() {
		$groups = Report::groups( array( $this->failed( 1, 'acme_sync', '', array( 'messages' => array( 'action created' ) ) ) ) );
		$this->assertSame( '', $groups[0]['signature'] );
		$this->assertSame( 'none', $groups[0]['kind'] );
		$this->assertSame( Triage::REVIEW, $groups[0]['label'] );
	}

	/**
	 * A healthy queue is good.
	 */
	public function test_a_healthy_queue_is_good() {
		$checks = Report::checks( $this->data(), array() );
		foreach ( $checks as $id => $check ) {
			$this->assertSame( Report::GOOD, $check['status'], $id );
		}
	}

	/**
	 * No tables means nothing to check.
	 */
	public function test_no_tables_means_nothing_to_check() {
		$checks = Report::checks( $this->data( array( 'has_tables' => false ) ), array() );
		$this->assertSame( array( 'tables' ), array_keys( $checks ) );
		$this->assertSame( Report::GOOD, $checks['tables']['status'] );
	}

	/**
	 * A few minutes late is normal.
	 */
	public function test_a_few_minutes_late_is_normal() {
		$data   = $this->data(
			array(
				'past_due' => array(
					'count'    => 4,
					'over_day' => 0,
					'oldest'   => self::NOW - 120,
					'hooks'    => array(),
				),
			)
		);
		$checks = Report::checks( $data, array() );
		$this->assertSame( Report::GOOD, $checks['queue']['status'] );
	}

	/**
	 * A queue that runs but falls behind.
	 */
	public function test_a_queue_that_runs_but_falls_behind() {
		$data   = $this->data(
			array(
				'past_due' => array(
					'count'    => 900,
					'over_day' => 0,
					'oldest'   => self::NOW - 7200,
					'hooks'    => array(),
				),
			)
		);
		$checks = Report::checks( $data, array() );
		$this->assertSame( Report::RECOMMENDED, $checks['queue']['status'] );
		$this->assertStringContainsString( 'not keeping up', $checks['queue']['detail'] );
	}

	/**
	 * A stopped queue with wp cron disabled is critical.
	 */
	public function test_a_stopped_queue_with_wp_cron_disabled_is_critical() {
		$data                               = $this->data(
			array(
				'past_due' => array(
					'count'    => 50,
					'over_day' => 10,
					'oldest'   => self::NOW - 2 * 86400,
					'hooks'    => array(),
				),
				'activity' => array(
					'last_log'      => self::NOW - 3 * 86400,
					'last_complete' => self::NOW - 3 * 86400,
				),
			)
		);
		$data['runner']['wp_cron_disabled'] = true;
		$data['runner']['next_run']         = self::NOW - 3 * 86400;
		$checks                             = Report::checks( $data, array() );
		$this->assertSame( Report::CRITICAL, $checks['queue']['status'] );
		$this->assertSame( 'Action Scheduler has stopped running', $checks['queue']['label'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $checks['queue']['detail'] );
	}

	/**
	 * A quiet site with late wp cron gets the hint.
	 */
	public function test_a_quiet_site_with_late_wp_cron_gets_the_hint() {
		$data                       = $this->data(
			array(
				'past_due' => array(
					'count'    => 3,
					'over_day' => 0,
					'oldest'   => self::NOW - 1500,
					'hooks'    => array(),
				),
				'activity' => array(
					'last_log'      => self::NOW - 1500,
					'last_complete' => null,
				),
			)
		);
		$data['runner']['next_run'] = self::NOW - 1600;
		$checks                     = Report::checks( $data, array() );
		$this->assertSame( Report::RECOMMENDED, $checks['queue']['status'] );
		$this->assertStringContainsString( 'WP-Cron runs when someone visits', $checks['queue']['detail'] );
		$this->assertStringNotContainsString( 'not keeping up', $checks['queue']['detail'] );
	}

	/**
	 * A missing runner event is named.
	 */
	public function test_a_missing_runner_event_is_named() {
		$data                       = $this->data(
			array(
				'past_due' => array(
					'count'    => 3,
					'over_day' => 3,
					'oldest'   => self::NOW - 2 * 86400,
					'hooks'    => array(),
				),
				'activity' => array(
					'last_log'      => null,
					'last_complete' => null,
				),
			)
		);
		$data['runner']['next_run'] = null;
		$checks                     = Report::checks( $data, array() );
		$this->assertSame( Report::CRITICAL, $checks['queue']['status'] );
		$this->assertStringContainsString( 'action_scheduler_run_queue) is not scheduled', $checks['queue']['detail'] );
		$this->assertStringContainsString( 'no record of any action having run', $checks['queue']['detail'] );
	}

	/**
	 * Tables without action scheduler.
	 */
	public function test_tables_without_action_scheduler() {
		$data                          = $this->data();
		$data['environment']['loaded'] = false;
		$checks                        = Report::checks( $data, array() );
		$this->assertSame( Report::RECOMMENDED, $checks['queue']['status'] );
		$this->assertStringContainsString( '20 pending actions', $checks['queue']['detail'] );
	}

	/**
	 * Recent failures ask for a look and count fix groups.
	 */
	public function test_recent_failures_ask_for_a_look_and_count_fix_groups() {
		$data   = $this->data(
			array(
				'failed' => array(
					'recent'  => 12,
					'sample'  => 500,
					'actions' => array(),
				),
				'counts' => array( 'failed' => 12 ),
			)
		);
		$groups = Report::groups(
			array(
				$this->failed( 1, 'acme_a', 'action failed: Invalid API key provided' ),
				$this->failed( 2, 'acme_b', 'action failed: cURL error 28: timed out' ),
			)
		);
		$checks = Report::checks( $data, $groups );
		$this->assertSame( Report::RECOMMENDED, $checks['failures']['status'] );
		$this->assertStringContainsString( '1 group needs a fix', $checks['failures']['detail'] );
	}

	/**
	 * Old failures alone are good.
	 */
	public function test_old_failures_alone_are_good() {
		$data   = $this->data( array( 'counts' => array( 'failed' => 3 ) ) );
		$checks = Report::checks( $data, array() );
		$this->assertSame( Report::GOOD, $checks['failures']['status'] );
		$this->assertStringContainsString( '3 older failed actions', $checks['failures']['detail'] );
	}

	/**
	 * Stuck actions and stale claims.
	 */
	public function test_stuck_actions_and_stale_claims() {
		$data   = $this->data(
			array(
				'running' => array(
					'count'  => 2,
					'long'   => 1,
					'oldest' => self::NOW - 1200,
				),
				'claims'  => array(
					'count'  => 3,
					'stale'  => 2,
					'oldest' => self::NOW - 1200,
				),
			)
		);
		$checks = Report::checks( $data, array() );
		$this->assertSame( Report::RECOMMENDED, $checks['stuck']['status'] );
		$this->assertStringContainsString( '1 action has been in progress', $checks['stuck']['detail'] );
		$this->assertStringContainsString( '2 claims are older', $checks['stuck']['detail'] );
	}

	/**
	 * Cleanup that falls behind retention.
	 */
	public function test_cleanup_that_falls_behind_retention() {
		$data                       = $this->data();
		$data['oldest']['complete'] = self::NOW - 60 * 86400;
		$data['oldest']['failed']   = self::NOW - 200 * 86400;
		$checks                     = Report::checks( $data, array() );
		$this->assertSame( Report::RECOMMENDED, $checks['storage']['status'] );
		$this->assertStringContainsString( 'oldest completed action is 60 days old', $checks['storage']['detail'] );
		$this->assertStringContainsString( 'oldest failed action is 200 days old', $checks['storage']['detail'] );
	}

	/**
	 * Failed actions are not judged when they are kept.
	 */
	public function test_failed_actions_are_not_judged_when_they_are_kept() {
		$data                                = $this->data();
		$data['retention']['failed_cleanup'] = false;
		$data['oldest']['failed']            = self::NOW - 400 * 86400;
		$checks                              = Report::checks( $data, array() );
		$this->assertSame( Report::GOOD, $checks['storage']['status'] );
	}

	/**
	 * Large tables.
	 */
	public function test_large_tables() {
		$data                                = $this->data();
		$data['storage']['actions']['bytes'] = 3 * GB_IN_BYTES;
		$checks                              = Report::checks( $data, array() );
		$this->assertSame( Report::RECOMMENDED, $checks['storage']['status'] );
		$this->assertStringContainsString( '3.0 GB', $checks['storage']['detail'] );
	}

	/**
	 * Plain text report leaves out private values.
	 */
	public function test_plain_text_report_leaves_out_private_values() {
		$data           = $this->data();
		$data['failed'] = array(
			'recent'  => 1,
			'sample'  => 500,
			'actions' => array(
				$this->failed(
					1,
					'acme_mail',
					'action failed via WP Cron: Could not send to jane@example.com from 203.0.113.9 via https://api.example.com/x?key=secret in /home/shop/public_html/wp-content/plugins/acme/mail.php',
					array( 'group' => 'acme-private-group' )
				),
			),
		);
		$report         = Report::build( $data );
		$text           = Report::plain_text( $report );
		foreach ( array( 'jane', 'example.com?', 'secret', '203.0.113.9', '/home/shop', 'acme-private-group' ) as $private ) {
			$this->assertStringNotContainsString( $private, $text, $private );
		}
		$this->assertStringContainsString( '1 x acme_mail', $text );
		$this->assertStringContainsString( 'wp-content/plugins/acme/mail.php', $text );
		$this->assertStringContainsString( 'Action Scheduler: 4.1.0 (from WooCommerce)', $text );
	}

	/**
	 * Plain text report without tables.
	 */
	public function test_plain_text_report_without_tables() {
		$text = Report::plain_text( Report::build( $this->data( array( 'has_tables' => false ) ) ) );
		$this->assertStringContainsString( 'Action Scheduler tables: not found', $text );
	}

	/**
	 * Every cause has an explanation.
	 */
	public function test_every_cause_has_an_explanation() {
		foreach ( array_keys( Triage::rules() ) as $cause ) {
			$this->assertStringNotContainsString( 'does not match a known cause', Report::cause_text( $cause ), $cause );
		}
		$this->assertStringNotContainsString( 'does not match a known cause', Report::cause_text( 'runner-timeout' ) );
		$this->assertStringContainsString( 'does not match a known cause', Report::cause_text( 'unknown' ) );
	}

	/**
	 * Label names.
	 */
	public function test_label_names() {
		$this->assertSame( 'Fix first', Report::label_name( Triage::FIX ) );
		$this->assertSame( 'Retry candidate, check first', Report::label_name( Triage::RETRY ) );
		$this->assertSame( 'Review', Report::label_name( Triage::REVIEW ) );
	}
}
