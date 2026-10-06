{if $flash_error}<div class="alert alert-danger">{$flash_error|escape}</div>{/if}
<div class="panel panel-default">
  <div class="panel-heading">
    <a href="{$modulelink}&action=inbox" class="btn btn-xs btn-default">← Inbox</a>
    <strong>{$thread.subject|escape}</strong>
    <span class="label label-default pull-right">{$thread.status|escape}</span>
  </div>
  <div class="panel-body chs-thread">
    {foreach $thread.messages as $m}
    <div class="chs-message {if $m.authorType eq 'staff'}chs-staff{else}chs-client{/if}">
      <div class="chs-message-meta"><strong>{$m.author|escape}</strong> · {$m.date|date_format:"%e %b %Y, %H:%M"}</div>
      <div class="chs-message-body">{$m.body|escape|nl2br}</div>
    </div>
    {/foreach}
  </div>
  {if $thread.status neq 'Closed'}
  <div class="panel-body">
    <form method="post" action="{$modulelink}&action=thread&id={$thread.id}">
      {$csrf_field}
      <div class="form-group"><textarea class="form-control" name="body" rows="4" maxlength="8000" placeholder="Reply…"></textarea></div>
      <button class="btn btn-primary">Send reply</button>
    </form>
  </div>
  {else}
  <div class="panel-body"><p class="text-muted">This conversation is closed. <a href="{$WEB_ROOT}/submitticket.php">Open a new one</a> to continue.</p></div>
  {/if}
</div>
