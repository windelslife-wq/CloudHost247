<?php
/**
 * C-4: VS-3 output escaping across every HTML builder + the console launcher.
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
    $lib = 'WHMCS\\Module\\Server\\SoyoustartVps\\SoyoustartServer';
    $XSS = '"><script>alert(1)</script>';
    $LANG = array_fill_keys(['serviceInfo', 'vpsservide_name', 'vpsservide_ipaddress',
        'vpsservide_cluster', 'vpsservide_netboot', 'vpsservide_zone', 'vpsservide_state',
        'vpsservide_vcore', 'vpsservide_offtertype', 'vpsservide_slamonitoring',
        'monitoring_enable', 'monitoring_disable', 'vpsservide_reverse_address',
        'vpsservide_memory_limit', 'vpsservide_disk', 'service_monitoring', 'status_ssh',
        'status_dns', 'status_tools', 'status_smtp', 'status_https', 'status_http',
        'status_ping', 'status_down', 'status_up', 'disk', 'disk_id', 'type', 'disk_size',
        'disk_bandwidth', 'disk_threshhold', 'disk_state', 'disk_connected',
        'disk_disconnected', 'disk_pending', 'reverse', 'save_change', 'close',
        'vps_ip_head', 'vps_ip', 'ipVersion', 'geo_location', 'mac_address', 'reverseDns',
        'create_date', 'status_descrition', 'snapshotMsg', 'snapshotBtn', 'snapshotCreateMsg',
        'snapshotCreateBtn', 'ip', 'firewall', 'action', 'btnCreateFirewall', 'ipDetailModalIp',
        'ipDetailModalVersion', 'ipDetailModalDesc', 'ipDetailModalCampus', 'ipDetailModalResion',
        'ipDetailModalType', 'ipDetailModalMacaddress', 'ipDetailModalFirewall'], 'L');

    echo "== escapeHtml unit ==\n";
    check('script tags neutralised', $lib::escapeHtml($XSS) === '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;');
    check('single quotes escaped', $lib::escapeHtml("a'b") === 'a&#039;b');
    check('ampersands escaped', $lib::escapeHtml('a&b') === 'a&amp;b');
    check('plain text untouched', $lib::escapeHtml('vps-123') === 'vps-123');
    check('integers stringify', $lib::escapeHtml(42) === '42');

    echo "== serviceInfoHtml ==\n";
    $o = new $lib();
    $html = $o->serviceInfoHtml(['httpcode' => 500, 'result' => (object) ['message' => 'boom ' . $XSS]], $LANG, '', true);
    check('error message escaped', strpos($html, '&lt;script&gt;') !== false && strpos($html, $XSS) === false);
    $info = (object) [
        'name' => 'srv' . $XSS, 'cluster' => 'c' . $XSS, 'netbootMode' => 'local', 'zone' => 'z' . $XSS,
        'state' => 'ok', 'slaMonitoring' => true, 'memoryLimit' => 2048,
        'model' => (object) ['vcore' => '2' . $XSS, 'disk' => '40'],
        'offerType' => 'ssd' . $XSS,
    ];
    $html = $o->serviceInfoHtml(['httpcode' => 200, 'result' => $info], $LANG, 'host' . $XSS, true);
    check('no raw payload in card', strpos($html, $XSS) === false);
    check('hostname override escaped', strpos($html, 'Host&quot;&gt;') !== false);
    check('ucwords applied before escaping', strpos($html, 'Srv&quot;') !== false || strpos($html, 'Host&quot;') !== false);
    check('memory GB math intact', strpos($html, '2GB') !== false);
    $info->memoryLimit = '0' . $XSS;
    $html = $o->serviceInfoHtml(['httpcode' => 200, 'result' => $info], $LANG, '', true);
    check('non-numeric memory escaped not executed', strpos($html, $XSS) === false && strpos($html, 'MB') !== false);

    echo "== serviceMonitoringHtml / diskInfoHtml ==\n";
    $html = $o->serviceMonitoringHtml(['httpcode' => 500, 'result' => (object) ['message' => $XSS]], $LANG, true);
    check('monitoring error escaped', strpos($html, $XSS) === false);
    $html = $o->diskInfoHtml([], 'disk ' . $XSS, $LANG, true);
    check('disk error escaped', strpos($html, $XSS) === false);
    $row = (object) ['id' => '1' . $XSS, 'type' => 'ssd', 'size' => 40, 'bandwidthLimit' => 100, 'lowFreeSpaceThreshold' => 10, 'state' => 'connected'];
    $html = $o->diskInfoHtml([$row], '', $LANG, true);
    check('disk row escaped', strpos($html, $XSS) === false && strpos($html, '1&quot;') !== false);

    echo "== ipInfoHtml ==\n";
    $html = $o->ipInfoHtml([], 'ip ' . $XSS, $LANG, true);
    check('ip error escaped', strpos($html, $XSS) === false);
    $ip = (object) ['ipAddress' => '1.2.3.4', 'version' => 'v4', 'type' => 'failover', 'geolocation' => 'CA', 'macAddress' => '', 'reverse' => 'rev' . $XSS];
    $html = $o->ipInfoHtml([$ip], '', $LANG, true);
    check('reverse (client-set) escaped', strpos($html, $XSS) === false && strpos($html, 'rev&quot;') !== false);
    check('empty mac renders dash', strpos($html, '<td> - </td>') !== false);
    $html = $o->ipInfoHtml([], '', $LANG, false);
    check('modal ships static empty data-ipAddress', strpos($html, 'data-ipAddress=""') !== false);

    echo "== snapshot html ==\n";
    $snap = ['httpcode' => 200, 'result' => (object) ['creationDate' => '2026-01-02T03:04:05+00:00', 'region' => 'r' . $XSS, 'description' => 'd' . $XSS]];
    $html = $o->cretateSnapshotHtml($snap, [], $LANG);
    check('snapshot region/desc escaped', strpos($html, $XSS) === false && strpos($html, 'd&quot;&gt;') !== false);
    check('snapshot date renders', strpos($html, '2026-01-02') !== false);
    $order = ['httpcode' => 500, 'result' => (object) ['message' => 'ord' . $XSS]];
    $html = $o->cretateSnapshotHtml(['httpcode' => 404, 'result' => (object) []], $order, $LANG);
    check('snapshot order error escaped', strpos($html, $XSS) === false);

    echo "== ip list / details html ==\n";
    $api = new \SoyoustartVpsTest\FakeApiCall();
    $api->getResult = ['httpcode' => 200, 'result' => (object) ['ipAddress' => '1.2.3.4', 'reverse' => 'v' . $XSS, 'version' => 'v4']];
    $api->detailsResult = ['httpcode' => 200, 'result' => (object) ['ip' => '1.2.3.0/24', 'description' => 'e' . $XSS, 'mitigationIp' => '9.9.9.9', 'firewallDetails' => (object) ['enabled' => true]]];
    $html = $o->createIpListHtml(['1.2.3.4'], $api, 'canada', 'vps-x', 7, $LANG);
    check('ip list reverse/desc escaped', strpos($html, $XSS) === false && strpos($html, 'v&quot;') !== false);
    check('firewall toggle renders when enabled', strpos($html, 'firewallEnableDisable') !== false);
    $html = $o->createIpListHtml([], $api, '', '', '', $LANG);
    check('empty ip list warns', strpos($html, 'No IPs assing with the server!') !== false);
    $detail = ['httpcode' => 200, 'result' => (object) ['version' => 'v4', 'macaddress' => '']];
    $fw = ['httpcode' => 200, 'result' => (object) ['ip' => '1.2.3.4', 'description' => 'w' . $XSS, 'campus' => 'c', 'regions' => ['r'], 'type' => 'failover', 'firewallDetails' => (object) ['enabled' => false]]];
    $html = $o->createIpDetailsHtmlvps($detail, $fw, $LANG);
    check('ip details desc escaped', strpos($html, $XSS) === false && strpos($html, 'w&quot;') !== false);
    check('missing mac adddress labelled', strpos($html, 'No Mac Address') !== false);
    $html = $o->createIpDetailsHtmlvps(['httpcode' => 500, 'result' => (object) ['message' => $XSS]], $fw, $LANG);
    check('ip details error escaped', strpos($html, $XSS) === false);
    $html = $o->createDiskListHtml([$row], $LANG);
    check('client disk list escaped', strpos($html, $XSS) === false);

    echo "== console launcher ==\n";
    $evil = 'https://console.example/"onmouseover="alert(1)';
    $script = soyoustart_vps_consoleScript($evil);
    check('console url json-encoded', $script === '<script>window.open(' . json_encode($evil) . ')</script>');
    check('quotes backslash-escaped inside js string', strpos($script, '\\"onmouseover=') !== false);
    check('single script element', substr_count($script, '<script>') === 1 && substr_count($script, '</script>') === 1);

    echo "\nescape: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
