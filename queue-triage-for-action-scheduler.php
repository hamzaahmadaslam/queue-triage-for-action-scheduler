<?php
/**
 * Plugin Name:       Queue Triage for Action Scheduler
 * Plugin URI:        https://github.com/hamzaahmadaslam/queue-triage-for-action-scheduler
 * Description:       A read-only report on the Action Scheduler queue: failed actions grouped by hook and error, past-due actions, stuck runs, table sizes and retention, with Site Health tests.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Hamza Ahmad Aslam
 * Author URI:        https://hamzaahmadaslam.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       queue-triage-for-action-scheduler
 *
 * @package QueueTriage
 */

defined( 'ABSPATH' ) || exit;

define( 'QUEUE_TRIAGE_VERSION', '1.0.0' );
define( 'QUEUE_TRIAGE_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-format.php';
require_once __DIR__ . '/includes/class-signature.php';
require_once __DIR__ . '/includes/class-triage.php';
require_once __DIR__ . '/includes/class-reader.php';
require_once __DIR__ . '/includes/class-report.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-site-health.php';
require_once __DIR__ . '/includes/class-cli.php';
require_once __DIR__ . '/includes/class-plugin.php';

add_action( 'plugins_loaded', array( 'QueueTriage\\Plugin', 'init' ) );
