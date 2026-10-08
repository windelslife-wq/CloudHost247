<div class="ch247-panel-catalog">
    <div class="alert alert-info" role="status">
        <strong>Control-panel catalog only.</strong>
        These entries describe panel software and WHMCS price references. Orders, server provisioning, panel installation,
        and license activation are not available through this catalog yet.
    </div>

    {if $catalog_error}
        <div class="alert alert-warning">{$catalog_error|escape:'html'}</div>
    {/if}

    {if $panel_categories}
        <nav aria-label="Control-panel categories" class="panel-catalog-categories">
            <ul class="list-inline">
                {foreach from=$panel_categories item=category}
                    <li><a href="#panel-category-{$category.slug|escape:'url'}">{$category.name|escape:'html'}</a></li>
                {/foreach}
            </ul>
        </nav>
    {/if}

    {if $control_panels}
        {foreach from=$panel_categories item=category}
            <section class="panel-catalog-category" id="panel-category-{$category.slug|escape:'html'}">
                <h2>{$category.name|escape:'html'}</h2>
                {if $category.description}
                    <p>{$category.description|escape:'html'}</p>
                {/if}
                <div class="row">
                    {foreach from=$control_panels item=panel}
                        {if $panel.category.id eq $category.id}
                            <div class="col-sm-6 col-lg-4">
                                <article class="panel panel-default panel-catalog-card">
                                    <div class="panel-heading">
                                        <h3 class="panel-title">{$panel.name|escape:'html'}</h3>
                                        <small>{$panel.vendor|escape:'html'}</small>
                                    </div>
                                    <div class="panel-body">
                                        <p>
                                            <span class="label label-info">Catalog metadata</span>
                                            <span class="label label-default">Not deployable</span>
                                        </p>
                                        <p><strong>Integration status:</strong> {$panel.integration_status|replace:'_':' '|ucfirst|escape:'html'}<br>
                                            <strong>Installation status:</strong> {$panel.installation_status|replace:'_':' '|ucfirst|escape:'html'}</p>
                                        {if $panel.summary}
                                            <p><strong>{$panel.summary|escape:'html'}</strong></p>
                                        {/if}
                                        {if $panel.description}
                                            <p>{$panel.description|escape:'html'|nl2br}</p>
                                        {/if}

                                        <h4>Requirements and compatibility</h4>
                                        {if $panel.supported_os}
                                            <p><strong>Operating systems:</strong>
                                                {foreach from=$panel.supported_os item=os name=osList}
                                                    {$os|escape:'html'}{if not $smarty.foreach.osList.last}, {/if}
                                                {/foreach}
                                            </p>
                                        {else}
                                            <p><strong>Operating systems:</strong> Not specified</p>
                                        {/if}
                                        <ul class="list-unstyled">
                                            {if $panel.requirements.minimum_cpu_cores gt 0}
                                                <li><strong>Minimum CPU:</strong> {$panel.requirements.minimum_cpu_cores|intval} cores</li>
                                            {/if}
                                            {if $panel.requirements.minimum_memory_mb gt 0}
                                                <li><strong>Minimum memory:</strong> {$panel.requirements.minimum_memory_mb|intval} MiB</li>
                                            {/if}
                                            {if $panel.requirements.minimum_storage_gb gt 0}
                                                <li><strong>Minimum storage:</strong> {$panel.requirements.minimum_storage_gb|intval} GiB</li>
                                            {/if}
                                        </ul>
                                        {if $panel.requirements.notes}
                                            <p>{$panel.requirements.notes|escape:'html'}</p>
                                        {/if}

                                        <h4>License metadata</h4>
                                        <p>{$panel.license_model|replace:'_':' '|ucfirst|escape:'html'}</p>
                                        {if $panel.license_terms_summary}
                                            <p>{$panel.license_terms_summary|escape:'html'|nl2br}</p>
                                        {/if}
                                        {if $panel.license_terms_url}
                                            <p><a href="{$panel.license_terms_url|escape:'html'}" target="_blank" rel="noopener noreferrer">License terms from vendor</a></p>
                                        {/if}

                                        {assign var=hasCapabilities value=false}
                                        {foreach from=$panel.capabilities item=enabled}
                                            {if $enabled}{assign var=hasCapabilities value=true}{/if}
                                        {/foreach}
                                        {if $hasCapabilities}
                                            <h4>Documented product capabilities</h4>
                                            <ul>
                                                {foreach from=$panel.capabilities key=capability item=enabled}
                                                    {if $enabled}
                                                        <li>{$capability|replace:'_':' '|ucfirst|escape:'html'}</li>
                                                    {/if}
                                                {/foreach}
                                            </ul>
                                        {/if}

                                        <p class="panel-catalog-sources">
                                            {if $panel.official_source_url}
                                                <a href="{$panel.official_source_url|escape:'html'}" target="_blank" rel="noopener noreferrer">Official source</a>
                                            {/if}
                                            {if $panel.documentation_url}
                                                &middot; <a href="{$panel.documentation_url|escape:'html'}" target="_blank" rel="noopener noreferrer">Documentation</a>
                                            {/if}
                                            {if $panel.support_url}
                                                &middot; <a href="{$panel.support_url|escape:'html'}" target="_blank" rel="noopener noreferrer">Support</a>
                                            {/if}
                                        </p>

                                        <h4>Catalog plans</h4>
                                        {if $panel.plans}
                                            {foreach from=$panel.plans item=plan}
                                                <div class="well well-sm">
                                                    <strong>{$plan.name|escape:'html'}</strong>
                                                    {if $plan.summary}<p>{$plan.summary|escape:'html'}</p>{/if}
                                                    {if $plan.description}<p>{$plan.description|escape:'html'|nl2br}</p>{/if}
                                                    <ul class="list-unstyled">
                                                        {if $plan.resources.cpu_cores gt 0}<li>{$plan.resources.cpu_cores|intval} vCPU</li>{/if}
                                                        {if $plan.resources.memory_mb gt 0}<li>{$plan.resources.memory_mb|intval} MiB memory</li>{/if}
                                                        {if $plan.resources.storage_gb gt 0}<li>{$plan.resources.storage_gb|intval} GiB storage</li>{/if}
                                                        {if $plan.resources.bandwidth_gb gt 0}<li>{$plan.resources.bandwidth_gb|intval} GiB bandwidth</li>{/if}
                                                        {if $plan.resources.max_accounts gt 0}<li>Up to {$plan.resources.max_accounts|intval} accounts</li>{/if}
                                                    </ul>
                                                    {if $plan.price_reference_configured}
                                                        <small class="text-muted">WHMCS price reference configured. Orders are not enabled.</small>
                                                    {else}
                                                        <small class="text-muted">No WHMCS price reference configured.</small>
                                                    {/if}
                                                </div>
                                            {/foreach}
                                        {else}
                                            <p class="text-muted">No plans have been published for this catalog entry.</p>
                                        {/if}
                                    </div>
                                </article>
                            </div>
                        {/if}
                    {/foreach}
                </div>
            </section>
        {/foreach}
    {else}
        <div class="well">
            <h2>No control panels are listed yet</h2>
            <p>Panel records will appear here after an administrator publishes verified catalog metadata.</p>
        </div>
    {/if}
</div>
