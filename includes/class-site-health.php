<?php
/**
 * Site Health tests and the Site Health Info section.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds two tests (is the queue running on time, are actions failing) and an Info section.
 */
class Site_Health {

	/**
	 * Hooks the tests in.
	 */
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'info' ) );
	}

	/**
	 * Adds the tests when Action Scheduler's tables exist. Without them there is nothing to test. Site Health
	 * shows results only to users who may see it, and its weekly check runs without a user, so there is no
	 * capability check here.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		if ( ! Plugin::has_tables() ) {
			return $tests;
		}
		$tests['direct']['queue_triage_queue']    = array(
			'label' => __( 'Action Scheduler queue', 'queue-triage-for-action-scheduler' ),
			'test'  => array( __CLASS__, 'test_queue' ),
		);
		$tests['direct']['queue_triage_failures'] = array(
			'label' => __( 'Action Scheduler failures', 'queue-triage-for-action-scheduler' ),
			'test'  => array( __CLASS__, 'test_failures' ),
		);
		return $tests;
	}

	/**
	 * Is the queue running on time?
	 *
	 * @return array
	 */
	public static function test_queue() {
		$report = Plugin::report();
		return self::result( 'queue_triage_queue', $report['checks']['queue'] );
	}

	/**
	 * Are actions failing?
	 *
	 * @return array
	 */
	public static function test_failures() {
		$report = Plugin::report();
		return self::result( 'queue_triage_failures', $report['checks']['failures'] );
	}

	/**
	 * A check in Site Health's result format.
	 *
	 * @param string $test  Test id.
	 * @param array  $check Check from Report::checks().
	 * @return array
	 */
	private static function result( $test, array $check ) {
		return array(
			'label'       => $check['label'],
			'status'      => $check['status'],
			'badge'       => array(
				'label' => __( 'Performance', 'queue-triage-for-action-scheduler' ),
				'color' => Report::GOOD === $check['status'] ? 'blue' : ( Report::CRITICAL === $check['status'] ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $check['detail'] ) . '</p>',
			'actions'     => Report::GOOD === $check['status'] ? '' : '<p><a href="' . esc_url( Admin_Page::url() ) . '">' . esc_html__( 'See the Queue Triage report', 'queue-triage-for-action-scheduler' ) . '</a></p>',
			'test'        => $test,
		);
	}

	/**
	 * Adds an "Action Scheduler" section to Site Health Info, which people copy into support requests.
	 *
	 * @param array $info Site Health Info sections.
	 * @return array
	 */
	public static function info( $info ) {
		$report = Plugin::report();
		$data   = $report['data'];
		$env    = $data['environment'];
		$fields = array(
			'version' => array(
				'label' => __( 'Version in use', 'queue-triage-for-action-scheduler' ),
				'value' => $env['loaded'] ? $env['version'] . ( '' !== $env['source'] ? ' (' . $env['source'] . ')' : '' ) : __( 'Not loaded', 'queue-triage-for-action-scheduler' ),
			),
		);
		if ( $data['has_tables'] ) {
			$counts = array();
			foreach ( $data['counts'] as $status => $n ) {
				$counts[] = $status . ': ' . $n;
			}
			$fields['counts']   = array(
				'label' => __( 'Actions by status', 'queue-triage-for-action-scheduler' ),
				'value' => implode( ', ', $counts ),
			);
			$fields['past_due'] = array(
				'label' => __( 'Past-due actions', 'queue-triage-for-action-scheduler' ),
				'value' => (string) $data['past_due']['count'],
			);
			$bytes              = 0;
			foreach ( $data['storage'] as $table ) {
				$bytes += $table['bytes'];
			}
			$fields['storage'] = array(
				'label' => __( 'Table size', 'queue-triage-for-action-scheduler' ),
				'value' => Format::bytes( $bytes ),
			);
			foreach ( $report['checks'] as $id => $check ) {
				$fields[ 'check_' . $id ] = array(
					'label' => $check['label'],
					'value' => $check['status'],
					'debug' => $check['status'] . ': ' . $check['detail'],
				);
			}
		}
		$info['queue-triage'] = array(
			'label'  => __( 'Action Scheduler (Queue Triage)', 'queue-triage-for-action-scheduler' ),
			'fields' => $fields,
		);
		return $info;
	}
}
