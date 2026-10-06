{*
 * CLOUDHOST247 — Logo Maker landing.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>A real vector logo, not a stock-template shrug</h1>
                    <p class="hero-subtitle">Four concept styles generated live from your inputs with curated
                        palettes — saved projects, live previews and SVG that stays sharp at booth-banner size.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Logo Maker</li>
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

        <div class="row align-items-center">
            <div class="col-lg-6">
                <h2 class="h4">What the studio generates — deterministically</h2>
                <p class="text-muted">No lottery of random thumbnails. The studio computes layouts from your
                    company name, industry, style and palette — preview is byte-identical to the final export,
                    so what you see is unambiguously what you get.</p>
                <ul class="chs-checklist">
                    <li><i class="fas fa-bolt" aria-hidden="true"></i> <strong>Four concepts</strong>: wordmark, monogram, icon-plus-name combo and badge</li>
                    <li><i class="fas fa-palette" aria-hidden="true"></i> <strong>{if $chsPalettes}{count($chsPalettes)}{else}curated{/if} palettes</strong> tuned for print and screen contrast</li>
                    <li><i class="fas fa-industry" aria-hidden="true"></i> <strong>{if $chsIndustries}{count($chsIndustries)}{else}industry{/if} icon sets</strong> — commerce, food, tech, legal and more</li>
                    <li><i class="fas fa-archive" aria-hidden="true"></i> <strong>Saved projects</strong> inside your account, per client</li>
                    <li><i class="fas fa-file-code" aria-hidden="true"></i> <strong>SVG export always works</strong>
                        {if isset($chsPngAvailable)}
                            {if $chsPngAvailable}
                                — PNG too: this host renders a 1200-px raster on request
                            {else}
                                ; PNG raster needs the server's Imagick extension, not installed here, so the
                                studio says so instead of silently shipping a broken file
                            {/if}
                        {/if}
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 mt-4 mt-lg-0">
                <div class="card border-0 shadow-sm chs-card-primary">
                    <div class="card-body text-center">
                        <h3 class="h5 mb-3">Sketch free, settle once</h3>
                        <p class="text-muted">Play in the studio for free. Export SVG whenever you like —
                            licensing is plain: the vectors you export are yours, for every brand use.</p>
                        <a class="btn btn-primary btn-lg" href="{$chsStudioUrl}">{if $chsLoggedIn}Open my logo studio{else}Sign in to open the studio{/if}</a>
                    </div>
                </div>
            </div>
        </div>
        {/if}
    </div>
</section>
