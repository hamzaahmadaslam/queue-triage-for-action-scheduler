<?php
/**
 * The Tools > Queue Triage screen.
 *
 * @package QueueTriage
 */

namespace QueueTriage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the read-only screen. It has no forms and handles no input.
 */
class Admin_Page {

	const SLUG = 'queue-triage';

	/**
	 * The screen's hook suffix.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hooks the screen in.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( QUEUE_TRIAGE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * The capability needed to see the screen.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability needed to see Queue Triage's screen, Site Health tests and report.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'queue_triage_capability', 'manage_options' );
	}

	/**
	 * Adds the page under Tools.
	 */
	public static function add_page() {
		$suffix            = add_management_page(
			__( 'Queue Triage for Action Scheduler', 'queue-triage-for-action-scheduler' ),
			__( 'Queue Triage', 'queue-triage-for-action-scheduler' ),
			self::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		self::$hook_suffix = $suffix ? $suffix : '';
	}

	/**
	 * The screen's URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Adds a link to the screen on the Plugins list.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		if ( current_user_can( self::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open report', 'queue-triage-for-action-scheduler' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Loads the stylesheet and the copy button's script on this screen only.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( '' === self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'queue-triage-admin', plugins_url( 'assets/admin.css', QUEUE_TRIAGE_FILE ), array(), QUEUE_TRIAGE_VERSION );
		wp_enqueue_script( 'queue-triage-admin', plugins_url( 'assets/admin.js', QUEUE_TRIAGE_FILE ), array(), QUEUE_TRIAGE_VERSION, true );
		wp_localize_script(
			'queue-triage-admin',
			'queueTriage',
			array(
				'copied' => __( 'Copied', 'queue-triage-for-action-scheduler' ),
				'failed' => __( 'Select the text and copy it', 'queue-triage-for-action-scheduler' ),
			)
		);
	}

	/**
	 * Link to Action Scheduler's own screen, filtered by status and hook.
	 *
	 * @param string $status Status.
	 * @param string $hook   Hook to search for.
	 * @return string
	 */
	public static function scheduled_actions_url( $status = '', $hook = '' ) {
		$args = array( 'page' => 'action-scheduler' );
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		if ( '' !== $hook ) {
			$args['s'] = $hook;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Renders the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'queue-triage-for-action-scheduler' ) );
		}

		$report = Plugin::report();
		$data   = $report['data'];
		$env    = $data['environment'];
		$now    = (int) $data['now'];

		echo '<div class="wrap queue-triage">';
		echo '<h1>' . esc_html__( 'Queue Triage for Action Scheduler', 'queue-triage-for-action-scheduler' ) . '</h1>';
		echo '<p class="queue-triage-intro">';
		esc_html_e( 'A read-only report on the Action Scheduler queue. Nothing on this page runs, retries, cancels or deletes an action.', 'queue-triage-for-action-scheduler' );
		if ( $env['loaded'] ) {
			echo ' <a href="' . esc_url( self::scheduled_actions_url() ) . '">' . esc_html__( 'Open Scheduled Actions', 'queue-triage-for-action-scheduler' ) . '</a>';
		}
		echo '</p>';

		self::render_checks( $report['checks'] );

		if ( ! $data['has_tables'] ) {
			self::render_environment( $data );
			echo '</div>';
			return;
		}

		self::render_failures( $report );
		self::render_past_due( $data );
		self::render_queue( $data );
		self::render_storage( $data );
		self::render_environment( $data );
		self::render_support( $report );

		echo '<p class="description">';
		printf(
			/* translators: %s: date and time. */
			esc_html__( 'Read at %s. Reload the page to read again.', 'queue-triage-for-action-scheduler' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now ) )
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * The status name shown on a check.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_name( $status ) {
		if ( Report::CRITICAL === $status ) {
			return __( 'Critical', 'queue-triage-for-action-scheduler' );
		}
		if ( Report::RECOMMENDED === $status ) {
			return __( 'Recommended', 'queue-triage-for-action-scheduler' );
		}
		return __( 'Good', 'queue-triage-for-action-scheduler' );
	}

	/**
	 * The checks at the top.
	 *
	 * @param array $checks Checks.
	 */
	private static function render_checks( array $checks ) {
		echo '<ul class="queue-triage-checks">';
		foreach ( $checks as $id => $check ) {
			echo '<li class="queue-triage-check is-' . esc_attr( $check['status'] ) . '" data-check="' . esc_attr( $id ) . '">';
			echo '<span class="queue-triage-badge">' . esc_html( self::status_name( $check['status'] ) ) . '</span> ';
			echo '<strong>' . esc_html( $check['label'] ) . '</strong>';
			echo '<p>' . esc_html( $check['detail'] ) . '</p>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Failed actions grouped by hook and error.
	 *
	 * @param array $report Report.
	 */
	private static function render_failures( array $report ) {
		$data   = $report['data'];
		$groups = $report['groups'];
		$now    = (int) $data['now'];
		$total  = isset( $data['counts']['failed'] ) ? (int) $data['counts']['failed'] : 0;
		$read   = count( $data['failed']['actions'] );

		echo '<h2 id="queue-triage-failures">' . esc_html__( 'Failed actions by hook and error', 'queue-triage-for-action-scheduler' ) . '</h2>';
		if ( 0 === $read ) {
			echo '<p>' . esc_html__( 'There are no failed actions.', 'queue-triage-for-action-scheduler' ) . '</p>';
			return;
		}

		echo '<p>';
		printf(
			/* translators: 1: number of actions read, 2: number of failed actions, 3: number of groups. */
			esc_html__( 'The newest %1$s of %2$s failed actions, grouped by hook and by their error with ids, dates, amounts and other values taken out: %3$s groups.', 'queue-triage-for-action-scheduler' ),
			esc_html( Format::number( $read ) ),
			esc_html( Format::number( $total ) ),
			esc_html( Format::number( count( $groups ) ) )
		);
		echo ' ';
		esc_html_e( 'The labels come from fixed rules that read the error and the hook name. They cannot tell whether a rerun is safe, so read each group before you act on it.', 'queue-triage-for-action-scheduler' );
		echo '</p>';

		$by_label = array(
			Triage::FIX    => 0,
			Triage::RETRY  => 0,
			Triage::REVIEW => 0,
		);
		foreach ( $groups as $group ) {
			$by_label[ $group['label'] ] += $group['count'];
		}
		echo '<ul class="queue-triage-labels">';
		foreach ( $by_label as $label => $count ) {
			echo '<li><span class="queue-triage-label is-' . esc_attr( $label ) . '">' . esc_html( Report::label_name( $label ) ) . '</span> ' . esc_html( Format::number( $count ) ) . '</li>';
		}
		echo '</ul>';

		echo '<table class="widefat striped queue-triage-groups"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Label', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Hook', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Failures', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Error and what to check', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Latest', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $groups, 0, 100 ) as $group ) {
			echo '<tr>';
			echo '<td><span class="queue-triage-label is-' . esc_attr( $group['label'] ) . '">' . esc_html( Report::label_name( $group['label'] ) ) . '</span></td>';
			echo '<td><code>' . esc_html( $group['hook'] ) . '</code>';
			if ( $group['groups'] ) {
				echo '<br><span class="description">' . esc_html( implode( ', ', $group['groups'] ) ) . '</span>';
			}
			if ( $data['environment']['loaded'] ) {
				echo '<br><a href="' . esc_url( self::scheduled_actions_url( 'failed', $group['hook'] ) ) . '">' . esc_html__( 'View these actions', 'queue-triage-for-action-scheduler' ) . '</a>';
			}
			echo '</td>';
			echo '<td class="num">' . esc_html( Format::number( $group['count'] ) );
			if ( $group['recurring'] > 0 ) {
				echo '<br><span class="description">' . esc_html(
					sprintf(
						/* translators: %s: number of actions. */
						__( '%s recurring', 'queue-triage-for-action-scheduler' ),
						Format::number( $group['recurring'] )
					)
				) . '</span>';
			}
			echo '</td>';
			echo '<td>';
			if ( '' === $group['example'] ) {
				echo '<em>' . esc_html__( 'No failure message in the log.', 'queue-triage-for-action-scheduler' ) . '</em>';
			} else {
				echo '<code class="queue-triage-error">' . esc_html( $group['example'] ) . '</code>';
			}
			echo '<p>' . esc_html( Report::cause_text( $group['cause'] ) ) . '</p>';
			foreach ( Report::group_notes( $group ) as $note ) {
				echo '<p class="queue-triage-note">' . esc_html( $note ) . '</p>';
			}
			echo '</td>';
			echo '<td>' . ( null === $group['latest'] ? '&ndash;' : esc_html(
				sprintf(
					/* translators: %s: duration, such as "3 hours". */
					__( '%s ago', 'queue-triage-for-action-scheduler' ),
					Format::duration( $now - $group['latest'] )
				)
			) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( count( $groups ) > 100 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: number of groups. */
					__( '%s smaller groups are not shown.', 'queue-triage-for-action-scheduler' ),
					Format::number( count( $groups ) - 100 )
				)
			) . '</p>';
		}
	}

	/**
	 * Past-due actions by hook.
	 *
	 * @param array $data Collected data.
	 */
	private static function render_past_due( array $data ) {
		$past_due = $data['past_due'];
		$now      = (int) $data['now'];
		echo '<h2>' . esc_html__( 'Past-due actions by hook', 'queue-triage-for-action-scheduler' ) . '</h2>';
		if ( 0 === $past_due['count'] ) {
			echo '<p>' . esc_html__( 'No pending action is past its scheduled time.', 'queue-triage-for-action-scheduler' ) . '</p>';
			return;
		}
		echo '<p>';
		printf(
			/* translators: 1: number of actions, 2: number of actions. */
			esc_html__( '%1$s pending actions are past their scheduled time; %2$s of them by more than a day. The ten hooks with the most:', 'queue-triage-for-action-scheduler' ),
			esc_html( Format::number( $past_due['count'] ) ),
			esc_html( Format::number( $past_due['over_day'] ) )
		);
		echo '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Hook', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Past due', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Oldest is late by', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $past_due['hooks'] as $row ) {
			echo '<tr><td><code>' . esc_html( $row['hook'] ) . '</code>';
			if ( $data['environment']['loaded'] ) {
				echo ' <a href="' . esc_url( self::scheduled_actions_url( 'past-due', $row['hook'] ) ) . '">' . esc_html__( 'View', 'queue-triage-for-action-scheduler' ) . '</a>';
			}
			echo '</td><td class="num">' . esc_html( Format::number( $row['count'] ) ) . '</td>';
			echo '<td>' . ( null === $row['oldest'] ? '&ndash;' : esc_html( Format::duration( $now - $row['oldest'] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Counts by status and the runner.
	 *
	 * @param array $data Collected data.
	 */
	private static function render_queue( array $data ) {
		$now    = (int) $data['now'];
		$runner = $data['runner'];
		$last   = max( (int) $data['activity']['last_log'], (int) $data['activity']['last_complete'] );

		echo '<h2>' . esc_html__( 'Queue and runner', 'queue-triage-for-action-scheduler' ) . '</h2>';
		echo '<div class="queue-triage-columns"><div>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Status', 'queue-triage-for-action-scheduler' ) . '</th><th scope="col" class="num">' . esc_html__( 'Actions', 'queue-triage-for-action-scheduler' ) . '</th></tr></thead><tbody>';
		foreach ( $data['counts'] as $status => $count ) {
			echo '<tr><td>';
			if ( $data['environment']['loaded'] ) {
				echo '<a href="' . esc_url( self::scheduled_actions_url( $status ) ) . '">' . esc_html( $status ) . '</a>';
			} else {
				echo esc_html( $status );
			}
			echo '</td><td class="num">' . esc_html( Format::number( $count ) ) . '</td></tr>';
		}
		echo '<tr><td>' . esc_html__( 'in progress for more than 5 minutes', 'queue-triage-for-action-scheduler' ) . '</td><td class="num">' . esc_html( Format::number( $data['running']['long'] ) ) . '</td></tr>';
		if ( $data['claims'] ) {
			echo '<tr><td>' . esc_html__( 'open claims (older than 5 minutes)', 'queue-triage-for-action-scheduler' ) . '</td><td class="num">' . esc_html( Format::number( $data['claims']['count'] ) . ' (' . Format::number( $data['claims']['stale'] ) . ')' ) . '</td></tr>';
		}
		echo '</tbody></table></div><div>';

		$rows = array(
			__( 'WP-Cron', 'queue-triage-for-action-scheduler' ) => $runner['wp_cron_disabled']
				? __( 'Disabled by DISABLE_WP_CRON. A server cron job or WP-CLI has to run it.', 'queue-triage-for-action-scheduler' )
				: ( $runner['alternate_wp_cron'] ? __( 'Runs on page visits (ALTERNATE_WP_CRON is set).', 'queue-triage-for-action-scheduler' ) : __( 'Runs on page visits.', 'queue-triage-for-action-scheduler' ) ),
			__( 'Next queue run', 'queue-triage-for-action-scheduler' ) => null === $runner['next_run']
				? __( 'Not scheduled', 'queue-triage-for-action-scheduler' )
				: ( $runner['next_run'] < $now
					/* translators: %s: duration. */
					? sprintf( __( 'Due %s ago', 'queue-triage-for-action-scheduler' ), Format::duration( $now - $runner['next_run'] ) )
					/* translators: %s: duration. */
					: sprintf( __( 'In %s', 'queue-triage-for-action-scheduler' ), Format::duration( $runner['next_run'] - $now ) ) ),
			__( 'Last activity', 'queue-triage-for-action-scheduler' ) => $last > 0
				/* translators: %s: duration. */
				? sprintf( __( '%s ago (newest log entry or completed action)', 'queue-triage-for-action-scheduler' ), Format::duration( $now - $last ) )
				: __( 'None recorded', 'queue-triage-for-action-scheduler' ),
		);
		if ( null !== $runner['batch_size'] ) {
			$rows[ __( 'Runner limits', 'queue-triage-for-action-scheduler' ) ] = sprintf(
				/* translators: 1: number of actions, 2: number of batches, 3: seconds. */
				__( '%1$s actions per batch, %2$s concurrent batches, %3$s seconds per run', 'queue-triage-for-action-scheduler' ),
				Format::number( $runner['batch_size'] ),
				Format::number( $runner['concurrent_batches'] ),
				Format::number( $runner['time_limit'] )
			);
			$rows[ __( 'Async runner', 'queue-triage-for-action-scheduler' ) ] = $runner['async_filtered_off']
				? __( 'Turned off by a filter', 'queue-triage-for-action-scheduler' )
				: __( 'Allowed: admin page loads can start the queue', 'queue-triage-for-action-scheduler' );
		}
		self::render_rows( $rows );
		echo '</div></div>';
	}

	/**
	 * Table sizes and retention.
	 *
	 * @param array $data Collected data.
	 */
	private static function render_storage( array $data ) {
		$now       = (int) $data['now'];
		$retention = $data['retention'];
		echo '<h2>' . esc_html__( 'Storage and retention', 'queue-triage-for-action-scheduler' ) . '</h2>';
		echo '<div class="queue-triage-columns"><div>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Table', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Rows (estimate)', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Size', 'queue-triage-for-action-scheduler' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $data['storage'] as $key => $table ) {
			echo '<tr><td><code>' . esc_html( $data['tables'][ $key ] ) . '</code></td>';
			echo '<td class="num">' . esc_html( Format::number( $table['rows'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( Format::bytes( $table['bytes'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Row counts and sizes are the database server\'s estimates and can lag behind.', 'queue-triage-for-action-scheduler' ) . '</p>';
		echo '</div><div>';

		$oldest = function ( $time ) use ( $now ) {
			return null === $time ? __( 'none', 'queue-triage-for-action-scheduler' ) : Format::duration( $now - $time );
		};
		$rows   = array(
			__( 'Kept after finishing', 'queue-triage-for-action-scheduler' ) => sprintf(
				/* translators: 1: list of statuses, 2: duration. */
				__( '%1$s: %2$s', 'queue-triage-for-action-scheduler' ),
				implode( ', ', $retention['statuses'] ),
				Format::duration( $retention['period'] )
			),
			__( 'Failed actions kept', 'queue-triage-for-action-scheduler' ) => $retention['failed_cleanup']
				? Format::duration( $retention['failed_period'] )
				: __( 'Until deleted by hand (Action Scheduler before 4.0, or cleanup turned off)', 'queue-triage-for-action-scheduler' ),
			__( 'Oldest completed action', 'queue-triage-for-action-scheduler' ) => $oldest( $data['oldest']['complete'] ),
			__( 'Oldest canceled action', 'queue-triage-for-action-scheduler' ) => $oldest( $data['oldest']['canceled'] ),
			__( 'Oldest failed action', 'queue-triage-for-action-scheduler' ) => $oldest( $data['oldest']['failed'] ),
		);
		self::render_rows( $rows );
		echo '</div></div>';
	}

	/**
	 * Action Scheduler version and the plugins that bundle it.
	 *
	 * @param array $data Collected data.
	 */
	private static function render_environment( array $data ) {
		$env = $data['environment'];
		echo '<h2>' . esc_html__( 'Action Scheduler on this site', 'queue-triage-for-action-scheduler' ) . '</h2>';
		if ( ! $env['loaded'] ) {
			echo '<p>' . esc_html__( 'No active plugin or theme loads Action Scheduler.', 'queue-triage-for-action-scheduler' ) . '</p>';
			return;
		}
		$rows = array(
			__( 'Version in use', 'queue-triage-for-action-scheduler' ) => $env['version'] . ( '' !== $env['source'] ? ' (' . $env['source'] . ')' : '' ),
			__( 'Data store', 'queue-triage-for-action-scheduler' )     => $env['store'],
		);
		if ( $env['sources'] ) {
			$copies = array();
			foreach ( $env['sources'] as $source ) {
				$copies[] = $source['version'] . ' ' . $source['path'];
			}
			$rows[ __( 'Copies bundled by plugins', 'queue-triage-for-action-scheduler' ) ] = implode( "\n", $copies );
		}
		self::render_rows( $rows );
		if ( ! $env['custom_tables'] ) {
			echo '<p>' . esc_html__( 'This site does not use Action Scheduler\'s own tables as its data store, so the figures above may be incomplete.', 'queue-triage-for-action-scheduler' ) . '</p>';
		}
	}

	/**
	 * The copyable support report.
	 *
	 * @param array $report Report.
	 */
	private static function render_support( array $report ) {
		echo '<h2>' . esc_html__( 'Report for a support ticket', 'queue-triage-for-action-scheduler' ) . '</h2>';
		echo '<p>' . esc_html__( 'Counts, settings, hook names and error signatures, with ids, values, email addresses, IP addresses and server paths left out. Read it before you share it.', 'queue-triage-for-action-scheduler' ) . '</p>';
		echo '<textarea id="queue-triage-report" class="large-text code" rows="14" readonly>' . esc_textarea( Report::plain_text( $report ) ) . '</textarea>';
		echo '<p><button type="button" class="button" id="queue-triage-copy" data-target="queue-triage-report">' . esc_html__( 'Copy report', 'queue-triage-for-action-scheduler' ) . '</button> <span id="queue-triage-copy-status" aria-live="polite"></span></p>';
	}

	/**
	 * A two-column table of labels and values.
	 *
	 * @param array<string, string> $rows Label to value.
	 */
	private static function render_rows( array $rows ) {
		echo '<table class="widefat striped queue-triage-rows"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
