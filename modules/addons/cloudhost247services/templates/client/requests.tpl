<div class="panel panel-default">
  <div class="panel-heading"><strong>My service requests</strong>
    <a class="btn btn-xs btn-primary pull-right" href="{$modulelink}&action=requestnew">New request</a></div>
  <div class="table-responsive">
  <table class="table table-striped">
    <thead><tr><th>#</th><th>Service</th><th>Title</th><th>Status</th><th>Quote</th><th>Opened</th></tr></thead>
    <tbody>
    {foreach $requests as $r}
    <tr>
      <td>{$r.id}</td>
      <td>{$type_labels[$r.type]|default:$r.type|escape}</td>
      <td><a href="{$modulelink}&action=request&id={$r.id}">{$r.title|escape}</a></td>
      <td><span class="label label-default">{$r.status|replace:'_':' '|ucwords}</span></td>
      <td>{if $r.quote_minor}{math equation="x / 100" x=$r.quote_minor format="%.2f"} {$r.currency|escape}{else}—{/if}</td>
      <td>{$r.created_at|date_format:"%e %b %Y"}</td>
    </tr>
    {foreachelse}
    <tr><td colspan="6" class="text-muted">No requests yet. Need a website, migration, SEO or integration?
      <a href="{$modulelink}&action=requestnew">Tell us about it</a>.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
