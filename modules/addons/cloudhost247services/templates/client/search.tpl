{* Domain search — live provider-backed results with server-side pricing. *}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Search for a domain</strong></div>
  <div class="panel-body">
    <form method="get" action="{$modulelink}">
      <input type="hidden" name="action" value="search">
      <div class="input-group">
        <input type="text" class="form-control input-lg" name="q" value="{$query|escape}" placeholder="your-next-big-thing.com" autocomplete="off" spellcheck="false" maxlength="256" autofocus>
        <span class="input-group-btn"><button type="submit" class="btn btn-primary btn-lg">Search</button></span>
      </div>
    </form>
    {if $errors}<div class="alert alert-danger" style="margin-top:10px">{foreach $errors as $err}<div>{$err|escape}</div>{/foreach}</div>{/if}
  </div>
</div>

{if $result}
<div class="panel panel-default">
  <div class="panel-heading"><strong>{$result.domain|escape}</strong></div>
  <div class="panel-body">
    {if $result.status == 'available'}
      <p><span class="label label-success">Available</span> — checked live via {$result.provider|escape}.</p>
      <table class="table table-condensed">
        <tr><th>Register (1 year)</th><td>{if $result.register_final_minor !== null}{math equation="x/100" x=$result.register_final_minor format="%.2f"} {$result.currency|escape}{if $result.discount_percent} <span class="label label-info">{$result.discount_percent|escape}% club discount</span>{/if}{else}—{/if}</td>
            <td><a class="btn btn-success btn-sm" href="{$result.add_to_cart_url|escape}">Add to cart</a></td></tr>
        <tr><th>Renewal (per year)</th><td>{if $result.renew_final_minor !== null}{math equation="x/100" x=$result.renew_final_minor format="%.2f"} {$result.currency|escape}{else}—{/if}</td><td></td></tr>
        <tr><th>Transfer in</th><td>{if $result.transfer_final_minor !== null}{math equation="x/100" x=$result.transfer_final_minor format="%.2f"} {$result.currency|escape}{else}—{/if}</td>
            <td>{if $result.transfer_final_minor !== null}<a class="btn btn-default btn-sm" href="{$result.transfer_url|escape}">Start transfer</a>{/if}</td></tr>
      </table>
      <p class="text-muted small">Prices are the operator's live catalogue prices{if $club} with your Discount Domain Club discount applied server-side{/if}. The cart charges exactly these amounts.</p>
      <p>
        <a class="btn btn-default btn-sm" href="{$result.whois_url|escape}">WHOIS lookup</a>
        <a class="btn btn-default btn-sm" href="{$result.appraisal_url|escape}">Appraise this domain</a>
      </p>
    {elseif $result.status == 'taken'}
      <p><span class="label label-danger">Taken</span> — the registry reports this name is registered.</p>
      <p><a class="btn btn-default btn-sm" href="{$result.whois_url|escape}">View public WHOIS</a>
         <a class="btn btn-default btn-sm" href="domain-broker.php?domain={$result.domain|escape:'url'}">Ask our broker</a></p>
    {elseif $result.status == 'unsupported_tld'}
      <p><span class="label label-warning">Unsupported extension</span> — we do not sell .{$result.tld|escape} yet. <a href="tld-directory.php">Browse supported extensions</a>.</p>
    {elseif $result.status == 'DOMAIN_PROVIDER_NOT_CONFIGURED'}
      <p><span class="label label-warning">Lookup unavailable</span> — no domain provider is configured for .{$result.tld|escape} right now. Prices below are our catalogue prices; availability cannot be confirmed until a provider is configured.</p>
      <table class="table table-condensed">
        <tr><th>Register (1 year)</th><td>{if $result.register_minor !== null}{math equation="x/100" x=$result.register_minor format="%.2f"} {$result.currency|escape}{else}—{/if}</td></tr>
        <tr><th>Renewal (per year)</th><td>{if $result.renew_minor !== null}{math equation="x/100" x=$result.renew_minor format="%.2f"} {$result.currency|escape}{else}—{/if}</td></tr>
        <tr><th>Transfer in</th><td>{if $result.transfer_minor !== null}{math equation="x/100" x=$result.transfer_minor format="%.2f"} {$result.currency|escape}{else}—{/if}</td></tr>
      </table>
    {else}
      <p><span class="label label-default">Unknown</span> — the registry did not answer (status: {$result.status|escape}). Try again in a moment; we never guess availability.</p>
    {/if}
  </div>
</div>
{/if}

{if $suggestions}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Available alternatives</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Domain</th><th>Availability</th><th>Register</th><th></th></tr></thead>
    <tbody>
    {foreach $suggestions as $s}
      <tr>
        <td>{$s.domain|escape}</td>
        <td>{if $s.available === true}<span class="label label-success">Available</span>{elseif $s.available === false}<span class="label label-danger">Taken</span>{else}<span class="label label-default">Unknown</span>{/if}</td>
        <td>{if $s.register_final_minor !== null}{math equation="x/100" x=$s.register_final_minor format="%.2f"} {$s.currency|escape}{else}—{/if}</td>
        <td>{if $s.available === true}<a class="btn btn-xs btn-success" href="{$s.add_to_cart_url|escape}">Add</a>{/if}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}

<p class="text-muted small">Also: <a href="{$modulelink}&action=bulk">bulk search</a> · <a href="tld-directory.php">all extensions</a> · <a href="{$modulelink}&action=transfers">transfer a domain</a> · <a href="whois-lookup.php">WHOIS</a></p>
