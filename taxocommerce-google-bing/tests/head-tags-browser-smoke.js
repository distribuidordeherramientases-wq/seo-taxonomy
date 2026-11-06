// Verify that known Microsoft Clarity snippets are reduced to a project ID
// before native WordPress form submission (without external requests).
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '../includes/class-taxocommerce-insights-head-tags.php'), 'utf8');
const startMarker = '        <script>\n        (function () {';
const start = php.indexOf(startMarker);
assert.notEqual(start, -1, 'Insights must render the browser-side serializer');
const end = php.indexOf('        </script>', start);
assert.ok(end > start, 'Browser-side serializer must be closed');
const javascript = php.slice(start + '        <script>'.length, end);

function submit(code, type = 'tracking') {
    const field = {value: code, disabled: false};
    const kind = {value: type};
    const hidden = {value: ''};
    let callback;
    const form = {
        querySelector(selector) {
            if (selector === 'textarea[name="entry_code"]') return field;
            if (selector === 'select[name="entry_kind"]') return kind;
            return null;
        },
        addEventListener(event, fn) {
            assert.equal(event, 'submit');
            callback = fn;
        }
    };
    const document = {
        getElementById(id) {
            if (id === 'tgb-head-tags-form') return form;
            if (id === 'tgb-head-tags-clarity-id') return hidden;
            return null;
        }
    };
    vm.runInNewContext(javascript, {document}, {timeout: 1000});
    assert.equal(typeof callback, 'function');
    callback();
    return {field, hidden};
}

const official = `<script type="text/javascript">
(function(c,l,a,r,i,t,y){
    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
})(window, document, "clarity", "script", "ww0i0jxbs8");
</script>`;
let result = submit(official);
assert.equal(result.hidden.value, 'ww0i0jxbs8');
assert.equal(result.field.disabled, true, 'Executable markup must not be transmitted in the form');

result = submit('<script async src="https://www.clarity.ms/tag/ww0i0jxbs8"></script>');
assert.equal(result.hidden.value, 'ww0i0jxbs8');
assert.equal(result.field.disabled, true);

result = submit('<meta name="msvalidate.01" content="verification-token">', 'verification');
assert.equal(result.hidden.value, '');
assert.equal(result.field.disabled, false, 'Non-Clarity markup must retain existing behavior');

result = submit('<script src="https://example.com/tracker.js"></script>');
assert.equal(result.hidden.value, '');
assert.equal(result.field.disabled, false, 'Unknown provider must not be silently rewritten');
process.stdout.write('Clarity client-side submission smoke test passed\n');
