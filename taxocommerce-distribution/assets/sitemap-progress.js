/* TaxoCommerce Distribution: authenticated, single-flight browser pulse. */
( function () {
    'use strict';
    const box = document.getElementById( 'tcdist-sitemap-progress' );
    const cfg = window.tcdistSitemapProgress;
    if ( !box || !cfg || box.dataset.running !== '1' ) {
        return;
    }
    const status = document.getElementById( 'tcdist-sitemap-status' );
    const counts = document.getElementById( 'tcdist-sitemap-counts' );
    const phase = document.getElementById( 'tcdist-sitemap-phase' );
    const message = document.getElementById( 'tcdist-sitemap-message' );
    const load = document.getElementById( 'tcdist-sitemap-load' );
    const published = document.getElementById( 'tcdist-sitemap-published-at' );
    const started = document.getElementById( 'tcdist-sitemap-started-at' );
    const updated = document.getElementById( 'tcdist-sitemap-updated-at' );
    const stalled = document.getElementById( 'tcdist-sitemap-stalled' );
    let active = true;

    async function tick() {
        if ( !active ) {
            return;
        }
        try {
            const body = new URLSearchParams( {
                action: 'tcdist_sitemap_pulse',
                nonce: cfg.nonce
            } );
            const response = await fetch( cfg.url, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            } );
            const json = await response.json();
            if ( !response.ok || !json.success || !json.data ) {
                throw new Error( 'Sitemap worker unavailable' );
            }

            const data = json.data;
            status.textContent = cfg.statusLabels?.[ data.status ] || data.status;
            if ( published && data.published ) {
                published.textContent = data.published;
            }
            if ( started && data.started ) {
                started.textContent = data.started;
            }
            if ( updated && data.heartbeat ) {
                updated.textContent = data.heartbeat;
            }
            if ( stalled ) {
                stalled.textContent = data.stalled ? cfg.stalled : '';
            }
            counts.textContent = data.processed + ' elementos revisados; ' + data.urls + ' URL preparadas.';
            phase.textContent = data.phase;
            message.textContent = data.message || '';
            const sample = data.sample || {};
            load.textContent = 'Último bloque: ' + ( sample.seconds ?? '—' ) + ' s · CPU PHP: ' +
                ( sample.cpu_percent ?? '—' ) + '% · Memoria PHP: ' + ( sample.memory_percent ?? '—' ) + '%';

            if ( data.status === 'completed' ) {
                active = false;
                message.textContent = cfg.finished;
                window.location.reload();
                return;
            }
            if ( data.status !== 'running' ) {
                active = false;
                if ( !message.textContent ) {
                    message.textContent = cfg.failed;
                }
                return;
            }
            const delay = Math.max( 1000, Math.min( 60000, Number( sample.delay_seconds || 5 ) * 1000 ) );
            window.setTimeout( tick, delay );
        } catch ( error ) {
            // Keep the last published index, report heartbeat failures visibly.
            if ( stalled ) {
                stalled.textContent = cfg.stalled;
            }
            window.setTimeout( tick, 15000 );
        }
    }
    window.setTimeout( tick, 1000 );
}() );
