<div class="panel panel-default">
  <div class="panel-heading"><strong>Notifications</strong>
    {if $unread > 0}
    <form method="post" action="{$modulelink}&action=notifications" class="pull-right chs-inline-form">
      {$csrf_field}<input type="hidden" name="do" value="mark_all">
      <button class="btn btn-xs btn-default">Mark all read</button>
    </form>
    {/if}
  </div>
  <div class="list-group">
    {foreach $notifications as $n}
    <div class="list-group-item{if !$n.read_at} chs-unread{/if}">
      <strong>{$n.subject|escape}</strong>
      <span class="text-muted pull-right small">{$n.created_at|date_format:"%e %b, %H:%M"}</span>
      <div class="text-muted">{$n.body|escape}</div>
      <div style="margin-top:6px">
        {if $n.link}<a class="btn btn-xs btn-primary" href="{$n.link}">Open</a>{/if}
        {if !$n.read_at}
        <form method="post" action="{$modulelink}&action=notifications" class="chs-inline-form">
          {$csrf_field}<input type="hidden" name="id" value="{$n.id}">
          <button class="btn btn-xs btn-link">Mark read</button>
        </form>
        {/if}
      </div>
    </div>
    {foreachelse}
    <div class="list-group-item text-muted">No notifications yet.</div>
    {/foreach}
  </div>
</div>
