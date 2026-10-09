{*
 * CLOUDHOST247 — Domain search landing (results come from the module's
 * domain-search service: live provider availability + server-side pricing).
 *}
<section class="hero-banner chs-hero chs-hero-search">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Find the name that owns the search</h1>
                    <p class="hero-subtitle">Live availability against the registry chain; pricing shown in your
                        currency before you commit. No fake "premium" badges — the price on the order form is the price.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Domain Search</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section chs-section-search">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card border-0 shadow-sm chs-card-primary chs-search-card">
                    <div class="card-body">
                        <form method="get" action="domain-search.php" class="chs-lookup-form chs-search-form-lg">
                            <div class="input-group input-group-lg">
                                <label class="sr-only" for="chs-domain-q">Search for a domain</label>
                                <input type="text" class="form-control" id="chs-domain-q" name="q"
                                       value="{$chsQuery|escape}" placeholder="your-next-big-thing.com"
                                       autocomplete="off" spellcheck="false" maxlength="256" autofocus>
                                <div class="input-group-append">
                                    <button class="btn btn-primary btn-lg" type="submit">Search availability</button>
                                </div>
                            </div>
                            <small class="form-text text-muted text-center mt-2">
                                Live answer from the configured provider, today's register / renew / transfer
                                prices, and suggested alternatives — in that order.
                            </small>
                        </form>

                        <div class="chs-quicklinks mt-3">
                            <a href="{$chsBulkUrl}" class="chs-quicklink"><i class="fas fa-list" aria-hidden="true"></i> Bulk list</a>
                            <a href="{$chsTransferUrl}" class="chs-quicklink"><i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer in</a>
                            <a href="{$chsDirectoryUrl}" class="chs-quicklink"><i class="fas fa-tags" aria-hidden="true"></i> All TLD pricing</a>
                            <a href="{$chsValuationUrl}" class="chs-quicklink"><i class="fas fa-chart-line" aria-hidden="true"></i> What is it worth?</a>
                            <a href="{$chsAuctionsUrl}" class="chs-quicklink"><i class="fas fa-gavel" aria-hidden="true"></i> Auction floor</a>
                        </div>
                    </div>
                </div>

                {if $chsErrors}
                    <div class="alert alert-danger mt-3">
                        {foreach $chsErrors as $err}<div>{$err|escape}</div>{/foreach}
                    </div>
                {/if}

                {if $chsResult}
                    <div class="card border-0 shadow-sm chs-card-result mt-4">
                        <div class="card-body">
                            <h2 class="h4 mb-3">{$chsResult.domain|escape}</h2>
                            {if $chsResult.status == 'available'}
                                <p><span class="badge badge-success">Available</span>
                                   <small class="text-muted">checked live via {$chsResult.provider|escape}</small></p>
                                <table class="table table-sm chs-price-table">
                                    <tr><th>Register (1 year)</th>
                                        <td><strong>{$chsResult.register_fmt|escape}</strong>
                                            {if $chsResult.discount_percent}<span class="badge badge-info">{$chsResult.discount_percent|escape}% club discount</span>{/if}</td>
                                        <td><a class="btn btn-success" href="{$chsResult.add_to_cart_url|escape}">Add to cart</a></td></tr>
                                    <tr><th>Renewal (per year)</th><td>{if $chsResult.renew_fmt}{$chsResult.renew_fmt|escape}{else}—{/if}</td><td></td></tr>
                                    <tr><th>Transfer in</th><td>{if $chsResult.transfer_fmt}{$chsResult.transfer_fmt|escape}{else}—{/if}</td>
                                        <td>{if $chsResult.transfer_fmt}<a class="btn btn-outline-primary" href="{$chsResult.transfer_url|escape}">Transfer</a>{/if}</td></tr>
                                </table>
                                <p class="mb-0">
                                    <a class="btn btn-outline-secondary btn-sm" href="{$chsResult.whois_url|escape}">WHOIS lookup</a>
                                    <a class="btn btn-outline-secondary btn-sm" href="{$chsResult.appraisal_url|escape}">Appraise this domain</a>
                                </p>
                            {elseif $chsResult.status == 'taken'}
                                <p><span class="badge badge-danger">Taken</span> — the registry reports this name is registered.</p>
                                <p class="mb-0">
                                    <a class="btn btn-outline-secondary btn-sm" href="{$chsResult.whois_url|escape}">View public WHOIS</a>
                                    <a class="btn btn-outline-secondary btn-sm" href="domain-broker.php?domain={$chsResult.domain|escape:'url'}">Ask our broker</a>
                                </p>
                            {elseif $chsResult.status == 'unsupported_tld'}
                                <p><span class="badge badge-warning">Unsupported extension</span> — we do not sell .{$chsResult.tld|escape} yet.</p>
                                <p class="mb-0"><a href="{$chsDirectoryUrl}">Browse every supported extension</a></p>
                            {elseif $chsResult.status == 'DOMAIN_PROVIDER_NOT_CONFIGURED'}
                                <p><span class="badge badge-warning">Lookup unavailable</span> — no domain provider is configured
                                    for .{$chsResult.tld|escape} right now. Catalogue prices still apply:</p>
                                <table class="table table-sm chs-price-table">
                                    <tr><th>Register (1 year)</th><td>{if $chsResult.register_base_fmt}{$chsResult.register_base_fmt|escape}{else}—{/if}</td></tr>
                                    <tr><th>Renewal (per year)</th><td>{if $chsResult.renew_fmt}{$chsResult.renew_fmt|escape}{else}—{/if}</td></tr>
                                    <tr><th>Transfer in</th><td>{if $chsResult.transfer_fmt}{$chsResult.transfer_fmt|escape}{else}—{/if}</td></tr>
                                </table>
                            {else}
                                <p><span class="badge badge-secondary">Unknown</span> — the registry did not answer
                                    (status: {$chsResult.status|escape}). We never guess availability; try again in a moment.</p>
                            {/if}
                        </div>
                    </div>
                {/if}

                {if $chsSuggestions}
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-body">
                            <h3 class="h5">Available alternatives</h3>
                            <div class="row">
                                {foreach $chsSuggestions as $s}
                                    <div class="col-md-6 col-12 mb-2">
                                        <div class="d-flex justify-content-between align-items-center border rounded p-2">
                                            <span><strong>{$s.domain|escape}</strong>
                                                {if $s.available === true}<span class="badge badge-success">Available</span>
                                                {elseif $s.available === false}<span class="badge badge-danger">Taken</span>
                                                {else}<span class="badge badge-secondary">Unknown</span>{/if}</span>
                                            <span>
                                                {if $s.register_fmt}<span class="mr-2">{$s.register_fmt|escape}/yr</span>{/if}
                                                {if $s.available === true}<a class="btn btn-sm btn-success" href="{$s.add_to_cart_url|escape}">Add</a>{/if}
                                            </span>
                                        </div>
                                    </div>
                                {/foreach}
                            </div>
                        </div>
                    </div>
                {/if}

                {if $chsSpotlight}
                    <div class="chs-spotlight-row mt-4">
                        {foreach $chsSpotlight as $s}
                            <a class="chs-spotlight" href="domain-search.php?q=search.{$s.tld|escape:'url'}">
                                <span class="chs-spotlight-tld">.{$s.tld|escape}</span>
                                {if $s.badge neq ''}<span class="badge badge-primary">{$s.badge|escape}</span>{/if}
                                <span class="chs-spotlight-price">{if $s.register_fmt}{$s.register_fmt|escape}/yr{else}&nbsp;{/if}</span>
                            </a>
                        {/foreach}
                    </div>
                {/if}
            </div>
        </div>
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row">
            <div class="col-lg-4">
                <h3 class="h5">Registry-checked, not badge-checked</h3>
                <p class="text-muted mb-0">Every answer comes from the actual registry chain through the configured
                    provider — when a name is dropped, you see it only after the registry says so. No repeated
                    caching of last week's availability.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">One order flow everywhere</h3>
                <p class="text-muted mb-0">A search result, a suggestion on this page, a bulk row — they all land in the
                    same WHMCS cart with the same checkout, so totals stay predictable. Prices here are calculated
                    server-side; the cart charges exactly these amounts.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Still available after WHOIS?</h3>
                <p class="text-muted mb-0">The search already answers availability. If the registry refuses to
                    answer, we say so — we never present "unknown" as "available". Check any name's current
                    record on the <a href="{$chsWhoisUrl}">WHOIS page</a>.</p>
            </div>
        </div>
    </div>
</section>
