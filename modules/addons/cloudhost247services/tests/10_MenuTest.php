<?php

require __DIR__ . '/bootstrap.php';

use Chs\Http\HostxMenu;

chs_boot();

/** Repo root in whatever runtime this suite runs under (wasm or native). */
function chs_repo_root()
{
    return is_dir('/repo') ? '/repo' : (defined('CHS_ROOT') ? CHS_ROOT : getcwd());
}

T::section('Mega menu entry structure matches HostX consumers');
$entries = HostxMenu::entries();
T::eq('four top categories', 4, count($entries));
$labels = array_map(function ($e) { return $e['name']; }, $entries);
T::eq('canonical order', ['Domains', 'Websites & Builders', 'Marketing', 'Hosting & Services'], $labels);

foreach ($entries as $top) {
    T::eq('top is menutype 3', 3, $top['menutype']);
    T::ok('groups present', count($top['submenu']) >= 1);
    foreach ($top['submenu'] as $group) {
        T::ok('childsubmenu rows present', count($group['childsubmenu']) >= 1);
        foreach ($group['childsubmenu'] as $row) {
            T::ok('row has url', isset($row['url']) && $row['url'] !== '');
            T::ok('row has icon class', strpos((string) $row['icon'], 'fa ') === 0);
        }
    }
}

T::section('Badges ride the name field as raw HTML (templates output verbatim)');
$flat = chs_json($entries);
T::ok('NEW badge present', strpos($flat, '>NEW</span>') !== false);
T::ok('POPULAR badge present', strpos($flat, '>POPULAR</span>') !== false);
T::ok('badge uses theme bootstrap classes', strpos($flat, 'badge badge-danger') !== false);

T::section('Every linked page actually exists in the repo (no dead menu links)');
$dead = [];
$walk = function ($rows) use (&$dead) {
    foreach ($rows as $row) {
        $url = $row['url'];
        if (!is_string($url) || $url === '#') {
            continue;
        }
        $path = strtok($url, '#');
        if (strpos($path, 'index.php') === 0) {
            continue; // module portal pages are served by the module router
        }
        if (!is_file(chs_repo_root() . '/' . $path)) {
            $dead[] = $path;
        }
    }
};
foreach ($entries as $top) {
    foreach ($top['submenu'] as $group) {
        $walk($group['childsubmenu']);
    }
}
T::eq('zero dead links', [], $dead);

T::section('Merge is idempotent and preserves existing theme entries');
$existing = [
    ['name' => 'Domains', 'url' => '#', 'menutype' => 1], // admin-configured entry with the same name
    ['name' => 'Support', 'url' => 'submitticket.php', 'menutype' => 1],
];
$merged = HostxMenu::merge($existing);
T::eq('originals kept, 3 new categories appended', 5, count($merged));
T::eq('existing Domains entry untouched', 1, $merged[0]['menutype']);
$again = HostxMenu::merge($merged);
T::eq('second merge adds nothing', count($again), count($merged));

T::section('All four top categories contribute call-to-action buttons pointing at real pages');
foreach ($entries as $top) {
    T::ok($top['name'] . ' caption URL exists',
        is_file(chs_repo_root() . '/' . $top['menu_caption_url']));
}

T::finish();
