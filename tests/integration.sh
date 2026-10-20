#!/usr/bin/env bash
# Integration test on a real WordPress install with Action Scheduler (from WooCommerce or the Action Scheduler
# plugin). Needs PHP with mysqli, WP-CLI, python3, and WP_PATH pointing at a WordPress install used only for
# testing: the test adds a must-use plugin and a backlog of fixture actions to it.
#   WP_PATH=/path/to/wordpress tests/integration.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
slug="queue-triage-for-action-scheduler"
: "${WP_PATH:?Set WP_PATH to a WordPress install}"
extra=()
if [ "$(id -u)" = "0" ]; then extra+=(--allow-root); fi
wpc() { wp --path="$WP_PATH" "${extra[@]}" "$@"; }

pass=0
fail=0
check() {
  if [ "$2" = "$3" ]; then
    pass=$((pass + 1)); echo "ok - $1"
  else
    fail=$((fail + 1)); echo "not ok - $1"; echo "  expected: $2"; echo "  actual:   $3"
  fi
}
contains() {
  if printf '%s' "$3" | grep -qF -- "$2"; then
    pass=$((pass + 1)); echo "ok - $1"
  else
    fail=$((fail + 1)); echo "not ok - $1"; echo "  expected to contain: $2"
  fi
}
lacks() {
  if printf '%s' "$3" | grep -qiF -- "$2"; then
    fail=$((fail + 1)); echo "not ok - $1"; echo "  should not contain: $2"
  else
    pass=$((pass + 1)); echo "ok - $1"
  fi
}

bash "$root/bin/build.sh" >/dev/null
plugins="$(wpc eval 'echo WP_PLUGIN_DIR;')"
mu="$(wpc eval 'echo WPMU_PLUGIN_DIR;')"
target="$plugins/$slug"
if [ -L "$target" ]; then rm "$target"; fi
rm -rf "$target"
cp -R "$root/build/$slug" "$target"
mkdir -p "$mu"
cp "$here/fixtures/qt-fixture-callbacks.php" "$mu/qt-fixture-callbacks.php"
cleanup() { rm -f "$mu/qt-fixture-callbacks.php"; }
trap cleanup EXIT

wpc plugin activate "$slug" >/dev/null
check "Action Scheduler is loaded" "1" "$(wpc eval 'echo (int) class_exists("ActionScheduler");')"

# Build the backlog with Action Scheduler's own runner, so the failures are logged the real way.
wpc eval-file "$here/fixtures/backlog.php" schedule >/dev/null
hooks="acme_crm_sync_contact,acme_newsletter_subscribe,acme_send_receipt_email,acme_capture_payment,acme_stock_export,acme_import_customer_row,acme_refresh_order_meta,legacy_sync_cleanup,acme_warm_cache,acme_partner_feed"
wpc action-scheduler run --hooks="$hooks" --batch-size=200 --batches=0 >/dev/null 2>&1 || true
wpc eval-file "$here/fixtures/backlog.php" age >/dev/null

checksum() { wpc db query "CHECKSUM TABLE $(wpc db prefix)actionscheduler_actions, $(wpc db prefix)actionscheduler_logs, $(wpc db prefix)actionscheduler_claims, $(wpc db prefix)actionscheduler_groups" --skip-column-names; }
before="$(checksum)"

json="$(wpc queue-triage report --format=json)"
q() { printf '%s' "$json" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $out = eval("return " . $argv[1] . ";"); echo is_bool($out) ? ($out ? "true" : "false") : (is_array($out) ? implode(",", $out) : $out);' "$1"; }
group() { q "implode(\"|\", array_map(function (\$g) { \$v = \$g[\"$2\"]; return is_bool(\$v) ? (\$v ? \"true\" : \"false\") : \$v; }, array_values(array_filter(\$r[\"groups\"], function (\$g) { return \$g[\"hook\"] === \"$1\"; }))))"; }

check "timeouts to a CRM form one group" "40" "$(group acme_crm_sync_contact count)"
check "a timeout is a retry candidate" "retry" "$(group acme_crm_sync_contact label)"
check "its cause is the network" "network" "$(group acme_crm_sync_contact cause)"
contains "the cURL error code is kept" "cURL error 28" "$(group acme_crm_sync_contact signature)"
lacks "the email in the URL query is removed" "jane" "$(group acme_crm_sync_contact example)"
check "rate limits form one group" "12|rate-limit|retry" "$(group acme_newsletter_subscribe count)|$(group acme_newsletter_subscribe cause)|$(group acme_newsletter_subscribe label)"
check "SMTP authentication is fix first" "auth|fix" "$(group acme_send_receipt_email cause)|$(group acme_send_receipt_email label)"
lacks "email addresses are removed" "@example.com" "$(group acme_send_receipt_email example)"
check "a payment timeout is never a retry candidate" "network|review|true" "$(group acme_capture_payment cause)|$(group acme_capture_payment label)|$(group acme_capture_payment side_effects)"
check "a TypeError is fix first" "20|code-error|fix" "$(group acme_stock_export count)|$(group acme_stock_export cause)|$(group acme_stock_export label)"
contains "paths are relative to WordPress" "wp-content/mu-plugins/qt-fixture-callbacks.php" "$(group acme_stock_export example)"
lacks "no server folders" "$WP_PATH" "$(group acme_stock_export example)"
check "a hook without callbacks is fix first" "6|missing-callback|fix" "$(group legacy_sync_cleanup count)|$(group legacy_sync_cleanup cause)|$(group legacy_sync_cleanup label)"
check "rows with different values share a group" "9|invalid-data" "$(group acme_import_customer_row count)|$(group acme_import_customer_row cause)"
check "a missing order is fix first" "missing-data|Order #<id> not found" "$(group acme_refresh_order_meta cause)|$(group acme_refresh_order_meta signature)"
check "an unknown message is review" "unknown|review" "$(group acme_partner_feed cause)|$(group acme_partner_feed label)"
check "completed actions are not failures" "" "$(group acme_warm_cache count)"
check "the backfill leads the past-due hooks" "acme_backfill_reviews" "$(q '$r["data"]["past_due"]["hooks"][0]["hook"]')"
check "149 backfill actions are still pending" "149" "$(q '$r["data"]["past_due"]["hooks"][0]["count"]')"
check "the queue check is critical" "critical" "$(q '$r["checks"]["queue"]["status"]')"
check "an action in progress for 20 minutes is found" "1" "$(q '$r["data"]["running"]["long"]')"
check "the stale claim is found" "1" "$(q '$r["data"]["claims"]["stale"]')"
check "the stuck check asks for a look" "recommended" "$(q '$r["checks"]["stuck"]["status"]')"
check "the failure check asks for a look" "recommended" "$(q '$r["checks"]["failures"]["status"]')"
check "storage is measured" "true" "$(q '$r["data"]["storage"]["actions"]["bytes"] > 0')"
check "the JSON leaves out raw log messages" "false" "$(q 'isset($r["data"]["failed"]["actions"][0]["messages"])')"

text="$(wpc queue-triage report)"
contains "the text report lists the groups" "40 x acme_crm_sync_contact [retry, network]" "$text"
lacks "the text report has no email addresses" "@example.com" "$text"
lacks "the text report has no URL queries" "?email" "$text"
lacks "the text report has no server folders" "$WP_PATH" "$text"
lacks "the text report has no group names" "acme-crm" "$text"

# The admin screen, rendered as an administrator and as a subscriber.
admin="$(wpc eval 'wp_set_current_user(1); set_current_screen("tools_page_queue-triage"); ob_start(); QueueTriage\Admin_Page::render(); echo ob_get_clean();')"
contains "the screen shows the failure table" "Failed actions by hook and error" "$admin"
contains "hook names are escaped and shown" "<code>acme_crm_sync_contact</code>" "$admin"
contains "the screen links to Scheduled Actions" "page=action-scheduler&#038;status=failed&#038;s=acme_crm_sync_contact" "$admin"
contains "the support report is there" "### Queue Triage for Action Scheduler report" "$admin"
lacks "the screen has no form" "<form" "$admin"
wpc user get qt_subscriber >/dev/null 2>&1 || wpc user create qt_subscriber qt_subscriber@example.com --role=subscriber >/dev/null
sub="$(wpc eval '$u = get_user_by("login", "qt_subscriber"); wp_set_current_user($u->ID); QueueTriage\Admin_Page::render();' 2>&1 || true)"
contains "a subscriber is turned away" "not allowed" "$sub"
lacks "and sees no report" "acme_crm_sync_contact" "$sub"

# Site Health.
check "both Site Health tests are registered" "true|true" "$(wpc eval '$t = apply_filters("site_status_tests", array("direct" => array(), "async" => array())); echo isset($t["direct"]["queue_triage_queue"]) ? "true" : "false", "|", isset($t["direct"]["queue_triage_failures"]) ? "true" : "false";')"
check "the queue test is critical" "critical" "$(wpc eval '$r = QueueTriage\Site_Health::test_queue(); echo $r["status"];')"
check "the failures test asks for a look" "recommended" "$(wpc eval '$r = QueueTriage\Site_Health::test_failures(); echo $r["status"];')"
contains "Site Health Info gets a section" "Actions by status" "$(wpc eval '$i = QueueTriage\Site_Health::info(array()); echo $i["queue-triage"]["fields"]["counts"]["label"];')"

after="$(checksum)"
check "reading changed nothing in the Action Scheduler tables" "$before" "$after"

# Without Action Scheduler loaded: deactivate the plugins that bundle it and read the tables that remain.
active="$(wpc plugin list --status=active --field=name | tr '\n' ' ')"
for p in woocommerce action-scheduler; do wpc plugin deactivate "$p" >/dev/null 2>&1 || true; done
check "without Action Scheduler the report still works" "not loaded" "$(wpc queue-triage report | sed -n 's/^Action Scheduler: //p')"
contains "and says the tables remain" "not loaded, but its tables are still here" "$(wpc queue-triage report)"
check "the screen renders without Action Scheduler" "1" "$(wpc eval 'wp_set_current_user(1); ob_start(); QueueTriage\Admin_Page::render(); echo (int) (false !== strpos(ob_get_clean(), "No active plugin or theme loads Action Scheduler"));')"
for p in $active; do wpc plugin activate "$p" >/dev/null 2>&1 || true; done

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
