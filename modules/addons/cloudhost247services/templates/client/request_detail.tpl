{if $flash_ok}<div class="alert alert-success">{$flash_ok}</div>{/if}
{if $flash_error}<div class="alert alert-danger">{$flash_error|escape}</div>{/if}

<div class="panel panel-default">
  <div class="panel-heading">
    <strong>#{$request.id} · {$request.title|escape}</strong>
    <span class="label label-primary pull-right">{$request.status|replace:'_':' '|ucwords}</span>
  </div>
  <div class="panel-body">
    <p class="text-muted">{$request.type_label|escape} · opened {$request.created_at|date_format:"%e %B %Y"}
      {if $request.budget_range} · budget {$request.budget_range|replace:'_':'–'|escape}{/if}
      {if $request.target_domain} · {$request.target_domain|escape}{/if}</p>

    {if $request.status eq 'quoted' && $request.quote_minor}
    <div class="alert alert-info chs-quote-box">
      <h4>Your quote: {math equation="x / 100" x=$request.quote_minor format="%.2f"} {$request.currency|escape}</h4>
      <p>Accepting raises a single invoice for the quoted amount. Work begins on payment.</p>
      <form method="post" action="{$modulelink}&action=request&id={$request.id}" style="display:inline">
        {$csrf_field}<input type="hidden" name="do" value="accept_quote">
        <button class="btn btn-success">Accept quote &amp; pay</button>
      </form>
    </div>
    {/if}

    <div class="chs-timeline">
      {foreach $request.updates as $u}
      <div class="chs-update {if $u.author_type eq 'admin'}chs-staff{else}chs-client{/if}">
        <div class="chs-update-meta">
          {if $u.author_type eq 'admin'}CloudHost247 team{else}You{/if} · {$u.created_at|date_format:"%e %b, %H:%M"}
          {if $u.status_to} → <strong>{$u.status_to|replace:'_':' '|ucwords}</strong>{/if}
        </div>
        {if $u.body}<div class="chs-update-body">{$u.body|escape|nl2br}</div>{/if}
      </div>
      {/foreach}
    </div>

    {if in_array($request.status, ['requested','reviewing','quoted','accepted','in_progress','delivered'])}
    <form method="post" action="{$modulelink}&action=request&id={$request.id}">
      {$csrf_field}<input type="hidden" name="do" value="reply">
      <div class="form-group"><label>Add a message</label>
        <textarea class="form-control" name="body" rows="4" maxlength="8000"></textarea></div>
      <button class="btn btn-primary">Send</button>
    </form>
    {/if}

    {if in_array($request.status, ['requested','reviewing','quoted'])}
    <form method="post" action="{$modulelink}&action=request&id={$request.id}" class="chs-inline-form"
          onsubmit="return confirm('Cancel this request?')">
      {$csrf_field}<input type="hidden" name="do" value="cancel">
      <button class="btn btn-link text-danger">Cancel request</button>
    </form>
    {/if}
    {if $request.status eq 'delivered'}
    <form method="post" action="{$modulelink}&action=request&id={$request.id}" class="chs-inline-form">
      {$csrf_field}<input type="hidden" name="do" value="complete">
      <button class="btn btn-success">Mark as completed</button>
    </form>
    {/if}
  </div>
</div>
