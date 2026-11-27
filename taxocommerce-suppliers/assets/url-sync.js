/* Supplier observations: sequential, bounded requests, no background work. */
(() => {
    'use strict';
    const start = document.getElementById('tcsup-sync-start');
    if (!start) return;
    const stop = document.getElementById('tcsup-sync-stop');
    const progress = document.getElementById('tcsup-sync-progress');
    const results = document.getElementById('tcsup-sync-results');
    const strings = window.tcsupSyncStrings || {};
    let running = false;
    let stopped = false;
    stop.addEventListener('click', () => { stopped = true; });
    start.addEventListener('click', async () => {
        if (running) return;
        running = true;
        stopped = false;
        start.disabled = true;
        let cursor = 0;
        let count = 0;
        const provider = document.getElementById('tcsup-sync-provider').value;
        const row = document.getElementById('tcsup-sync-id').value;
        const value = id => document.getElementById('tcsup-sync-' + id)?.value || '';
        const filters = {object_id:value('object'),sku:value('sku'),cluster:value('cluster'),primary:value('primary'),secondary:value('secondary'),category:value('category')};
        try {
            while (!stopped) {
                const data = new URLSearchParams({action:'tcsup_url_check', _ajax_nonce:start.dataset.nonce, provider, row_id:row, cursor:String(cursor), ...filters});
                const response = await fetch(window.ajaxurl, {method:'POST', credentials:'same-origin', body:data});
                if (!response.ok) throw new Error('HTTP');
                const payload = await response.json();
                if (!payload.success) throw new Error('Request');
                const entry = payload.data;
                if (entry.done) break;
                if (!entry.id || entry.id <= cursor) throw new Error('Cursor');
                cursor = entry.id;
                const tr = document.createElement('tr');
                [entry.id,entry.provider,entry.name,entry.http,entry.status+' / '+entry.identity,entry.price+' '+entry.currency,entry.stock,entry.checked].forEach(value => {
                    const td = document.createElement('td');
                    td.textContent = String(value ?? '');
                    tr.appendChild(td);
                });
                results.prepend(tr);
                while (results.rows.length > 100) results.deleteRow(-1);
                count++;
                progress.textContent = String(count);
                if (row) break;
                await new Promise(resolve => setTimeout(resolve, 1500));
            }
            progress.textContent = count + ' — ' + (stopped ? strings.stopped : strings.done);
        } catch (error) {
            progress.textContent = count + ' — ' + strings.error;
        } finally {
            running = false;
            start.disabled = false;
        }
    });
})();
