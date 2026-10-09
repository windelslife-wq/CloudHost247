{*
 * CLOUDHOST247 — Bulk domain search (module service: async worker, honest
 * availability, server-side pricing, CSV export).
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Check your whole shortlist at once</h1>
                    <p class="hero-subtitle">Paste up to {$chsMax} domains or keywords — the registry answers for
                        all of them in one pass, processed in the background for large lists.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Bulk Domain Search</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                {if $chsUnavailable}
                    <div class="alert alert-warning">{$chsUnavailableWhy|escape}</div>
                {/if}

                {if $chsErrors}
                    <div class="alert alert-danger">
                        {foreach $chsErrors as $err}<div>{$err|escape}</div>{/foreach}
                    </div>
                {/if}

                {if $chsSearch}
                    {if $chsSearch.status == 'queued' || $chsSearch.status == 'running'}
                        <meta http-equiv="refresh" content="5">
                    {/if}
                    <div class="card border-0 shadow-sm chs-card-primary mb-4">
                        <div class="card-body">
                            <h2 class="h4 mb-1">Bulk search #{$chsSearch.id}
                                {if $chsSearch.status == 'queued'}<span class="badge badge-secondary">Queued</span>
                                {elseif $chsSearch.status == 'running'}<span class="badge badge-info">Running — {$chsSearch.completed} / {$chsSearch.total} checked</span>
                                {elseif $chsSearch.status == 'completed'}<span class="badge badge-success">Completed — {$chsTotal} names, {$chsAvailable} available</span>
                                {else}<span class="badge badge-danger">{$chsSearch.status|escape}</span>{/if}
                            </h2>
                            <p class="mb-2">
                                <a class="btn btn-outline-secondary btn-sm" href="bulk-domain-search.php?export={$chsSearch.id}">Export CSV</a>
                                {if !$chsOnlyAvailable && $chsSearch.status == 'completed'}
                                    <a class="btn btn-outline-secondary btn-sm" href="bulk-domain-search.php?id={$chsSearch.id}&only_available=1">Available only</a>
                                {elseif $chsOnlyAvailable}
                                    <a class="btn btn-outline-secondary btn-sm" href="bulk-domain-search.php?id={$chsSearch.id}">Show all</a>
                                {/if}
                                <a class="btn btn-outline-secondary btn-sm" href="bulk-domain-search.php">New search</a>
                            </p>
                            {if $chsSearch.status != 'completed'}
                                <p class="text-muted">Processing in the background — this page refreshes automatically.</p>
                            {/if}
                            {if $chsRows}
                            <form method="post" action="{$chsCartUrl|escape}">
                                <input type="hidden" name="a" value="add">
                                <input type="hidden" name="domain" value="register">
                                <input type="hidden" name="bulk" value="1">
                                <div class="table-responsive">
                                <table class="table table-striped table-sm">
                                    <thead><tr>
                                        <th><input type="checkbox" id="chs-bulk-all" aria-label="Select all available"></th>
                                        <th>Domain</th><th>Availability</th><th>Register</th><th>Renewal</th><th></th>
                                    </tr></thead>
                                    <tbody>
                                    {foreach $chsRows as $row}
                                        <tr>
                                            <td>{if $row.available == 1}<input type="checkbox" name="bulkdomains[]" value="{$row.domain|escape}" class="chs-bulk-check" aria-label="Select {$row.domain|escape}">{/if}</td>
                                            <td>{$row.domain|escape}</td>
                                            <td>
                                                {if $row.available === null}<span class="badge badge-secondary">{$row.status|escape}</span>
                                                {elseif $row.available == 1}<span class="badge badge-success">Available</span>
                                                {else}<span class="badge badge-danger">Taken</span>{/if}
                                            </td>
                                            <td>{if $row.register_fmt}{$row.register_fmt|escape}{else}—{/if}</td>
                                            <td>{if $row.renew_fmt}{$row.renew_fmt|escape}{else}—{/if}</td>
                                            <td>
                                                {if $row.available == 1}
                                                    <a class="btn btn-xs btn-success" href="cart.php?a=add&domain=register&query={$row.domain|escape:'url'}">Add</a>
                                                {else}
                                                    <a class="btn btn-xs btn-outline-secondary" href="whois-lookup.php?domain={$row.domain|escape:'url'}">Whois</a>
                                                {/if}
                                            </td>
                                        </tr>
                                    {/foreach}
                                    </tbody>
                                </table>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Add selected to cart</button>
                                <span class="text-muted small">Selected available domains go to the standard WHMCS cart &amp; checkout.</span>
                            </form>
                            {/if}
                            {if $chsPages > 1}
                            <nav><ul class="pagination pagination-sm">
                                {section name=p start=1 loop=$chsPages+1 step=1 max=$chsPages}
                                    <li{if $smarty.section.p.index == $chsPage} class="active"{/if}>
                                        <a href="bulk-domain-search.php?id={$chsSearch.id}&page={$smarty.section.p.index}{if $chsOnlyAvailable}&only_available=1{/if}">{$smarty.section.p.index}</a>
                                    </li>
                                {/section}
                            </ul></nav>
                            {/if}
                        </div>
                    </div>
                    <script>
                    (function () {
                        var all = document.getElementById('chs-bulk-all');
                        if (!all) { return; }
                        all.addEventListener('change', function () {
                            document.querySelectorAll('.chs-bulk-check').forEach(function (box) { box.checked = all.checked; });
                        });
                    })();
                    </script>
                {else}
                    <div class="card border-0 shadow-sm chs-card-primary">
                        <div class="card-body">
                            <h2 class="h4 text-center mb-3">Bulk availability check</h2>
                            <form method="post" action="bulk-domain-search.php" class="chs-bulk-form">
                                {$csrf_field}
                                <div class="form-group">
                                    <label for="chs-bulk-list">Domains or keywords — one per line, or separated by spaces/commas</label>
                                    <textarea class="form-control chs-bulk-textarea" id="chs-bulk-list" name="chs_domains"
                                              rows="10" spellcheck="false" autocomplete="off" maxlength="20000"
                                              placeholder="bluepinestudio.com&#10;bluepinestudio.co&#10;bluepine.studio&#10;... or just: bluepine studio hosting"></textarea>
                                    <small class="form-text text-muted">
                                        Full domains are checked as-is; bare keywords are expanded across every extension
                                        we sell. Up to {$chsMax} names per search. Invalid names are reported, never
                                        silently treated as available.
                                    </small>
                                </div>
                                <div class="text-center">
                                    <button class="btn btn-primary btn-lg" type="submit">Check the list</button>
                                </div>
                            </form>
                        </div>
                    </div>
                {/if}

                <p class="text-muted mt-3">
                    Moving the whole portfolio instead? Head over to the
                    <a href="{$chsTransferUrl}">transfer desk</a>. Just pricing first?
                    Try the <a href="{$chsDirectoryUrl}">TLD directory</a>. Single name?
                    <a href="{$chsSearchUrl}">Search one domain</a>.
                </p>
            </div>
        </div>
    </div>
</section>
