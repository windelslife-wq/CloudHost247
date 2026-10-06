<div class="panel panel-default">
  <div class="panel-heading"><strong>My watchlist</strong></div>
  <div class="table-responsive">
  <table class="table table-striped">
    <thead><tr><th>Domain</th><th>Status</th><th>Current</th><th>Bids</th><th>Closes</th><th></th></tr></thead>
    <tbody>
    {foreach $auctions as $a}
    <tr>
      <td><a href="{$modulelink}&action=auction&id={$a.id}">{$a.domain|escape}</a></td>
      <td>{$a.status|replace:'_':' '|ucwords}</td>
      <td>{math equation="x / 100" x=$a.current_price_minor format="%.2f"} {$a.currency|escape}</td>
      <td>{$a.bids_count}</td>
      <td>{$a.ends_at|date_format:"%e %b, %H:%M"}</td>
      <td>
        <form method="post" action="{$modulelink}&action=watchlist" class="chs-inline-form">
          {$csrf_field}<input type="hidden" name="do" value="unwatch"><input type="hidden" name="id" value="{$a.id}">
          <button class="btn btn-xs btn-default">Remove</button>
        </form>
      </td>
    </tr>
    {foreachelse}
    <tr><td colspan="6" class="text-muted">Nothing watched yet. <a href="{$modulelink}&action=auctions">Browse auctions</a>.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
