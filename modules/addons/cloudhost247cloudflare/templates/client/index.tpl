<div class="cf247-portal">
    <div class="cf247-heading">
        <div>
            <div class="cf247-eyebrow">CLOUDHOST247 · CLOUDFLARE</div>
            <h1>{$cloudflare_title|escape}</h1>
        </div>
        {if $service}
            <a class="btn btn-default" href="index.php?m=cloudhost247cloudflare">All Cloudflare services</a>
        {/if}
    </div>

    {if $success}<div class="alert alert-success">{$success|escape}</div>{/if}
    {if $error}<div class="alert alert-danger">{$error|escape}</div>{/if}
    {if $section_error}<div class="alert alert-warning">{$section_error|escape}</div>{/if}
    {if $notice}<div class="alert alert-warning">{$notice|escape}</div>{/if}

    {if $login_required}
        <div class="cf247-card"><h3>Sign in to continue</h3><p>Sign in to your CloudHost247 account to view and manage Cloudflare services.</p><a class="btn btn-primary" href="clientarea.php">Sign in to CloudHost247</a></div>
    {elseif $unavailable}
        <div class="cf247-card"><h3>Cloudflare services unavailable</h3><p>{$message|escape}</p></div>
    {elseif $list_view}
        {if $services}
            <div class="cf247-service-grid">
                {foreach from=$services item=item}
                    <a class="cf247-service-card" href="index.php?m=cloudhost247cloudflare&action=service&id={$item.id|intval}&tab=overview">
                        <div class="cf247-service-card-top"><span class="cf247-icon"><i class="fa fa-cloud"></i></span><span class="cf247-status cf247-status-{$item.status|lower|escape}">{$item.status|replace:'_':' '|escape}</span></div>
                        <h3>{$item.plan_label|escape}</h3>
                        <div class="cf247-domain">{$item.display_domain|escape}</div>
                        <div class="cf247-service-meta">{if $item.zone_id}Zone: {$item.activation_status|replace:'_':' '|escape}{else}Provisioning not complete{/if}</div>
                    </a>
                {/foreach}
            </div>
        {else}
            <div class="cf247-card cf247-empty">
                <span class="cf247-icon cf247-icon-large"><i class="fa fa-cloud"></i></span>
                <h3>No Cloudflare services yet</h3>
                <p>Cloudflare products purchased through CloudHost247 will appear here after payment and provisioning.</p>
                <a href="cart.php" class="btn btn-primary">Browse services</a>
            </div>
        {/if}
    {elseif $service}
        <div class="cf247-overview-bar">
            <div>
                <div class="cf247-eyebrow">{$service.plan_label|escape}</div>
                <h2>{$service.zone_name|escape}</h2>
                <div class="cf247-muted">Zone ID <code>{$service.zone_id|escape}</code></div>
            </div>
            <div class="cf247-status-group">
                <span class="cf247-status cf247-status-{$service.status|lower|escape}">Service: {$service.status|replace:'_':' '|escape}</span>
                <span class="cf247-status cf247-status-{$service.activation_status|lower|escape}">Zone: {$service.activation_status|replace:'_':' '|escape}</span>
            </div>
        </div>

        <nav class="cf247-tabs">
            {foreach from=$tabs item=nav}
                <a class="{if $active_tab eq $nav.key}active{/if}" href="index.php?m=cloudhost247cloudflare&action=service&id={$service.id|intval}&tab={$nav.key|escape}">{$nav.label|escape}</a>
            {/foreach}
        </nav>

        {if $active_tab eq 'overview'}
            <div class="cf247-card">
                <h3>Service overview</h3>
                <div class="cf247-overview-grid">
                    <div><span>Domain</span><strong>{$service.zone_name|escape}</strong></div>
                    <div><span>Cloudflare plan</span><strong>{$service.plan_label|escape}</strong></div>
                    <div><span>Zone status</span><strong>{$service.activation_status|replace:'_':' '|escape}</strong></div>
                    <div><span>Last synchronized</span><strong>{if $service.last_synced_at}{$service.last_synced_at|escape}{else}Not synchronized yet{/if}</strong></div>
                    <div><span>SSL mode</span><strong>{$service.ssl_mode|escape}</strong></div>
                    <div><span>DNS record cache</span><strong>Provider data is synchronized on demand</strong></div>
                </div>
            </div>
            <div class="cf247-card">
                <h3>Cloudflare nameservers</h3>
                {if $nameservers}
                    <p>Update the nameservers at your domain registrar. Cloudflare will confirm activation after the change is visible.</p>
                    <div class="cf247-ns-list">
                        {foreach from=$nameservers item=ns}
                            <div class="cf247-ns-row"><code>{$ns|escape}</code><button type="button" class="btn btn-default btn-xs cf247-copy" data-copy="{$ns|escape}">Copy</button></div>
                        {/foreach}
                    </div>
                    <div class="cf247-note"><strong>Nameserver status:</strong> {$service.activation_status|replace:'_':' '|escape}. Do not remove existing nameservers until your registrar accepts the new values.</div>
                    <p class="cf247-muted">Your domain must use these nameservers before Cloudflare can proxy DNS traffic. CloudHost247 does not mark the zone active until Cloudflare reports it as active.</p>
                {else}
                    <div class="alert alert-warning">Cloudflare has not returned nameservers for this zone yet. Nameserver data is not fabricated; try again after synchronization or contact support.</div>
                {/if}
            </div>
            <div class="cf247-card">
                <h3>Plan entitlements</h3>
                <div class="cf247-feature-grid">
                    {foreach from=$features key=feature item=enabled}
                        {if $enabled}<span><i class="fa fa-check"></i> {$feature|replace:'.':' '|replace:'_':' '|escape}</span>{/if}
                    {/foreach}
                </div>
            </div>
        {elseif $active_tab eq 'dns'}
            <div class="cf247-card">
                <div class="cf247-card-heading"><div><h3>DNS records</h3><p>Cloudflare is the source of truth. Records below are a synchronized local representation.</p></div><a class="btn btn-default btn-sm" href="index.php?m=cloudhost247cloudflare&action=service&id={$service.id|intval}&tab=dns&refresh=1">Refresh DNS</a></div>
                {if $selected_record}
                    <h4>Edit DNS record</h4>
                    <form method="post" class="cf247-form-grid">
                        {$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="dns_update"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="dns"><input type="hidden" name="record_id" value="{$selected_record.cloudflare_record_id|escape}"><input type="hidden" name="type" value="{$selected_record.type|escape}">
                        <div class="form-group"><label>Type</label><input class="form-control" value="{$selected_record.type|escape}" disabled></div>
                        <div class="form-group"><label>Name</label><input class="form-control" name="name" value="{$selected_record.name|escape}" required></div>
                        <div class="form-group cf247-wide"><label>Content</label><input class="form-control" name="content" value="{$selected_record.content|escape}" required></div>
                        <div class="form-group"><label>TTL (1 = Automatic)</label><input class="form-control" type="number" min="1" max="86400" name="ttl" value="{$selected_record.ttl|intval}"></div>
                        <div class="form-group"><label>Priority</label><input class="form-control" type="number" min="0" max="65535" name="priority" value="{$selected_record.priority|intval}"></div>
                        <div class="form-group"><label>Proxy</label><select class="form-control" name="proxied"><option value="0">DNS only</option><option value="1"{if $selected_record.proxied} selected{/if}>Proxied</option></select></div>
                        <div class="form-group"><label>Comment</label><input class="form-control" name="comment" value="{$selected_record.comment|escape}"></div>
                        <div class="cf247-wide"><button class="btn btn-primary">Save record</button> <a class="btn btn-default" href="index.php?m=cloudhost247cloudflare&action=service&id={$service.id|intval}&tab=dns">Cancel</a></div>
                    </form>
                    <hr>
                {/if}
                <div class="table-responsive"><table class="table table-striped cf247-table"><thead><tr><th>Type</th><th>Name</th><th>Content</th><th>TTL</th><th>Proxy</th><th>Priority</th><th>Ownership</th><th>Actions</th></tr></thead><tbody>
                    {foreach from=$records item=record}
                        <tr><td>{$record.type|escape}</td><td>{$record.name|escape}</td><td class="cf247-content">{$record.content|escape}</td><td>{if $record.ttl eq 1}Auto{else}{$record.ttl|intval}{/if}</td><td>{if $record.proxied}Proxied{else}DNS only{/if}</td><td>{$record.priority|escape}</td><td>{$record.ownership|replace:'_':' '|escape}</td><td><a class="btn btn-xs btn-default" href="index.php?m=cloudhost247cloudflare&action=service&id={$service.id|intval}&tab=dns&record_id={$record.cloudflare_record_id|escape}">Edit</a>
                        <form method="post" class="cf247-inline-form" onsubmit="return confirm('Delete DNS Record? This action will remove the record from Cloudflare.')">{$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="dns_delete"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="dns"><input type="hidden" name="record_id" value="{$record.cloudflare_record_id|escape}"><input type="hidden" name="confirm_delete" value="1"><button class="btn btn-xs btn-danger">Delete</button></form></td></tr>
                    {foreachelse}<tr><td colspan="8" class="text-muted">No DNS records were returned by Cloudflare.</td></tr>{/foreach}
                </tbody></table></div>
                <h4>Add DNS record</h4>
                <form method="post" class="cf247-form-grid">
                    {$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="dns_create"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="dns">
                    <div class="form-group"><label>Type</label><select class="form-control" name="type">{foreach from=$dns_types item=type}<option value="{$type|escape}">{$type|escape}</option>{/foreach}</select></div>
                    <div class="form-group"><label>Name (@ for zone root)</label><input class="form-control" name="name" placeholder="www or www.example.com" required></div>
                    <div class="form-group cf247-wide"><label>Content</label><input class="form-control" name="content" required></div>
                    <div class="form-group"><label>TTL (1 = Automatic)</label><input class="form-control" type="number" min="1" max="86400" name="ttl" value="1"></div>
                    <div class="form-group"><label>Priority (MX / SRV)</label><input class="form-control" type="number" min="0" max="65535" name="priority" value="10"></div>
                    <div class="form-group"><label>Proxy status</label><select class="form-control" name="proxied"><option value="0">DNS only</option><option value="1">Proxied</option></select></div>
                    <div class="form-group"><label>Comment</label><input class="form-control" name="comment" maxlength="512"></div>
                    <div class="cf247-wide"><button class="btn btn-primary">Add DNS record</button></div>
                </form>
            </div>
        {elseif $active_tab eq 'dnssec'}
            <div class="cf247-card"><h3>DNSSEC</h3>
                {if $dnssec}
                    <p><strong>Status:</strong> {$dnssec.status|default:'DATA_UNAVAILABLE'|escape}</p>
                    {if $dnssec.ds}<p><strong>DS record:</strong> <code>{$dnssec.ds|escape}</code></p>{/if}
                    {if $dnssec.key_tag}<p><strong>Key tag:</strong> {$dnssec.key_tag|escape} &nbsp; <strong>Algorithm:</strong> {$dnssec.algorithm|escape} &nbsp; <strong>Digest type:</strong> {$dnssec.digest_type|escape} &nbsp; <strong>Digest:</strong> <code>{$dnssec.digest|escape}</code></p>{/if}
                    <p>Enable DNSSEC at Cloudflare, then publish the returned DS record at your domain registrar.</p>
                    <form method="post" class="cf247-inline-form">{$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="dnssec_change"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="dnssec"><input type="hidden" name="enabled" value="{if $dnssec.status eq 'active'}0{else}1{/if}"><button class="btn btn-primary">{if $dnssec.status eq 'active'}Disable DNSSEC{else}Enable DNSSEC{/if}</button></form>
                {else}<p class="text-muted">DNSSEC data is not available from Cloudflare.</p>{/if}
            </div>
        {elseif $active_tab eq 'analytics'}
            <div class="cf247-card"><div class="cf247-card-heading"><div><h3>Analytics</h3><p>Metrics are returned only when Cloudflare provides them for this account and zone.</p></div><form method="get"><input type="hidden" name="m" value="cloudhost247cloudflare"><input type="hidden" name="action" value="service"><input type="hidden" name="id" value="{$service.id|intval}"><input type="hidden" name="tab" value="analytics"><select class="form-control" name="range" onchange="this.form.submit()"><option value="24h">Last 24 hours</option><option value="7d"{if $smarty.get.range eq '7d'} selected{/if}>7 days</option><option value="30d"{if $smarty.get.range eq '30d'} selected{/if}>30 days</option></select></form></div>
                {if $analytics.metrics}<div class="cf247-metric-grid">{foreach from=$analytics.metrics key=metric item=value}<div class="cf247-metric"><span>{$metric|replace:'_':' '|capitalize|escape}</span><strong>{if $value eq 'DATA_UNAVAILABLE'}DATA_UNAVAILABLE{else}{$value|escape}{/if}</strong></div>{/foreach}</div>{else}<p>DATA_UNAVAILABLE</p>{/if}
            </div>
        {elseif $active_tab eq 'ssl' || $active_tab eq 'firewall' || $active_tab eq 'speed' || $active_tab eq 'caching' || $active_tab eq 'security'}
            <div class="cf247-card"><h3>{if $active_tab eq 'ssl'}SSL / TLS{elseif $active_tab eq 'firewall'}Firewall &amp; security{elseif $active_tab eq 'speed'}Speed optimization{elseif $active_tab eq 'caching'}Caching{else}Content protection{/if}</h3>
                {if $setting_rows}
                    <div class="cf247-settings-list">
                    {foreach from=$setting_rows item=row}
                        <div class="cf247-setting-row"><div><strong>{$row.label|escape}</strong><div class="cf247-muted">Current Cloudflare value: {if $row.unavailable}DATA_UNAVAILABLE{else}{$row.value|escape}{/if}</div></div>
                        {if $service_manageable && !$row.unavailable}
                            <form method="post" class="cf247-setting-form">
                                {$csrf_field nofilter}<input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="{$active_tab|escape}"><input type="hidden" name="setting" value="{$row.key|escape}">
                                {if $active_tab eq 'ssl'}<input type="hidden" name="ch247cf_action" value="ssl_setting">
                                {elseif $active_tab eq 'firewall'}<input type="hidden" name="ch247cf_action" value="firewall_setting">
                                {elseif $active_tab eq 'speed'}<input type="hidden" name="ch247cf_action" value="speed_setting">
                                {elseif $active_tab eq 'caching'}<input type="hidden" name="ch247cf_action" value="cache_setting">
                                {else}<input type="hidden" name="ch247cf_action" value="scrape_setting">{/if}
                                {if $row.key eq 'minify'}
                                    <label class="checkbox-inline"><input type="checkbox" name="minify[css]" value="1"{if $row.css} checked{/if}> CSS</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="minify[html]" value="1"{if $row.html} checked{/if}> HTML</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="minify[js]" value="1"{if $row.js} checked{/if}> JavaScript</label>
                                {elseif $row.key eq 'ssl'}
                                    <select class="form-control" name="value"><option value="off">Off</option><option value="flexible">Flexible</option><option value="full">Full</option><option value="strict">Full (Strict)</option></select>
                                {elseif $row.key eq 'cache_level'}
                                    <select class="form-control" name="value"><option value="basic">Basic</option><option value="standard">Standard</option><option value="aggressive">Aggressive</option></select>
                                {elseif $row.key eq 'security_level'}
                                    <select class="form-control" name="value"><option value="essentially_off">Essentially off</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="under_attack">Under attack</option></select>
                                {elseif $row.key eq 'min_tls_version'}
                                    <select class="form-control" name="value"><option>1.0</option><option>1.1</option><option>1.2</option><option>1.3</option></select>
                                {elseif $row.key eq 'browser_cache_ttl' || $row.key eq 'challenge_ttl'}
                                    <input class="form-control" type="number" name="value" value="{$row.value|escape}" min="0" max="31536000">
                                {else}
                                    <select class="form-control" name="value"><option value="on">On</option><option value="off">Off</option></select>
                                {/if}
                                <button class="btn btn-primary btn-sm">Save</button>
                            </form>
                        {/if}</div>
                    {/foreach}
                    </div>
                {else}<p class="text-muted">Cloudflare did not return configuration data for this section.</p>{/if}
            </div>
            {if $active_tab eq 'caching' && $features['cache.purge']}
                <div class="cf247-card"><h3>Purge Cloudflare cache</h3><p>Choose carefully. Purge Everything may temporarily reduce cache hit rates.</p>
                    <form method="post" class="cf247-form-grid">{$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="cache_purge"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="caching"><div class="form-group"><label>Mode</label><select class="form-control" name="purge_mode"><option value="urls">Purge URLs</option><option value="everything">Purge Everything</option></select></div><div class="form-group cf247-wide"><label>URLs (one HTTPS URL per line)</label><textarea class="form-control" name="urls" rows="4" placeholder="https://{$service.zone_name|escape}/file.css"></textarea></div><div class="form-group"><label><input type="checkbox" name="confirm_purge" value="1"> Confirm Purge Everything</label></div><div class="cf247-wide"><button class="btn btn-warning">Purge cache</button></div></form>
                    {if $settings.development_mode eq 'on'}<div class="alert alert-warning"><strong>Development Mode is enabled.</strong> It temporarily bypasses Cloudflare cache and normally expires after about three hours.</div>{/if}
                </div>
            {/if}
            {if $active_tab eq 'firewall'}
                <div class="cf247-card"><h3>IP access rules</h3><p>Rules use the current Cloudflare Rulesets API. Supported actions are Block and Challenge.</p>
                    <form method="post" class="cf247-form-grid">{$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="firewall_add"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="firewall"><div class="form-group"><label>IP / CIDR</label><input class="form-control" name="address" placeholder="203.0.113.5 or 203.0.113.0/24" required></div><div class="form-group"><label>Action</label><select class="form-control" name="rule_action"><option value="block">Block</option><option value="managed_challenge">Managed challenge</option><option value="js_challenge">JavaScript challenge</option></select></div><div class="form-group"><label>Description</label><input class="form-control" name="description" maxlength="255" required></div><div><button class="btn btn-primary">Add rule</button></div></form>
                    <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Rule</th><th>Action</th><th>Expression</th><th>Enabled</th><th></th></tr></thead><tbody>{foreach from=$firewall_rules.rules item=rule}<tr><td>{$rule.description|escape}</td><td>{$rule.action|escape}</td><td><code>{$rule.expression|escape}</code></td><td>{if $rule.enabled}Yes{else}No{/if}</td><td><form method="post" onsubmit="return confirm('Delete this firewall rule from Cloudflare?')">{$csrf_field nofilter}<input type="hidden" name="ch247cf_action" value="firewall_delete"><input type="hidden" name="service_id" value="{$service.id|intval}"><input type="hidden" name="tab" value="firewall"><input type="hidden" name="rule_id" value="{$rule.id|escape}"><input type="hidden" name="confirm_delete" value="1"><button class="btn btn-xs btn-danger">Delete</button></form></td></tr>{foreachelse}<tr><td colspan="5" class="text-muted">No Cloudflare firewall rules were returned.</td></tr>{/foreach}</tbody></table></div>
                </div>
            {/if}
        {elseif $active_tab eq 'plan'}
            <div class="cf247-card"><h3>Cloudflare plan</h3><div class="cf247-overview-grid"><div><span>Purchased plan</span><strong>{$service.plan_label|escape}</strong></div><div><span>Provider plan ID</span><strong>{$service.provider_plan_id|escape}</strong></div><div><span>Cloudflare zone plan</span><strong>{if $provider_plan.plan.name}{$provider_plan.plan.name|escape}{elseif $provider_plan.name}{$provider_plan.name|escape}{else}DATA_UNAVAILABLE{/if}</strong></div></div>
                <p>Plan changes are processed through the existing WHMCS product-upgrade and invoice workflow. Cloudflare is changed only after WHMCS invokes the paid package change.</p>
                {if $features['plan.change'] && $plan_upgrade_url}<a class="btn btn-primary" href="{$plan_upgrade_url|escape}">Upgrade / downgrade in WHMCS</a>{elseif !$plan_upgrade_url}<p class="text-muted">This service is attached to a WHMCS product addon. Ask support to change its plan using the existing WHMCS billing workflow.</p>{else}<p class="text-muted">Plan changes are not included in this service.</p>{/if}
            </div>
        {elseif $active_tab eq 'activity'}
            <div class="cf247-card"><h3>Service activity</h3><div class="cf247-timeline">{foreach from=$activity item=event}<div class="cf247-timeline-item"><div class="cf247-timeline-dot"></div><div><strong>{$event.action|replace:'_':' '|capitalize|escape}</strong><div class="cf247-muted">{$event.created_at|escape}</div>{if $event.error_code}<div class="text-danger">{$event.error_code|escape}</div>{/if}</div></div>{foreachelse}<p class="text-muted">No service activity has been recorded yet.</p>{/foreach}</div></div>
        {/if}
    {/if}
</div>
<script>
(function(){document.querySelectorAll('.cf247-copy').forEach(function(button){button.addEventListener('click',function(){var value=button.getAttribute('data-copy')||'';if(navigator.clipboard){navigator.clipboard.writeText(value).then(function(){button.textContent='Copied';});}});});})();
</script>
