/**
 * CloudHost247 Tools - Robots.txt Generator
 *
 * Route-split module: this file is loaded only on /tools/robots-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "robots-generator",
            exec: "client",
            fields: [{"name": "rules", "label": "Rules", "type": "textarea", "placeholder": "One directive per line", "width": "full", "spellcheck": false}, {"name": "preset", "label": "Preset", "type": "select", "value": "allow_all", "options": [{"value": "allow_all", "label": "Allow everything"}, {"value": "block_all", "label": "Block everything"}, {"value": "wordpress", "label": "WordPress"}, {"value": "whmcs", "label": "WHMCS"}]}, {"name": "sitemap", "label": "Sitemap URL", "type": "url", "placeholder": "https://example.com/sitemap.xml"}],
            run: function (v) {
              var preset = v.preset || 'allow_all';
              var sitemap = String(v.sitemap || '').trim();
              var custom = String(v.rules || '').trim();
              var lines = [];
              if (preset === 'allow_all') {
                lines.push('User-agent: *', 'Disallow:');
              } else if (preset === 'block_all') {
                lines.push('User-agent: *', 'Disallow: /');
              } else if (preset === 'wordpress') {
                lines.push('User-agent: *', 'Disallow: /wp-admin/', 'Allow: /wp-admin/admin-ajax.php',
                  'Disallow: /wp-includes/', 'Disallow: /?s=', 'Disallow: /search/');
              } else if (preset === 'whmcs') {
                lines.push('User-agent: *', 'Disallow: /admin/', 'Disallow: /clientarea.php', 'Disallow: /cart.php',
                  'Disallow: /viewinvoice.php', 'Disallow: /dl.php', 'Disallow: /download.php',
                  'Disallow: /submitticket.php', 'Disallow: /viewticket.php', 'Disallow: /pwreset.php',
                  'Disallow: /register.php', 'Disallow: /login.php', 'Disallow: /logout.php');
              } else {
                lines.push('User-agent: *', 'Disallow:');
              }
              if (custom) { lines.push('', '# Custom rules'); custom.split('\n').forEach(function (l) { if (l.trim()) { lines.push(l.trim()); } }); }
              if (sitemap) {
                if (!/^https?:\/\//i.test(sitemap)) { return { error: 'The sitemap URL must be absolute and start with http:// or https://' }; }
                lines.push('', 'Sitemap: ' + sitemap);
              }
              var txt = lines.join('\n') + '\n';
              return {
                preset: preset, robots_txt: txt, lines: lines.length, bytes: txt.length,
                install_path: 'Upload as /robots.txt in your web root \u2014 it must be at the domain root to be honoured.',
                note: 'robots.txt is a crawling directive, not an access control. Well-behaved crawlers obey it; malicious ones ignore it entirely, and a Disallow line publicly advertises the path. Never use it to hide sensitive URLs \u2014 use authentication. To keep a page out of search results use a noindex meta tag, because a disallowed page can still be indexed from external links.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre', text: d.robots_txt }));
              wrap.appendChild(CH.kvList(d, ['preset', 'lines', 'bytes', 'install_path']));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.robots_txt; },
            downloadText: function (d) { return d.robots_txt; },
            submitLabel: "Run Robots.txt Generator"
        });
    });
})(window, document);
