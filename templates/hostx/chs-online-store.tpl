{*
 * CLOUDHOST247 — Online Store hub.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Your store, on infrastructure you actually control</h1>
                    <p class="hero-subtitle">WooCommerce on our hosting, or a managed build delivered by humans —
                        with the gateways and catalogue yours, not rented with a switch-off date.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Online Store</li>
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
                <h2 class="h4">Self-hosted WooCommerce</h2>
                <p class="text-muted">Full control of the stack: WordPress + WooCommerce on plans that handle the checkout load.</p>
                <ul class="chs-plan-points">
                    <li>PHP cache + database tuned for commerce traffic</li>
                    <li>Free auto-SSL so checkout never runs over plain HTTP</li>
                    <li>Plugin freedom: any payment gateway your country supports</li>
                </ul>
                <a href="{$chsHostingUrl}" class="btn btn-primary btn-block">WooCommerce-ready hosting</a>
                <a href="{$chsCpanelUrl}" class="btn btn-outline-secondary btn-block">cPanel hosting</a>
            </div>
            <div class="chs-plan-card">
                <h2 class="h4">Managed store build</h2>
                <p class="text-muted">Our team builds catalogue, payments, shipping and order flows to spec — launch when you're ready.</p>
                <ul class="chs-plan-points">
                    <li>Catalogue import + product page structure</li>
                    <li>Payment/shipping/tax configured for your jurisdiction</li>
                    <li>QA on a real staging copy before DNS flips</li>
                </ul>
                <a href="{$chsExpertUrl}" class="btn btn-primary btn-block">Hire an expert team</a>
            </div>
            <div class="chs-plan-card">
                <h2 class="h4">Grow the traffic</h2>
                <p class="text-muted">A store without traffic is warehouse décor. Stand up acquisition alongside the storefront.</p>
                <ul class="chs-plan-points">
                    <li><a href="{$chsMarketingUrl}">Digital marketing</a> — SEO, search ads, email flows</li>
                    <li>Analytics and conversion instrumentation done right</li>
                    <li>Expert retainer for ongoing catalog/landing changes</li>
                </ul>
                <a href="{$chsMarketingUrl}" class="btn btn-primary btn-block">Digital marketing</a>
                <a href="{$chsSslUrl}" class="btn btn-outline-secondary btn-block">Certificates</a>
            </div>
        </div>

        <div class="alert alert-light chs-note mt-4">
            <strong>Honest note on checkout:</strong> your store takes payments over whichever gateway you wire
            (Stripe, Adyen, PayPal, local processors) — we never pretend the host is a bank. That means lower fees
            for you and a store that genuinely belongs to your books.
        </div>
    </div>
</section>
