<?php
/**
 * Test fixture: schedules a backlog of fixture actions. Run with `wp eval-file`, never on a real site.
 *
 * Usage: wp eval-file tests/fixtures/backlog.php schedule
 *        wp action-scheduler run --hooks=<fixture hooks>
 *        wp eval-file tests/fixtures/backlog.php age
 *
 * @package QueueTriage
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$qt_step    = isset( $args[0] ) ? $args[0] : 'schedule';
$qt_actions = $wpdb->prefix . 'actionscheduler_actions';
$qt_logs    = $wpdb->prefix . 'actionscheduler_logs';
$qt_claims  = $wpdb->prefix . 'actionscheduler_claims';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture.

if ( 'schedule' === $qt_step ) {
	// Start from a clean slate of fixture actions.
	$qt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM %i WHERE hook LIKE %s OR hook = 'legacy_sync_cleanup'", $qt_actions, $wpdb->esc_like( 'acme_' ) . '%' ) );
	foreach ( array_chunk( $qt_ids, 500 ) as $qt_chunk ) {
		$qt_in = implode( ',', array_map( 'intval', $qt_chunk ) );
		$wpdb->query( "DELETE FROM {$qt_logs} WHERE action_id IN ({$qt_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$qt_actions} WHERE action_id IN ({$qt_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $qt_claims ) );

	$qt_plan = array(
		'acme_crm_sync_contact'     => array( 40, 'acme-crm' ),
		'acme_newsletter_subscribe' => array( 12, 'acme-newsletter' ),
		'acme_send_receipt_email'   => array( 8, 'acme-mail' ),
		'acme_capture_payment'      => array( 5, 'acme-payments' ),
		'acme_stock_export'         => array( 20, 'acme-stock' ),
		'acme_import_customer_row'  => array( 9, 'acme-import' ),
		'acme_refresh_order_meta'   => array( 4, '' ),
		'legacy_sync_cleanup'       => array( 6, 'legacy' ),
		'acme_warm_cache'           => array( 60, 'acme-cache' ),
	);
	foreach ( $qt_plan as $qt_hook => $qt_row ) {
		for ( $i = 1; $i <= $qt_row[0]; $i++ ) {
			as_schedule_single_action( time() - 120 - $i, $qt_hook, array( 1000 + $i ), $qt_row[1] );
		}
	}
	as_schedule_recurring_action( time() - 3600, HOUR_IN_SECONDS, 'acme_partner_feed', array(), 'acme-feed' );

	// Past-due work that the queue has not reached yet, up to three days late.
	for ( $i = 1; $i <= 150; $i++ ) {
		as_schedule_single_action( time() - $i * 1700, 'acme_backfill_reviews', array( $i ), 'acme-reviews' );
	}
	for ( $i = 1; $i <= 30; $i++ ) {
		as_schedule_single_action( time() + $i * 3600, 'acme_warm_cache', array( 5000 + $i ), 'acme-cache' );
	}
	echo "scheduled\n";
}

if ( 'age' === $qt_step ) {
	// Spread the failures over the last three days, as on a real store.
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET last_attempt_gmt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL (action_id MOD 4320) MINUTE) WHERE status = 'failed' AND hook LIKE %s", $qt_actions, $wpdb->esc_like( 'acme_' ) . '%' ) );

	// One action left in progress 20 minutes ago by a runner that died, and its claim.
	$qt_claim_time = gmdate( 'Y-m-d H:i:s', time() - 1200 );
	$wpdb->insert( $qt_claims, array( 'date_created_gmt' => $qt_claim_time ) );
	$qt_claim = (int) $wpdb->insert_id;
	$qt_id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT action_id FROM %i WHERE hook = 'acme_backfill_reviews' AND status = 'pending' ORDER BY action_id ASC LIMIT 1", $qt_actions ) );
	$wpdb->update(
		$qt_actions,
		array(
			'status'             => 'in-progress',
			'claim_id'           => $qt_claim,
			'last_attempt_gmt'   => $qt_claim_time,
			'last_attempt_local' => $qt_claim_time,
		),
		array( 'action_id' => $qt_id )
	);
	echo "aged\n";
}
