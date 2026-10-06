{*
 * CLOUDHOST247 — Domain search landing (form posts to cart.php).
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
                        <form method="get" action="cart.php" class="chs-lookup-form chs-search-form-lg">
                            <input type="hidden" name="a" value="add">
                            <input type="hidden" name="domain" value="register">
                            <div class="input-group input-group-lg">
                                <label class="sr-only" for="chs-domain-q">Search for a domain</label>
                                <input type="text" class="form-control" id="chs-domain-q" name="query"
                                       value="{$chsQuery|escape}" placeholder="your-next-big-thing.com"
                                       autocomplete="off" spellcheck="false" maxlength="256" autofocus>
                                <div class="input-group-append">
                                    <button class="btn btn-primary btn-lg" type="submit">Search availability</button>
                                </div>
                            </div>
                            <small class="form-text text-muted text-center mt-2">
                                You'll get the registry's live answer, the real price, and our suggested
                                alternatives — in that order.
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

                {if $chsSpotlight}
                    <div class="chs-spotlight-row mt-4">
                        {foreach $chsSpotlight as $s}
                            <a class="chs-spotlight" href="cart.php?a=add&domain=register&query=search.{$s.tld|escape:'url'}">
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
                <p class="text-muted mb-0">Every answer comes from the actual registry chain through your
                    registrar — when a name is dropped, you see it only after the registry says so. No repeated
                    caching of last week's availability.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">One order flow everywhere</h3>
                <p class="text-muted mb-0">A search result, a domain from a YouTube description, a suggestion on
                    this page — they all land in the same cart with the same checkout, so totals stay predictable.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Still available after WHOIS?</h3>
                <p class="text-muted mb-0">The search already answers availability. If the registry refuses to
                    answer, we say so — we never present "unknown" as "available". Check any name's current
                    WHOIS record separately on the <a href="whois-lookup.php">WHOIS page</a>.</p>
            </div>
        </div>
    </div>
</section>
