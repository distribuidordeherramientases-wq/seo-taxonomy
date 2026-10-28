'use strict';
/* Browser smoke for automatic continuation, pause/resume and live audit stats. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const { URLSearchParams } = require('url');

const script = fs.readFileSync('taxocommerce-images/assets/audit.js','utf8');
const listeners = {};
const status = {textContent:''};
const bar = {value:0,max:1};
const pause = {disabled:false,addEventListener:(type, fn)=>{listeners['pause:'+type]=fn;}};
const resume = {disabled:true,addEventListener:(type, fn)=>{listeners['resume:'+type]=fn;}};
const items = {'tc-images-audit-progress':status, 'tci-audit-progress-bar':bar,
    'tci-audit-pause':pause, 'tci-audit-resume':resume};
const document = {querySelector:(q)=>q==='[data-tci-audit-runner]'?{}:null,
    getElementById:(q)=>items[q]||null};
const timers = [];
const responses = [
    {success:true,data:{scanned:25,total:75,status:'running',priority:3,
        load:{batch:75,items:25,elapsed:2,next_batch:75,items_per_second:12.5,cpu_percent:42,
            memory_percent:24,reason:'Lote parcial sin aumentar'}}},
    {success:true,data:{scanned:75,total:75,status:'completed',priority:3,
        load:{batch:75,items:50,elapsed:3,next_batch:75,items_per_second:16.6,cpu_percent:45,
            memory_percent:25,reason:'Auditoría finalizada'}}}
];
const actions=[];
const window = {
    taxocommerceImagesAudit:{ajax:'https://example.test/wp-admin/admin-ajax.php',nonce:'test'},
    location:{reload:()=>actions.push('reload')},
    fetch:(url, options)=>{
        const params = new URLSearchParams(options.body);
        assert.strictEqual(params.get('action'),'taxocommerce_images_audit_step');
        assert.strictEqual(params.get('nonce'),'test');
        assert.strictEqual(options.credentials,'same-origin');
        actions.push('fetch');
        const response = responses.shift();
        assert(response,'More requests than expected');
        return Promise.resolve({ok:true,status:200,json:()=>Promise.resolve(response)});
    },
    setTimeout:(fn,delay)=>{timers.push({fn,delay});return timers.length;},
    clearTimeout:()=>{}
};
vm.runInNewContext(script,{window,document,URLSearchParams,Math,Number,Error,Promise});
async function executeTimer() {
    assert(timers.length>0,'timer expected');
    const timer = timers.shift();
    assert.strictEqual(timer.delay,0,'No idle delay after completed batch');
    timer.fn();
    await new Promise(setImmediate);
}
(async()=>{
    assert.strictEqual(timers.length,1,'Auto-start must schedule without pressing a button');
    await executeTimer();
    assert(status.textContent.includes('25 / 75 imágenes'),'progress not updated');
    assert(status.textContent.includes('75 imágenes'),'next adaptive batch not displayed');
    assert.strictEqual(bar.value,25);
    assert.strictEqual(timers.length,1,'Next bounded batch should be queued automatically');
    listeners['pause:click']();
    assert.strictEqual(pause.disabled,true,'Pause must disable itself');
    assert.strictEqual(resume.disabled,false,'Resume must become available');
    // Simulate a paused user; canceled browser timer is ignored in the smoke.
    timers.length=0;
    listeners['resume:click']();
    assert.strictEqual(timers.length,1,'Resume should queue a new step');
    await executeTimer();
    assert.strictEqual(bar.value,75);
    assert.strictEqual(actions.filter(a=>a==='fetch').length,2);
    assert.strictEqual(actions[actions.length-1],'reload','Completed scan should refresh results');
    console.log('Images audit browser continuation: OK');
})().catch(error=>{console.error(error); process.exitCode=1;});
