<div class="panel panel-default">
  <div class="panel-heading"><strong>Unified inbox</strong>
    <span class="pull-right text-muted small">{foreach $channels as $c}{$c.label|escape}{if $c.live} (live){/if}{/foreach}</span></div>
  <div class="panel-body">
    <form class="form-inline chs-filterbar" method="get" action="index.php">
      <input type="hidden" name="m" value="cloudhost247services"><input type="hidden" name="action" value="inbox">
      <input class="form-control" name="search" value="{$search|escape}" placeholder="Search subjects…">
      <select class="form-control" name="status">
        <option value="">Any status</option>
        {foreach ['Open','Answered','Customer-Reply','In Progress','On Hold','Closed'] as $s}
          <option value="{$s}"{if $status eq $s} selected{/if}>{$s}</option>
        {/foreach}
      </select>
      <button class="btn btn-default">Filter</button>
    </form>
  </div>
  <div class="list-group chs-inbox-list">
    {foreach $conversations as $c}
    <a href="{$modulelink}&action=thread&id={$c.id}" class="list-group-item{if $c.unread} chs-unread{/if}">
      {if $c.unread}<span class="chs-unread-dot" title="Unread"></span>{/if}
      <strong>{$c.subject|escape}</strong>
      <span class="label label-default">{$c.status|escape}</span>
      {if $c.urgency eq 'High'}<span class="label label-danger">High priority</span>{/if}
      <span class="text-muted pull-right small">{$c.last_reply|date_format:"%e %b, %H:%M"}</span>
      <div class="text-muted small">{$c.replies} repl{if $c.replies != 1}ies{else}y{/if} · opened {$c.date|date_format:"%e %b %Y"}</div>
    </a>
    {foreachelse}
    <div class="list-group-item text-muted">No conversations match. <a href="{$WEB_ROOT}/submitticket.php">Start one with our team</a>.</div>
    {/foreach}
  </div>
</div>
