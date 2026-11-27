/* One administrator-requested IMAP test per HTTP request; no automatic retries. */
(function () {
    'use strict';
    const form = document.getElementById('tcmailpro-check-form');
    const settings = window.tcmailproAccountCheck;
    if (!form || !settings || form.elements.check_mode.value !== 'functionality' || !settings.accounts.length) {
        return;
    }
    const progress = document.getElementById('tcmailpro-check-progress');
    let running = false;
    form.addEventListener('submit', async function (event) {
        if (running) {
            event.preventDefault();
            return;
        }
        if (event.submitter && event.submitter.name === 'test_account') {
            return;
        }
        event.preventDefault();
        running = true;
        const buttons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
        buttons.forEach(button => { button.disabled = true; });
        try {
            let done = 0;
            for (const account of settings.accounts) {
                progress.textContent = settings.progress + ' ' + done + '/' + settings.accounts.length;
                const body = new URLSearchParams();
                body.set('action', 'tcmailpro_imap_check');
                body.set('_ajax_nonce', form.elements._wpnonce.value);
                body.set('account', account);
                const response = await fetch(settings.url, { method: 'POST', credentials: 'same-origin', body });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error('check_interrupted');
                }
                done++;
                progress.textContent = settings.progress + ' ' + done + '/' + settings.accounts.length;
            }
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) {
            progress.textContent = settings.failed;
            buttons.forEach(button => { button.disabled = false; });
            running = false;
        }
    });
}());
