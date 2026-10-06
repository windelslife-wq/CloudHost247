{*
 * CLOUDHOST247 — TLD Directory. Live rows from TldCatalogService.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Every extension, today&rsquo;s price</h1>
                    <p class="hero-subtitle">The catalogue below is read live from the billing system — not a
                        static price sheet that drifts. Register, renew or transfer any extension that matches
                        your brand.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">TLD Directory</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section">
    <div class="container">
        {if isset($chsUnavailable) && $chsUnavailable}
            <div class="card chs-card-notice border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h4"><i class="fas fa-tools" aria-hidden="true"></i> Service warming up</h2>
                    <p class="mb-3">{$chsUnavailableWhy}</p>
                    <a href="{$chsSupportLink}" class="btn btn-primary">Contact the team</a>
                </div>
            </div>
        {else}

        <div class="row chs-toolbar">
            <div class="col-xl-5 col-lg-6">
                <form method="get" action="tld-directory.php" class="chs-filter-form">
                    {if $chsCategory neq ''}<input type="hidden" name="category" value="{$chsCategory|escape}">{/if}
                    <input type="hidden" name="sort" value="{$chsSort|escape}">
                    <div class="input-group">
                        <label class="sr-only" for="chs-tld-search">Search extension or tagline</label>
                        <input type="text" class="form-control" id="chs-tld-search" name="q"
                               value="{$chsQ|escape}" placeholder="Try “shop”, “tech”, “.io”…" maxlength="60">
                        <div class="input-group-append">
                            <button class="btn btn-outline-primary" type="submit">Search</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-xl-7 col-lg-6 text-lg-right mt-2 mt-lg-0">
                <form method="get" action="tld-directory.php" class="d-inline">
                    <input type="hidden" name="q" value="{$chsQ|escape}">
                    <input type="hidden" name="category" value="{$chsCategory|escape}">
                    <label for="chs-tld-sort" class="sr-only">Order</label>
                    <select id="chs-tld-sort" name="sort" class="form-control form-control-sm chs-sort-select" onchange="this.form.submit()">
                        <option value="featured"{if $chsSort eq 'featured'} selected{/if}>Featured first</option>
                        <option value="name"{if $chsSort eq 'name'} selected{/if}>A–Z</option>
                        <option value="price_asc"{if $chsSort eq 'price_asc'} selected{/if}>Price rising</option>
                        <option value="price_desc"{if $chsSort eq 'price_desc'} selected{/if}>Price falling</option>
                    </select>
                </form>
                <span class="badge badge-light chs-currency-pill" title="Prices are displayed in your account currency">
                    {$chsCurrency|escape}
                </span>
            </div>
        </div>

        {if $chsCategories}
            <div class="chs-chip-row mt-3">
                <a class="chs-chip {if $chsCategory eq ''}chs-chip-on{/if}" href="tld-directory.php?sort={$chsSort|escape}">All</a>
                {foreach $chsCategories as $f}
                    <a class="chs-chip {if $chsCategory eq $f.value}chs-chip-on{/if}"
                       href="tld-directory.php?category={$f.value|escape}&sort={$chsSort|escape}">
                        {$f.value|ucfirst|escape} <span class="chs-chip-count">{$f.count}</span>
                    </a>
                {/foreach}
            </div>
        {/if}

        {if isset($chsErrors.service)}
            <div class="alert alert-warning mt-3">{$chsErrors.service|escape}</div>
        {/if}

        <div class="table-responsive chs-tld-table-wrap mt-4">
            <table class="table table-hover chs-tld-table">
                <thead>
                    <tr>
                        <th scope="col">Extension</th>
                        <th scope="col">Category</th>
                        <th scope="col" class="text-right">Register /yr</th>
                        <th scope="col" class="text-right">Renew /yr</th>
                        <th scope="col" class="text-right">Transfer</th>
                        <th scope="col" class="d-none d-lg-table-cell">Features</th>
                        <th scope="col" class="text-right">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $chsRows as $r}
                        <tr>
                            <th scope="row" class="chs-tld-name">
                                .{$r.tld|escape}
                                {if $r.badge neq ''}<span class="badge badge-primary chs-tld-badge">{$r.badge|escape}</span>{/if}
                            </th>
                            <td>{$r.category|ucfirst|escape}</td>
                            <td class="text-right">
                                {if $r.register_fmt}{$r.register_fmt|escape}{else}<span class="text-muted">on request</span>{/if}
                            </td>
                            <td class="text-right">
                                {if $r.renew_fmt}{$r.renew_fmt|escape}{else}<span class="text-muted">—</span>{/if}
                            </td>
                            <td class="text-right">
                                {if $r.transfer_fmt}{$r.transfer_fmt|escape}{else}<span class="text-muted">—</span>{/if}
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <span class="chs-feat-icons" title="Supported add-ons">
                                    {if $r.features.dns}<span class="chs-feat" title="DNS management">DNS</span>{/if}
                                    {if $r.features.forwarding}<span class="chs-feat" title="Email forwarding">FWD</span>{/if}
                                    {if $r.features.id_protection}<span class="chs-feat" title="WHOIS privacy">IDN</span>{/if}
                                    {if $r.features.epp}<span class="chs-feat" title="EPP code supported">EPP</span>{/if}
                                </span>
                            </td>
                            <td class="text-right">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="{$chsSearchUrl}{$r.tld|escape:'url'}">Search names</a>
                            </td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="7" class="text-center text-muted">Nothing matches that filter — try
                            clearing the search or picking another category.</td></tr>
                    {/foreach}
                </tbody>
            </table>
        </div>

        <p class="text-muted mt-3 mb-0">
            Looking for a premium name instead? <a href="{$chsAuctionsUrl}">Bid on the auction floor</a> —
            or <a href="{$chsValuationUrl}">appraise a domain</a> you already own.
        </p>
        {/if}
    </div>
</section>
