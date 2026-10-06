<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\ValidationException;
use Chs\Services\TldCatalogService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();

$svc = new TldCatalogService();

T::section('Catalogue merges WHMCS pricing rows with module meta');
$cat = $svc->catalog();
T::ok('four TLDs surfaced', count($cat['rows']) === 4);
$byTld = [];
foreach ($cat['rows'] as $row) {
    $byTld[$row['tld']] = $row;
}
T::ok('com present with meta from seed', isset($byTld['com']));
$com = $byTld['com'];
T::eq('register price in minor', 1299, $com['register_minor']);
T::eq('renew price in minor', 1599, $com['renew_minor']);
T::eq('transfer price in minor', 999, $com['transfer_minor']);
T::ok('price flagged available', $com['price_available'] === true);
T::ok('dns flag from WHMCS', $com['features']['dns'] === true);
T::ok('epp flag from WHMCS', $com['features']['epp'] === true);
T::ok('seeded category kept from meta', in_array($com['category'], ['popular', 'generic'], true));
T::eq('currency echo', 'USD', $cat['currency']);
T::ok('facets present', is_array($cat['categories']) && is_array($cat['regions']));

T::eq('io has no addons per fixture', false, $byTld['io']['features']['dns']);
T::eq('unpriced-in-currency handled as unavailable', null, $byTld['co.uk']['register_minor']);
T::eq('multi-part tld kept whole', 'co.uk', $byTld['co.uk']['tld']);

T::section('Currency switching picks the right pricing rows');
$eur = $svc->catalog([], 'EUR');
$eurCom = null;
foreach ($eur['rows'] as $row) {
    if ($row['tld'] === 'com') { $eurCom = $row; }
}
T::ok('com row in EUR catalogue', $eurCom !== null);
T::eq('EUR register price', 1199, $eurCom['register_minor']);
T::eq('renew null in EUR (no fixture)', null, $eurCom['renew_minor']);

T::section('Search, category, badge, featured filters + sort');
$hits = $svc->catalog(['search' => 'co']);
T::eq('search .co matches co+com+co.uk', true, (function () use ($hits) {
    $tlds = array_column($hits['rows'], 'tld');
    return in_array('com', $tlds, true) && in_array('co.uk', $tlds, true) && !in_array('io', $tlds, true);
})());
$byCat = $svc->catalog(['category' => 'tech']);
T::ok('tech category includes io (seeded)', (function () use ($byCat) {
    return in_array('io', array_column($byCat['rows'], 'tld'), true);
})());
$T = $svc->catalog(['badge' => 'POPULAR']);
T::ok('badge filter applied', (function () use ($T) {
    foreach ($T['rows'] as $r) { if ($r['badge'] !== 'POPULAR') { return false; } }
    return count($T['rows']) >= 1;
})());
$feat = $svc->catalog(['only_featured' => true]);
T::ok('featured filter applied', (function () use ($feat) {
    foreach ($feat['rows'] as $r) { if (!$r['is_featured']) { return false; } }
    return count($feat['rows']) >= 1;
})());
$sorted = $svc->catalog(['sort' => 'price_asc']);
$prices = array_values(array_filter(array_map(function ($r) { return $r['register_minor']; }, $sorted['rows']), 'is_int'));
$copy = $prices; sort($copy);
T::eq('price_asc really ascending', $copy, $prices);

T::section('Spotlight + detail');
$spot = $svc->spotlight(3);
T::ok('spotlight ≤ limit', count($spot) <= 3);
T::ok('spotlight rows priced when available', (function () use ($spot) {
    foreach ($spot as $r) { if (!isset($r['register_minor'])) { return false; } }
    return true;
})());
$detail = $svc->detail('com');
T::ok('detail for com found', $detail !== null && $detail['tld'] === 'com');
T::eq('dot-prefix tolerated', 'com', $svc->detail('.com')['tld']);
T::ok('unknown tld null', $svc->detail('notarealextension') === null);

T::section('Admin meta management');
T::throws('illegal tld rejected', function () use ($svc) {
    $svc->saveMeta('not a tld!', ['category' => 'generic']);
}, ValidationException::class);
$svc->saveMeta('co.uk', [
    'category' => 'country', 'region' => 'United Kingdom', 'is_featured' => 0,
    'is_popular' => 0, 'is_new' => 0, 'badge' => 'new', 'tagline' => 'For UK trade.',
    'sort_order' => 50, 'visible' => 1,
]);
$meta = Db::first('tld_meta', ['tld' => 'co.uk']);
T::eq('badge cleaned + uppercased', 'NEW', $meta['badge']);
T::eq('tagline stored', 'For UK trade.', $meta['tagline']);
T::eq('region stored', 'United Kingdom', $meta['region']);

$svc->saveMeta('io', ['category' => 'tech', 'visible' => 0]);
$vis = $svc->catalog();
T::ok('visible=0 hides from catalogue', !in_array('io', array_column($vis['rows'], 'tld'), true));
$admin = $svc->adminList();
T::ok('admin list still shows hidden', (function () use ($admin) {
    foreach ($admin as $r) {
        if ($r['tld'] === 'io') { return true; }
    }
    return false;
})());
$svc->saveMeta('io', ['category' => 'tech', 'visible' => 1]);
T::ok('re-visible works', in_array('io', array_column($svc->catalog()['rows'], 'tld'), true));

T::finish();
