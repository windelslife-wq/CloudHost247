{*
 * CLOUDHOST247 — AI Website Builder landing (honest availability).
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>AI drafts, humans finish</h1>
                    <p class="hero-subtitle">Describe the business in a paragraph. The builder drafts page
                        structure, copy, SEO meta and design direction — then an expert reviews and ships it.
                        That is the whole promise, and it's real.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">AI Website Builder</li>
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
            <div class="col-lg-8">
                {if $chsConfigured}
                    <div class="card border-0 shadow-sm chs-card-primary">
                        <div class="card-body text-center">
                            <h2 class="h4 mb-3">The AI builder is live on this host</h2>
                            <p class="text-muted">Sign in, describe your site, and the first draft is in your
                                account history. Hosted editing happens inside your dashboard — this is not a
                                demo form.</p>
                            <a class="btn btn-primary btn-lg" href="{$chsBuilderUrl}">Open my AI builder</a>
                        </div>
                    </div>
                {else}
                    <div class="card chs-card-notice border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h4"><i class="fas fa-plug" aria-hidden="true"></i> Not configured on this host yet</h2>
                            <p>The AI builder runs on an external model provider that operations must enable
                                deliberately. It is <strong>intentionally</strong> switched off until they do —
                                we do not simulate drafts or ship placeholder "AI magic" screens.</p>
                            {if $chsMissing}
                                <p class="mb-1">Missing configuration (<em>operator-only settings</em>):</p>
                                <ul>
                                    {foreach $chsMissing as $key}
                                        <li><code>{$key|escape}</code></li>
                                    {/foreach}
                                </ul>
                            {/if}
                            <p class="mb-3">Two fully-real alternatives are live right now: the human-run
                                <a href="{$chsDesignUrl}">Website Design service</a>, and
                                <a href="{$chsExpertUrl}">Hire an Expert</a> for custom builds.</p>
                            <a href="{$chsDesignUrl}" class="btn btn-primary">Website Design service</a>
                            <a href="{$chsExpertUrl}" class="btn btn-outline-secondary">Hire an expert</a>
                        </div>
                    </div>
                {/if}

                <div class="row mt-4">
                    <div class="col-md-4">
                        <h3 class="h6">You describe</h3>
                        <p class="text-muted small">One paragraph — who you are, what the site does, audience,
                            language. No design vocabulary required.</p>
                    </div>
                    <div class="col-md-4">
                        <h3 class="h6">AI drafts</h3>
                        <p class="text-muted small">Pages, sections, SEO meta, palette and imagery briefs.
                            Stored in your account; regenerated on request with honest daily limits.</p>
                    </div>
                    <div class="col-md-4">
                        <h3 class="h6">Humans finish</h3>
                        <p class="text-muted small">An expert reviews the draft, adapts copy to your brand and
                            ships it onto your hosting — accountable end-to-end.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
