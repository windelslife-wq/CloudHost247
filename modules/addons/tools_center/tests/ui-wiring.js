'use strict';

/**
 * UI wiring tests for the Tools Center page (tools-center.js).
 *
 * These run the real tools-center.js in a jsdom window, with the real
 * toolDefinitions block taken from templates/tools/tool.tpl. They check that
 * ordinary server-backed tools still POST to the server and that no client
 * tool is left behind by mistake.
 *
 * Requires the optional jsdom package (not a runtime dependency):
 *   cd modules/addons/tools_center/tests && npm install --no-save jsdom@24
 */

module.exports = function buildUiTests(ctx) {
    const { read } = ctx;
    const assert = require('node:assert/strict');
    const { JSDOM } = require('jsdom');

    const toolsTpl = read('templates/tools/tool.tpl');
    const start = toolsTpl.indexOf('var toolDefinitions = {');
    const end = toolsTpl.indexOf('\n};', start) + 3;
    const defsSource = toolsTpl.slice(start, end);

    function makeWindow() {
        const dom = new JSDOM(`<!doctype html><html><body>
            <div id="toolForm"></div>
            <h3 id="currentToolName"></h3>
            <div id="toolResults" style="display:none"><div id="resultsContent"></div></div>
            <div id="toolModal"><h4 id="modalTitle"></h4><div id="modalBody"></div></div>
        </body></html>`, {
            runScripts: 'outside-only',
            url: 'https://client.example/index.php?m=tools_center&cat=productivity&tool=loremIpsum'
        });
        const w = dom.window;
        w.__xhrCalls = 0;
        w.XMLHttpRequest = function () {
            w.__xhrCalls++;
            this.open = function () {};
            this.setRequestHeader = function () {};
            this.send = function () {};
        };
        w.eval(defsSource);
        w.eval(read('js/tools-center.js'));
        return w;
    }

    function submit(w, form) {
        form.dispatchEvent(new w.Event('submit', { cancelable: true, bubbles: true }));
    }

    return [
        {
            name: 'UI: a server-backed productivity tool still POSTs to the server',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'loremIpsum');
                const form = w.document.querySelector('#toolForm form');
                submit(w, form);
                assert.equal(w.__xhrCalls, 1, 'server-backed tools must still POST to the server');
            }
        },
        {
            name: 'UI: removed QR tools are no longer in the tool definitions',
            fn() {
                const w = makeWindow();
                assert.equal(w.toolDefinitions.qrGenerator, undefined);
                assert.equal(w.toolDefinitions.qrScanner, undefined);
            }
        }
    ];
};
