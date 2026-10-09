<div class="ch247-vps" data-endpoint="{$vps_api_url|escape:'html'}" data-csrf="{$vps_csrf|escape:'html'}">
    <h1>My VPS</h1>
    <p>Checkout and billing are handled by WHMCS. An existing paid VPS service can request one server using the operator-approved configuration; no charge is made on this page.</p>
    {if $vps_error}<div class="alert alert-warning" role="alert">{$vps_error|escape:'html'}</div>{/if}
    {if !$vps_enabled}
        <div class="alert alert-info" role="status">VPS self-service provisioning is not available yet. Your existing WHMCS services are unaffected.</div>
    {else}
        <h2>VPS products</h2>
        {if $vps_products}
            <ul class="list-group">
                {foreach from=$vps_products item=product}
                    <li class="list-group-item">{$product.name|escape:'html'}
                        <a class="btn btn-default btn-sm pull-right" href="{$WEB_ROOT|escape:'html'}/cart.php?a=add&amp;pid={$product.product_id|intval}">Order in WHMCS</a>
                    </li>
                {/foreach}
            </ul>
            <p>WHMCS shows the authoritative price, billing cycle, and invoice at checkout. Return here once the linked invoice has been paid.</p>
        {else}
            <p>No VPS products are currently available to order.</p>
        {/if}

        <h2>Your VPS services</h2>
        {if $vps_services}
            <ul class="list-group">
                {foreach from=$vps_services item=service}
                    <li class="list-group-item">
                        <strong>{$service.product|escape:'html'}</strong> — WHMCS service #{$service.id|intval}
                        ({$service.status|escape:'html'})
                        {if $service.existing}
                            <span class="label label-info">Server request already recorded</span>
                        {else}
                            <button type="button" class="btn btn-primary btn-sm ch247-vps-request"
                                data-service-id="{$service.id|intval}" data-request-key="{$service.request_key|escape:'html'}">
                                Request VPS for paid service
                            </button>
                        {/if}
                        <span class="ch247-vps-feedback" role="status" aria-live="polite"></span>
                    </li>
                {/foreach}
            </ul>
        {else}
            <p>No mapped WHMCS VPS services were found on your account.</p>
        {/if}
        <noscript><p>JavaScript is required to submit a VPS request. Checkout and existing services remain available in WHMCS.</p></noscript>
        <script src="{$vps_js_url|escape:'html'}" defer></script>
    {/if}
</div>
