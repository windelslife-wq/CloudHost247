{* Server detail — status, provider-supported actions, reinstall, SSH keys, job timeline. *}
{if $flash_ok}<div class="alert alert-success">{$flash_ok|escape}</div>{/if}
{if $flash_error}<div class="alert alert-danger">{$flash_error|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>{$server.hostname|escape}</strong>
    <span class="pull-right">
      {if $server.status == 'active'}<span class="label label-success">Running</span>
      {elseif $server.status == 'stopped'}<span class="label label-default">Stopped</span>
      {elseif $server.status == 'provisioning'}<span class="label label-info">Provisioning</span>
      {elseif $server.status == 'pending_payment'}<span class="label label-warning">Awaiting payment</span>
      {elseif $server.status == 'failed'}<span class="label label-danger">Failed</span>
      {else}<span class="label label-default">{$server.status|escape}</span>{/if}
    </span>
  </div>
  <div class="panel-body">
    <div class="row">
      <div class="col-sm-6">
        <table class="table table-condensed">
          <tr><th>Operating system</th><td>{if $server.os_logo}<img src="{$server.os_logo|escape}" alt="" style="height:18px;width:18px;vertical-align:-4px"> {/if}{$server.os_display|escape} <span class="text-muted small">{$server.architecture|escape}</span></td></tr>
          <tr><th>IP address</th><td>{if $server.ip_address}<code>{$server.ip_address|escape}</code>{else}<span class="text-muted">not assigned yet</span>{/if}</td></tr>
          <tr><th>Region</th><td>{$server.region_name|escape}</td></tr>
          <tr><th>Provider</th><td>{$server.provider_name|escape}</td></tr>
          <tr><th>Product</th><td>{$server.product_name|escape}</td></tr>
          <tr><th>Renewal date</th><td>{if $server.next_due}{$server.next_due|escape}{else}<span class="text-muted">—</span>{/if}</td></tr>
        </table>
      </div>
      <div class="col-sm-6">
        <h5>Actions</h5>
        {if $server.status == 'provisioning' || $server.status == 'pending_payment'}
          <p class="text-muted">Actions become available once the server is ready.</p>
        {elseif $server.status == 'deleted'}
          <p class="text-muted">This server has been deleted.</p>
        {else}
          <form method="post" action="{$modulelink}&action=server&id={$server.id}" style="display:inline-block;margin:2px">
            {$csrf_field}
            {if !empty($capabilities.start)}<button class="btn btn-xs btn-success" name="do" value="start">Start</button>{/if}
            {if !empty($capabilities.stop)}<button class="btn btn-xs btn-default" name="do" value="stop">Stop</button>{/if}
            {if !empty($capabilities.reboot)}<button class="btn btn-xs btn-warning" name="do" value="reboot">Reboot</button>{/if}
            {if !empty($capabilities.shutdown)}<button class="btn btn-xs btn-default" name="do" value="shutdown">Shutdown</button>{/if}
            {if !empty($capabilities.rescue)}<button class="btn btn-xs btn-default" name="do" value="rescue">Rescue mode</button>{/if}
          </form>
          <form method="post" action="{$modulelink}&action=server&id={$server.id}" style="display:inline-block;margin:2px">
            {$csrf_field}
            {if !empty($capabilities.console)}<button class="btn btn-xs btn-primary" name="do" value="console">Console</button>{/if}
          </form>
          {if $console}
            <div class="alert alert-info" style="margin-top:8px">Console access: <a href="{$console.url|escape}" target="_blank" rel="noopener">open console ({$console.type|escape})</a></div>
          {/if}
          {if !empty($capabilities.delete)}
            <form method="post" action="{$modulelink}&action=server&id={$server.id}" style="display:inline-block;margin:2px" onsubmit="return confirm('Delete this server? This terminates the service and cannot be undone.');">
              {$csrf_field}
              <button class="btn btn-xs btn-danger" name="do" value="delete">Delete</button>
            </form>
          {/if}
          {if empty($capabilities.start) && empty($capabilities.stop) && empty($capabilities.reboot) && empty($capabilities.console) && empty($capabilities.delete)}
            <p class="text-muted">The provider for this server does not expose management actions.</p>
          {/if}
        {/if}
      </div>
    </div>
  </div>
</div>

{if $server.status == 'active' || $server.status == 'stopped'}
<div class="panel panel-danger">
  <div class="panel-heading"><strong>Reinstall operating system</strong></div>
  <div class="panel-body">
    <div class="alert alert-danger"><strong>Warning:</strong> reinstalling the operating system erases the current operating-system data on this server. Make sure important data is backed up. This action cannot be undone.</div>
    {if $reinstall_targets}
      <form method="post" action="{$modulelink}&action=server&id={$server.id}" class="form-inline">
        {$csrf_field}
        <input type="hidden" name="do" value="reinstall">
        <label>New OS</label>
        <select name="os_version_id" class="form-control input-sm">
          {foreach $reinstall_targets as $t}
            <option value="{$t.version_id}">{$t.os_name|escape} — {$t.display_name|escape}</option>
          {/foreach}
        </select>
        <label>Architecture</label>
        <select name="architecture" class="form-control input-sm">
          {foreach $reinstall_targets[0].architectures as $arch}
            <option value="{$arch|escape}"{if $arch == $server.architecture} selected{/if}>{$arch|escape}</option>
          {/foreach}
        </select>
        <label><input type="checkbox" name="confirm" value="1" required> I understand this erases the current OS data</label>
        <button class="btn btn-sm btn-danger">Reinstall OS</button>
      </form>
    {else}
      <p class="text-muted">No OS is currently available for reinstall on this server's provider/region.</p>
    {/if}
  </div>
</div>
{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>SSH keys</strong></div>
  <div class="panel-body">
    <table class="table table-condensed table-striped">
      <thead><tr><th>Name</th><th>Fingerprint</th><th></th></tr></thead>
      <tbody>
      {foreach $ssh_keys as $k}
        <tr>
          <td>{$k.name|escape}</td>
          <td><code class="small">{$k.fingerprint|escape}</code></td>
          <td>
            <form method="post" action="{$modulelink}&action=server&id={$server.id}" style="display:inline">
              {$csrf_field}<input type="hidden" name="do" value="sshkey_delete"><input type="hidden" name="key_id" value="{$k.id}">
              <button class="btn btn-xs btn-danger">Delete</button>
            </form>
          </td>
        </tr>
      {foreachelse}
        <tr><td colspan="3" class="text-muted">No SSH keys yet.</td></tr>
      {/foreach}
      </tbody>
    </table>
    <form method="post" action="{$modulelink}&action=server&id={$server.id}" class="form-inline">
      {$csrf_field}
      <input type="hidden" name="do" value="sshkey_add">
      <input class="form-control input-sm" name="key_name" placeholder="Key name" required>
      <input class="form-control input-sm" name="public_key" placeholder="ssh-ed25519 AAAA…" style="width:420px" required>
      <button class="btn btn-sm btn-default">Add key</button>
    </form>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><strong>Provisioning history</strong></div>
  <div class="table-responsive">
  <table class="table table-condensed table-striped">
    <thead><tr><th>Job</th><th>Type</th><th>Status</th><th>Last stage</th><th>Updated</th><th>Error</th></tr></thead>
    <tbody>
    {foreach $jobs as $j}
      <tr>
        <td>#{$j.id}</td>
        <td>{$j.type|escape}</td>
        <td>
          {if $j.status == 'READY'}<span class="label label-success">Ready</span>
          {elseif $j.status == 'FAILED'}<span class="label label-danger">Failed</span>
          {elseif $j.status == 'CANCELLED'}<span class="label label-default">Cancelled</span>
          {else}<span class="label label-info">{$j.status|escape}</span>{/if}
        </td>
        <td>{$j.stage|escape}</td>
        <td>{$j.updated_at|escape}</td>
        <td>{if $j.error_code}<span class="label label-danger">{$j.error_code|escape}</span> <span class="small text-muted">{$j.error_message|escape}</span>{/if}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="6" class="text-muted">No provisioning jobs yet.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>

<p><a href="{$modulelink}&action=servers">← Back to my servers</a></p>
