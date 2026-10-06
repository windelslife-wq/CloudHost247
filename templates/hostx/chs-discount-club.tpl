{*
 * CLOUDHOST247 — Discount Domain Club.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Domain pricing insiders get</h1>
                    <p class="hero-subtitle">One membership, member pricing on every eligible extension.
                        The discounted total you see on the order form is exactly what you are charged.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Discount Domain Club</li>
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
        {if isset($chsErrors.service)}
            <div class="alert alert-warning">{$chsErrors.service|escape}</div>
        {/if}

        <div class="chs-plans-grid">
            {foreach $chsPlans as $p}
                <div class="chs-plan-card">
                    {if $p.discount_percent >= 40}
                        <div class="chs-plan-flag">Best value</div>
                    {/if}
                    <h2 class="h4 mb-1">{$p.name|escape}</h2>
                    <p class="text-muted">{$p.description|escape}</p>

                    <div class="chs-plan-price">
                        <span class="chs-plan-amount">{$p.price_fmt|escape}</span>
                        <span class="chs-plan-period">/ {$p.period_months} month{if $p.period_months gt 1}s{/if}</span>
                    </div>

                    <ul class="chs-plan-points">
                        <li><strong>{$p.discount_fmt|escape}</strong> member pricing
                            {if $p.applies_register}on registrations{/if}
                            {if $p.applies_renew}{if $p.applies_register} and {/if}renewals{/if}
                            {if $p.applies_transfer}{if $p.applies_register || $p.applies_renew} and {/if}transfers{/if}
                        </li>
                        <li>{if $p.tlds}{count($p.tlds)} eligible{elseif true}All{/if} extensions —
                            {if $p.example_tld neq ''}e.g. <code>{$p.example_tld}</code> at <strong>{$p.example_pct}</strong> off list{/if}
                        </li>
                        <li>Fresh at checkout — the total you see is the total you pay, no checkout-time shock</li>
                        {if $p.max_domains gt 0}
                            <li>Applies to the first {$p.max_domains} domains per member per year</li>
                        {/if}
                    </ul>

                    <div class="chs-plan-tlds">
                        {foreach $p.tld_preview as $t}
                            <span class="chs-chip chs-chip-sm">.{$t.tld|escape}</span>
                        {/foreach}
                        {if count($p.tlds) gt 8}
                            <span class="chs-chip chs-chip-sm chs-chip-sm-muted">+{count($p.tlds) - 8} more</span>
                        {/if}
                    </div>

                    <a class="btn btn-primary btn-block" href="{$chsJoinUrl}">
                        {if $chsLoggedIn}Join the club{else}Sign in to join{/if}
                    </a>
                </div>
            {foreachelse}
                <div class="alert alert-info">Membership plans are being loaded — check back shortly.</div>
            {/foreach}
        </div>
        {/if}
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row">
            <div class="col-lg-4">
                <h3 class="h5">It pays for itself fast</h3>
                <p class="text-muted mb-0">Registering more than a handful of domains a year? Member pricing on
                    the eligible extensions usually covers the annual fee after just a few names — check the
                    arithmetic against the live <a href="{$chsDirectoryUrl}">TLD directory</a>.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Zero coupon discipline</h3>
                <p class="text-muted mb-0">No codes to hunt for, no terms buried in fine print. When your
                    membership is active, the order form simply charges you less — we can trace every discounted
                    order back to the plan that produced it.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Cancel cleanly</h3>
                <p class="text-muted mb-0">Cancel any time from your account. Member pricing runs until the end of
                    the paid period, expiry is automatic and visible — no dangling renewals you didn't ask for.</p>
            </div>
        </div>
    </div>
</section>
