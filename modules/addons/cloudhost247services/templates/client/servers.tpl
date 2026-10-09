{* My servers — the module's infrastructure registry linked to WHMCS services. *}
{if $flash}<div class="alert alert-success">{$flash|escape}</div>{/if}
{if $error}<div class="alert alert-danger">{$error|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>My servers</strong>
    <a class="btn btn-xs btn-primary pull-right" href="{$modulelink}&action=order">Order a server</a>
  </div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Server</th><th>Product</th><th>Operating system</th><th>IP address</th><th>Region</th><th>Status</th><th></th></tr></thead>
    <tbody>
    {foreach $servers as $s}
      <tr>
        <td><strong>{$s.hostname|escape}</strong></td>
        <td>{$s.product_name|escape}</td>
        <td>{if $s.os_logo}<img src="{$s.os_logo|escape}" alt="" style="height:16px;width:16px;vertical-align:-3px"> {/if}{$s.os_display|escape} <span class="text-muted small">{$s.architecture|escape}</span></td>
        <td>{if $s.ip_address}{$s.ip_address|escape}{else}<span class="text-muted">—</span>{/if}</td>
        <td>{$s.region_name|escape}</td>
        <td>
          {if $s.status == 'active'}<span class="label label-success">Running</span>
          {elseif $s.status == 'stopped'}<span class="label label-default">Stopped</span>
          {elseif $s.status == 'provisioning'}<span class="label label-info">Provisioning</span>
          {elseif $s.status == 'pending_payment'}<span class="label label-warning">Awaiting payment</span>
          {elseif $s.status == 'failed'}<span class="label label-danger">Failed</span>
          {elseif $s.status == 'deleted'}<span class="label label-default">Deleted</span>
          {else}<span class="label label-default">{$s.status|escape}</span>{/if}
        </td>
        <td><a class="btn btn-xs btn-primary" href="{$modulelink}&action=server&id={$s.id}">Manage</a></td>
      </tr>
    {foreachelse}
      <tr><td colspan="7" class="text-muted">No servers yet. <a href="{$modulelink}&action=order">Order your first server</a>.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>

<p class="text-muted small"><a href="{$modulelink}&action=order">Order a server</a></p>
