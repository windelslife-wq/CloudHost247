{*
 * CLOUDHOST247 — Domain Auctions public browse.
 * Live data, countdown seconds via suite.js, logout => CTA to sign in.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>The domain auction floor</h1>
                    <p class="hero-subtitle">Bid on expiring and seller-listed premium domains. Proxy bidding,
                        anti-sniping protection and invoice-settled wins — nothing happens off the books.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Domain Auctions</li>
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
            <div class="col-lg-6">
                <form method="get" action="domain-auctions.php" class="chs-filter-form">
                    <input type="hidden" name="sort" value="{$chsSort|escape}">
                    <div class="input-group">
                        <label class="sr-only" for="chs-auction-search">Search domains</label>
                        <input type="text" class="form-control" id="chs-auction-search" name="q"
                               value="{$chsSearch|escape}" placeholder="Search premium domains…" maxlength="120">
                        <div class="input-group-append">
                            <button class="btn btn-outline-primary" type="submit">Filter</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-lg-6 text-lg-right mt-2 mt-lg-0">
                <form method="get" action="domain-auctions.php" class="chs-sort-form d-inline">
                    <input type="hidden" name="q" value="{$chsSearch|escape}">
                    <label for="chs-sort" class="sr-only">Order</label>
                    <select id="chs-sort" name="sort" class="form-control form-control-sm chs-sort-select" onchange="this.form.submit()">
                        <option value="ending"{if $chsSort eq 'ending'} selected{/if}>Ending soon</option>
                        <option value="price_asc"{if $chsSort eq 'price_asc'} selected{/if}>Price rising</option>
                        <option value="price_desc"{if $chsSort eq 'price_desc'} selected{/if}>Price falling</option>
                        <option value="bids"{if $chsSort eq 'bids'} selected{/if}>Most contested</option>
                        <option value="newest"{if $chsSort eq 'newest'} selected{/if}>Newest first</option>
                    </select>
                </form>
                <a href="{$chsSellUrl}" class="btn btn-primary btn-sm">
                    <i class="fas fa-gavel" aria-hidden="true"></i> Sell yours
                </a>
            </div>
        </div>

        {if isset($chsErrors.service)}
            <div class="alert alert-warning">{$chsErrors.service|escape}</div>
        {/if}

        {if $chsEndingSoon && count($chsEndingSoon) > 0 && $chsPage eq 1 && $chsSearch eq ''}
            <h2 class="h5 mt-4 mb-3"><i class="fas fa-fire text-danger" aria-hidden="true"></i> Closing within six hours</h2>
            <div class="chs-auction-grid chs-auction-grid-hot">
                {foreach $chsEndingSoon as $a}
                    <div class="chs-auction-card">
                        <a class="chs-auction-domain" href="{$chsDetailBase}{$a.id}">{$a.domain|escape}</a>
                        <div class="chs-auction-meta">
                            <span class="chs-auction-price">{$a.price_fmt|escape}</span>
                            <span class="chs-auction-bids">{$a.bids_count} bid{if $a.bids_count neq 1}s{/if}</span>
                        </div>
                        <span class="chs-countdown" data-ends="{$a.seconds_remaining}">{$a.seconds_remaining}s</span>
                    </div>
                {/foreach}
            </div>
            <hr>
        {/if}

        <h2 class="h5 mb-3">{if $chsSearch neq ''}Matching auctions{else}All live auctions{/if}
            <span class="text-muted font-weight-normal">({$chsTotal})</span></h2>

        <div class="chs-auction-grid">
            {foreach $chsAuctions as $a}
                <div class="chs-auction-card {if $a.status neq 'active'}chs-auction-card-muted{/if}">
                    <a class="chs-auction-domain" href="{$chsDetailBase}{$a.id}">{$a.domain|escape}</a>
                    <span class="chs-auction-tld">.{$a.tld|escape}</span>
                    <div class="chs-auction-meta">
                        <span class="chs-auction-price">{$a.price_fmt|escape}</span>
                        <span class="chs-auction-bids">{$a.bids_count} bid{if $a.bids_count neq 1}s{/if}</span>
                    </div>
                    <div class="chs-auction-state">
                        {if $a.status eq 'active'}
                            <span class="chs-countdown" data-ends="{$a.seconds_remaining}">live</span>
                        {elseif $a.status eq 'scheduled'}
                            <span class="badge badge-info">Starts {$a.starts_at|escape}</span>
                        {else}
                            <span class="badge badge-secondary">{$a.status|ucfirst}</span>
                        {/if}
                        {if $a.bin_fmt neq ''}
                            <span class="badge badge-success">BIN {$a.bin_fmt|escape}</span>
                        {/if}
                    </div>
                    {if $a.has_reserve}
                        <div class="chs-auction-reserve {if $a.reserve_met}chs-reserve-met{/if}">
                            {if $a.reserve_met}Reserve met{else}Reserve not yet met{/if}
                        </div>
                    {/if}
                    <a href="{$chsDetailBase}{$a.id}" class="btn btn-outline-primary btn-block btn-sm mt-2">
                        {if $chsLoggedIn}Watch / bid{else}View and sign in to bid{/if}
                    </a>
                </div>
            {foreachelse}
                <div class="alert alert-info mb-0">No auctions match right now. The floor refills constantly —
                    list your own domains or check back shortly.</div>
            {/foreach}
        </div>

        {if $chsPages > 1}
            <nav aria-label="Auction pages" class="mt-4">
                <ul class="pagination">
                    {section name=p start=1 loop=$chsPages+1}
                        <li class="page-item {if $smarty.section.p.index eq $chsPage}active{/if}">
                            <a class="page-link"
                               href="domain-auctions.php?sort={$chsSort|escape}{if $chsSearch neq ''}&q={$chsSearch|escape}{/if}&page={$smarty.section.p.index}">{$smarty.section.p.index}</a>
                        </li>
                    {/section}
                </ul>
            </nav>
        {/if}
        {/if}
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row">
            <div class="col-lg-4">
                <h3 class="h5">Proxy bidding, no theatre</h3>
                <p class="text-muted mb-0">Set your ceiling once; the engine defends your lead one minimum
                    increment at a time. The same rules for every bidder, and every action is journaled for audit.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Anti-sniping windows</h3>
                <p class="text-muted mb-0">A bid landing inside the final five minutes extends the clock, so a
                    fair best-price always beats a fast last click. Extensions are capped and visible on every card.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Invoices, not handshakes</h3>
                <p class="text-muted mb-0">Win and an invoice settles automatically; pay it and the transfer desk
                    executes the handover. Miss the due date and the sale is cancelled — transparently, on record.</p>
            </div>
        </div>
    </div>
</section>
