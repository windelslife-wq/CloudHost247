{*
 * CLOUDHOST247 — Domain Services section.
 *
 * Three columns: Find a Domain / Domain Investing / Domain Tools & Services.
 * Every item is a working link into a live service page.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Domain services, end to end</h1>
                    <p class="hero-subtitle">Find it, transfer it, invest in it, manage it — every service below is a
                        live CloudHost247 system, not a placeholder.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Domain Services</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section chs-domains-services">
    <div class="container">
        <div class="row">
            {foreach $chsServices as $column}
                <div class="col-lg-4 col-md-6 col-12">
                    <h2 class="h4 chs-domains-col-title">{$column.title|escape}</h2>
                    {foreach $column.items as $item}
                        <a href="{$item.url|escape}" class="card border-0 shadow-sm chs-domain-service-card">
                            <div class="card-body">
                                <div class="chs-domain-service-head">
                                    <span class="chs-domain-service-icon"><i class="fas {$item.icon|escape}" aria-hidden="true"></i></span>
                                    <h3 class="h5 mb-0">{$item.title|escape}</h3>
                                </div>
                                <p class="text-muted small mb-2">{$item.text|escape}</p>
                                <span class="btn btn-sm btn-outline-primary">{$item.cta|escape} <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                            </div>
                        </a>
                    {/foreach}
                </div>
            {/foreach}
        </div>
    </div>
</section>

<section class="chs-section chs-section-muted">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h2 class="h4">Already a customer?</h2>
                <p class="text-muted">Manage your domains, transfers and memberships from the client portal.</p>
                <p>
                    <a class="btn btn-primary" href="{$chsPortalUrl|escape}&action=domains">My domains</a>
                    <a class="btn btn-default" href="{$chsPortalUrl|escape}&action=transfers">My transfers</a>
                    <a class="btn btn-default" href="{$chsPortalUrl|escape}&action=search">Search in the portal</a>
                </p>
            </div>
        </div>
    </div>
</section>
