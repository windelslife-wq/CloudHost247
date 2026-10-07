<div class="alert alert-info">
    <strong>Cloudflare service</strong>
    {if $cloudflare_service.zone_id}
        <p>Zone status: {$cloudflare_service.status|escape}. Plan: {$cloudflare_service.plan_label|escape}. Domain: {$cloudflare_service.zone_name|escape}.</p>
        <p><a class="btn btn-primary" href="{$cloudflare_url|escape}">Open Cloudflare management</a></p>
    {else}
        <p>Cloudflare provisioning is pending. The zone will appear here after Cloudflare confirms the request.</p>
    {/if}
</div>
