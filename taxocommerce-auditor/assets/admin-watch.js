/* global taxocommerceAuditorWatch */
(function () {
    'use strict';

    var config = window.taxocommerceAuditorWatch;
    var monitor = document.querySelector('[data-tca-live]');
    if (!config || !monitor || !window.fetch || !window.URLSearchParams) {
        return;
    }

    var progress = monitor.querySelector('[data-tca-progress]');
    var warning = monitor.querySelector('[data-tca-warning]');
    var scope = monitor.getAttribute('data-scope');
    var failures = 0;
    var stopped = false;
    var busy = false;

    function readableTime(timestamp) {
        return Number(timestamp) > 0
            ? new Date(Number(timestamp) * 1000).toLocaleTimeString()
            : config.none;
    }

    function schedule(milliseconds) {
        if (!stopped) {
            window.setTimeout(poll, milliseconds);
        }
    }

    function poll() {
        if (stopped) {
            return;
        }
        if (busy) {
            schedule(1000);
            return;
        }

        busy = true;
        var body = new window.URLSearchParams();
        body.set('action', 'taxocommerce_auditor_watch');
        body.set('nonce', config.nonce);
        body.set('scope', scope);

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
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
            progress.textContent = data.status_label + ' · ' + data.processed +
                ' / ' + (data.total === null ? '—' : data.total) +
                ' · ' + config.latest + ': ' + readableTime(data.last_batch_timestamp) +
                ' · ' + config.next + ': ' + readableTime(data.next_event_timestamp);

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
            // This is a status request or one due batch, never a tight worker loop.
            var seconds = Number(data.speed_delay_seconds) || 12;
            schedule(Math.min(15000, Math.max(5000, Math.floor(seconds * 500))));
        }).catch(function () {
            failures += 1;
            warning.textContent = config.failure;
            warning.hidden = false;
            // Failed requests back off instead of overloading a struggling host.
            schedule(Math.min(60000, 5000 * Math.pow(2, Math.min(failures, 4))));
        }).finally(function () {
            busy = false;
        });
    }

    schedule(1500);
}());
