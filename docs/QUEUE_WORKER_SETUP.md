# Queue worker setup

Use deploy/supervisor/pentagon.conf.example after reviewing absolute paths, account, PHP binary, log paths and Chromium browser location. Start one document worker on documents and one PDF worker on exports. Existing jobs enforce their own timeouts/atomic claims and retain attempts. The example uses tries=1; an authorized explicit retry creates the next attempt. Do not blindly replay every failed job.

Set queue retry_after=1200, document worker timeout=960, PDF worker timeout=240, stopwaitsecs=1200. Application document timeout is 900 seconds by default; retry_after must remain longer than the complete worker timeout. Ensure CLI pcntl is available on Linux. Separate queues prevent a long extraction from blocking all PDF work. Database is the documented initial queue; Redis needs independent broker backlog monitoring.

After a reviewed release, use php artisan queue:restart for graceful replacement, then supervisor restart only after active jobs finish. Never kill a busy worker or run simultaneous duplicate schedulers. Scheduler: deploy/cron.example. cPanel fallback: deploy/cpanel/cron.example, only if flock and a full extraction timeout are supported; max-time stops between jobs and does not shorten a running job. Choose persistent workers or cron workers, not both.

System Health shows recent per-queue heartbeats, busy state windows, retained failures and stuck counts. No heartbeat is Not verified. A fresh busy heartbeat does not prove progress. Investigate logs/attempts and host resource pressure before retrying. The page never deletes jobs or approves content. Log rotation and filesystem capacity alerts are host responsibilities.
