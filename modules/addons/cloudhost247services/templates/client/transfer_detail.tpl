{* Transfer detail — status, history, EPP-on-file indicator (never the code). *}
{if $flash_ok}<div class="alert alert-success">{$flash_ok|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading"><strong>Transfer: {$transfer.domain|escape}</strong>
    <span class="pull-right">{$transfer.status|ucfirst|escape}</span></div>
  <div class="panel-body">
    <table class="table table-condensed">
      <tr><th>Current registrar</th><td>{if $transfer.registrar}{$transfer.registrar|escape}{else}—{/if}</td>
          <th>Price paid / due</th><td>{math equation="x/100" x=$transfer.price_minor format="%.2f"} {$transfer.currency|escape}</td></tr>
      <tr><th>Requested</th><td>{if $transfer.requested_at}{$transfer.requested_at|date_format:"%e %b %Y %H:%M"}{else}—{/if}</td>
          <th>Completed</th><td>{if $transfer.completed_at}{$transfer.completed_at|date_format:"%e %b %Y"}{else}—{/if}</td></tr>
      <tr><th>EPP / auth code</th><td>{$transfer.epp_status|escape}</td>
          <th>Provider reference</th><td>{if $transfer.provider_ref}{$transfer.provider_ref|escape}{else}—{/if}</td></tr>
      {if $transfer.invoice_id}
      <tr><th>Invoice</th><td colspan="3">#{$transfer.invoice_id}
        {if $invoice_status}<span class="label label-{if $invoice_status == 'Paid'}success{elseif $invoice_status == 'Unpaid'}warning{else}default{/if}">{$invoice_status|escape}</span>{/if}
        {if $invoice_url}<a class="btn btn-xs btn-primary" href="{$invoice_url|escape}">View / pay invoice</a>{/if}
      </td></tr>
      {/if}
      {if $transfer.error}
      <tr><th>Last error</th><td colspan="3"><code>{$transfer.error|escape}</code></td></tr>
      {/if}
    </table>

    {if $transfer.status == 'PENDING' && $invoice_status == 'Unpaid'}
      <p class="text-muted small">The transfer is submitted to the registry once the invoice is paid.</p>
    {elseif $transfer.status == 'INITIATED' || $transfer.status == 'PROCESSING' || $transfer.status == 'AWAITING_AUTH_CODE'}
      <p class="text-muted small">The transfer is in progress at the registry. Most transfers complete within 5–7 days after the registrant approves the transfer email.</p>
    {/if}

    {if $transfer.status != 'COMPLETED' && $transfer.status != 'CANCELLED' && $transfer.status != 'EXPIRED'}
    <form method="post" action="{$modulelink}&action=transfer&id={$transfer.id}" onsubmit="return confirm('Cancel this transfer? An unpaid invoice will be cancelled too.')">
      {$csrf_field}<input type="hidden" name="do" value="cancel">
      <button class="btn btn-sm btn-danger">Cancel transfer</button>
    </form>
    {/if}
  </div>
</div>

{if $transfer.history}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Transfer history</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>When</th><th>Transition</th><th>Actor</th><th>Note</th></tr></thead>
    <tbody>
    {foreach $transfer.history as $h}
      <tr>
        <td>{$h.at|date_format:"%e %b %Y %H:%M"}</td>
        <td>{if $h.from}{$h.from|ucfirst|escape} → {/if}{$h.to|ucfirst|escape}</td>
        <td>{$h.actor|escape}</td>
        <td>{$h.note|escape}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}

<p><a href="{$modulelink}&action=transfers">← Back to transfers</a></p>
