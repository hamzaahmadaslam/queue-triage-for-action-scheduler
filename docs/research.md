# Research notes (2026-10-20)

What was checked before building, and where each fact came from.

## Existing plugins on wordpress.org

From the wordpress.org plugins API (`query_plugins` and `plugin_information`), 2026-10-20.

| Plugin | Active installs | Added | What it does |
| --- | --- | --- | --- |
| WP Crontrol | 300,000+ | 2008 | WP-Cron events only. No Action Scheduler view. |
| Advanced Cron Manager | 30,000+ | 2014 | WP-Cron events only. |
| Action Scheduler (Automattic) | 20,000+ | 2020 | The library as a plugin. Its Tools > Scheduled Actions screen lists actions one row at a time and shows a "past-due actions found; something may be wrong" notice. |
| WS Action Scheduler Cleaner | 3,000+ | 2025 | Deletes old rows. |
| Cleanup Action Scheduler | 1,000+ | 2022 | Deletes old rows. |
| Easy Actions Scheduler Cleaner | 900+ | 2025 | Deletes old rows on a schedule. |
| ZS Action Scheduler Optimizer | 300+ | 2024 | Table sizes, retention, cleanup. |
| Failed Actions Monitor for Action Scheduler | 20+ | 2025 | Daily email listing failed actions. |
| AMPM Queue Manager for Action Scheduler | 10+ | 2026-07-31 | Runs due actions, cleans old ones. |
| StallRadar Past Due Monitor | under 10 | 2026-09-14 | Past-due actions by hook, runner causes, runs and cancels actions, has a Pro version. |
| SchedSense: Fast Diagnostics for Action Scheduler | under 10 | 2026-09-29 | Counts, overdue age buckets, callback source, support report; can delete failed actions and spawn cron. |
| ModelGain Queue Health for Action Scheduler | under 10 | 2026-10-14 | Read-only: overdue and failed counts, top hooks, callback owner, support report. |
| Minuttes | under 10 | 2026-06-07 | GraphQL API and WP-CLI, with a paid hosted dashboard. |

What none of them advertises: grouping failed actions by the error itself, after the ids, dates,
amounts and paths in the message are replaced, so 800 failures read as four problems; a rule-based
label on each group; and checks on whether cleanup keeps up with the retention period.

Five of the thirteen arrived since June 2026 and four of them have no measurable installs yet, so the
field is open but not empty. The plugin has to be clearly better at one job: explaining failures.

## Guidelines and naming

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
  (last updated 2026-03-11). Guideline 17: "we will not permit a slug to begin with another
  product's term", and non-owners should use the "[Your Product] for [Other Product]" pattern.
  Action Scheduler is an Automattic project, so `action-scheduler-health` would be refused.
  Guideline 7: no external requests without consent. Guideline 11: notices sparing and dismissible.
- Plugin Check's `Trademarks_Check` (trunk, read 2026-10-20) blocks slugs starting with `woo-`,
  `woocommerce`, `wp` (warning) and others, and blocks "plugin" in a slug. "action-scheduler" is not
  on its list, so the rule above is enforced by the human review, not the tool.
- Chosen name: **Queue Triage for Action Scheduler**, slug `queue-triage-for-action-scheduler`.
  The slug was free (plugins API: "Plugin not found") and it matches the owner's existing
  `action-scheduler-triage` command-line tool.
- [readme.txt guide](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
  (last updated 2026-03-11): at most 5 tags, short description at most 150 characters, a readme over
  10 KB may cause errors, `Stable tag: trunk` is not allowed for new plugins, `Tested up to` takes the
  major version only, and `Requires at least` / `Requires PHP` are read from the main plugin file.
- [Add your plugin](https://wordpress.org/plugins/developers/add/): review takes 1 to 10 days. The
  slug comes from the Plugin Name header and can be changed once before review starts. The most
  common rejections are unescaped output, unsanitized input and form handling without a nonce.

## Action Scheduler facts used in the code

Read from the Action Scheduler 4.2.0 source (wordpress.org) and the 4.1.0 copy inside WooCommerce 11.2.1.

- Tables: `actionscheduler_actions` (indexes on `status, scheduled_date_gmt` and `hook, status,
  scheduled_date_gmt`), `actionscheduler_logs` (index on `log_date_gmt`), `actionscheduler_claims`,
  `actionscheduler_groups`.
- Queue runner: WP-Cron hook `action_scheduler_run_queue` on the `every_minute` schedule, 25 actions per
  batch, one concurrent batch, 30 second time limit, all filterable.
- Cleanup: completed and canceled actions are deleted after 31 days (`action_scheduler_retention_period`).
  Since 4.0.0 failed actions are deleted after 93 days (`action_scheduler_retention_period_for_failed`,
  `action_scheduler_enable_failed_action_cleanup`); before 4.0.0 failed actions were kept.
- Pending actions claimed for more than 300 seconds are unclaimed; actions in progress for more than
  300 seconds are marked failed with "action was in-progress for at least N seconds without completing"
  (older releases: "action marked as failed after N seconds").
- Failure log lines: "action failed via {context}: {message}", "unexpected shutdown: PHP Fatal error
  {message} in {file} on line {n}", "This action appears to be consistently failing. A new instance will
  not be scheduled.", and "Scheduled action for {hook} will not be executed as no callbacks are
  registered." These strings are translated, so on a site in another language the plugin falls back to
  the last log line.
- The built-in past-due notice uses a one-day threshold (`action_scheduler_pastdue_actions_seconds`).

## Second opinion

The plan was put to an outside reviewer on 2026-10-20, and each point below was checked before it was used:

- The competitors it named (ModelGain, SchedSense, Minuttes) exist; checked against the plugins API above.
- A timeout does not prove a job is safe to run again. The label is "Retry candidate, check first", not
  "safe to retry", and hooks that look like payments, renewals, refunds, emails or webhooks never get it.
- A disabled WP-Cron does not mean a broken queue: a server cron or WP-CLI may run it. The plugin shows
  evidence of recent runs instead of guessing.
- Retention depends on the Action Scheduler version, so the screen shows the values in effect.
- Show how many failures were read ("the newest 500 of 12,000"), not an implied complete answer.
- A copyable support report that leaves out arguments, emails, IP addresses and paths.

The review's account of support-forum complaints (past-due backlogs, actions stuck in progress, log tables
with millions of rows, owners unsure whether deleting is safe) was not checked thread by thread.
