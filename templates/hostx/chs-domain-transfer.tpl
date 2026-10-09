{*
 * CLOUDHOST247 — Domain transfer landing.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Move your domain without the theatre</h1>
                    <p class="hero-subtitle">Unlock, authorise, approve, complete — visible states at every step,
                        plus a renewal year on successful transfer.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Transfer a Domain</li>
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
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm chs-card-primary">
                    <div class="card-body">
                        <h2 class="h4 text-center mb-3">Start a transfer</h2>

                        {if $chsErrors}
                            <div class="alert alert-danger">{foreach $chsErrors as $err}<div>{$err|escape}</div>{/foreach}</div>
                        {/if}

                        {if $chsCreated}
                            <div class="alert alert-success">
                                Transfer requested for <strong>{$chsCreated.domain|escape}</strong> — invoice
                                <strong>#{$chsCreated.invoice_id}</strong> issued. The transfer is submitted to the
                                registry as soon as the invoice is paid.
                                <div class="mt-2">
                                    <a class="btn btn-success" href="{$chsCreated.url|escape}">View &amp; pay invoice</a>
                                    <a class="btn btn-outline-secondary" href="{$chsPortalTransfersUrl|escape}&action=transfer&id={$chsCreated.transfer_id}">Track the transfer</a>
                                </div>
                            </div>
                        {/if}

                        {if $chsEligibility}
                            {if $chsEligibility.eligible === true}
                                <div class="alert alert-success">
                                    Eligible to transfer.
                                    {if $chsQuote}Quoted price: <strong>{$chsQuote.final_fmt|escape}</strong> for 1 year
                                    (includes the renewal year){if $chsQuote.discount} <span class="badge badge-info">{$chsQuote.discount|escape}% club discount</span>{/if}.{/if}
                                    {if !$chsLoggedIn}<br>Sign in to create the tracked transfer here, or continue in the
                                    <a href="{$chsSearchUrl|escape}{$chsOld.domain|escape:'url'}">standard cart flow</a>.{/if}
                                </div>
                            {elseif $chsEligibility.eligible === null}
                                <div class="alert alert-warning">We could not fully verify eligibility — the registry
                                    lookup is unavailable right now. {foreach $chsEligibility.reasons as $r}{$r|escape} {/foreach}</div>
                            {else}
                                <div class="alert alert-danger">Not transferable right now:
                                    {foreach $chsEligibility.reasons as $r}<div>{$r|escape}</div>{/foreach}</div>
                            {/if}
                        {/if}

                        <form method="post" action="domain-transfer.php" class="chs-lookup-form">
                            {$csrf_field}
                            <div class="form-group text-left">
                                <label for="chs-transfer-domain">Domain to transfer</label>
                                <input type="text" class="form-control form-control-lg" id="chs-transfer-domain"
                                       name="chs_domain" value="{$chsOld.domain|escape}"
                                       placeholder="yourdomain.com" autocomplete="off" spellcheck="false" maxlength="256" required>
                            </div>
                            <div class="form-group text-left">
                                <label for="chs-transfer-epp">EPP / auth code <small class="text-muted">(from your current registrar)</small></label>
                                <input type="text" class="form-control form-control-lg" id="chs-transfer-epp"
                                       name="chs_epp" value="{$chsOld.epp|escape}"
                                       autocomplete="off" spellcheck="false" maxlength="64">
                            </div>
                            <small class="form-text text-muted text-center d-block mb-2">
                                We check transferability and quote the exact price before any charge. The EPP code
                                is stored encrypted and is only used to submit the transfer after payment.
                                No bait-and-switch on checkout.
                            </small>
                            <div class="text-center">
                                <button class="btn btn-primary btn-lg" type="submit">
                                    {if $chsLoggedIn}Request transfer{else}Check eligibility &amp; price{/if}
                                </button>
                            </div>
                        </form>
                        <p class="text-center mt-3 mb-0">
                            <a href="{$chsWhoisUrl}">Check the current WHOIS first</a> — unlock status and the
                            registrant address matter for approval emails.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <h2 class="h5">Before you press go — the honest checklist</h2>
                <ul class="chs-checklist">
                    <li><i class="fas fa-unlock" aria-hidden="true"></i> <strong>Unlocked at current registrar</strong> — most transfer failures are a lock you forgot about</li>
                    <li><i class="fas fa-key" aria-hidden="true"></i> <strong>EPP / auth code in hand</strong> — issued by the current registrar, usually in their dashboard</li>
                    <li><i class="fas fa-envelope-open-text" aria-hidden="true"></i> <strong>Registrant email reachable</strong> — the registry's approval mail lands there, not at us</li>
                    <li><i class="fas fa-user-clock" aria-hidden="true"></i> <strong>Not transferred or registered in the last 60 days</strong> — registry rules, not ours</li>
                    <li><i class="fas fa-calendar-check" aria-hidden="true"></i> <strong>Not expiring in the next week</strong> — renewal first, transfer second, avoids edge cases</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row">
            <div class="col-md-3 col-6">
                <div class="chs-step">
                    <span class="chs-step-num">1</span>
                    <h3 class="h6">Unlock &amp; authorise</h3>
                    <p class="text-muted small mb-0">Unlock at the current registrar, request the EPP/auth code.</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="chs-step">
                    <span class="chs-step-num">2</span>
                    <h3 class="h6">Order &amp; pay</h3>
                    <p class="text-muted small mb-0">Enter the code here; the price on-screen is the price you pay.</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="chs-step">
                    <span class="chs-step-num">3</span>
                    <h3 class="h6">Registry approval</h3>
                    <p class="text-muted small mb-0">Approve the registry email at the registrant address.</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="chs-step">
                    <span class="chs-step-num">4</span>
                    <h3 class="h6">Complete</h3>
                    <p class="text-muted small mb-0">Five to seven days total; a renewal year is added on success.</p>
                </div>
            </div>
        </div>
        <div class="row mt-4">
            <div class="col-lg-8 mx-auto text-center">
                <p class="text-muted mb-3">Compare timing and pricing first: see the per-extension transfer figures on the
                    <a href="{$chsDirectoryUrl}">TLD directory</a>. Acquiring a taken premium name? The
                    <a href="{$chsBrokerUrl}">Domain Broker service</a> handles that properly.</p>
            </div>
        </div>
    </div>
</section>
