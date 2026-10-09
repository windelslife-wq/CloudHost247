{* My domains — module-tracked customer domains synced from the platform. *}
{if $flash}<div class="alert alert-success">{$flash|escape}</div>{/if}
{if $error}<div class="alert alert-danger">{$error|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>My domains</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Domain</th><th>Status</th><th>Registrar</th><th>Expires</th><th>Auto-renew</th><th></th></tr></thead>
    <tbody>
    {foreach $domains as $d}
      <tr>
        <td><strong>{$d.domain|escape}</strong></td>
        <td>{$d.status|ucfirst|escape}</td>
        <td>{$d.registrar|escape}</td>
        <td>{if $d.expires_at}{$d.expires_at|date_format:"%e %b %Y"}{else}—{/if}</td>
        <td>{if $d.auto_renew}<span class="label label-success">On</span>{else}<span class="label label-default">Off</span>{/if}</td>
        <td><a class="btn btn-xs btn-primary" href="{$modulelink}&action=domain&id={$d.id}">Manage</a></td>
      </tr>
    {foreachelse}
      <tr><td colspan="6" class="text-muted">No domains yet. <a href="{$modulelink}&action=search">Search for one</a> — orders placed through the cart appear here automatically.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>

<p class="text-muted small"><a href="{$modulelink}&action=search">Search for a domain</a> · <a href="{$modulelink}&action=transfers">Transfer a domain in</a> · <a href="clientarea.php?action=domains">WHMCS domain manager</a></p>
