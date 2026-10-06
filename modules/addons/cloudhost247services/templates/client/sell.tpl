<div class="panel panel-default">
  <div class="panel-heading"><strong>Sell a domain on CloudHost247 Auctions</strong></div>
  <div class="panel-body">
    <p class="text-muted">Only domains registered inside your account can be listed — this protects buyers.
      External name? <a href="{$WEB_ROOT}/cart.php?a=add&domain=transfer">Transfer it in first</a>, then list it here.</p>
    {if $errors}<div class="alert alert-danger">{foreach $errors as $e}<div>{$e|escape}</div>{/foreach}</div>{/if}
    <form method="post" action="{$modulelink}&action=sell" class="chs-form">
      {$csrf_field}
      <div class="form-group">
        <label>Domain</label>
        {if $eligible}
        <input class="form-control" name="domain" list="chs-eligible" value="{$old.domain|escape}" required>
        <datalist id="chs-eligible">{foreach $eligible as $d}<option value="{$d|escape}">{/foreach}</datalist>
        {else}
        <input class="form-control" name="domain" value="{$old.domain|escape}" required>
        <p class="help-block">No eligible domains found on your account yet.</p>
        {/if}
      </div>
      <div class="row">
        <div class="col-sm-3"><div class="form-group"><label>Starting price</label>
          <input class="form-control" name="start" type="number" min="1" step="0.01" value="{$old.start|escape}" required></div></div>
        <div class="col-sm-3"><div class="form-group"><label>Reserve (optional)</label>
          <input class="form-control" name="reserve" type="number" min="0" step="0.01" value="{$old.reserve|escape}"></div></div>
        <div class="col-sm-3"><div class="form-group"><label>Buy-It-Now (optional)</label>
          <input class="form-control" name="bin" type="number" min="0" step="0.01" value="{$old.bin|escape}"></div></div>
        <div class="col-sm-3"><div class="form-group"><label>Duration</label>
          <select class="form-control" name="duration">
            {foreach [3,5,7,10,14,21,30] as $d}<option value="{$d}"{if $old.duration eq $d} selected{/if}>{$d} days</option>{/foreach}
          </select></div></div>
      </div>
      <div class="form-group"><label>Description (optional, 500 chars)</label>
        <textarea class="form-control" name="description" rows="3" maxlength="500">{$old.description|escape}</textarea></div>
      <button class="btn btn-primary">Create listing</button>
    </form>
  </div>
</div>
