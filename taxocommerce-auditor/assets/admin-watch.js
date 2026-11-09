/* global taxocommerceAuditorWatch */
(function () {
    'use strict';

    var config = window.taxocommerceAuditorWatch;
    var monitor = document.querySelector('[data-tca-live]');
    if (!monitor) {
        return;
    }
    var progress = monitor.querySelector('[data-tca-progress]');
    var warning = monitor.querySelector('[data-tca-warning]');
    if (!progress || !warning) {
        return;
    }
    if (!config || !window.fetch || !window.URLSearchParams) {
        warning.textContent = config && config.failure ? config.failure : progress.textContent;
        warning.hidden = false;
        return;
    }
    var scope = monitor.getAttribute('data-scope');
    var failures = 0;
    var idleRuns = 0;
    var stopped = false;
    var busy = false;
    var timer = null;

    function readableTime(timestamp) {
        return Number(timestamp) > 0
            ? new Date(Number(timestamp) * 1000).toLocaleTimeString()
            : config.none;
    }

    function schedule(milliseconds) {
        if (stopped) {
            return;
        }
        if (timer !== null) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(function () {
            timer = null;
            poll();
        }, milliseconds);
    }

    function poll() {
        if (stopped || busy) {
            return;
        }

        busy = true;
        var nextDelay = 2000;
        var body = new window.URLSearchParams();
        body.set('action', 'taxocommerce_auditor_watch');
        body.set('nonce', config.nonce);
        body.set('scope', scope);

        // A single request executes at most one batch. The following request
        // is only scheduled AFTER that response, independently of WP-Cron.
        var controller = window.AbortController ? new window.AbortController() : null;
        var watchdog = controller ? window.setTimeout(function () {
            controller.abort();
        }, 60000) : null;

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (result) {
            if (!result || result.success !== true || !result.data) {
                throw new Error('Invalid progress response');
            }
            var data = result.data;
            failures = 0;
            if (typeof data.nonce === 'string' && data.nonce !== '') {
                config.nonce = data.nonce;
            }

            var statusLabel = document.querySelector('[data-tca-status-label]');
            var counter = document.querySelector('[data-tca-counter]');
            if (statusLabel) {
                statusLabel.textContent = data.status_label;
            }
            if (counter) {
                counter.textContent = data.processed + ' / ' +
                    (data.total === null ? '—' : data.total);
            }
            progress.textContent = data.status_label + ' · ' + data.processed +
                ' / ' + (data.total === null ? '—' : data.total) +
                ' · ' + config.latest + ': ' + readableTime(data.last_batch_timestamp) +
                ' · ' + config.continuous;

            warning.hidden = true;
            warning.textContent = '';
            if (data.reason) {
                warning.textContent = data.reason;
                warning.hidden = false;
            } else if (data.cron_disabled) {
                warning.textContent = config.disabled;
                warning.hidden = false;
            } else if (data.cron_overdue) {
                warning.textContent = config.overdue;
                warning.hidden = false;
            }

            if (data.status === 'completed') {
                stopped = true;
                window.location.reload();
                return;
            }
            if (data.status !== 'queued' && data.status !== 'running') {
                stopped = true;
                return;
            }

            // Speed is persisted per scope. It controls both server batch
            // limits and the idle time between consecutive AJAX requests.
            var pauseMs = Number(data.client_delay_ms);
            if (!Number.isFinite(pauseMs)) {
                pauseMs = 2000;
            }
            nextDelay = Math.min(10000, Math.max(300, pauseMs));
            if (Number(data.batch_processed) <= 0) {
                idleRuns += 1;
                // Another worker may own the atomic lock. Never busy-loop.
                nextDelay = Math.max(nextDelay,
                    Math.min(15000, 1000 * Math.pow(2, Math.min(idleRuns, 4))));
            } else {
                idleRuns = 0;
            }
        }).catch(function (error) {
            failures += 1;
            // Keep the exact HTTP failure visible (403, 500, timeout etc.)
            // instead of silently leaving the user with a stalled counter.
            var reason = error && typeof error.message === 'string' ? error.message : '';
            warning.textContent = config.failure + (reason ? ' (' + reason + ')' : '');
            warning.hidden = false;
            // Transport errors back off; the next request can resume safely
            // from the persisted cursor without replaying completed objects.
            nextDelay = Math.min(60000, 5000 * Math.pow(2, Math.min(failures, 4)));
        }).finally(function () {
            if (watchdog !== null) {
                window.clearTimeout(watchdog);
            }
            busy = false;
            if (!stopped) {
                schedule(nextDelay);
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !stopped && !busy) {
            schedule(300);
        }
    });

    // Start with the first batch immediately after the admin screen loads.
    schedule(300);
}());
