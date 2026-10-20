<?php
/**
 * Wires the plugin together.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the screen, the Site Health tests and the WP-CLI command, and builds the report once per request.
 */
class Plugin {

	/**
	 * The report built during this request.
	 *
	 * @var array|null
	 */
	private static $report = null;

	/**
	 * Hooks everything in.
	 */
	public static function init() {
		if ( is_admin() ) {
			Admin_Page::register();
		}
		Site_Health::register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'queue-triage', CLI::class );
		}
	}

	/**
	 * The report, built on first use and reused for the rest of the request.
	 *
	 * @param int|null $sample Failed actions to read; null uses the filtered default.
	 * @param bool     $fresh  Build it again even if it was built already.
	 * @return array
	 */
	public static function report( $sample = null, $fresh = false ) {
		if ( null === self::$report || $fresh ) {
			if ( null === $sample ) {
				/**
				 * Filters how many of the newest failed actions are read for the failure groups.
				 *
				 * @param int $sample Default 500, at most 5000.
				 */
				$sample = (int) apply_filters( 'queue_triage_failed_sample', Reader::DEFAULT_SAMPLE );
			}
			$reader       = new Reader();
			self::$report = Report::build( $reader->collect( $sample ) );
		}
		return self::$report;
	}

	/**
	 * Whether Action Scheduler's actions table exists.
	 *
	 * @return bool
	 */
	public static function has_tables() {
		$reader = new Reader();
		$tables = $reader->existing_tables();
		return isset( $tables['actions'] );
	}
}
