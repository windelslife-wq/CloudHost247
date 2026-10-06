<div class="panel panel-default">
  <div class="panel-heading"><strong>Live domain auctions</strong>
    <span class="pull-right"><a href="{$modulelink}&action=watchlist">Watchlist</a> · <a href="{$modulelink}&action=sell">Sell a domain</a></span>
  </div>
  <div class="panel-body">
    <form class="form-inline chs-filterbar" method="get" action="index.php">
      <input type="hidden" name="m" value="cloudhost247services"><input type="hidden" name="action" value="auctions">
      <input class="form-control" name="search" value="{$filters.search|escape}" placeholder="Search domains…">
      <select class="form-control" name="sort">
        <option value="ending"{if $filters.sort eq 'ending'} selected{/if}>Ending soonest</option>
        <option value="price_asc"{if $filters.sort eq 'price_asc'} selected{/if}>Price: low to high</option>
        <option value="price_desc"{if $filters.sort eq 'price_desc'} selected{/if}>Price: high to low</option>
        <option value="bids"{if $filters.sort eq 'bids'} selected{/if}>Most bids</option>
        <option value="newest"{if $filters.sort eq 'newest'} selected{/if}>Newest listings</option>
      </select>
      <label class="checkbox-inline"><input type="checkbox" name="ending" value="1"{if $filters.ending_soon} checked{/if}> Ending in 6h</label>
      <button class="btn btn-default">Filter</button>
    </form>
  </div>

  {if !$auctions}
    <div class="panel-body"><p class="text-muted">No auctions match your filters right now. Listings change constantly — check back soon.</p></div>
  {else}
  <div class="chs-auction-grid">
    {foreach $auctions as $a}
    <a class="chs-auction-card" href="{$modulelink}&action=auction&id={$a.id}">
      <div class="chs-auction-domain">{$a.domain|escape}</div>
      <div class="chs-auction-meta">
        {if $a.status eq 'scheduled'}<span class="label label-info">Opens {$a.starts_at|date_format:"%e %b, %H:%M"}</span>
        {elseif $a.status eq 'active'}<span class="label label-success">Live</span>
        {elseif $a.status eq 'sold'}<span class="label label-primary">Sold — settlement</span>
        {elseif $a.status eq 'paid'}<span class="label label-primary">Paid — transferring</span>
        {/if}
        {if $a.bin_price_minor}<span class="label label-warning">Buy-It-Now</span>{/if}
        {if $a.has_reserve && !$a.reserve_met}<span class="label label-default">Reserve not met</span>{/if}
      </div>
      <div class="chs-auction-price">
        {if $a.bids_count > 0}
          {math equation="x / 100" x=$a.current_price_minor format="%.2f"} {$a.currency|escape}
        {else}
          From {math equation="x / 100" x=$a.start_price_minor format="%.2f"} {$a.currency|escape}
        {/if}
      </div>
      <div class="chs-auction-stats text-muted">
        {$a.bids_count} bid{if $a.bids_count != 1}s{/if} ·
        {if $a.status eq 'active'}
          <span class="chs-countdown" data-ends="{$a.seconds_remaining}">{$a.ends_at|date_format:"%e %b, %H:%M"}</span>
        {else}
          {$a.ends_at|date_format:"%e %b, %H:%M"} UTC
        {/if}
      </div>
    </a>
    {/foreach}
  </div>
  {/if}

  {if $pages > 1}
  <div class="panel-body">
    <ul class="pagination">
      {section name=p loop=$pages+1 start=1}
        {assign var=pno value=$smarty.section.p.index}
        <li{if $pno eq $page} class="active"{/if}>
          <a href="{$modulelink}&action=auctions&page={$pno}{if $filters.search}&search={$filters.search|escape:url}{/if}{if $filters.sort}&sort={$filters.sort|escape:url}{/if}">{$pno}</a>
        </li>
      {/section}
    </ul>
  </div>
  {/if}
</div>
