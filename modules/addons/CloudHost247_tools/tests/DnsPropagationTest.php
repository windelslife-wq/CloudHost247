<?php
/**
 * CloudHost247 Tools - DNS Propagation Checker: wire format, classification,
 * and input handling. Offline: no network is used.
 *
 * The response bytes are real captures from public resolvers; the expected
 * records were decoded by a separate Python implementation, so this suite is
 * not checking the PHP parser against its own output.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/DnsPropagation.php';
require __DIR__ . '/../includes/tools/dns_tools.php';

$pass = 0;
$fail = 0;
function ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL $label\n"; }
}
function eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n"; }
}
function hexbin($hex) { return hex2bin($hex); }
function idOf($hex) { return hexdec(substr($hex, 0, 4)); }

// Captured from 8.8.8.8 over UDP (and one TCP reply), then parsed by an independent Python decoder.
$fx = [];
$fx['example_com_A'] = ['hex' => 'ae9c81800001000200000000076578616d706c6503636f6d0000010001c00c000100010000012c0004ac4293f3c00c000100010000012c00046814179a', 'rcode' => 0, 'truncated' => false, 'records' => ['172.66.147.243', '104.20.23.154'], 'cname' => []];
$fx['example_com_NS'] = ['hex' => '1fa181800001000200000000076578616d706c6503636f6d0000020001c00c0002000100005460001807656c6c696f7474026e730a636c6f7564666c617265c014c00c000200010000546000070468657261c031', 'rcode' => 0, 'truncated' => false, 'records' => ['elliott.ns.cloudflare.com', 'hera.ns.cloudflare.com'], 'cname' => []];
$fx['gmail_com_MX'] = ['hex' => 'f5248180000100050000000005676d61696c03636f6d00000f0001c00c000f0001000000040020002804616c74340d676d61696c2d736d74702d696e016c06676f6f676c65c012c00c000f00010000000400040005c02ec00c000f0001000000040009001e04616c7433c02ec00c000f0001000000040009000a04616c7431c02ec00c000f0001000000040009001404616c7432c02e', 'rcode' => 0, 'truncated' => false, 'records' => ['40 alt4.gmail-smtp-in.l.google.com', '5 gmail-smtp-in.l.google.com', '30 alt3.gmail-smtp-in.l.google.com', '10 alt1.gmail-smtp-in.l.google.com', '20 alt2.gmail-smtp-in.l.google.com'], 'cname' => []];
$fx['google_com_TXT'] = ['hex' => '31ed8380000100000000000006676f6f676c6503636f6d0000100001', 'rcode' => 0, 'truncated' => true, 'records' => [], 'cname' => []];
$fx['google_com_AAAA'] = ['hex' => '82498180000100040000000006676f6f676c6503636f6d00001c0001c00c001c00010000012c00102607f8b0400e0c080000000000000071c00c001c00010000012c00102607f8b0400e0c08000000000000008ac00c001c00010000012c00102607f8b0400e0c08000000000000008bc00c001c00010000012c00102607f8b0400e0c080000000000000064', 'rcode' => 0, 'truncated' => false, 'records' => ['2607:f8b0:400e:c08::71', '2607:f8b0:400e:c08::8a', '2607:f8b0:400e:c08::8b', '2607:f8b0:400e:c08::64'], 'cname' => []];
$fx['nonexistent_label_test_7f3a_example_com_A'] = ['hex' => '7cf1818000010000000100001b6e6f6e6578697374656e742d6c6162656c2d746573742d37663361076578616d706c6503636f6d0000010001c0280006000100000708003207656c6c696f7474026e730a636c6f7564666c617265c03003646e73c0509006f398000027100000096000093a8000000708', 'rcode' => 0, 'truncated' => false, 'records' => [], 'cname' => []];
$fx['www_github_com_A'] = ['hex' => '0ca681800001000200000000037777770667697468756203636f6d0000010001c00c0005000100000c130002c010c010000100010000003c0004141d8617', 'rcode' => 0, 'truncated' => false, 'records' => ['20.29.134.23'], 'cname' => ['github.com']];
$fx['cloudflare_com_TXT'] = ['hex' => '041a838000010000000000000a636c6f7564666c61726503636f6d0000100001', 'rcode' => 0, 'truncated' => true, 'records' => [], 'cname' => []];
$fx['github_com_TXT'] = ['hex' => 'e44f838000010000000000000667697468756203636f6d0000100001', 'rcode' => 0, 'truncated' => true, 'records' => [], 'cname' => []];
$fx['qzxv_no_such_host_41b7_com_A'] = ['hex' => 'eb148183000100000001000016717a78762d6e6f2d737563682d686f73742d3431623703636f6d0000010001c0230006000100000384003d01610c67746c642d73657276657273036e657400056e73746c640c766572697369676e2d677273c0236ac9f3f9000007080000038400093a8000000384', 'rcode' => 3, 'truncated' => false, 'records' => [], 'cname' => []];
$fx['tcp_cloudflare_TXT'] = ['hex' => '432181800001001d000000000a636c6f7564666c61726503636f6d0000100001c00c0010000100000128003b3a6d69726f2d766572696669636174696f6e3d62646437646661306134396164666234336164366464666166373937363333323436633037333536c00c0010000100000128002b2a6170706c652d646f6d61696e2d766572696669636174696f6e3d444e6e574a6f41724a6f62464a4b684ac00c00100001000001280047466c69766572616d702d736974652d766572696669636174696f6e3d456848314d716777626e6454576c31414e3634684f544b7a3768633173383079557063684c626770665930c00c00100001000001280023225a4f4f4d5f7665726966795f374c4642764f4f3953496967797046473278526c4d41c00c0010000100000128005b5a64726966742d646f6d61696e2d766572696669636174696f6e3d66303337383038613236616538623235626331336231663166326234633365306637386330336536376632346365666464346563353230656661386537313966c00c0010000100000128000e0d4d533d6d733730323734313834c00c0010000100000128002e2d6a616d662d736974652d766572696669636174696f6e3d632d655576484262686746784d756c4653592d514a51c00c0010000100000128003938646f636b65722d766572696669636174696f6e3d63353738653231632d333466622d343437342d396239302d643535656534636261313063c00c0010000100000128003e3d756265722d646f6d61696e2d766572696669636174696f6e3d35383038363033392d313530612d343261342d613462652d623430333239323161613066c00c00100001000001280055547374726970652d766572696669636174696f6e3d35303936643031666632636631393432383564643531636165313866323466613963323664633932386365626163333633366434363262346336393235363233c00c00100001000001280055547374726970652d766572696669636174696f6e3d62663161393465366231366163653235303261346137666666353734613235633861343532393130353439363063383833633539626533396431373838646239c00c0010000100000128002f2e63616e76612d736974652d766572696669636174696f6e3d6f4f7961566e48432d4f69466f523142507665744e41c00c0010000100000128005e5d636973636f2d63692d646f6d61696e2d766572696669636174696f6e3d32376539323638383436313938303465663938376165346161316334313638663662313532616461383466346338626663373465623262643239313261643732c00c0010000100000128003f3e6c6f676d65696e2d766572696669636174696f6e2d636f64653d62333433336338362d333832332d343830382d386137652d353830343234363966363534c00c00100001000001280095945f73616d6c2d646f6d61696e2d6368616c6c656e67652e32646330303430352d373963642d343537622d623238382d6131313963366630633762372e37313939366435332d643137382d346261392d626566342d3766376534366564616237342e636c6f7564666c6172652e636f6d3d31633837333666642d383462322d343139372d393835662d336662323835326632343537c00c00100001000001280025246173763d3839346636643166396638336263663434653462316263343062633163346161c00c0010000100000128004544676f6f676c652d736974652d766572696669636174696f6e3d5a646c515a4c424241506b78654654434d31727069425f6962744766665f4a46354b6c6c4e4b7744523949c00c001000010000012800cecd763d73706631206970343a3139392e31352e3231322e302f3232206970343a3137332e3234352e34382e302f323020696e636c7564653a5f7370662e676f6f676c652e636f6d20696e636c7564653a737066312e6d6373762e6e657420696e636c7564653a7370662e6d616e6472696c6c6170702e636f6d20696e636c7564653a6d61696c2e7a656e6465736b2e636f6d20696e636c7564653a73747370672d637573746f6d65722e636f6d20696e636c7564653a5f7370662e73616c6573666f7263652e636f6d202d616c6cc00c00100001000001280021205f6e65716d6b676171316c71396974357338716d65747268626e753132317762c00c0010000100000128009b9a4469726563744665644175746855726c3d68747470733a2f2f636c6f7564666c6172652d73656375726974792e636c6f7564666c6172656163636573732e636f6d2f63646e2d6367692f6163636573732f73736f2f73616d6c2f65626563393333373733633639633933343230643133653637373661646238633461313930663632383166396431333262636562643764636230393637626464c00c0010000100000128003e3d6f6e6574727573742d646f6d61696e2d766572696669636174696f6e3d6264356364303861316539363434373939666462393865643764363063396362c00c001000010000012800424163726561746f70792d646f6d61696e2d766572696669636174696f6e3d39376432636135302d396236662d346132312d396264622d666262363330653463656337c00c0010000100000128009b9a4469726563744665644175746855726c3d68747470733a2f2f636c6f7564666c6172652d73656375726974792e636c6f7564666c6172656163636573732e636f6d2f63646e2d6367692f6163636573732f73736f2f73616d6c2f64626136373536616433313266633133633435663730356366376635653837663464363538626530313663343166393530333239616134303835623361626331c00c0010000100000128002d2c7374617475732d706167652d646f6d61696e2d766572696669636174696f6e3d7231346672776c6a77627873c00c0010000100000128005f5e61746c61737369616e2d646f6d61696e2d766572696669636174696f6e3d5778784b794e39614c6e6a45736f4f6a5559493654306262357663716d4b7a61496b4339527832516b4e6237353147334c4c2f637573382f5a444f6768387842c00c00100001000001280021205f776b6a6330666f74306437717276726474373862786b6a3265326f36376432c00c0010000100000128003e3d6461746162616e6b2d646f6d61696e2d766572696669636174696f6e2d686b656864323d667a6775346b6d625a774d6f5739397a454e674f3475384e4cc00c0010000100000128003c3b66616365626f6f6b2d646f6d61696e2d766572696669636174696f6e3d68396d6d367a6f706a367032706f3534776f6131366d3562736b6d366f6fc00c0010000100000128004544676f6f676c652d736974652d766572696669636174696f6e3d43377468664e65585661686b56686e6969715449316953566e456c4b525f6b4242746e45486b6547446c6f', 'tid' => 17185, 'rcode' => 0, 'truncated' => false, 'records' => ['miro-verification=bdd7dfa0a49adfb43ad6ddfaf797633246c07356', 'apple-domain-verification=DNnWJoArJobFJKhJ', 'liveramp-site-verification=EhH1MqgwbndTWl1AN64hOTKz7hc1s80yUpchLbgpfY0', 'ZOOM_verify_7LFBvOO9SIigypFG2xRlMA', 'drift-domain-verification=f037808a26ae8b25bc13b1f1f2b4c3e0f78c03e67f24cefdd4ec520efa8e719f', 'MS=ms70274184', 'jamf-site-verification=c-eUvHBbhgFxMulFSY-QJQ', 'docker-verification=c578e21c-34fb-4474-9b90-d55ee4cba10c', 'uber-domain-verification=58086039-150a-42a4-a4be-b4032921aa0f', 'stripe-verification=5096d01ff2cf194285dd51cae18f24fa9c26dc928cebac3636d462b4c6925623', 'stripe-verification=bf1a94e6b16ace2502a4a7fff574a25c8a45291054960c883c59be39d1788db9', 'canva-site-verification=oOyaVnHC-OiFoR1BPvetNA', 'cisco-ci-domain-verification=27e926884619804ef987ae4aa1c4168f6b152ada84f4c8bfc74eb2bd2912ad72', 'logmein-verification-code=b3433c86-3823-4808-8a7e-58042469f654', '_saml-domain-challenge.2dc00405-79cd-457b-b288-a119c6f0c7b7.71996d53-d178-4ba9-bef4-7f7e46edab74.cloudflare.com=1c8736fd-84b2-4197-985f-3fb2852f2457', 'asv=894f6d1f9f83bcf44e4b1bc40bc1c4aa', 'google-site-verification=ZdlQZLBBAPkxeFTCM1rpiB_ibtGff_JF5KllNKwDR9I', 'v=spf1 ip4:199.15.212.0/22 ip4:173.245.48.0/20 include:_spf.google.com include:spf1.mcsv.net include:spf.mandrillapp.com include:mail.zendesk.com include:stspg-customer.com include:_spf.salesforce.com -all', '_neqmkgaq1lq9it5s8qmetrhbnu121wb', 'DirectFedAuthUrl=https://cloudflare-security.cloudflareaccess.com/cdn-cgi/access/sso/saml/ebec933773c69c93420d13e6776adb8c4a190f6281f9d132bcebd7dcb0967bdd', 'onetrust-domain-verification=bd5cd08a1e9644799fdb98ed7d60c9cb', 'creatopy-domain-verification=97d2ca50-9b6f-4a21-9bdb-fbb630e4cec7', 'DirectFedAuthUrl=https://cloudflare-security.cloudflareaccess.com/cdn-cgi/access/sso/saml/dba6756ad312fc13c45f705cf7f5e87f4d658be016c41f950329aa4085b3abc1', 'status-page-domain-verification=r14frwljwbxs', 'atlassian-domain-verification=WxxKyN9aLnjEsoOjUYI6T0bb5vcqmKzaIkC9Rx2QkNb751G3LL/cus8/ZDOgh8xB', '_wkjc0fot0d7qrvrdt78bxkj2e2o67d2', 'databank-domain-verification-hkehd2=fzgu4kmbZwMoW99zENgO4u8NL', 'facebook-domain-verification=h9mm6zopj6p2po54woa16m5bskm6oo', 'google-site-verification=C7thfNeXVahkVhniiqTI1iSVnElKR_kBBtnEHkeGDlo'], 'cname' => []];
$queryHex = '123401000001000000000000076578616d706c6503636f6d0000010001'; // python-built: example.com A, id 0x1234

// ---------- resolver list ----------
$resolvers = CloudHost247_dns_resolvers();
eq('ten resolvers', count($resolvers), 10);
$ips = array_column($resolvers, 'ip');
eq('resolver IPs are unique', count(array_unique($ips)), 10);
ok('every resolver IP is IPv4', count(array_filter($ips, function ($ip) { return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false; })) === 10);

// ---------- supported types and admin list ----------
eq('supported types', array_keys(CloudHost247_dns_qtypes()), ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT']);
eq('type list: drops unknown, de-duplicates, upper-cases', CloudHost247_dns_parse_type_list('a, mx, bogus, SOA, MX'), ['A', 'MX']);
eq('type list: empty falls back to all', CloudHost247_dns_parse_type_list(''), ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT']);
eq('type list: nothing valid falls back to all', CloudHost247_dns_parse_type_list('soa,ptr,any'), ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT']);

// ---------- query builder ----------
$built = CloudHost247_dns_build_query('example.com', 'A', 0x1234);
eq('query bytes match the python-built packet', bin2hex((string) $built), $queryHex);
ok('query carries RD and one question', substr($built, 2, 2) === "\x01\x00" && substr($built, 4, 2) === "\x00\x01");
eq('builder: unsupported type refused', CloudHost247_dns_build_query('example.com', 'SOA', 1), null);
eq('builder: empty name refused', CloudHost247_dns_build_query('', 'A', 1), null);
eq('builder: empty label refused', CloudHost247_dns_build_query('a..b', 'A', 1), null);
eq('builder: 64-char label refused', CloudHost247_dns_build_query(str_repeat('a', 64) . '.com', 'A', 1), null);
eq('builder: 63-char label accepted', CloudHost247_dns_build_query(str_repeat('a', 63) . '.com', 'A', 1) !== null, true);
eq('builder: name over 253 refused', CloudHost247_dns_build_query(str_repeat('abcdefghi.', 30) . 'com', 'A', 1), null);
eq('builder: bad id refused', CloudHost247_dns_build_query('example.com', 'A', 70000), null);
eq('builder: space in name refused', CloudHost247_dns_build_query('exa mple.com', 'A', 1), null);
ok('builder: underscore label allowed (_dmarc)', CloudHost247_dns_build_query('_dmarc.example.com', 'TXT', 1) !== null);

// ---------- parser: real captures ----------
$udpCases = [
    ['example_com_A', 'A'], ['example_com_NS', 'NS'], ['gmail_com_MX', 'MX'],
    ['google_com_AAAA', 'AAAA'], ['www_github_com_A', 'A'],
    ['nonexistent_label_test_7f3a_example_com_A', 'A'],
    ['qzxv_no_such_host_41b7_com_A', 'A'],
];
foreach ($udpCases as $case) {
    list($key, $type) = $case;
    $f = $fx[$key];
    $p = CloudHost247_dns_parse_response(hexbin($f['hex']), idOf($f['hex']), $type);
    eq("$key: parsed ok", $p['ok'], true);
    eq("$key: rcode", $p['rcode'], $f['rcode']);
    eq("$key: truncated flag", $p['truncated'], $f['truncated']);
    eq("$key: records", $p['records'], $f['records']);
    eq("$key: cname", $p['cname'], $f['cname']);
}
// TXT over UDP is truncated and carries no records: the caller must retry over TCP.
foreach (['github_com_TXT', 'cloudflare_com_TXT'] as $key) {
    $f = $fx[$key];
    $p = CloudHost247_dns_parse_response(hexbin($f['hex']), idOf($f['hex']), 'TXT');
    eq("$key: UDP reply is flagged truncated", $p['truncated'], true);
    eq("$key: no records in truncated UDP reply", $p['records'], []);
}
// The same TXT answer over TCP decodes to real multi-string records.
$t = $fx['tcp_cloudflare_TXT'];
$p = CloudHost247_dns_parse_response(hexbin($t['hex']), $t['tid'], 'TXT');
eq('tcp TXT: parsed ok', $p['ok'], true);
eq('tcp TXT: records decoded (multi-string joined)', $p['records'], $t['records']);
ok('tcp TXT: has records', count($p['records']) > 5);

// ---------- parser: refusals and malformed input ----------
$base = $fx['example_com_A'];
$raw = hexbin($base['hex']);
$id = idOf($base['hex']);
$p = CloudHost247_dns_parse_response($raw, $id + 1, 'A');
eq('wrong id is refused', $p, ['ok' => false, 'error' => 'id mismatch']);
$servfail = substr($raw, 0, 2) . "\x81\x82" . substr($raw, 4);
$p = CloudHost247_dns_parse_response($servfail, $id, 'A');
eq('SERVFAIL is an error, not an empty answer', $p, ['ok' => false, 'error' => 'resolver returned RCODE 2']);
$refused = substr($raw, 0, 2) . "\x81\x85" . substr($raw, 4);
eq('REFUSED is an error', CloudHost247_dns_parse_response($refused, $id, 'A')['ok'], false);
$query = substr($raw, 0, 2) . "\x01\x00" . substr($raw, 4);
eq('a query packet is not a response', CloudHost247_dns_parse_response($query, $id, 'A'), ['ok' => false, 'error' => 'not a response']);
eq('short packet refused', CloudHost247_dns_parse_response(substr($raw, 0, 8), $id, 'A')['ok'], false);
$cut = CloudHost247_dns_parse_response(substr($raw, 0, 60), $id, 'A');
eq('cut-off packet is malformed, not a crash', $cut['ok'], false);
// A compression pointer that points at itself must not loop forever.
$loop = pack('n6', 7, 0x8180, 1, 0, 0, 0) . "\xC0\x0C" . pack('nn', 1, 1);
eq('pointer loop is rejected', CloudHost247_dns_parse_response($loop, 7, 'A')['ok'], false);
// A pointer past the end of the packet is rejected.
$past = pack('n6', 9, 0x8180, 1, 0, 0, 0) . "\xC0\xFF" . pack('nn', 1, 1);
eq('pointer past end is rejected', CloudHost247_dns_parse_response($past, 9, 'A')['ok'], false);
$truncFlag = substr($raw, 0, 2) . "\x83\x80" . substr($raw, 4);
eq('TC flag is reported', CloudHost247_dns_parse_response($truncFlag, $id, 'A')['truncated'], true);
// NXDOMAIN is a real answer (the resolver knows the name is absent).
$nx = $fx['qzxv_no_such_host_41b7_com_A'];
eq('NXDOMAIN parses as rcode 3', CloudHost247_dns_parse_response(hexbin($nx['hex']), idOf($nx['hex']), 'A')['rcode'], 3);

// ---------- outcome mapping ----------
eq('outcome: records -> answer', CloudHost247_dns_outcome(['ok' => true, 'records' => ['1.2.3.4'], 'cname' => []], 'A'),
    ['outcome' => 'answer', 'records' => ['1.2.3.4'], 'error' => '']);
eq('outcome: empty -> no_records', CloudHost247_dns_outcome(['ok' => true, 'records' => [], 'cname' => []], 'MX')['outcome'], 'no_records');
eq('outcome: CNAME-only A shows the alias', CloudHost247_dns_outcome(['ok' => true, 'records' => [], 'cname' => ['github.com']], 'A'),
    ['outcome' => 'answer', 'records' => ['CNAME github.com'], 'error' => '']);
eq('outcome: CNAME-only MX is no_records', CloudHost247_dns_outcome(['ok' => true, 'records' => [], 'cname' => ['x.com']], 'MX')['outcome'], 'no_records');
eq('outcome: error carries the reason', CloudHost247_dns_outcome(['ok' => false, 'error' => 'id mismatch'], 'A'),
    ['outcome' => 'error', 'records' => [], 'error' => 'id mismatch']);

// ---------- classification ----------
function rows_of($answers, $none, $errors, $records = ['1.1.1.1'])
{
    $rows = [];
    for ($i = 0; $i < $answers; $i++) { $rows[] = ['outcome' => 'answer', 'records' => $records]; }
    for ($i = 0; $i < $none; $i++) { $rows[] = ['outcome' => 'no_records', 'records' => []]; }
    for ($i = 0; $i < $errors; $i++) { $rows[] = ['outcome' => 'error', 'records' => [], 'error' => 'timeout']; }
    return $rows;
}
$c = CloudHost247_dns_classify(rows_of(10, 0, 0));
eq('all identical -> propagated', $c['status'], 'propagated');
eq('propagated flag true', $c['propagated'], true);
eq('propagated counts', [$c['answering'], $c['responded'], $c['total']], [10, 10, 10]);
$c = CloudHost247_dns_classify(rows_of(6, 4, 0));
eq('some missing -> partial', $c['status'], 'partial');
eq('partial is not propagated', $c['propagated'], false);
eq('partial counts', [$c['answering'], $c['responded']], [6, 10]);
$c = CloudHost247_dns_classify(rows_of(0, 10, 0));
eq('nobody has it -> not_propagated', $c['status'], 'not_propagated');
$c = CloudHost247_dns_classify(rows_of(0, 0, 10));
eq('nobody reachable -> unknown', $c['status'], 'unknown');
ok('unknown never says propagated', $c['propagated'] === false);
$c = CloudHost247_dns_classify(array_merge(rows_of(5, 0, 0, ['1.1.1.1']), rows_of(5, 0, 0, ['2.2.2.2'])));
eq('answers differ -> partial, two distinct sets', [$c['status'], $c['distinct_answers']], ['partial', 2]);
$c = CloudHost247_dns_classify(rows_of(9, 0, 1));
eq('one unreachable -> partial, counted', [$c['status'], $c['unreachable']], ['partial', 1]);
ok('summary mentions the unreachable resolver', strpos($c['summary'], '1 resolver did not reply') !== false);
$rowsOrder = [
    ['outcome' => 'answer', 'records' => ['10 mx2.x.', '5 mx1.x.']],
    ['outcome' => 'answer', 'records' => ['5 mx1.x.', '10 mx2.x.']],
];
eq('record order does not change the verdict', CloudHost247_dns_classify($rowsOrder)['status'], 'propagated');

// ---------- transport guards (no network) ----------
$bad = CloudHost247_dns_query_servers([['name' => 'x', 'ip' => '8.8.8.8']], 'bad..name', 'A');
eq('invalid name: every resolver reports an error, no query sent', $bad[0], ['outcome' => 'error', 'records' => [], 'error' => 'invalid name or record type']);
$bad = CloudHost247_dns_query_servers([['name' => 'x', 'ip' => '8.8.8.8']], 'example.com', 'SOA');
eq('unsupported type: error, no query sent', $bad[0]['outcome'], 'error');

// ---------- handler input handling (returns before any network or settings call) ----------
eq('propagation: invalid domain', CloudHost247_tool_dns_propagation(['domain' => 'not a domain'])['error'], 'Invalid domain name');
eq('propagation: record_type is read (SOA is refused, not defaulted to A)',
    CloudHost247_tool_dns_propagation(['domain' => 'example.com', 'record_type' => 'SOA'])['error'], 'Invalid record type');
eq('propagation: ANY is refused', CloudHost247_tool_dns_propagation(['domain' => 'example.com', 'record_type' => 'ANY'])['error'], 'Invalid record type');
eq('lookup: record_type is read (BOGUS is refused, not defaulted to A)',
    CloudHost247_tool_dns_lookup(['domain' => 'example.com', 'record_type' => 'BOGUS'])['error'], 'Invalid DNS record type');
eq('lookup: invalid domain', CloudHost247_tool_dns_lookup(['domain' => 'x..y', 'record_type' => 'MX'])['error'], 'Invalid domain name');

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
