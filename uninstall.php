<?php
/**
 * Runs when the plugin is deleted.
 *
 * Queue Triage stores no options, transients, tables or files, and it never changes Action Scheduler's
 * data, so there is nothing to remove. Action Scheduler's own tables belong to the plugins that use them
 * and are left alone.
 *
 * @package QueueTriage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
