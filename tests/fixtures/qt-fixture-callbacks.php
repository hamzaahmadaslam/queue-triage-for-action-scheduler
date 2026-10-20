<?php
/**
 * Test fixture, copied into mu-plugins by tests/integration.sh. Never ship this file.
 *
 * Registers callbacks for the fixture hooks. Each one fails the way a real integration fails, so the
 * failure groups come from Action Scheduler's own logging.
 *
 * @package QueueTriage
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'acme_crm_sync_contact',
	function ( $contact_id ) {
		throw new Exception( sprintf( 'cURL error 28: Operation timed out after %d milliseconds with 0 bytes received for https://api.crm.example/v2/contacts/%d?email=jane%%40example.com', 10000 + (int) $contact_id, (int) $contact_id ) );
	}
);

add_action(
	'acme_newsletter_subscribe',
	function ( $list_id ) {
		throw new Exception( sprintf( 'HTTP 429 Too Many Requests: rate limit exceeded for list %d', (int) $list_id ) );
	}
);

add_action(
	'acme_send_receipt_email',
	function ( $order_id ) {
		throw new Exception( sprintf( 'SMTP Error: Could not authenticate. Receipt for order #%d to buyer%d@example.com not sent.', (int) $order_id, (int) $order_id ) );
	}
);

add_action(
	'acme_capture_payment',
	function ( $order_id ) {
		throw new Exception( sprintf( 'cURL error 28: Connection timed out after 30001 milliseconds while capturing order %d', (int) $order_id ) );
	}
);

add_action(
	'acme_stock_export',
	function ( $product_id ) {
		$product = get_post( (int) $product_id + 900000 );
		acme_fixture_export_row( $product );
	}
);

/**
 * Typed like a plugin function that expects a post, so a missing post raises a TypeError.
 *
 * @param WP_Post $post Post.
 */
function acme_fixture_export_row( WP_Post $post ) {
	return $post->ID;
}

add_action(
	'acme_import_customer_row',
	function ( $row ) {
		throw new Exception( sprintf( "Invalid email 'not an address %d' in row %d", (int) $row, (int) $row ) );
	}
);

add_action(
	'acme_refresh_order_meta',
	function ( $order_id ) {
		throw new Exception( sprintf( 'Order #%d not found', (int) $order_id ) );
	}
);

add_action(
	'acme_partner_feed',
	function () {
		throw new Exception( 'Unexpected response from the partner feed' );
	}
);

add_action(
	'acme_warm_cache',
	function () {
		// Completes.
	}
);

add_action(
	'acme_backfill_reviews',
	function () {
		// Completes when the queue reaches it.
	}
);

// Keep admin page loads from starting the queue while tests and screenshots read it.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
