# Board email alerts

All valid email addresses in `cgm_members` receive alerts, including the member
who made the change. The API queues document additions, event saves, meeting
creation, financial additions/deletions, and monthly report publication. Monthly
imports generate one summary alert rather than one email per imported row.

## Enable on the existing PHP/MySQL host

1. Deploy `api.php`, `board.html`, `notifications.php`, and
   `notification-worker.php` together. Keep the existing ignored `db.php` and
   MySQL database. PHP 8+ needs PDO MySQL and cURL.
2. Back up the database, then run `php notification-worker.php --install` from
   the portal directory. This adds only the `cgm_notifications` queue table.
3. In the portal's admin Email Settings, configure the existing email endpoint,
   shared secret, and full HTTPS board portal URL. The endpoint must accept the
   existing JSON `{to, subject, html}` contract and `X-Portal-Secret` header.
   Secrets stay in the existing database; never commit them. The ignored
   `send-email.php` endpoint implementation is not included in this repository.
4. Schedule `notification-worker.php` every five minutes using the hosting
   provider's cron service. Example (replace the absolute path and PHP binary):

   ```cron
   */5 * * * * CGM_TIMEZONE=America/Chicago /usr/bin/php /path/to/cgm/notification-worker.php >> /path/outside/webroot/cgm-notifications.log 2>&1
   ```

5. Run the worker once manually. Add a document and verify each board member
   receives one email. Repeat for an event and a financial report. Check that
   `sent_at` is populated in the queue. Test on a staging database first.

Meeting reminders are queued 7 days and 24 hours before scheduled meetings,
using America/Chicago unless `CGM_TIMEZONE` is set. Undated meetings are skipped;
meetings without a time use 9 AM. The worker must run during the one-hour window
after each threshold; stale reminders are skipped. Reminder keys include the
meeting date/time so a rescheduled meeting receives fresh reminders.

Failed deliveries remain queued and are retried on the next worker run. The
worker serializes concurrent runs with a MySQL advisory lock and handles 100
emails per run. Monitor queue backlog and worker logs. An endpoint timeout after
acceptance can cause a duplicate on retry; delivery is not exactly-once. Endpoint
HTTP success means accepted by the endpoint, not confirmed inbox delivery.

If the queue cannot be written, the portal reports that the change was saved
but alerts were not queued. Those alerts need manual follow-up after repair.
No emails are sent merely by deploying or installing the queue; the worker sends
queued emails. Browser-based change alerts have been replaced by server queuing;
the existing admin test-email button remains available.

## Cloud development

Use the existing `/workspace/cgm` checkout; do not create a Git worktree.
Build `docker --host=unix:///var/run/docker.sock build -t cgm-dev:local dev`.
Run `docker --host=unix:///var/run/docker.sock run --rm -p 127.0.0.1:8080:8080 -v /workspace/cgm:/app:ro cgm-dev:local`.
This is a local development server; it does not enforce Apache `.htaccess`.
Use the production Apache host's existing security configuration for deployment.

Run `docker --host=unix:///var/run/docker.sock run --rm -v /workspace/cgm:/app:ro cgm-dev:local php tests/notifications.php`.
These are isolated logic tests, not a live MySQL/email integration test.
The cloud checkout lacks `db.php` and the production schema, so database/API
and delivery checks remain blocked here. Supply configuration through secure
hosting/environment settings, never in chat. Do not run the worker against the
production database during onboarding without intending to send pending email.
