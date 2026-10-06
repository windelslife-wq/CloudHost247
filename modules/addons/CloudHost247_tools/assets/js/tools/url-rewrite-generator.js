/**
 * CloudHost247 Tools - URL Rewrite Generator
 *
 * Route-split module: this file is loaded only on /tools/url-rewrite-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "url-rewrite-generator",
            exec: "client",
            fields: [{"name": "url", "label": "URL", "type": "url", "placeholder": "https://example.com/page", "width": "full", "required": true, "spellcheck": false}],
            run: function (v) {
              var raw = String(v.url || '').trim();
              if (!raw) { return { error: 'Enter a dynamic URL, for example /product.php?id=12&cat=shoes' }; }
              var qIndex = raw.indexOf('?');
              if (qIndex === -1) { return { error: 'That URL has no query string, so there is nothing to rewrite. Include the ?key=value part.' }; }
              var path = raw.slice(0, qIndex).replace(/^https?:\/\/[^/]+/, '');
              var query = raw.slice(qIndex + 1);
              var params = query.split('&').map(function (p) { return p.split('=')[0]; }).filter(Boolean);
              if (!params.length) { return { error: 'No query parameters found.' }; }
              var script = path.replace(/^\/+/, '') || 'index.php';
              var base = script.replace(/\.(php|asp|aspx|jsp|html?)$/i, '');
              var pretty = '/' + base + '/' + params.map(function () { return 'value'; }).join('/');
              var apache = ['<IfModule mod_rewrite.c>', 'RewriteEngine On',
                'RewriteRule ^' + base + '/' + params.map(function () { return '([^/]+)'; }).join('/') + '/?$ ' +
                script + '?' + params.map(function (p, i) { return p + '=$' + (i + 1); }).join('&') + ' [L,QSA]',
                '</IfModule>'].join('\n');
              var nginx = 'location ~ ^/' + base + '/' + params.map(function () { return '([^/]+)'; }).join('/') + '/?$ {\n' +
                '    try_files $uri /' + script + '?' + params.map(function (p, i) { return p + '=$' + (i + 1); }).join('&') + ';\n}';
              return {
                original_url: raw, parameters: params.join(', '),
                pretty_url: pretty,
                apache_htaccess: apache,
                nginx_config: nginx,
                note: 'Replace each "value" placeholder with a real slug. Add a canonical link tag on the pretty URL and 301 the old query-string URL to it, otherwise search engines will see duplicate content at two addresses.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.kvList(d, ['original_url', 'parameters', 'pretty_url']));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Apache (.htaccess)' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre', text: d.apache_htaccess }));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Nginx' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre', text: d.nginx_config }));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.apache_htaccess; },
            submitLabel: "Run URL Rewrite Generator"
        });
    });
})(window, document);
