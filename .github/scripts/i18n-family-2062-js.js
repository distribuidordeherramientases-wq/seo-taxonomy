#!/usr/bin/env node
/**
 * Convert UI-only JavaScript literals to native wp.i18n gettext.
 * Uses a parser to distinguish UI literals from identifiers or comparisons.
 * Usage: node i18n-family-2062-js.js <repository-root> <output-manifest-json>
 */
const fs = require('fs');
const path = require('path');
const ts = require(process.env.TS_MODULE_PATH || 'typescript');
const root = process.argv[2];
const manifestFile = process.argv[3];
if (!root || !manifestFile) throw Error('root and manifest path required');

function translatable(s) {
    return /[A-Za-zÀ-ÿ]{3,}/u.test(s)
        && s.length >= 4
        && !/<[^>]+>/.test(s)
        && !/\$\{|https?:\/\/|[{}]|^\.?[a-z0-9_-]+$/.test(s)
        && !/^\s*\//.test(s);
}
function collect(node, matches) {
    if (ts.isStringLiteral(node) && translatable(node.text)) {
        matches.push(node);
        return;
    }
    if (ts.isConditionalExpression(node)) {
        collect(node.whenTrue, matches);
        collect(node.whenFalse, matches);
        return;
    }
    if (ts.isBinaryExpression(node)) {
        if ([
            ts.SyntaxKind.PlusToken,
            ts.SyntaxKind.BarBarToken,
            ts.SyntaxKind.QuestionQuestionToken,
        ].includes(node.operatorToken.kind)) {
            collect(node.left, matches);
            collect(node.right, matches);
        }
        return;
    }
    if (ts.isParenthesizedExpression(node)) {
        collect(node.expression, matches);
    }
}
function translate(file, domain) {
    let source = fs.readFileSync(file, 'utf8');
    const ast = ts.createSourceFile(file, source, ts.ScriptTarget.ES2020, true, ts.ScriptKind.JS);
    const matched = [];
    function visit(node) {
        if (ts.isBinaryExpression(node)
            && node.operatorToken.kind === ts.SyntaxKind.EqualsToken
            && ts.isPropertyAccessExpression(node.left)
            && ['textContent', 'innerText', 'placeholder', 'title'].includes(node.left.name.text)) {
            collect(node.right, matched);
        }
        if (ts.isCallExpression(node)) {
            const f = node.expression.getText(ast);
            if (['alert', 'window.alert', 'confirm', 'window.confirm', 'prompt', 'window.prompt'].includes(f)
                && node.arguments.length) {
                collect(node.arguments[0], matched);
            }
        }
        ts.forEachChild(node, visit);
    }
    visit(ast);
    const unique = new Map();
    for (const node of matched) unique.set(node.getStart(ast) + '-' + node.end, node);
    const edits = [...unique.values()].sort((a, b) => b.getStart(ast) - a.getStart(ast));
    const words = [];
    for (const node of edits) {
        const old = source.slice(node.getStart(ast), node.end);
        if (old[0] !== "'" && old[0] !== '"') continue;
        const word = node.text;
        words.push(word);
        const replacement = 'wp.i18n.__(' + JSON.stringify(word) + ', ' + JSON.stringify(domain) + ')';
        source = source.slice(0, node.getStart(ast)) + replacement + source.slice(node.end);
    }
    if (words.length) fs.writeFileSync(file, source);
    return [...new Set(words)];
}
const report = {};
for (const plugin of fs.readdirSync(root)) {
    if (!plugin.startsWith('taxocommerce-')) continue;
    const dir = path.join(root, plugin);
    if (!fs.statSync(dir).isDirectory()) continue;
    const stack = [dir];
    while (stack.length) {
        const cwd = stack.pop();
        for (const filename of fs.readdirSync(cwd)) {
            const p = path.join(cwd, filename);
            if (fs.statSync(p).isDirectory()) {
                if (!['tests', 'node_modules'].includes(filename)) stack.push(p);
            } else if (p.endsWith('.js')) {
                const words = translate(p, plugin);
                if (words.length) report[path.relative(root, p)] = words;
            }
        }
    }
}
fs.writeFileSync(manifestFile, JSON.stringify(report, null, 2) + '\n');
console.log('Native JavaScript UI labels localized:', Object.values(report).reduce((n,v) => n+v.length,0));
