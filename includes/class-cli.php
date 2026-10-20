<?php
/**
 * WP-CLI command.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the Queue Triage report. Read-only.
 */
class CLI {

	/**
	 * Prints the report: checks, failure groups and the support text.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : groups prints the failure groups as a table, text prints the support report, json prints everything.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - groups
	 *   - json
	 * ---
	 *
	 * [--sample=<number>]
	 * : How many of the newest failed actions to read.
	 * ---
	 * default: 500
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp queue-triage report
	 *     wp queue-triage report --format=groups
	 *     wp queue-triage report --format=json --sample=2000
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'text';
		$sample = isset( $assoc_args['sample'] ) ? absint( $assoc_args['sample'] ) : Reader::DEFAULT_SAMPLE;
		$report = Plugin::report( $sample, true );

		if ( 'json' === $format ) {
			$out = $report;
			// Log messages hold raw text; the groups carry the cleaned version.
			foreach ( $out['data']['failed']['actions'] as $i => $action ) {
				unset( $out['data']['failed']['actions'][ $i ]['messages'] );
			}
			\WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'groups' === $format ) {
			$rows = array();
			foreach ( $report['groups'] as $group ) {
				$rows[] = array(
					'label'     => $group['label'],
					'count'     => $group['count'],
					'hook'      => $group['hook'],
					'cause'     => $group['cause'],
					'signature' => '' === $group['signature'] ? '(no failure message)' : $group['signature'],
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'label', 'count', 'hook', 'cause', 'signature' ) );
			return;
		}

		\WP_CLI::line( Report::plain_text( $report, 50 ) );
	}
}
