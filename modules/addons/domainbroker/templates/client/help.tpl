{*
 * Domain Broker — FAQ / help.
 *}
<div class="domainbroker-portal">

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">Domain Broker help</h1>
            <p class="db-portal-subtitle">How brokered acquisitions work at {$brand}.</p>
        </div>
        <div class="db-portal-header-actions">
            <a href="{$urls.new}" class="btn btn-primary">Start a request</a>
        </div>
    </div>

    <ul class="nav nav-pills db-nav">
        {foreach $nav as $item}
            <li role="presentation"{if $item.active} class="active"{/if}>
                <a href="{$item.url}"><i class="fa {$item.icon}"></i> {$item.label}</a>
            </li>
        {/foreach}
    </ul>

    <div class="panel-group db-faq" id="db-faq" role="tablist">
        {foreach $faqs as $index => $faq}
            <div class="panel panel-default">
                <div class="panel-heading" role="tab" id="db-faq-head-{$index}">
                    <h4 class="panel-title">
                        <a role="button" data-toggle="collapse" data-parent="#db-faq"
                           href="#db-faq-body-{$index}"
                           aria-expanded="{if $index == 0}true{else}false{/if}"
                           aria-controls="db-faq-body-{$index}">
                            {$faq.q}
                        </a>
                    </h4>
                </div>
                <div id="db-faq-body-{$index}" class="panel-collapse collapse{if $index == 0} in{/if}"
                     role="tabpanel" aria-labelledby="db-faq-head-{$index}">
                    <div class="panel-body">{$faq.a}</div>
                </div>
            </div>
        {/foreach}
    </div>

    {if $support_email}
        <div class="db-helpstrip">
            <div><strong>Still stuck?</strong> Email us at {$support_email} and we will come back to you.</div>
        </div>
    {/if}
</div>
