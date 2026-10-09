{* Domain detail — management: auto-renew, privacy, nameservers, DNS records. *}
{assign var=d value=$detail.domain}
{if $flash_ok}<div class="alert alert-success">{$flash_ok|escape}</div>{/if}
{if $flash_error}<div class="alert alert-danger">{$flash_error|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>{$d.domain|escape}</strong>
    <span class="pull-right">{$d.status|ucfirst|escape}</span></div>
  <div class="panel-body">
    <table class="table table-condensed">
      <tr><th>Registrar</th><td>{$d.registrar|escape}</td><th>Expires</th><td>{if $d.expires_at}{$d.expires_at|date_format:"%e %b %Y"}{else}—{/if}</td></tr>
      <tr><th>Registered</th><td>{if $d.registered_at}{$d.registered_at|date_format:"%e %b %Y"}{else}—{/if}</td>
          <th>Provider</th><td>{$detail.provider|escape}</td></tr>
      <tr><th>WHOIS privacy</th><td>{if $d.whois_privacy}<span class="label label-success">On</span>{else}<span class="label label-default">Off</span>{/if}</td>
          <th>Auto-renew</th><td>{if $d.auto_renew}<span class="label label-success">On</span>{else}<span class="label label-default">Off</span>{/if}</td></tr>
      {if $d.renewal_price_minor !== null}
      <tr><th>Renewal price</th><td colspan="3">{math equation="x/100" x=$d.renewal_price_minor format="%.2f"} {$d.currency|escape} / year (catalogue price, server-side)</td></tr>
      {/if}
    </table>

    <div class="row">
      <div class="col-sm-6">
        <h4>Auto-renew</h4>
        <form method="post" action="{$modulelink}&action=domain&id={$d.id}">
          {$csrf_field}<input type="hidden" name="do" value="auto_renew">
          <input type="hidden" name="on" value="{if $d.auto_renew}0{else}1{/if}">
          <p class="text-muted small">{if $d.auto_renew}Auto-renew is on — an invoice is issued before expiry and the domain is renewed once paid.{else}Auto-renew is off — renew manually before the expiration date.{/if}</p>
          <button class="btn btn-sm {if $d.auto_renew}btn-default{else}btn-success{/if}">{if $d.auto_renew}Turn off{else}Turn on{/if}</button>
        </form>
      </div>
      <div class="col-sm-6">
        <h4>WHOIS privacy</h4>
        {if $detail.capabilities.update_domain}
        <form method="post" action="{$modulelink}&action=domain&id={$d.id}">
          {$csrf_field}<input type="hidden" name="do" value="privacy">
          <input type="hidden" name="on" value="{if $d.whois_privacy}0{else}1{/if}">
          <button class="btn btn-sm {if $d.whois_privacy}btn-default{else}btn-success{/if}">{if $d.whois_privacy}Disable privacy{else}Enable privacy{/if}</button>
        </form>
        {else}
        <p class="text-muted small">WHOIS privacy for this domain is managed at the registrar ({$detail.provider|escape}). Contact support to change it.</p>
        {/if}
      </div>
    </div>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><strong>Nameservers</strong></div>
  <div class="panel-body">
    {if $detail.capabilities.update_nameservers}
      <form method="post" action="{$modulelink}&action=domain&id={$d.id}">
        {$csrf_field}<input type="hidden" name="do" value="nameservers">
        <div class="form-group">
          <textarea class="form-control" name="nameservers" rows="4" placeholder="ns1.example.net&#10;ns2.example.net">{foreach $detail.nameservers as $ns}{$ns|escape}{if !$ns@last}&#10;{/if}{/foreach}</textarea>
        </div>
        <button class="btn btn-primary btn-sm">Update nameservers</button>
      </form>
    {else}
      {if $detail.nameservers}
        <ul class="list-unstyled">{foreach $detail.nameservers as $ns}<li><code>{$ns|escape}</code></li>{/foreach}</ul>
      {/if}
      <p class="text-muted small">Nameservers for this domain are managed at the registrar ({$detail.provider|escape}).</p>
    {/if}
  </div>
</div>

{if $detail.dns_enabled}
<div class="panel panel-default">
  <div class="panel-heading"><strong>DNS records</strong></div>
  <div class="panel-body">
    {if $detail.capabilities.create_dns_record}
      <form method="post" action="{$modulelink}&action=domain&id={$d.id}" class="form-inline well well-sm">
        {$csrf_field}<input type="hidden" name="do" value="dns_add">
        <select name="type" class="form-control input-sm">
          {foreach $dns_types as $t}<option value="{$t}">{$t}</option>{/foreach}
        </select>
        <input name="name" class="form-control input-sm" placeholder="name (@ for apex)" style="width:110px">
        <input name="value" class="form-control input-sm" placeholder="value" style="width:180px">
        <input name="ttl" class="form-control input-sm" value="3600" style="width:70px" title="TTL (seconds)">
        <input name="priority" class="form-control input-sm" placeholder="prio" style="width:60px" title="Priority (MX/SRV)">
        <button class="btn btn-success btn-sm">Add record</button>
      </form>
      <div class="table-responsive">
      <table class="table table-striped table-condensed">
        <thead><tr><th>Type</th><th>Name</th><th>Value</th><th>TTL</th><th>Priority</th><th>Sync</th><th></th></tr></thead>
        <tbody>
        {foreach $detail.dns_records as $r}
          <tr>
            <td>{$r.record_type|escape}</td><td>{$r.name|escape}</td><td><code>{$r.value|escape}</code></td>
            <td>{$r.ttl}</td><td>{if $r.priority !== null}{$r.priority}{else}—{/if}</td>
            <td>{if $r.sync_status == 'synced'}<span class="label label-success">Synced</span>{elseif $r.sync_status == 'error'}<span class="label label-danger" title="{$r.last_error|escape}">Error</span>{else}<span class="label label-warning">Pending</span>{/if}</td>
            <td>
              <form method="post" action="{$modulelink}&action=domain&id={$d.id}" onsubmit="return confirm('Delete this DNS record?')">
                {$csrf_field}<input type="hidden" name="do" value="dns_delete"><input type="hidden" name="record_id" value="{$r.id}">
                <button class="btn btn-xs btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="7" class="text-muted">No DNS records managed here yet.</td></tr>
        {/foreach}
        </tbody>
      </table>
      </div>
    {else}
      <p class="text-muted">DNS records for this domain are managed at the registrar ({$detail.provider|escape}) — this interface does not edit authoritative DNS without a configured DNS-capable provider.</p>
    {/if}
  </div>
</div>
{/if}

{if $detail.renewals}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Renewals</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Invoice</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    {foreach $detail.renewals as $rn}
      <tr><td>{if $rn.invoice_id}#{$rn.invoice_id}{else}—{/if}</td>
          <td>{math equation="x/100" x=$rn.amount_minor format="%.2f"} {$rn.currency|escape}</td>
          <td>{$rn.status|ucfirst|escape}</td>
          <td>{if $rn.completed_at}{$rn.completed_at|date_format:"%e %b %Y"}{elseif $rn.attempted_at}{$rn.attempted_at|date_format:"%e %b %Y"}{else}{$rn.created_at|date_format:"%e %b %Y"}{/if}</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}

{if $detail.history}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Domain history</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>When</th><th>Event</th><th>Actor</th></tr></thead>
    <tbody>
    {foreach $detail.history as $ev}
      <tr><td>{$ev.created_at|date_format:"%e %b %Y %H:%M"}</td><td>{$ev.type|escape}</td><td>{$ev.actor_type|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}

<p><a href="{$modulelink}&action=domains">← Back to my domains</a>
{if $detail.transfer} · <a href="{$modulelink}&action=transfer&id={$detail.transfer.id}">View transfer #{$detail.transfer.id}</a>{/if}</p>
