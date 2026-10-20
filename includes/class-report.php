<?php
/**
 * Builds the report from what the reader collected.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Groups failures, runs the checks and writes the plain-text support report.
 *
 * Works on the array from Reader::collect() only, so tests can feed it made-up data.
 */
class Report {

	const GOOD        = 'good';
	const RECOMMENDED = 'recommended';
	const CRITICAL    = 'critical';

	/**
	 * Past-due age that is still normal between two runs of the queue.
	 */
	const PAST_DUE_GRACE = 600;

	/**
	 * Builds the whole report.
	 *
	 * @param array $data From Reader::collect().
	 * @return array{data: array, groups: array, checks: array}
	 */
	public static function build( array $data ) {
		$groups = self::groups( isset( $data['failed']['actions'] ) ? $data['failed']['actions'] : array() );
		return array(
			'data'   => $data,
			'groups' => $groups,
			'checks' => self::checks( $data, $groups ),
		);
	}

	/**
	 * Groups failed actions by hook and error template, largest group first.
	 *
	 * @param array $actions Failed actions from the reader.
	 * @return array
	 */
	public static function groups( array $actions ) {
		$groups = array();
		foreach ( $actions as $action ) {
			$failure  = Signature::failure_of( $action['messages'] );
			$message  = '' === $failure['text'] ? '' : Signature::clean( $failure['text'] );
			$template = '' === $message ? '' : Signature::template( $message );
			$key      = $action['hook'] . "\n" . $template;

			if ( ! isset( $groups[ $key ] ) ) {
				$triage         = Triage::classify( $action['hook'], $failure['kind'], $message );
				$groups[ $key ] = array(
					'hook'         => $action['hook'],
					'signature'    => $template,
					'example'      => $message,
					'kind'         => $failure['kind'],
					'label'        => $triage['label'],
					'cause'        => $triage['cause'],
					'side_effects' => $triage['side_effects'],
					'count'        => 0,
					'recurring'    => 0,
					'stopped'      => false,
					'latest'       => null,
					'earliest'     => null,
					'groups'       => array(),
					'ids'          => array(),
				);
			}
			$group = &$groups[ $key ];
			++$group['count'];
			if ( ! empty( $action['recurring'] ) ) {
				++$group['recurring'];
			}
			$group['stopped'] = $group['stopped'] || $failure['stopped'];
			$time             = null !== $action['attempted'] ? $action['attempted'] : $action['scheduled'];
			if ( null !== $time ) {
				$group['latest']   = null === $group['latest'] ? $time : max( $group['latest'], $time );
				$group['earliest'] = null === $group['earliest'] ? $time : min( $group['earliest'], $time );
			}
			if ( '' !== $action['group'] && count( $group['groups'] ) < 3 && ! in_array( $action['group'], $group['groups'], true ) ) {
				$group['groups'][] = $action['group'];
			}
			if ( count( $group['ids'] ) < 5 ) {
				$group['ids'][] = $action['id'];
			}
			unset( $group );
		}

		$groups = array_values( $groups );
		usort(
			$groups,
			function ( $a, $b ) {
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] - $a['count'];
				}
				return strcmp( $a['hook'] . $a['signature'], $b['hook'] . $b['signature'] );
			}
		);
		return $groups;
	}

	/**
	 * The label's name.
	 *
	 * @param string $label Triage label.
	 * @return string
	 */
	public static function label_name( $label ) {
		switch ( $label ) {
			case Triage::FIX:
				return __( 'Fix first', 'queue-triage-for-action-scheduler' );
			case Triage::RETRY:
				return __( 'Retry candidate, check first', 'queue-triage-for-action-scheduler' );
			default:
				return __( 'Review', 'queue-triage-for-action-scheduler' );
		}
	}

	/**
	 * What a cause means and what to check next.
	 *
	 * @param string $cause Cause from Triage::classify().
	 * @return string
	 */
	public static function cause_text( $cause ) {
		$texts = array(
			'missing-callback' => __( 'No code is listening for this hook. The plugin that scheduled these is probably inactive or removed. Reactivate it, or cancel these actions if the plugin is gone for good.', 'queue-triage-for-action-scheduler' ),
			'resource-limit'   => __( 'PHP ran out of memory or time inside the callback. Check the PHP error log, then raise the limit or make the job smaller before these run again.', 'queue-triage-for-action-scheduler' ),
			'code-error'       => __( 'A PHP error in the callback. The file in the message points to the plugin or theme; update or fix it before these run again.', 'queue-triage-for-action-scheduler' ),
			'database-busy'    => __( 'The database was busy (a lock wait, a deadlock or a dropped connection). This often passes. Check whether the job did part of its work before it failed.', 'queue-triage-for-action-scheduler' ),
			'database'         => __( 'A database query failed. Check the table and the plugin that owns the hook before these run again.', 'queue-triage-for-action-scheduler' ),
			'auth'             => __( 'A remote service refused the credentials. Renew the API key or reconnect the integration; until then these fail the same way.', 'queue-triage-for-action-scheduler' ),
			'rate-limit'       => __( 'A remote service asked for fewer requests. These may work later. Check the service\'s limits and whether a rerun would send anything twice.', 'queue-triage-for-action-scheduler' ),
			'network'          => __( 'A request to a remote service timed out or could not connect. These may work once the service is reachable. Check that the service did not already receive the work.', 'queue-triage-for-action-scheduler' ),
			'remote-server'    => __( 'A remote service returned a server error. These may work later. Check the service\'s status page first.', 'queue-triage-for-action-scheduler' ),
			'missing-data'     => __( 'Something the job needs no longer exists, such as a deleted order, product or remote record. Running it again fails the same way; restore the record or cancel these.', 'queue-triage-for-action-scheduler' ),
			'invalid-data'     => __( 'The job\'s data was rejected as invalid. Fix the record, or the code that builds it, before these run again.', 'queue-triage-for-action-scheduler' ),
			'runner-timeout'   => __( 'The callback was still running when Action Scheduler\'s time limit passed, so it was marked failed. It may have done part of its work. Check the PHP error log for a fatal error or a slow remote call.', 'queue-triage-for-action-scheduler' ),
		);
		return isset( $texts[ $cause ] ) ? $texts[ $cause ] : __( 'The message does not match a known cause. Read the full log of one of these actions.', 'queue-triage-for-action-scheduler' );
	}

	/**
	 * Notes that apply to a group on top of its cause.
	 *
	 * @param array $group A group from groups().
	 * @return string[]
	 */
	public static function group_notes( array $group ) {
		$notes = array();
		if ( $group['side_effects'] ) {
			$notes[] = __( 'The hook name suggests it may take a payment, send a message or call a webhook. A rerun could repeat that, so check what already happened.', 'queue-triage-for-action-scheduler' );
		}
		if ( $group['stopped'] ) {
			$notes[] = __( 'Action Scheduler stopped rescheduling this recurring action after repeated failures. It will not come back on its own.', 'queue-triage-for-action-scheduler' );
		}
		if ( 'none' === $group['kind'] ) {
			$notes[] = __( 'Action Scheduler logged no reason for these failures.', 'queue-triage-for-action-scheduler' );
		}
		return $notes;
	}

	/**
	 * Runs the checks.
	 *
	 * @param array $data   From Reader::collect().
	 * @param array $groups From groups().
	 * @return array<string, array{status: string, label: string, detail: string}>
	 */
	public static function checks( array $data, array $groups ) {
		if ( empty( $data['has_tables'] ) ) {
			return array(
				'tables' => array(
					'status' => self::GOOD,
					'label'  => __( 'Action Scheduler is not in use on this site', 'queue-triage-for-action-scheduler' ),
					'detail' => __( 'Its database tables were not found, so there is nothing to check.', 'queue-triage-for-action-scheduler' ),
				),
			);
		}
		return array(
			'queue'    => self::check_queue( $data ),
			'failures' => self::check_failures( $data, $groups ),
			'stuck'    => self::check_stuck( $data ),
			'storage'  => self::check_storage( $data ),
		);
	}

	/**
	 * Is the queue running on time?
	 *
	 * @param array $data Collected data.
	 * @return array
	 */
	private static function check_queue( array $data ) {
		$now      = (int) $data['now'];
		$past_due = $data['past_due'];
		$runner   = $data['runner'];
		$activity = $data['activity'];
		$loaded   = ! empty( $data['environment']['loaded'] );
		$last     = max( (int) $activity['last_log'], (int) $activity['last_complete'] );
		$age      = null === $past_due['oldest'] ? 0 : $now - (int) $past_due['oldest'];

		if ( ! $loaded ) {
			return array(
				'status' => self::RECOMMENDED,
				'label'  => __( 'Action Scheduler is not loaded, but its tables are still here', 'queue-triage-for-action-scheduler' ),
				'detail' => sprintf(
					/* translators: %s: number of pending actions. */
					__( 'No active plugin loads Action Scheduler, so nothing will run the %s pending actions. If the plugins that used it are gone for good, the tables can be removed.', 'queue-triage-for-action-scheduler' ),
					Format::number( isset( $data['counts']['pending'] ) ? $data['counts']['pending'] : 0 )
				),
			);
		}

		$hints = array();
		if ( $runner['wp_cron_disabled'] ) {
			$hints[] = __( 'DISABLE_WP_CRON is set, so only a server cron job, WP-CLI or the async runner can start the queue.', 'queue-triage-for-action-scheduler' );
		}
		if ( null === $runner['next_run'] ) {
			$hints[] = __( 'The WP-Cron event that starts the queue (action_scheduler_run_queue) is not scheduled.', 'queue-triage-for-action-scheduler' );
		} elseif ( ! $runner['wp_cron_disabled'] && $now - (int) $runner['next_run'] > self::PAST_DUE_GRACE ) {
			$hints[] = sprintf(
				/* translators: %s: duration, such as "3 hours". */
				__( 'The WP-Cron event that starts the queue is %s late. WP-Cron runs when someone visits the site, so low traffic or a blocked request to the site itself can stall it.', 'queue-triage-for-action-scheduler' ),
				Format::duration( $now - (int) $runner['next_run'] )
			);
		}
		if ( $runner['async_filtered_off'] ) {
			$hints[] = __( 'A filter turns off the async runner, which starts the queue from admin page loads.', 'queue-triage-for-action-scheduler' );
		}
		$evidence = $last > 0
			? sprintf(
				/* translators: %s: duration, such as "3 minutes". */
				__( 'The newest log entry or completed action is %s old.', 'queue-triage-for-action-scheduler' ),
				Format::duration( $now - $last )
			)
			: __( 'There is no record of any action having run.', 'queue-triage-for-action-scheduler' );

		if ( 0 === (int) $past_due['count'] || $age <= self::PAST_DUE_GRACE ) {
			$detail = 0 === (int) $past_due['count']
				? __( 'No pending action is past its scheduled time.', 'queue-triage-for-action-scheduler' )
				: sprintf(
					/* translators: 1: number of actions, 2: duration. */
					__( '%1$s actions are due; the oldest is %2$s late, which is normal between two runs.', 'queue-triage-for-action-scheduler' ),
					Format::number( $past_due['count'] ),
					Format::duration( $age )
				);
			return array(
				'status' => self::GOOD,
				'label'  => __( 'Action Scheduler is running on time', 'queue-triage-for-action-scheduler' ),
				'detail' => trim( $detail . ' ' . ( $runner['wp_cron_disabled'] && $last > 0 ? __( 'WP-Cron is disabled, and something else is running the queue.', 'queue-triage-for-action-scheduler' ) : '' ) ),
			);
		}

		$stalled = 0 === $last || $now - $last > HOUR_IN_SECONDS;
		$busy    = $last > 0 && $now - $last <= self::PAST_DUE_GRACE;
		$detail  = sprintf(
			/* translators: 1: number of actions, 2: duration. */
			__( '%1$s pending actions are past their scheduled time; the oldest is %2$s late.', 'queue-triage-for-action-scheduler' ),
			Format::number( $past_due['count'] ),
			Format::duration( $age )
		) . ' ' . $evidence;
		if ( $busy ) {
			$detail .= ' ' . __( 'The queue is running but not keeping up: look at the hooks with the most past-due actions.', 'queue-triage-for-action-scheduler' );
		} elseif ( $hints ) {
			$detail .= ' ' . implode( ' ', $hints );
		} else {
			$detail .= ' ' . __( 'Nothing has started the queue since then. Check that WP-Cron or a server cron job runs.', 'queue-triage-for-action-scheduler' );
		}

		return array(
			'status' => $age > DAY_IN_SECONDS || $stalled ? self::CRITICAL : self::RECOMMENDED,
			'label'  => $stalled
				? __( 'Action Scheduler has stopped running', 'queue-triage-for-action-scheduler' )
				: __( 'Action Scheduler is falling behind', 'queue-triage-for-action-scheduler' ),
			'detail' => $detail,
		);
	}

	/**
	 * Did actions fail in the last seven days?
	 *
	 * @param array $data   Collected data.
	 * @param array $groups Failure groups.
	 * @return array
	 */
	private static function check_failures( array $data, array $groups ) {
		$recent = (int) $data['failed']['recent'];
		$total  = isset( $data['counts']['failed'] ) ? (int) $data['counts']['failed'] : 0;
		if ( 0 === $recent ) {
			return array(
				'status' => self::GOOD,
				'label'  => __( 'No scheduled actions failed in the last 7 days', 'queue-triage-for-action-scheduler' ),
				'detail' => $total > 0
					? sprintf(
						/* translators: %s: number of actions. */
						_n( '%s older failed action is still stored.', '%s older failed actions are still stored.', $total, 'queue-triage-for-action-scheduler' ),
						Format::number( $total )
					)
					: __( 'No failed actions are stored.', 'queue-triage-for-action-scheduler' ),
			);
		}

		$fix = 0;
		foreach ( $groups as $group ) {
			if ( Triage::FIX === $group['label'] ) {
				++$fix;
			}
		}
		$detail = sprintf(
			/* translators: 1: number of failed actions, 2: number of groups. */
			__( '%1$s actions failed in the last 7 days. The newest failures fall into %2$s groups by hook and error.', 'queue-triage-for-action-scheduler' ),
			Format::number( $recent ),
			Format::number( count( $groups ) )
		);
		if ( $fix > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: number of groups. */
				_n( '%s group needs a fix before its actions can succeed.', '%s groups need a fix before their actions can succeed.', $fix, 'queue-triage-for-action-scheduler' ),
				Format::number( $fix )
			);
		}
		return array(
			'status' => self::RECOMMENDED,
			'label'  => __( 'Scheduled actions are failing', 'queue-triage-for-action-scheduler' ),
			'detail' => $detail,
		);
	}

	/**
	 * Are actions stuck in progress, or claims left behind?
	 *
	 * @param array $data Collected data.
	 * @return array
	 */
	private static function check_stuck( array $data ) {
		$long  = (int) $data['running']['long'];
		$stale = isset( $data['claims']['stale'] ) ? (int) $data['claims']['stale'] : 0;
		if ( 0 === $long && 0 === $stale ) {
			return array(
				'status' => self::GOOD,
				'label'  => __( 'No actions look stuck', 'queue-triage-for-action-scheduler' ),
				'detail' => __( 'No action has been in progress for more than 5 minutes, and no claim is older than 5 minutes.', 'queue-triage-for-action-scheduler' ),
			);
		}
		$parts = array();
		if ( $long > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of actions. */
				_n( '%s action has been in progress for more than 5 minutes.', '%s actions have been in progress for more than 5 minutes.', $long, 'queue-triage-for-action-scheduler' ),
				Format::number( $long )
			);
		}
		if ( $stale > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of claims. */
				_n( '%s claim is older than 5 minutes, which usually means a queue run was stopped part-way.', '%s claims are older than 5 minutes, which usually means queue runs were stopped part-way.', $stale, 'queue-triage-for-action-scheduler' ),
				Format::number( $stale )
			);
		}
		$parts[] = __( 'Action Scheduler releases these on its own when the queue runs; long jobs can also be intentional.', 'queue-triage-for-action-scheduler' );
		return array(
			'status' => self::RECOMMENDED,
			'label'  => __( 'Some actions may be stuck', 'queue-triage-for-action-scheduler' ),
			'detail' => implode( ' ', $parts ),
		);
	}

	/**
	 * Is cleanup keeping up, and how large are the tables?
	 *
	 * @param array $data Collected data.
	 * @return array
	 */
	private static function check_storage( array $data ) {
		$now       = (int) $data['now'];
		$retention = $data['retention'];
		$oldest    = $data['oldest'];
		$storage   = $data['storage'];
		$bytes     = 0;
		foreach ( $storage as $table ) {
			$bytes += (int) $table['bytes'];
		}
		$problems = array();

		$slack = 2 * DAY_IN_SECONDS;
		if ( $retention['period'] > 0 && in_array( 'complete', $retention['statuses'], true ) && null !== $oldest['complete'] && $now - $oldest['complete'] > $retention['period'] + $slack ) {
			$problems[] = sprintf(
				/* translators: 1: duration, 2: duration. */
				__( 'The oldest completed action is %1$s old, but completed actions are kept for %2$s. Cleanup runs with the queue, so it is behind or stopped.', 'queue-triage-for-action-scheduler' ),
				Format::duration( $now - $oldest['complete'] ),
				Format::duration( $retention['period'] )
			);
		}
		if ( $retention['failed_cleanup'] && $retention['failed_period'] > 0 && null !== $oldest['failed'] && $now - $oldest['failed'] > $retention['failed_period'] + $slack ) {
			$problems[] = sprintf(
				/* translators: 1: duration, 2: duration. */
				__( 'The oldest failed action is %1$s old, but failed actions are kept for %2$s.', 'queue-triage-for-action-scheduler' ),
				Format::duration( $now - $oldest['failed'] ),
				Format::duration( $retention['failed_period'] )
			);
		}
		if ( $bytes > GB_IN_BYTES ) {
			$problems[] = sprintf(
				/* translators: %s: size, such as "1.2 GB". */
				__( 'The Action Scheduler tables use %s. Large log tables slow down backups and the Scheduled Actions screen.', 'queue-triage-for-action-scheduler' ),
				Format::bytes( $bytes )
			);
		}

		if ( ! $problems ) {
			return array(
				'status' => self::GOOD,
				'label'  => __( 'Action Scheduler cleanup is keeping up', 'queue-triage-for-action-scheduler' ),
				'detail' => sprintf(
					/* translators: %s: size, such as "12 MB". */
					__( 'The Action Scheduler tables use about %s.', 'queue-triage-for-action-scheduler' ),
					Format::bytes( $bytes )
				),
			);
		}
		return array(
			'status' => self::RECOMMENDED,
			'label'  => __( 'Action Scheduler tables are growing', 'queue-triage-for-action-scheduler' ),
			'detail' => implode( ' ', $problems ),
		);
	}

	/**
	 * A plain-text report to paste into a support ticket.
	 *
	 * It holds counts, settings, hook names and cleaned error signatures. It never holds action arguments,
	 * action ids, group names, email addresses, IP addresses, URL queries or server paths.
	 *
	 * @param array $report From build().
	 * @param int   $limit  Failure groups to include.
	 * @return string
	 */
	public static function plain_text( array $report, $limit = 15 ) {
		$data  = $report['data'];
		$env   = $data['environment'];
		$now   = (int) $data['now'];
		$lines = array();

		$lines[] = '### Queue Triage for Action Scheduler report';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i', $now ) . ' UTC';
		$lines[] = 'Action Scheduler: ' . ( $env['loaded'] ? $env['version'] . ( $env['source'] ? ' (from ' . $env['source'] . ')' : '' ) : 'not loaded' );
		if ( $env['store'] ) {
			$lines[] = 'Data store: ' . $env['store'];
		}
		if ( count( $env['sources'] ) > 1 ) {
			$copies = array();
			foreach ( $env['sources'] as $source ) {
				$copies[] = $source['version'] . ' in ' . $source['path'];
			}
			$lines[] = 'Bundled copies: ' . implode( '; ', $copies );
		}
		$lines[] = 'WordPress: ' . get_bloginfo( 'version' ) . ', PHP: ' . PHP_VERSION;

		if ( empty( $data['has_tables'] ) ) {
			$lines[] = 'Action Scheduler tables: not found';
			return implode( "\n", $lines ) . "\n";
		}

		$lines[] = '';
		$lines[] = '## Checks';
		foreach ( $report['checks'] as $check ) {
			$lines[] = '- [' . $check['status'] . '] ' . $check['label'] . ': ' . $check['detail'];
		}

		$lines[] = '';
		$lines[] = '## Counts';
		$counts  = array();
		foreach ( $data['counts'] as $status => $n ) {
			$counts[] = $status . ' ' . $n;
		}
		$lines[] = $counts ? implode( ', ', $counts ) : 'no actions';
		$lines[] = 'Past due: ' . $data['past_due']['count'] . ( null !== $data['past_due']['oldest'] ? ', oldest ' . Format::duration( $now - $data['past_due']['oldest'] ) . ' late' : '' );
		foreach ( $data['past_due']['hooks'] as $hook ) {
			$lines[] = '  ' . $hook['count'] . ' x ' . $hook['hook'];
		}
		$lines[] = 'In progress: ' . $data['running']['count'] . ' (' . $data['running']['long'] . ' over 5 minutes)';
		if ( $data['claims'] ) {
			$lines[] = 'Claims: ' . $data['claims']['count'] . ' (' . $data['claims']['stale'] . ' over 5 minutes)';
		}

		$runner  = $data['runner'];
		$lines[] = '';
		$lines[] = '## Runner';
		$lines[] = 'DISABLE_WP_CRON: ' . ( $runner['wp_cron_disabled'] ? 'yes' : 'no' ) . ', ALTERNATE_WP_CRON: ' . ( $runner['alternate_wp_cron'] ? 'yes' : 'no' );
		$lines[] = 'Next action_scheduler_run_queue: ' . ( null === $runner['next_run'] ? 'not scheduled' : ( $runner['next_run'] < $now ? Format::duration( $now - $runner['next_run'] ) . ' ago' : 'in ' . Format::duration( $runner['next_run'] - $now ) ) );
		if ( null !== $runner['batch_size'] ) {
			$lines[] = 'Batch size ' . $runner['batch_size'] . ', concurrent batches ' . $runner['concurrent_batches'] . ', time limit ' . $runner['time_limit'] . ' s' . ( $runner['async_filtered_off'] ? ', async runner off' : '' );
		}
		$last    = max( (int) $data['activity']['last_log'], (int) $data['activity']['last_complete'] );
		$lines[] = 'Last activity: ' . ( $last > 0 ? Format::duration( $now - $last ) . ' ago' : 'none recorded' );

		$retention = $data['retention'];
		$lines[]   = '';
		$lines[]   = '## Storage';
		foreach ( $data['storage'] as $key => $table ) {
			$lines[] = $key . ': about ' . $table['rows'] . ' rows, ' . Format::bytes( $table['bytes'] );
		}
		$lines[] = 'Retention: ' . implode( ', ', $retention['statuses'] ) . ' ' . Format::duration( $retention['period'] ) . '; failed ' . ( $retention['failed_cleanup'] ? Format::duration( $retention['failed_period'] ) : 'kept' );

		$lines[] = '';
		$lines[] = '## Failure groups';
		$lines[] = 'Read the newest ' . count( $data['failed']['actions'] ) . ' of ' . ( isset( $data['counts']['failed'] ) ? $data['counts']['failed'] : 0 ) . ' failed actions.';
		foreach ( array_slice( $report['groups'], 0, $limit ) as $group ) {
			$lines[] = '- ' . $group['count'] . ' x ' . $group['hook'] . ' [' . $group['label'] . ', ' . $group['cause'] . ']';
			$lines[] = '  ' . ( '' === $group['signature'] ? '(no failure message)' : $group['signature'] );
		}
		if ( count( $report['groups'] ) > $limit ) {
			$lines[] = '- ' . ( count( $report['groups'] ) - $limit ) . ' more groups not shown';
		}

		return implode( "\n", $lines ) . "\n";
	}
}
