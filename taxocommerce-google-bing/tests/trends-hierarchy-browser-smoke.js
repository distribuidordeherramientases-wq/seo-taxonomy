/* Runtime smoke: the browser auto-starts grouped charts on tab entry. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const {URL, URLSearchParams} = require('url');

const requests = [];
let seq = 0;
const pending = new Map();
const setTimer = (fn, ms) => {const id = ++seq; pending.set(id, {fn, ms}); return id;};
const clearTimer = id => pending.delete(id);
const element = () => ({disabled:false, value:'', textContent:'', hidden:false, listeners:{},
    addEventListener(type, fn) {this.listeners[type] = fn;}});
const btn = () => element();
function group(terms, title, kind) {
    const state = element(), link = element();
    const graph = {nodes:[], replaceChildren(...nodes) {this.nodes=nodes;}};
    return {
        dataset:{terms:JSON.stringify(terms),kind},
        querySelector(selector) {
            return ({
                '.tgb-h-compare-chart':graph,
                '.tgb-h-compare-state':state,
                '.tgb-h-compare-link':link,
                'h4':{textContent:title}
            })[selector] || null;
        },
        state, link, graph
    };
}
const groups = [
    group(['Automoción','Bricolaje','Equipamiento profesional'],'Clústeres','cluster'),
    group(['Mecánica','Equipamiento de taller'],'Hubs de automoción','hub_primary'),
    group(['Herramientas de mano'],'Hubs de bricolaje','hub_primary'),
    group([],'Hubs sin elementos','hub_primary')
];
const controls = {
    '#tgb-h-period':Object.assign(element(),{value:'today 12-m',selectedIndex:0,options:[{textContent:'12 meses'}]}),
    '#tgb-h-speed':Object.assign(element(),{value:'3'}),
    '#tgb-h-speed-label':element(),
    '#tgb-h-progress':Object.assign(element(),{value:0}),
    '#tgb-h-state':element(),
    '#tgb-h-start':btn(),
    '#tgb-h-pause':btn(),
    '#tgb-h-resume':btn(),
    '#tgb-h-stop':btn(),
    '#tgb-h-chart':{querySelector(){return null;}, replaceChildren(){}},
    '#tgb-h-chart-title':element(),
    '#tgb-h-explore':element(),
    '#tgb-h-log':{children:[],prepend(item){this.children.unshift(item);},get lastElementChild(){return this.children[this.children.length-1];}}
};
const root = {
    querySelector(selector) {return controls[selector] || null;},
    querySelectorAll(selector) {
        return selector === '.tgb-h-compare-card' ? groups : [];
    }
};
const document = {
    getElementById(id) {return id === 'tgb-hierarchy' ? root : null;},
    createElement(tag) {
        if (tag !== 'iframe') {return element();}
        const frame = {
            setAttribute(){},removeAttribute(){},remove(){},
            set src(value) {
                requests.push(value);
                // Simulate completed navigation; Google chart contents are
                // deliberately not represented or asserted by this test.
                if (this.onload) {this.onload();}
            }
        };
        return frame;
    }
};
const context = {
    document,URL,URLSearchParams,JSON,Date,Array,Number,Math,Set,console,
    setTimeout:setTimer,clearTimeout:clearTimer
};
vm.runInNewContext(
    fs.readFileSync('taxocommerce-google-bing/assets/trends-hierarchy.js','utf8'),
    context
);

function drain() {
    let ticks=0;
    while (pending.size && ticks++ < 40) {
        const [id, timer] = pending.entries().next().value;
        pending.delete(id);
        if (timer.ms < 18000) {timer.fn();}
    }
    assert(ticks < 40, 'A comparison queue stalled');
}
drain();
assert.strictEqual(requests.length,3,'Must request one cluster comparison and one per populated cluster');
const comparisons = requests.map(uri => {
    const url = new URL(uri);
    const body = JSON.parse(url.searchParams.get('req'));
    assert.strictEqual(url.hostname, 'trends.google.com');
    assert.strictEqual(body.comparisonItem.length <= 5,true);
    assert.strictEqual(body.comparisonItem.every(x=>x.geo==='ES'),true);
    assert.strictEqual(body.comparisonItem.every(x=>x.time==='today 12-m'),true);
    return body.comparisonItem.map(x=>x.keyword);
});
assert.deepStrictEqual(comparisons,[
    ['Automoción','Bricolaje','Equipamiento profesional'],
    ['Mecánica','Equipamiento de taller'],
    ['Herramientas de mano']
]);
assert.strictEqual(controls['#tgb-h-progress'].value,100);
assert.strictEqual(groups[3].graph.nodes.length,0,'Empty hub graph must not request Google');
assert.strictEqual(groups[0].link.href.includes('geo=ES'),true);
assert.strictEqual(groups[0].link.href.includes('q=Automoci'),true);

// A new period must regenerate the report automatically.
controls['#tgb-h-period'].value='today 3-m';
controls['#tgb-h-period'].listeners.change();
drain();
assert.strictEqual(requests.length,6);
assert.strictEqual(new URL(requests[5]).searchParams.get('eq').includes('today+3-m'),true);
console.log('Trends browser auto-comparison smoke: OK');
