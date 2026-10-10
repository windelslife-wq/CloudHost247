{if $flash_ok}<div class="alert alert-success">{$flash_ok|escape}</div>{/if}
{if $flash_error}<div class="alert alert-danger">{$flash_error|escape}</div>{/if}

<div class="chs-auction-detail">
  <div class="panel panel-default">
    <div class="panel-heading">
      <strong class="chs-auction-title">{$auction.domain|escape}</strong>
      <span class="pull-right">
        {if $auction.status eq 'scheduled'}<span class="label label-info">Opens {$auction.starts_at|date_format:"%e %b %Y %H:%M"} UTC</span>
        {elseif $auction.status eq 'active'}<span class="label label-success">Live auction</span>
        {elseif $auction.status eq 'sold'}<span class="label label-primary">Sold — awaiting payment</span>
        {elseif $auction.status eq 'paid'}<span class="label label-primary">Paid — transfer in progress</span>
        {else}<span class="label label-default">{$auction.status|replace:'_':' '|ucwords}</span>{/if}
      </span>
    </div>
    <div class="panel-body">
      {if $auction.description}<p>{$auction.description|escape}</p>{/if}
      <div class="chs-price-grid">
        <div><span class="chs-price-label">Current price</span>
          <span class="chs-price-value">{math equation="x / 100" x=$auction.current_price_minor format="%.2f"} {$auction.currency|escape}</span></div>
        <div><span class="chs-price-label">Bids</span><span class="chs-price-value">{$auction.bids_count}</span></div>
        <div><span class="chs-price-label">Reserve</span>
          <span class="chs-price-value">{if !$auction.has_reserve}None{elseif $auction.reserve_met}Met{else}Not met{/if}</span></div>
        <div><span class="chs-price-label">Closes</span>
          <span class="chs-price-value chs-countdown" data-ends="{$auction.seconds_remaining}">{$auction.ends_at|date_format:"%e %b, %H:%M"}</span>
          {if $auction.extensions_used > 0}<span class="text-muted small"> +{$auction.extensions_used} anti-snipe extension(s)</span>{/if}
        </div>
      </div>

      {if $auction.status eq 'active' && !$auction.seller_is_you}
      <form method="post" action="{$modulelink}&action=auction&id={$auction.id}" class="chs-bid-form">
        {$csrf_field}
        <input type="hidden" name="do" value="bid">
        <input type="hidden" name="submit_token" value="{$submit_token}">
        <div class="row">
          <div class="col-sm-4">
            <label>Your bid ({$auction.currency|escape})</label>
            <input class="form-control" name="amount" type="number" min="{$auction.min_next_bid_minor / 100}" step="0.01"
                   value="{math equation="x / 100" x=$auction.min_next_bid_minor format="%.2f"}" required>
            <span class="help-block">Minimum next bid: {$increment_hint|escape}</span>
          </div>
          <div class="col-sm-4">
            <label>Your maximum (optional, proxy bidding)</label>
            <input class="form-control" name="max" type="number" min="0" step="0.01" placeholder="Auto-bid up to…">
            <span class="help-block">We outbid challengers one step at a time, never beyond your cap.</span>
          </div>
          <div class="col-sm-4 chs-bid-actions">
            <label>&nbsp;</label>
            <button class="btn btn-primary btn-block">Place bid</button>
          </div>
        </div>
      </form>
      {if $auction.bin_price_minor}
      <form method="post" action="{$modulelink}&action=auction&id={$auction.id}" class="chs-bin-form"
            onsubmit="return confirm('Buy {$auction.domain|escape:'javascript'} immediately for {math equation="x / 100" x=$auction.bin_price_minor format="%.2f"} {$auction.currency|escape}? This wins instantly and an invoice is issued right away.')">
        {$csrf_field}
        <input type="hidden" name="do" value="bid">
        <input type="hidden" name="amount" value="{math equation="x / 100" x=$auction.bin_price_minor format="%.2f"}">
        <input type="hidden" name="submit_token" value="{$submit_token}bin">
        <button class="btn btn-warning">Buy-It-Now: {math equation="x / 100" x=$auction.bin_price_minor format="%.2f"} {$auction.currency|escape}</button>
      </form>
      {/if}
      {elseif $auction.seller_is_you}
      <div class="alert alert-info">This is your listing. Bids appear below as they arrive.</div>
      {/if}

      <form method="post" action="{$modulelink}&action=auction&id={$auction.id}" class="chs-inline-form">
        {$csrf_field}
        <input type="hidden" name="do" value="{if $watching}unwatch{else}watch{/if}">
        <button class="btn btn-default">{if $watching}Remove from watchlist{else}Watch this auction{/if}</button>
      </form>
    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-heading"><strong>Bid history</strong> <span class="text-muted">(bidders are anonymised)</span></div>
    <div class="table-responsive">
    <table class="table table-striped table-condensed">
      <thead><tr><th>Amount</th><th>Bidder</th><th>Type</th><th>When</th></tr></thead>
      <tbody>
      {foreach $auction.bid_history as $b}
      <tr{if $b.is_you} class="chs-row-you"{/if}>
        <td>{math equation="x / 100" x=$b.amount_minor format="%.2f"} {$auction.currency|escape}</td>
        <td>{if $b.is_you}<strong>You</strong>{else}Anonymous bidder{/if}</td>
        <td>{if $b.source eq 'proxy'}Auto (maximum){else}Manual{/if}</td>
        <td>{$b.when|date_format:"%e %b, %H:%M:%S"}</td>
      </tr>
      {foreachelse}
      <tr><td colspan="4" class="text-muted">No bids yet — be the first.</td></tr>
      {/foreach}
      </tbody>
    </table>
    </div>
  </div>
</div>
