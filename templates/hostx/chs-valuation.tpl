{*
 * CLOUDHOST247 — Domain Valuation landing (public).
 * All values server-rendered; the disclaimer is mandatory on every result.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>{$chsHeroTitle}</h1>
                    <p class="hero-subtitle">{$chsHeroSub}</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Domain Valuation</li>
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
            <div class="col-lg-8 col-xl-7">
                {if isset($chsUnavailable) && $chsUnavailable}
                    <div class="card chs-card-notice border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h4"><i class="fas fa-tools" aria-hidden="true"></i> Service warming up</h2>
                            <p class="mb-3">{$chsUnavailableWhy}</p>
                            <a href="{$chsSupportLink}" class="btn btn-primary">Contact the team</a>
                        </div>
                    </div>
                {else}
                <div class="card border-0 shadow-sm chs-card-primary">
                    <div class="card-body">
                        <h2 class="h4 text-center mb-3">Appraise any domain</h2>
                        <form method="post" action="domain-valuation.php" class="chs-lookup-form">
                            {$csrf_field}
                            <div class="input-group input-group-lg">
                                <label class="sr-only" for="chs-val-domain">Domain name</label>
                                <input type="text" class="form-control" id="chs-val-domain" name="chs_domain"
                                       value="{$chsOld.domain|escape}" placeholder="example.com"
                                       autocomplete="off" spellcheck="false" maxlength="260" required>
                                <div class="input-group-append">
                                    <button class="btn btn-primary" type="submit">Value it</button>
                                </div>
                            </div>
                            <small class="form-text text-muted text-center mt-2">
                                Free. No sign-up needed — guests get a few appraisals a day, clients get more.
                                Results are stored to improve the engine, never sold.
                            </small>
                        </form>
                        {if isset($chsErrors.token)}
                            <div class="alert alert-warning mt-3 mb-0">{$chsErrors.token|escape}</div>
                        {/if}
                        {if isset($chsErrors.domain)}
                            <div class="alert alert-warning mt-3 mb-0">{$chsErrors.domain|escape}</div>
                        {/if}
                        {if isset($chsErrors.limit)}
                            <div class="alert alert-info mt-3 mb-0">{$chsErrors.limit|escape}</div>
                        {/if}
                        {if isset($chsErrors.service)}
                            <div class="alert alert-warning mt-3 mb-0">{$chsErrors.service|escape}</div>
                        {/if}
                    </div>
                </div>

                {if $chsResult}
                    <div class="card border-0 shadow-sm chs-card-result mt-4">
                        <div class="card-body">
                            <h2 class="h5">Appraisal for <span class="text-primary">{$chsResult.domain|escape}</span></h2>
                            <div class="chs-valuation-figure">
                                <span class="chs-valuation-number">{$chsResult.estimate|escape}</span>
                                <span class="chs-valuation-meta">
                                    score {$chsResult.score}/98 &middot; confidence: <strong>{$chsResult.confidence|escape}</strong>
                                    &middot; engine: {$chsResult.engine|escape}
                                </span>
                            </div>
                            <p class="text-muted">{$chsResult.summary|escape}</p>

                            <h3 class="h6 mt-4 mb-2">How we got there</h3>
                            <div class="chs-factor-list">
                                {foreach $chsFactors as $f}
                                    <div class="chs-factor chs-factor-{$f.direction|escape}">
                                        <div class="chs-factor-head">
                                            <span class="chs-factor-label">{$f.label|escape}</span>
                                            <span class="chs-factor-score">{$f.score}/99</span>
                                        </div>
                                        <p class="chs-factor-detail mb-0">{$f.detail|escape}</p>
                                    </div>
                                {foreachelse}
                                    <p class="text-muted mb-0">No factor detail recorded.</p>
                                {/foreach}
                            </div>

                            <div class="alert alert-light chs-disclaimer mt-4" role="note">
                                <strong>Read me.</strong> {$chsResult.disclaimer|escape}
                            </div>

                            <div class="text-center">
                                <a href="{$chsSellUrl}" class="btn btn-primary">List this domain on the auction floor</a>
                                <a href="domain-broker.php" class="btn btn-outline-secondary">Talk to a broker instead</a>
                            </div>
                        </div>
                    </div>
                {/if}
                {/if}
            </div>
        </div>
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row">
            <div class="col-lg-4">
                <h3 class="h5">Deterministic, not theatrical</h3>
                <p class="text-muted mb-0">The same name appraised twice returns the same figure. Every number is
                    the product of visible factors below — no keyboard-smashing randomness pretending to be AI.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Rate-limited for fairness</h3>
                <p class="text-muted mb-0">Appraisal is free but metered per account or IP address, so the engine
                    stays fast and honest for everyone. Hit the cap and it says so — no silent rounding or stale
                    numbers.</p>
            </div>
            <div class="col-lg-4">
                <h3 class="h5">Upgrade path</h3>
                <p class="text-muted mb-0">Operators can plug an external appraisal API in from the admin console
                    (Settings &rarr; Valuation). Until then the rules engine answers every request locally, on
                    your own data.</p>
            </div>
        </div>
    </div>
</section>
