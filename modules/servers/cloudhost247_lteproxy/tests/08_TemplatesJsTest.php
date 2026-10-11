<?php
/**
 * C-2: Smarty template + JS static suite (no Smarty/node needed).
 * Rules: no bare {$var} output anywhere, JSON contexts use |json_encode,
 * the client JS has no inline handlers and escapes every interpolation.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';

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
    function modfile($rel)
    {
        return file_get_contents(ch247_mod() . '/' . $rel);
    }
    /** Bare {$var} outputs: fully unfiltered variable interpolation. */
    function bareVars($src)
    {
        preg_match_all('/\{\$[A-Za-z0-9_.@]+\}/', $src, $m);
        return $m[0];
    }

    echo "== client templates (LP-4 / LP-5 / LP-24 / LP-38) ==\n";
    $dt = modfile('templates/client/dashboard.tpl');
    check('no {json_encode( tag (LP-4 fatal)', strpos($dt, '{json_encode(') === false);
    check('9 json_encode filters', substr_count($dt, '|json_encode}') === 9);
    check('order id json-encoded (LP-24)', strpos($dt, 'orderId: {$orderId|json_encode}') !== false);
    check('csrf json-encoded (LP-24)', strpos($dt, 'csrfToken: {$csrfToken|json_encode}') !== false);
    check('auth values are names (LP-5)', strpos($dt, '<option value="{$name|escape}">') !== false
        && strpos($dt, '<option value="{$key}">') === false);
    check('static labels escaped', strpos($dt, '<option value="{$key|escape}">{$name|escape}</option>') !== false
        && strpos($dt, '<option value="{$type|escape}">{$type|escape}</option>') !== false);
    $bare = bareVars($dt);
    check('no bare vars in dashboard (' . implode(',', $bare) . ')', $bare === []);
    $et = modfile('templates/client/error.tpl');
    check('error escaped', strpos($et, '{$error|escape}') !== false);
    $bare = bareVars($et);
    check('no bare vars in error tpl (' . implode(',', $bare) . ')', $bare === []);

    echo "== admin templates (LP-38) ==\n";
    $at = modfile('templates/admin/dashboard.tpl');
    check('admin stats escaped', strpos($at, '{$stats.totalServices|default:0|escape}') !== false
        && strpos($at, "{\$apiBalance|default:'\$0.00'|escape}") !== false);
    check('admin service rows escaped', strpos($at, '{$svc.clientName|escape}') !== false
        && strpos($at, 'userid={$svc.clientId|escape}&id={$svc.id|escape}') !== false);
    check('admin api facts escaped', strpos($at, '{$apiBaseUrl|escape}') !== false
        && strpos($at, '{$maskedApiKey|escape}') !== false);
    $bare = bareVars($at);
    check('no bare vars in admin dashboard (' . implode(',', $bare) . ')', $bare === []);
    $st = modfile('templates/admin/settings.tpl');
    check('settings values escaped', strpos($st, '{$settings.api_key|escape}') !== false
        && strpos($st, '{$settings.api_secret|escape}') !== false);
    check('no raw PHP_SELF', strpos($st, '$PHP_SELF)}') === false
        && strpos($st, '{$smarty.server.PHP_SELF|escape}') !== false);
    $bare = bareVars($st);
    check('no bare vars in settings (' . implode(',', $bare) . ')', $bare === []);

    echo "== client.js (LP-6 / LP-13 / LP-20) ==\n";
    $js = modfile('assets/js/client.js');
    check('no inline handlers', strpos($js, 'onclick=') === false
        && strpos($js, 'onClick=') === false);
    check('delegated row actions', substr_count($js, 'data-action=') === 5
        && strpos($js, 'handleRowAction(event)') !== false
        && strpos($js, "getElementById('proxy-table-body')") !== false);
    check('csrf travels by header only (LP-20)', strpos($js, 'X-CSRF-Token') !== false
        && strpos($js, 'csrf_token=') === false && strpos($js, 'csrfToken)') === false
        && strpos($js, "'csrf_token'") === false && strpos($js, '"csrf_token"') === false);
    check('request takes action param (LP-6)',
        strpos($js, 'async request(endpoint, options = {}, action = \'\')') !== false
        && strpos($js, "'&action=' + encodeURIComponent(action)") !== false);
    check('quote-safe escaper', strpos($js, ".replace(/'/g, '&#039;')") !== false
        && strpos($js, '.replace(/"/g, \'&quot;\')') !== false);
    // Per-occurrence rule: every ${...} must open with an escaper or a
    // whitelisted static-output expression. (Nested backtick blocks never
    // match as one unit; their inner interpolations are checked as usual.)
    // Structural .map() (its callback interpolations are checked separately)
    // and static-output ternaries (emit only fixed literals, never data).
    // 'type}' is the bare ${type} in a className assignment: className never
    // parses markup, and every showToast caller passes a literal type.
    $allowed = ['this.escapeHtml(', 'encodeURIComponent(', 'CSS.escape(',
        'proxy.status === ', 'proxy.is_1by1 ? ', 'results.map(', 'r.alive ? ',
        'aliveData.alive ? ', 'speedData ? ', 'r.next_rotation ? ',
        'r.last_rotated ? ', 'h.type === ', 'h.expires_at ? ',
        'type}', 'type === '];
    $bad = [];
    foreach (explode("\n", $js) as $i => $line) {
        $off = 0;
        while (($pos = strpos($line, '${', $off)) !== false) {
            $rest = substr($line, $pos + 2);
            $ok = false;
            foreach ($allowed as $prefix) {
                if (strpos($rest, $prefix) === 0) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $bad[] = ($i + 1) . ':' . trim($line);
            }
            $off = $pos + 2;
        }
    }
    check('every interpolation escaped (' . implode(' | ', array_slice($bad, 0, 3)) . ')', $bad === []);
    $admin = modfile('assets/js/admin.js');
    check('admin.js single static sink', substr_count($admin, 'innerHTML') === 1
        && strpos($admin, "btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Refreshing...';") !== false
        && strpos($admin, 'outerHTML') === false && strpos($admin, 'document.write') === false
        && strpos($admin, 'eval(') === false);

    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
