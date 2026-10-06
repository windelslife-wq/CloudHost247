{*
 * CLOUDHOST247 — Hire an Expert.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Experts who invoice, not vibe</h1>
                    <p class="hero-subtitle">Send a structured brief; receive a fixed quote with scope in writing;
                        pay a real invoice only when you accept. The same people who run our infrastructure build for you.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Hire an Expert</li>
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

        <div class="row justify-content-center mb-5">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm chs-card-primary">
                    <div class="card-body text-center">
                        <h2 class="h4 mb-2">One brief, one quote, one invoice</h2>
                        <p class="text-muted">Pick a service below, describe the goal, choose a budget band —
                            an expert replies with a fixed quote you accept or decline inside your account.
                            No channel-hopping, no prices that change mid-project.</p>
                        <a class="btn btn-primary btn-lg" href="{$chsRequestUrl}">{if $chsLoggedIn}Start my brief{else}Sign in to start a brief{/if}</a>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="h5 mb-3">What we take on</h2>
        <div class="chs-type-grid">
            {foreach $chsTypes as $typeKey => $t}
                <a class="chs-type-card" href="{$chsRequestUrl}&type={$typeKey|escape:'url'}">
                    <span class="chs-type-title">{$t.label|escape}</span>
                    <span class="chs-type-desc">{$t.desc|escape}</span>
                    <span class="chs-type-cta">Brief us <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                </a>
            {foreachelse}
                <div class="alert alert-info mb-0">The service list is being prepared — you can always reach the
                    team via the support desk in the meantime.</div>
            {/foreach}
        </div>

        {if $chsBudgets}
            <div class="mt-4">
                <h3 class="h6 text-muted">Budget bands we quote inside</h3>
                <div class="chs-chip-row">
                    {foreach $chsBudgets as $b}
                        <span class="chs-chip">{$b|escape}</span>
                    {/foreach}
                </div>
            </div>
        {/if}
        {/if}
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row">
            <div class="col-md-3 col-6">
                <div class="chs-step"><span class="chs-step-num">1</span>
                    <h3 class="h6">You brief</h3>
                    <p class="text-muted small mb-0">Structured: goal, audience signatures, domain, budget band.</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="chs-step"><span class="chs-step-num">2</span>
                    <h3 class="h6">We scope &amp; quote</h3>
                    <p class="text-muted small mb-0">A fixed quote lands in your portal — scope and timeline in writing.</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="chs-step"><span class="chs-step-num">3</span>
                    <h3 class="h6">Accept &amp; build</h3>
                    <p class="text-muted small mb-0">Accept in one click; the invoice is raised, your expert is assigned.</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="chs-step"><span class="chs-step-num">4</span>
                    <h3 class="h6">Deliver on thread</h3>
                    <p class="text-muted small mb-0">Timeline, discussions and delivery all live in your
                        <a href="{$chsPortalUrl}">request history</a>.</p>
                </div>
            </div>
        </div>
    </div>
</section>
