{* Bulk search results — paginated, selectable, exportable. *}
{if $search.status == 'queued' || $search.status == 'running'}
<meta http-equiv="refresh" content="5">
{/if}

<div class="panel panel-default">
  <div class="panel-heading">
    <strong>Bulk search #{$search.id}</strong>
    <span class="pull-right">
      {if $search.status == 'queued'}<span class="label label-default">Queued</span>
      {elseif $search.status == 'running'}<span class="label label-info">Running — {$search.completed} / {$search.total} checked</span>
      {elseif $search.status == 'completed'}<span class="label label-success">Completed — {$search.total} names, {$available} available</span>
      {else}<span class="label label-danger">{$search.status|escape}</span>{/if}
    </span>
  </div>
  <div class="panel-body">
    <p>
      <a class="btn btn-default btn-sm" href="{$modulelink}&action=bulkview&id={$search.id}&export=csv">Export CSV</a>
      {if !$onlyAvailable && $search.status == 'completed'}
        <a class="btn btn-default btn-sm" href="{$modulelink}&action=bulkview&id={$search.id}&only_available=1">Show available only</a>
      {elseif $onlyAvailable}
        <a class="btn btn-default btn-sm" href="{$modulelink}&action=bulkview&id={$search.id}">Show all</a>
      {/if}
      <a class="btn btn-default btn-sm" href="{$modulelink}&action=bulk">New search</a>
    </p>

    {if $rows}
    <form method="post" action="{$cartUrl|escape}">
      <input type="hidden" name="a" value="add">
      <input type="hidden" name="domain" value="register">
      <input type="hidden" name="bulk" value="1">
      <div class="table-responsive">
      <table class="table table-striped table-condensed">
        <thead><tr>
          <th><input type="checkbox" id="chs-select-all" aria-label="Select all available"></th>
          <th>Domain</th><th>Availability</th><th>Register</th><th>Renewal</th><th></th>
        </tr></thead>
        <tbody>
        {foreach $rows as $row}
          <tr>
            <td>
              {if $row.available == 1}
                <input type="checkbox" name="bulkdomains[]" value="{$row.domain|escape}" class="chs-bulk-check" aria-label="Select {$row.domain|escape}">
              {/if}
            </td>
            <td>{$row.domain|escape}</td>
            <td>
              {if $row.available === null}<span class="label label-default">{$row.status|escape}</span>
              {elseif $row.available == 1}<span class="label label-success">Available</span>
              {else}<span class="label label-danger">Taken</span>{/if}
            </td>
            <td>{if $row.register_discounted_minor !== null}{math equation="x/100" x=$row.register_discounted_minor format="%.2f"} {$row.currency|escape}{elseif $row.register_minor !== null}{math equation="x/100" x=$row.register_minor format="%.2f"} {$row.currency|escape}{else}—{/if}</td>
            <td>{if $row.renew_minor !== null}{math equation="x/100" x=$row.renew_minor format="%.2f"} {$row.currency|escape}{else}—{/if}</td>
            <td>
              {if $row.available == 1}
                <a class="btn btn-xs btn-success" href="cart.php?a=add&domain=register&query={$row.domain|escape:'url'}">Add</a>
              {else}
                <a class="btn btn-xs btn-default" href="whois-lookup.php?domain={$row.domain|escape:'url'}">Whois</a>
              {/if}
            </td>
          </tr>
        {/foreach}
        </tbody>
      </table>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Add selected to cart</button>
      <span class="text-muted small">Selected available domains go to the standard WHMCS cart &amp; checkout.</span>
    </form>
    {elseif $search.status != 'completed'}
      <p class="text-muted">Processing… this page refreshes automatically.</p>
    {else}
      <p class="text-muted">No results.</p>
    {/if}

    {if $pages > 1}
    <nav><ul class="pagination pagination-sm">
      {section name=p start=1 loop=$pages+1 step=1 max=$pages}
        <li{if $smarty.section.p.index == $page} class="active"{/if}>
          <a href="{$modulelink}&action=bulkview&id={$search.id}&page={$smarty.section.p.index}{if $onlyAvailable}&only_available=1{/if}">{$smarty.section.p.index}</a>
        </li>
      {/section}
    </ul></nav>
    {/if}
  </div>
</div>

<script>
(function () {
  var all = document.getElementById('chs-select-all');
  if (!all) { return; }
  all.addEventListener('change', function () {
    document.querySelectorAll('.chs-bulk-check').forEach(function (box) { box.checked = all.checked; });
  });
})();
</script>
