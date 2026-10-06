{*
 * CLOUDHOST247 — Build Your Website hub.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Three honest ways to get your site live</h1>
                    <p class="hero-subtitle">No black-box "builder" that vanishes at renewal time: pick the path
                        that matches your skills, your budget and your deadline — each is a real product or service,
                        priced and supported.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Build Your Website</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section">
    <div class="container">
        <div class="chs-plans-grid chs-plans-grid-3">
            <div class="chs-plan-card">
                <h2 class="h4"><i class="fas fa-tools text-primary" aria-hidden="true"></i> Host &amp; build it yourself</h2>
                <p class="text-muted">Install WordPress, WooCommerce or any stack on our hosting.
                    Best if you enjoy control and want the leanest running cost.</p>
                <ul class="chs-plan-points">
                    <li>WordPress-ready plans with staging-friendly tooling</li>
                    <li>cPanel for hands-on management</li>
                    <li>Scale to VPS or dedicated when you actually need it</li>
                </ul>
                <a href="{$chsHostingUrl}" class="btn btn-primary btn-block">WordPress hosting</a>
                <a href="{$chsCpanelUrl}" class="btn btn-outline-secondary btn-block">cPanel hosting</a>
            </div>

            <div class="chs-plan-card">
                <h2 class="h4"><i class="fas fa-drafting-compass text-primary" aria-hidden="true"></i> Have us design it</h2>
                <p class="text-muted">A professional team delivers a finished, on-brand site.
                    Best when your time is worth more than the invoice.</p>
                <ul class="chs-plan-points">
                    <li>Design-driven, not template-swap cosmetic</li>
                    <li>Content, photography and on-page SEO handled</li>
                    <li>Fixed scope, fixed invoice, timeline in writing</li>
                </ul>
                <a href="{$chsDesignUrl}" class="btn btn-primary btn-block">Website Design service</a>
                <a href="{$chsExpertUrl}" class="btn btn-outline-secondary btn-block">Hire an expert instead</a>
            </div>

            <div class="chs-plan-card">
                <h2 class="h4"><i class="fas fa-magic text-primary" aria-hidden="true"></i> Start from an AI draft</h2>
                {if $chsAiConfigured}
                    <p class="text-muted">Describe the site you want; the AI builder drafts the structure, copy and
                        design cues, then a human expert reviews and finishes it.</p>
                    <ul class="chs-plan-points">
                        <li>Useful immediately — pages, sections, SEO meta and imagery briefs</li>
                        <li>Human review before anything ships</li>
                        <li>Full edit history in your account</li>
                    </ul>
                    <a href="{$chsAiUrl}" class="btn btn-primary btn-block">Open the AI builder</a>
                {else}
                    <p class="text-muted">Our AI-assisted drafting is being rolled out on this host; the engine is
                        not yet configured by the operations team. Everything below is already live instead.</p>
                    <ul class="chs-plan-points">
                        <li><a href="{$chsDesignUrl}">Website Design service</a> — immediate and human-run</li>
                        <li><a href="{$chsExpertUrl}">Hire an expert</a> for custom builds and integrations</li>
                        <li>Self-host on <a href="{$chsHostingUrl}">WordPress hosting</a> the DIY way</li>
                    </ul>
                    <a href="{$chsDesignUrl}" class="btn btn-primary btn-block">Get the design service</a>
                {/if}
            </div>
        </div>

        <div class="alert alert-light chs-note mt-4">
            Selling online? Our dedicated <a href="{$chsStoreUrl}">online store</a> page maps the same three
            paths onto checkout, catalogue and fulfilment.
        </div>
    </div>
</section>
