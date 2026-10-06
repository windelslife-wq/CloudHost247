<div class="container-fluid ch247-digital-products">
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <h1 class="h2 mb-1"><i class="fas fa-download mr-2" aria-hidden="true"></i>My Downloads</h1>
            <p class="text-muted mb-0">Access your CloudHost247 products, releases and licenses in one place.</p>
        </div>
    </div>

    {if $error}
        <div class="alert alert-danger" role="alert">{$error|escape}</div>
    {/if}

    {if !$downloads}
        <div class="card shadow-sm"><div class="card-body text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3" aria-hidden="true"></i>
            <h2 class="h4">Your download library is empty</h2>
            <p class="text-muted mb-0">Products become available here after a linked WHMCS order is paid.</p>
        </div></div>
    {else}
        <div class="row">
        {foreach from=$downloads item=item}
            <div class="col-12 col-xl-6 mb-4">
                <article class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                            <div><span class="badge badge-light text-uppercase">{$item.product_type|escape}</span><h2 class="h4 mt-2 mb-1">{$item.product_name|escape}</h2></div>
                            <span class="badge {if $item.license_status == 'active'}badge-success{else}badge-secondary{/if}">{if $item.license_status}{$item.license_status|escape}{else}Access active{/if}</span>
                        </div>
                        <dl class="row small mt-3 mb-0">
                            <dt class="col-6 text-muted">Current version</dt><dd class="col-6 text-right">{$item.current_version|escape}</dd>
                            <dt class="col-6 text-muted">Purchased version</dt><dd class="col-6 text-right">{$item.purchased_version|escape}</dd>
                            <dt class="col-6 text-muted">Purchased</dt><dd class="col-6 text-right">{$item.purchase_date|escape}</dd>
                            <dt class="col-6 text-muted">Downloads</dt><dd class="col-6 text-right">{$item.downloads_used}{if $item.download_limit} / {$item.download_limit}{else} / Unlimited{/if}</dd>
                        </dl>
                        {if $item.license_key}<div class="mt-3"><label class="small text-muted d-block">License</label><code>{$item.license_key|escape}</code></div>{/if}
                        {if $item.changelog}<div class="alert alert-light small mt-3 mb-0"><strong>Release notes:</strong> {$item.changelog|escape|nl2br}</div>{/if}
                    </div>
                    <div class="card-footer bg-transparent">
                        <form method="post" action="{$modulelink|escape}" class="d-inline">
                            {$csrf_field nofilter}
                            <input type="hidden" name="dp_action" value="generate_token">
                            <input type="hidden" name="entitlement_id" value="{$item.entitlement_id}">
                            <button class="btn btn-primary" type="submit" {if $item.download_remaining === 0}disabled{/if}><i class="fas fa-download mr-1" aria-hidden="true"></i>Download</button>
                        </form>
                        <a class="btn btn-link btn-sm" href="{$modulelink|escape}&amp;action=product&amp;entitlement_id={$item.entitlement_id}">Release details</a>
                        {if $item.new_version}<span class="badge badge-info ml-2">New version available</span>{/if}
                        {if $item.checksum}<span class="small text-muted float-right d-none d-md-inline" title="SHA-256">SHA-256: {$item.checksum|escape|substr:0:12}…</span>{/if}
                    </div>
                </article>
            </div>
        {/foreach}
        </div>
    {/if}
</div>
