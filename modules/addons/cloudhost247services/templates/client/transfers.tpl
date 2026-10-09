{* Domain transfers — eligibility pre-check, quote, tracked creation. *}
{if $flash_ok}<div class="alert alert-success">{$flash_ok|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>Start a domain transfer</strong></div>
  <div class="panel-body">
    <form method="post" action="{$modulelink}&action=transfers">
      {$csrf_field}
      <div class="form-group">
        <label for="chs-tr-domain">Domain to transfer</label>
        <input type="text" class="form-control" id="chs-tr-domain" name="domain" value="{$old.domain|escape}" placeholder="yourdomain.com" autocomplete="off" spellcheck="false">
      </div>
      <div class="form-group">
        <label for="chs-tr-epp">EPP / auth code <span class="text-muted">(from your current registrar)</span></label>
        <input type="text" class="form-control" id="chs-tr-epp" name="epp" value="{$old.epp|escape}" autocomplete="off" spellcheck="false" maxlength="64">
      </div>
      {if $errors}<div class="alert alert-danger">{foreach $errors as $err}<div>{$err|escape}</div>{/foreach}</div>{/if}
      <p>
        <button type="submit" name="do" value="check" class="btn btn-default">Check eligibility &amp; price</button>
        <button type="submit" name="do" value="create" class="btn btn-primary">Request transfer</button>
      </p>
      <p class="text-muted small">The EPP code is stored encrypted, is never displayed again, and is only used to submit the transfer after the invoice is paid. The invoice is a real WHMCS invoice — pay it like any other order.</p>
    </form>

    {if $eligibility}
      <hr>
      {if $eligibility.eligible === true}
        <div class="alert alert-success">Eligible to transfer.
          {if $quote} Quoted price: <strong>{math equation="x/100" x=$quote.final_minor format="%.2f"} {$quote.currency|escape}</strong> for 1 year (includes the renewal year).{if $quote.discount_percent} <span class="label label-info">{$quote.discount_percent|escape}% club discount applied</span>{/if}{/if}
        </div>
      {elseif $eligibility.eligible === null}
        <div class="alert alert-warning">We could not fully verify eligibility (the registry lookup is unavailable right now). Reasons found so far: {foreach $eligibility.reasons as $r}{$r|escape} {/foreach}</div>
      {else}
        <div class="alert alert-danger">Not transferable right now: {foreach $eligibility.reasons as $r}<div>{$r|escape}</div>{/foreach}</div>
      {/if}
    {/if}
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><strong>Your transfers</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Domain</th><th>Status</th><th>Price</th><th>Requested</th><th></th></tr></thead>
    <tbody>
    {foreach $transfers as $t}
      <tr>
        <td>{$t.domain|escape}</td>
        <td>{$t.status|ucfirst|escape}</td>
        <td>{math equation="x/100" x=$t.price_minor format="%.2f"} {$t.currency|escape}</td>
        <td>{if $t.requested_at}{$t.requested_at|date_format:"%e %b %Y"}{else}—{/if}</td>
        <td><a class="btn btn-xs btn-default" href="{$modulelink}&action=transfer&id={$t.id}">Details</a></td>
      </tr>
    {foreachelse}
      <tr><td colspan="5" class="text-muted">No transfers yet.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
