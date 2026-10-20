<?php
/**
 * Reads Action Scheduler's tables and settings. Read-only.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the raw facts the report is built from.
 *
 * Every query is a SELECT with a LIMIT or an aggregate on an indexed column, and nothing is written. The
 * queries go to the tables directly because Action Scheduler's public API has no way to group actions by
 * hook or to read table sizes. Results are not cached: the screen shows the queue as it is now.
 */
class Reader {

	/**
	 * Failed actions read for the failure signatures, newest first.
	 */
	const DEFAULT_SAMPLE = 500;

	/**
	 * Most log lines read for the sampled failed actions.
	 */
	const MAX_LOG_ROWS = 5000;

	/**
	 * The current time as a Unix timestamp, replaceable in tests.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Sets the clock.
	 *
	 * @param int|null $now Unix timestamp, or null for the current time.
	 */
	public function __construct( $now = null ) {
		$this->now = null === $now ? time() : (int) $now;
	}

	/**
	 * Table names with the site's prefix.
	 *
	 * @return array{actions: string, logs: string, claims: string, groups: string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'actions' => $wpdb->prefix . 'actionscheduler_actions',
			'logs'    => $wpdb->prefix . 'actionscheduler_logs',
			'claims'  => $wpdb->prefix . 'actionscheduler_claims',
			'groups'  => $wpdb->prefix . 'actionscheduler_groups',
		);
	}

	/**
	 * Everything the report needs.
	 *
	 * @param int $sample Failed actions to read for the signatures.
	 * @return array
	 */
	public function collect( $sample = self::DEFAULT_SAMPLE ) {
		$sample = max( 1, min( 5000, (int) $sample ) );
		$tables = $this->existing_tables();

		$env  = $this->environment();
		$data = array(
			'now'         => $this->now,
			'environment' => $env,
			'runner'      => $this->runner(),
			'retention'   => $this->retention( $env['version'] ),
			'tables'      => $tables,
			'has_tables'  => isset( $tables['actions'] ),
			'storage'     => array(),
			'counts'      => array(),
			'past_due'    => array(),
			'failed'      => array(),
			'running'     => array(),
			'claims'      => array(),
			'activity'    => array(),
			'oldest'      => array(),
		);

		if ( ! $data['has_tables'] ) {
			return $data;
		}

		$data['storage']  = $this->storage( $tables );
		$data['counts']   = $this->counts();
		$data['past_due'] = $this->past_due();
		$data['failed']   = $this->failed( $sample, isset( $tables['logs'] ), isset( $tables['groups'] ) );
		$data['running']  = $this->running();
		$data['claims']   = isset( $tables['claims'] ) ? $this->claims() : array();
		$data['activity'] = $this->activity( isset( $tables['logs'] ) );
		$data['oldest']   = $this->oldest_finished();

		return $data;
	}

	/**
	 * Which of the four tables exist.
	 *
	 * @return array<string, string> Key to table name, for the tables found.
	 */
	public function existing_tables() {
		global $wpdb;
		$found = array();
		foreach ( self::tables() as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks a table name; nothing to cache.
			$name = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $name === $table ) {
				$found[ $key ] = $table;
			}
		}
		return $found;
	}

	/**
	 * Action Scheduler version, data store and the plugins that bundle it.
	 *
	 * @return array
	 */
	public function environment() {
		$env = array(
			'loaded'        => class_exists( 'ActionScheduler' ) && class_exists( 'ActionScheduler_Versions' ),
			'version'       => '',
			'store'         => '',
			'custom_tables' => false,
			'source'        => '',
			'sources'       => array(),
		);
		if ( ! $env['loaded'] ) {
			return $env;
		}

		$versions = \ActionScheduler_Versions::instance();
		if ( method_exists( $versions, 'latest_version' ) ) {
			$env['version'] = (string) $versions->latest_version();
		}
		if ( method_exists( $versions, 'get_sources' ) ) {
			foreach ( (array) $versions->get_sources() as $path => $version ) {
				$env['sources'][] = array(
					'path'    => self::relative_path( (string) $path ),
					'version' => (string) $version,
				);
			}
		}
		if ( class_exists( 'ActionScheduler_SystemInformation' ) && method_exists( 'ActionScheduler_SystemInformation', 'active_source' ) ) {
			$source = \ActionScheduler_SystemInformation::active_source();
			if ( ! empty( $source['name'] ) ) {
				$env['source'] = (string) $source['name'];
			}
		}

		$store = \ActionScheduler::store();
		if ( is_object( $store ) ) {
			$env['store']         = get_class( $store );
			$env['custom_tables'] = $store instanceof \ActionScheduler_DBStore || ( class_exists( 'ActionScheduler_HybridStore' ) && $store instanceof \ActionScheduler_HybridStore );
		}
		return $env;
	}

	/**
	 * What runs the queue: WP-Cron settings, the runner's cron event and the runner's limits.
	 *
	 * The limits are read through Action Scheduler's own filters with its defaults, so the values shown
	 * are the ones in effect on this site.
	 *
	 * @return array
	 */
	public function runner() {
		$next = wp_next_scheduled( 'action_scheduler_run_queue', array( 'WP Cron' ) );
		if ( false === $next ) {
			$next = wp_next_scheduled( 'action_scheduler_run_queue' );
		}
		$runner = array(
			'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron'  => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'next_run'           => false === $next ? null : (int) $next,
			'batch_size'         => null,
			'concurrent_batches' => null,
			'time_limit'         => null,
			'async_filtered_off' => false,
		);
		if ( class_exists( 'ActionScheduler' ) ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reads Action Scheduler's own filters.
			$runner['batch_size']         = (int) apply_filters( 'action_scheduler_queue_runner_batch_size', 25 );
			$runner['concurrent_batches'] = (int) apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 1 );
			$runner['time_limit']         = (int) apply_filters( 'action_scheduler_queue_runner_time_limit', 30 );
			// The async runner's own default depends on the queue; a filter that turns it off returns false here.
			$runner['async_filtered_off'] = ! apply_filters( 'action_scheduler_allow_async_request_runner', true );
			// phpcs:enable
		}
		return $runner;
	}

	/**
	 * How long Action Scheduler keeps finished actions, as filtered on this site.
	 *
	 * @param string $version Action Scheduler version, or an empty string when it is not loaded.
	 * @return array
	 */
	public function retention( $version ) {
		$month    = 2678400; // Action Scheduler's "month": 31 days.
		$cleans   = '' === $version || version_compare( $version, '4.0.0', '>=' );
		$statuses = array( 'complete', 'canceled' );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reads Action Scheduler's own filters.
		$period          = apply_filters( 'action_scheduler_retention_period', $month );
		$failed_period   = apply_filters( 'action_scheduler_retention_period_for_failed', 3 * $month );
		$failed_cleanup  = $cleans && (bool) apply_filters( 'action_scheduler_enable_failed_action_cleanup', true );
		$filter_statuses = apply_filters( 'action_scheduler_default_cleaner_statuses', $statuses );
		// phpcs:enable

		if ( is_array( $filter_statuses ) ) {
			$statuses = array_values( array_map( 'strval', $filter_statuses ) );
		}
		if ( in_array( 'failed', $statuses, true ) ) {
			$failed_cleanup = true;
			$failed_period  = $period;
		}

		return array(
			'period'         => is_numeric( $period ) ? max( 0, (int) $period ) : $month,
			'failed_period'  => is_numeric( $failed_period ) ? max( 0, (int) $failed_period ) : 3 * $month,
			'failed_cleanup' => $failed_cleanup,
			'statuses'       => $statuses,
		);
	}

	/**
	 * Rows and bytes per table, from information_schema. These are the server's estimates.
	 *
	 * @param array<string, string> $tables Existing tables.
	 * @return array<string, array{rows: int, bytes: int, free: int}>
	 */
	private function storage( array $tables ) {
		global $wpdb;
		$names = array_values( $tables );
		$args  = array_merge( array( DB_NAME ), $names );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table metadata has no API; shown live.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS bytes, DATA_FREE AS free_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
				$args
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $tables as $key => $table ) {
			$out[ $key ] = array(
				'rows'  => 0,
				'bytes' => 0,
				'free'  => 0,
			);
			foreach ( (array) $rows as $row ) {
				if ( $row['name'] === $table ) {
					$out[ $key ] = array(
						'rows'  => (int) $row['row_count'],
						'bytes' => (int) $row['bytes'],
						'free'  => (int) $row['free_bytes'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Actions per status.
	 *
	 * @return array<string, int>
	 */
	private function counts() {
		global $wpdb;
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grouped count with no API equivalent; shown live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $tables['actions'] ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Pending actions whose scheduled time has passed, in total and by hook.
	 *
	 * @return array
	 */
	private function past_due() {
		global $wpdb;
		$tables = self::tables();
		$now    = gmdate( 'Y-m-d H:i:s', $this->now );
		$day    = gmdate( 'Y-m-d H:i:s', $this->now - DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uses the status_scheduled_date_gmt index; shown live.
		$total = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, MIN(scheduled_date_gmt) AS oldest, SUM(scheduled_date_gmt < %s) AS over_day FROM %i WHERE status = 'pending' AND scheduled_date_gmt < %s",
				$day,
				$tables['actions'],
				$now
			),
			ARRAY_A
		);
		$hooks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, COUNT(*) AS n, MIN(scheduled_date_gmt) AS oldest FROM %i WHERE status = 'pending' AND scheduled_date_gmt < %s GROUP BY hook ORDER BY n DESC, hook ASC LIMIT 10",
				$tables['actions'],
				$now
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array(
			'count'    => (int) ( isset( $total['n'] ) ? $total['n'] : 0 ),
			'over_day' => (int) ( isset( $total['over_day'] ) ? $total['over_day'] : 0 ),
			'oldest'   => self::timestamp( isset( $total['oldest'] ) ? $total['oldest'] : null ),
			'hooks'    => array(),
		);
		foreach ( (array) $hooks as $row ) {
			$out['hooks'][] = array(
				'hook'   => (string) $row['hook'],
				'count'  => (int) $row['n'],
				'oldest' => self::timestamp( $row['oldest'] ),
			);
		}
		return $out;
	}

	/**
	 * The newest failed actions with the log lines that explain them.
	 *
	 * @param int  $sample     Failed actions to read.
	 * @param bool $has_logs   Whether the logs table exists.
	 * @param bool $has_groups Whether the groups table exists.
	 * @return array
	 */
	private function failed( $sample, $has_logs, $has_groups ) {
		global $wpdb;
		$tables = self::tables();
		$week   = gmdate( 'Y-m-d H:i:s', $this->now - WEEK_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded reads on indexed columns; shown live.
		$recent = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed' AND last_attempt_gmt >= %s", $tables['actions'], $week )
		);
		if ( $has_groups ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.action_id, a.hook, a.scheduled_date_gmt, a.last_attempt_gmt, a.schedule, g.slug AS group_slug FROM %i AS a LEFT JOIN %i AS g ON g.group_id = a.group_id WHERE a.status = 'failed' ORDER BY a.scheduled_date_gmt DESC, a.action_id DESC LIMIT %d",
					$tables['actions'],
					$tables['groups'],
					$sample
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, hook, scheduled_date_gmt, last_attempt_gmt, schedule, '' AS group_slug FROM %i WHERE status = 'failed' ORDER BY scheduled_date_gmt DESC, action_id DESC LIMIT %d",
					$tables['actions'],
					$sample
				),
				ARRAY_A
			);
		}
		// phpcs:enable

		$actions = array();
		foreach ( (array) $rows as $row ) {
			$schedule  = (string) $row['schedule'];
			$actions[] = array(
				'id'        => (int) $row['action_id'],
				'hook'      => (string) $row['hook'],
				'group'     => (string) $row['group_slug'],
				'scheduled' => self::timestamp( $row['scheduled_date_gmt'] ),
				'attempted' => self::timestamp( $row['last_attempt_gmt'] ),
				// The schedule is a serialized object. It is never unserialized; its class name is enough.
				'recurring' => false !== strpos( $schedule, 'CronSchedule' ) || false !== strpos( $schedule, 'IntervalSchedule' ),
				'messages'  => array(),
			);
		}

		if ( $has_logs && $actions ) {
			$ids  = wp_list_pluck( $actions, 'id' );
			$args = array_merge( array( $tables['logs'] ), $ids, array( self::MAX_LOG_ROWS ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uses the action_id index; shown live.
			$logs  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT action_id, message FROM %i WHERE action_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY log_id ASC LIMIT %d',
					$args
				),
				ARRAY_A
			);
			$index = array_flip( $ids );
			foreach ( (array) $logs as $log ) {
				$id = (int) $log['action_id'];
				if ( isset( $index[ $id ] ) ) {
					$actions[ $index[ $id ] ]['messages'][] = (string) $log['message'];
				}
			}
		}

		return array(
			'recent'  => $recent,
			'sample'  => $sample,
			'actions' => $actions,
		);
	}

	/**
	 * Actions marked in progress, and how many have been running for more than five minutes.
	 *
	 * @return array
	 */
	private function running() {
		global $wpdb;
		$tables = self::tables();
		$cutoff = gmdate( 'Y-m-d H:i:s', $this->now - 300 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uses the status index; shown live.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MIN(last_attempt_gmt) AS oldest, SUM(last_attempt_gmt < %s) AS long_running FROM %i WHERE status = 'in-progress'", $cutoff, $tables['actions'] ), ARRAY_A );
		return array(
			'count'  => (int) ( isset( $row['n'] ) ? $row['n'] : 0 ),
			'long'   => (int) ( isset( $row['long_running'] ) ? $row['long_running'] : 0 ),
			'oldest' => self::timestamp( isset( $row['oldest'] ) ? $row['oldest'] : null ),
		);
	}

	/**
	 * Open claims. A runner claims a batch and deletes the claim when it is done; claims older than five
	 * minutes usually belong to a runner that was stopped part-way.
	 *
	 * @return array
	 */
	private function claims() {
		global $wpdb;
		$tables = self::tables();
		$cutoff = gmdate( 'Y-m-d H:i:s', $this->now - 300 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small table; shown live.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, MIN(date_created_gmt) AS oldest, SUM(date_created_gmt < %s) AS stale FROM %i', $cutoff, $tables['claims'] ), ARRAY_A );
		return array(
			'count'  => (int) ( isset( $row['n'] ) ? $row['n'] : 0 ),
			'stale'  => (int) ( isset( $row['stale'] ) ? $row['stale'] : 0 ),
			'oldest' => self::timestamp( isset( $row['oldest'] ) ? $row['oldest'] : null ),
		);
	}

	/**
	 * Evidence that something runs the queue: the newest log line and the newest completed action.
	 *
	 * @param bool $has_logs Whether the logs table exists.
	 * @return array
	 */
	private function activity( $has_logs ) {
		global $wpdb;
		$tables = self::tables();
		$out    = array(
			'last_log'      => null,
			'last_complete' => null,
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed MAX() and LIMIT 1 reads; shown live.
		if ( $has_logs ) {
			$out['last_log'] = self::timestamp( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(log_date_gmt) FROM %i', $tables['logs'] ) ) );
		}
		$out['last_complete'] = self::timestamp(
			$wpdb->get_var(
				$wpdb->prepare( "SELECT last_attempt_gmt FROM %i WHERE status = 'complete' ORDER BY scheduled_date_gmt DESC LIMIT 1", $tables['actions'] )
			)
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * The oldest completed, canceled and failed action by scheduled date, to see whether cleanup keeps up.
	 *
	 * @return array<string, int|null>
	 */
	private function oldest_finished() {
		global $wpdb;
		$tables = self::tables();
		$out    = array();
		foreach ( array( 'complete', 'canceled', 'failed' ) as $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed MIN(); shown live.
			$value          = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(scheduled_date_gmt) FROM %i WHERE status = %s', $tables['actions'], $status ) );
			$out[ $status ] = self::timestamp( $value );
		}
		return $out;
	}

	/**
	 * A GMT datetime from the database as a Unix timestamp, or null for empty and zero dates.
	 *
	 * @param string|null $value Datetime.
	 * @return int|null
	 */
	public static function timestamp( $value ) {
		if ( null === $value || '' === $value || 0 === strpos( (string) $value, '0000-00-00' ) ) {
			return null;
		}
		$time = strtotime( $value . ' UTC' );
		return false === $time ? null : $time;
	}

	/**
	 * A path relative to the WordPress folder, so reports do not show the server's folder layout.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function relative_path( $path ) {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $path, $root ) ) {
			return substr( $path, strlen( $root ) );
		}
		$content = wp_normalize_path( dirname( WP_CONTENT_DIR ) ) . '/';
		if ( 0 === strpos( $path, $content ) ) {
			return substr( $path, strlen( $content ) );
		}
		return basename( $path );
	}
}
