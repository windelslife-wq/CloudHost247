{*
 * CLOUDHOST247 — Public WHOIS lookup.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>WHOIS, straight from the registry</h1>
                    <p class="hero-subtitle">Live answers from the authoritative WHOIS — with GDPR redaction
                        preserved, honest privacy labels and a cached service that plays nicely with registries.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">WHOIS Lookup</li>
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
                        <h2 class="h4 text-center mb-3">Look up any domain</h2>
                        <form method="post" action="whois-lookup.php" class="chs-lookup-form">
                            {$csrf_field}
                            <div class="input-group input-group-lg">
                                <label class="sr-only" for="chs-whois-domain">Domain name</label>
                                <input type="text" class="form-control" id="chs-whois-domain" name="chs_domain"
                                       value="{$chsOld.domain|escape}" placeholder="example.com"
                                       autocomplete="off" spellcheck="false" maxlength="260" required>
                                <div class="input-group-append">
                                    <button class="btn btn-primary" type="submit">WHOIS it</button>
                                </div>
                            </div>
                            <small class="form-text text-muted text-center mt-2">
                                The answer comes from the authoritative registry. Lookup volume is rate-limited
                                per address so the service stays healthy.
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
                    {assign var=p value=$chsResult.parsed}
                    <div class="card border-0 shadow-sm chs-card-result mt-4">
                        <div class="card-body">
                            <h2 class="h5">
                                WHOIS record for <span class="text-primary">{$chsResult.domain|escape}</span>
                                {if $chsResult.privacy_protected}
                                    <span class="badge badge-warning" title="The registry redacts personal data — GDPR/ICANN policy">Privacy</span>
                                {/if}
                            </h2>
                            <dl class="row chs-facts mb-0">
                                <dt class="col-sm-4">Registry server</dt>
                                <dd class="col-sm-8"><code>{$chsResult.server|escape}</code></dd>

                                <dt class="col-sm-4">Registrar</dt>
                                <dd class="col-sm-8">
                                    {if $p.registrar}{$p.registrar|escape}{else}
                                        {if $chsResult.privacy_protected}<em class="text-muted">Redacted by policy</em>{else}<em class="text-muted">—</em>{/if}
                                    {/if}
                                </dd>

                                <dt class="col-sm-4">Registered on</dt>
                                <dd class="col-sm-8">{if $p.created}{$p.created|escape}{else}<em class="text-muted">—</em>{/if}</dd>

                                <dt class="col-sm-4">Expires on</dt>
                                <dd class="col-sm-8">{if $p.expires}{$p.expires|escape}{else}<em class="text-muted">—</em>{/if}</dd>

                                <dt class="col-sm-4">Last updated</dt>
                                <dd class="col-sm-8">{if $p.updated}{$p.updated|escape}{else}<em class="text-muted">—</em>{/if}</dd>

                                <dt class="col-sm-4">Statuses</dt>
                                <dd class="col-sm-8">
                                    {foreach $p.statuses as $st}
                                        <span class="badge badge-light chs-status-chip">{$st|escape}</span>
                                    {foreachelse}
                                        <em class="text-muted">—</em>
                                    {/foreach}
                                </dd>

                                <dt class="col-sm-4">Nameservers</dt>
                                <dd class="col-sm-8">
                                    {foreach $p.nameservers as $ns}
                                        <code class="chs-ns-chip">{$ns|escape}</code>
                                    {foreachelse}
                                        <em class="text-muted">—</em>
                                    {/foreach}
                                </dd>

                                <dt class="col-sm-4">DNSSEC</dt>
                                <dd class="col-sm-8">{if $p.dnssec}{$p.dnssec|escape}{else}<em class="text-muted">—</em>{/if}</dd>

                                <dt class="col-sm-4">Abuse contact</dt>
                                <dd class="col-sm-8">{if $p.abuse_email}<a href="mailto:{$p.abuse_email|escape}">{$p.abuse_email|escape}</a>{else}<em class="text-muted">—</em>{/if}</dd>

                                <dt class="col-sm-4">Registrant</dt>
                                <dd class="col-sm-8">
                                    {if $chsResult.privacy_protected}
                                        <span class="text-muted"><i class="fas fa-user-shield" aria-hidden="true"></i>
                                            Redacted by registry policy. Personal data is withheld by the
                                            registry, not by us — GDPR/ICANN requires it.</span>
                                    {elseif $p.registrant_org}
                                        {$p.registrant_org|escape}
                                    {else}
                                        <em class="text-muted">—</em>
                                    {/if}
                                </dd>
                            </dl>

                            <details class="mt-3">
                                <summary><strong>Raw registry response</strong> (verbatim, redactions preserved)</summary>
                                <pre class="chs-raw-block mt-2">{$chsResult.raw|escape}</pre>
                            </details>

                            <div class="text-center mt-4">
                                <a href="{$chsAvailCheck}" class="btn btn-outline-secondary">Check a similar name</a>
                                <a href="{$chsAvailabilityUrl}" class="btn btn-primary">Register this TLD instead</a>
                            </div>
                        </div>
                    </div>
                {/if}
                {/if}
            </div>
        </div>
    </div>
</section>
