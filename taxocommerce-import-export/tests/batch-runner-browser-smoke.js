'use strict';
/* Behavioral test for client-assisted immediate CSV batch chaining. */
const assert = require('assert');
const vm = require('vm');
const fs = require('fs');
const { URLSearchParams } = require('url');
const source = fs.readFileSync('taxocommerce-import-export/assets/batch-runner.js', 'utf8');

async function run(sequence, expectedDelays) {
    const timers = [];
    const observed = [];
    const status = {textContent: ''};
    const window = {
        tcIeBatchRunner: {ajaxUrl:'https://example.test/wp-admin/admin-ajax.php',nonce:'testing'},
        setTimeout(fn, ms) {timers.push({fn,ms}); return timers.length;}
    };
    const document = {getElementById(id) {return id === 'tc-ie-batch-live' ? status : null;}};
    let requests = 0;
    const fetch = (url, options) => {
        requests++;
        assert.strictEqual(options.method,'POST');
        const params = new URLSearchParams(options.body);
        assert.strictEqual(params.get('action'), 'taxocommerce_ie_batch_step');
        assert.strictEqual(params.get('nonce'),'testing');
        const row = sequence.shift();
        assert(row, 'Unexpected extra step');
        return Promise.resolve({
            ok:true,
            json: () => Promise.resolve({success:true,data:{
                enabled:row.enabled,busy:row.busy, status:row.enabled?'processing':'idle',
                last_batch:10, next_batch:14, elapsed:0.2, rows_per_second:50
            }})
        });
    };
    vm.runInNewContext(source,{window,document,URLSearchParams,fetch,Number,Math,Promise});
    let guard = 0;
    while (timers.length && guard++ < 15) {
        const task = timers.shift();
        observed.push(task.ms);
        task.fn();
        await new Promise(resolve=>setImmediate(resolve));
    }
    assert(guard < 15, 'Runner did not finish');
    assert.deepStrictEqual(observed,expectedDelays);
    assert.strictEqual(requests, expectedDelays.length);
    assert(status.textContent.includes('Siguiente lote: 14 filas'));
}
(async ()=>{
    await run([
        {enabled:true,busy:false},
        {enabled:true,busy:false},
        {enabled:false,busy:false}
    ], [0,0,0]);
    await run([
        {enabled:true,busy:true},
        {enabled:false,busy:false}
    ], [0,500]);
    console.log('Immediate CSV browser queue: OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
