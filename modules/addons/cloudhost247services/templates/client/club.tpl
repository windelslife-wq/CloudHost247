{if $flash}<div class="alert alert-success">{$flash}</div>{/if}

{if $membership}
<div class="panel panel-success">
  <div class="panel-heading"><strong>Your membership is active</strong></div>
  <div class="panel-body">
    <p><strong>{$membership.plan.name|escape}</strong> —
      {math equation="x" x=$membership.plan.discount_percent format="%.0f"}% off eligible registrations
      {if $membership.plan.applies_renew} and renewals{/if}.</p>
    <p class="text-muted">Valid until <strong>{$membership.expires_at|date_format:"%e %B %Y"}</strong>{if $membership.cancelled_at} (cancellation scheduled — benefits continue to the end){/if}.</p>
    {if !$membership.cancelled_at}
    <form method="post" action="{$modulelink}&action=club" onsubmit="return confirm('Cancel membership at the end of the paid period?')">
      {$csrf_field}<input type="hidden" name="do" value="cancel"><input type="hidden" name="membership_id" value="{$membership.id}">
      <button class="btn btn-sm btn-default">Cancel at period end</button>
    </form>
    {/if}
  </div>
</div>
{/if}

<div class="chs-club-plans">
  {foreach $plans as $plan}
  <div class="panel panel-default chs-club-plan">
    <div class="panel-body">
      <h3>{$plan.name|escape}</h3>
      <div class="chs-club-price">
        {math equation="x / 100" x=$plan.price_minor format="%.2f"} {$plan.currency|escape}
        <span class="text-muted">/ {$plan.period_months} month{if $plan.period_months != 1}s{/if}</span>
      </div>
      <p>{$plan.description|escape}</p>
      <ul class="chs-club-benefits">
        <li><strong>{math equation="x" x=$plan.discount_percent format="%.0f"}%</strong> off eligible domain registrations</li>
        {if $plan.applies_renew}<li>Same discount on eligible renewals</li>{/if}
        {if $plan.applies_transfer}<li>Discounted inbound transfers</li>{/if}
        <li>Discount applies automatically in the order form — no codes</li>
        <li>Eligible extensions: {foreach $plan.tlds item=t name=tl}.{$t.tld|escape}{if !$smarty.foreach.tl.last}, {/if}{/foreach}</li>
      </ul>
      {if !$membership}
      <form method="post" action="{$modulelink}&action=club">
        {$csrf_field}<input type="hidden" name="do" value="join"><input type="hidden" name="plan_id" value="{$plan.id}">
        <button class="btn btn-primary btn-lg btn-block">Join — an invoice will be issued</button>
      </form>
      {/if}
    </div>
  </div>
  {/foreach}
</div>

{if $memberships|@count > 1}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Membership history</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Plan</th><th>Status</th><th>Started</th><th>Ends</th></tr></thead>
    <tbody>
    {foreach $memberships as $m}
    <tr>
      <td>{if $m.plan}{$m.plan.name|escape}{else}—{/if}</td>
      <td>{$m.status|ucwords}</td>
      <td>{if $m.starts_at}{$m.starts_at|date_format:"%e %b %Y"}{else}—{/if}</td>
      <td>{if $m.expires_at}{$m.expires_at|date_format:"%e %b %Y"}{else}—{/if}</td>
    </tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}
