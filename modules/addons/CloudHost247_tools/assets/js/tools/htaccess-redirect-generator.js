/**
 * CloudHost247 Tools - HTACCESS Redirect Generator
 *
 * Route-split module: this file is loaded only on /tools/htaccess-redirect-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "htaccess-redirect-generator",
            exec: "client",
            fields: [{"name": "redirect_type", "label": "Redirect type", "type": "select", "value": "301 permanent", "options": [{"value": "301 permanent", "label": "301 permanent"}, {"value": "302 temporary", "label": "302 temporary"}, {"value": "www to non-www", "label": "www to non-www"}, {"value": "non-www to www", "label": "non-www to www"}, {"value": "http to https", "label": "http to https"}]}, {"name": "from", "label": "Old path", "placeholder": "/old-page", "width": "full"}, {"name": "to", "label": "New destination", "placeholder": "/new-page", "width": "full"}],
            run: function (v) {
              var type = v.redirect_type || '301 permanent';
              var from = String(v.from || '').trim();
              var to = String(v.to || '').trim();
              var out = [], explain = '';
              var head = ['<IfModule mod_rewrite.c>', 'RewriteEngine On'];
              if (type === 'www to non-www') {
                out = head.concat(['RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]',
                  'RewriteRule ^ https://%1%{REQUEST_URI} [R=301,L]'], ['</IfModule>']);
                explain = 'Sends every www request to the bare domain, preserving the path and query string, and upgrades to HTTPS at the same time.';
              } else if (type === 'non-www to www') {
                out = head.concat(['RewriteCond %{HTTP_HOST} !^www\\. [NC]',
                  'RewriteCond %{HTTP_HOST} ^(.+)$', 'RewriteRule ^ https://www.%1%{REQUEST_URI} [R=301,L]'], ['</IfModule>']);
                explain = 'Sends every bare-domain request to www, preserving path and query string.';
              } else if (type === 'http to https') {
                out = head.concat(['RewriteCond %{HTTPS} off',
                  'RewriteCond %{HTTP:X-Forwarded-Proto} !https',
                  'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]'], ['</IfModule>']);
                explain = 'Forces HTTPS. The X-Forwarded-Proto condition prevents a redirect loop when the site sits behind a load balancer or CDN that terminates TLS.';
              } else {
                if (!from || !to) { return { error: 'Enter both the old path and the new destination.' }; }
                var code = type.indexOf('302') === 0 ? 302 : 301;
                var fromPath = from.replace(/^https?:\/\/[^/]+/, '').replace(/^\/+/, '');
                out = head.concat(['RewriteRule ^' + fromPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/?$ ' + to + ' [R=' + code + ',L,NE]'], ['</IfModule>']);
                explain = code === 301
                  ? 'A 301 is permanent: search engines transfer ranking signals and browsers cache it aggressively. Use it when the move is final.'
                  : 'A 302 is temporary: search engines keep the old URL indexed. Use it only while the move really is temporary.';
              }
              var txt = out.join('\n') + '\n';
              return {
                redirect_type: type, htaccess: txt, explanation: explain,
                install_path: 'Add to the .htaccess file in your site root, above any WordPress or framework rules.',
                note: 'Requires Apache with mod_rewrite and AllowOverride enabled. Test with curl -I before relying on it. Browsers cache 301 responses hard \u2014 use a private window when testing, or you may keep seeing an old redirect.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre', text: d.htaccess }));
              wrap.appendChild(CH.kvList(d, ['redirect_type', 'explanation', 'install_path']));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.htaccess; },
            submitLabel: "Run"
        });
    });
})(window, document);
