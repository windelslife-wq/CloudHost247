<?php
/**
 * C-3: clientarea.tpl is executed with hostile values (escaping + href gate).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/eval_entry.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) {
            $pass++;
        } else {
            $fail++;
            echo "FAIL: $label\n";
        }
    }
    function render_tpl(array $vars)
    {
        extract($vars);
        ob_start();
        include dirname(__DIR__) . '/templates/clientarea.tpl';
        return ob_get_clean();
    }
    $XSS = '"><script>alert(1)</script>';

    echo "== template escaping ==\n";
    $html = render_tpl(['error' => 'boom ' . $XSS, 'serviceid' => 11]);
    check('error escaped', strpos($html, $XSS) === false && strpos($html, 'boom &quot;') !== false);

    $order = [
        'status' => 'pending' . $XSS, 'smm_order_id' => '7' . $XSS, 'smm_service_id' => '1',
        'quantity' => 500, 'link' => 'https://social.example/u1', 'start_count' => '0' . $XSS,
        'remains' => 500, 'last_check' => '', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
    ];
    $html = render_tpl(['order' => $order, 'status_color' => 'warning', 'serviceid' => 11]);
    check('no raw payload in page', strpos($html, $XSS) === false);
    check('status escaped after ucfirst', strpos($html, 'Pending&quot;') !== false);
    check('panel ids escaped', strpos($html, '7&quot;') !== false);
    check('legit link clickable', strpos($html, '<a href="https://social.example/u1"') !== false);
    check('link has noopener', strpos($html, 'rel="noopener"') !== false);
    check('empty last check labelled', strpos($html, 'Pending first check') !== false);

    $order['link'] = 'javascript:alert(1)';
    $html = render_tpl(['order' => $order, 'status_color' => 'warning', 'serviceid' => 11]);
    check('javascript: link not linked', strpos($html, 'href="javascript:') === false && strpos($html, 'javascript:alert(1)') !== false);

    $order['link'] = 'https://social.example/x" onmouseover="alert(1)';
    $html = render_tpl(['order' => $order, 'status_color' => 'warning', 'serviceid' => 11]);
    check('href attribute cannot break out', strpos($html, '" onmouseover="') === false && strpos($html, '&quot; onmouseover=&quot;') !== false);

    echo "\ntemplate: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
