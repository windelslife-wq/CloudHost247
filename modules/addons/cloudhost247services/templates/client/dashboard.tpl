{* CloudHost247 Services — client dashboard *}
{if $flash}<div class="alert alert-success">{$flash}</div>{/if}
<div class="chs-dash">
  <div class="chs-dash-grid">
    {if $features.valuation}
    <a class="chs-dash-card" href="{$modulelink}&action=valuation">
      <span class="chs-dash-icon chs-i-gauge"></span>
      <span class="chs-dash-title">Domain Valuation</span>
      <span class="chs-dash-desc">Instant, explainable market estimates.</span>
    </a>
    {/if}
    {if $features.auctions}
    <a class="chs-dash-card" href="{$modulelink}&action=auctions">
      <span class="chs-dash-icon chs-i-hammer"></span>
      <span class="chs-dash-title">Domain Auctions</span>
      <span class="chs-dash-desc">Bid on premium names, or sell your own.</span>
    </a>
    <a class="chs-dash-card" href="{$modulelink}&action=watchlist">
      <span class="chs-dash-icon chs-i-eye"></span>
      <span class="chs-dash-title">Watchlist</span>
      <span class="chs-dash-desc">Auctions you are tracking.</span>
    </a>
    {/if}
    {if $features.club}
    <a class="chs-dash-card" href="{$modulelink}&action=club">
      <span class="chs-dash-icon chs-i-tag"></span>
      <span class="chs-dash-title">Discount Domain Club</span>
      <span class="chs-dash-desc">{if $membership}Active until {$membership.expires_at|date_format:"%e %b %Y"}{else}Member pricing on registrations & renewals.{/if}</span>
    </a>
    {/if}
    {if $features.requests}
    <a class="chs-dash-card" href="{$modulelink}&action=requests">
      <span class="chs-dash-icon chs-i-briefcase"></span>
      <span class="chs-dash-title">Service Requests</span>
      <span class="chs-dash-desc">{$open_requests} open · design, dev, SEO, migration…</span>
    </a>
    {/if}
    {if $features.logo}
    <a class="chs-dash-card" href="{$modulelink}&action=logostudio">
      <span class="chs-dash-icon chs-i-pen"></span>
      <span class="chs-dash-title">Logo Studio</span>
      <span class="chs-dash-desc">{$logo_count} saved project{if $logo_count != 1}s{/if}.</span>
    </a>
    {/if}
    <a class="chs-dash-card" href="{$modulelink}&action=ai">
      <span class="chs-dash-icon chs-i-magic"></span>
      <span class="chs-dash-title">AI Website Builder</span>
      <span class="chs-dash-desc">{if $ai_status.configured}Describe it, get a full site outline.{else}Awaiting provider setup by our team.{/if}</span>
    </a>
    {if $features.inbox}
    <a class="chs-dash-card" href="{$modulelink}&action=inbox">
      <span class="chs-dash-icon chs-i-inbox"></span>
      <span class="chs-dash-title">Unified Inbox</span>
      <span class="chs-dash-desc">{if $inbox_unread > 0}{$inbox_unread} unread conversation{if $inbox_unread != 1}s{/if}{else}All conversations read.{/if}</span>
    </a>
    {/if}
    <a class="chs-dash-card" href="{$modulelink}&action=notifications">
      <span class="chs-dash-icon chs-i-bell"></span>
      <span class="chs-dash-title">Notifications</span>
      <span class="chs-dash-desc">{if $unread_notifications > 0}{$unread_notifications} unread{else}You are up to date.{/if}</span>
    </a>
  </div>

  {if $notifications}
  <div class="panel panel-default chs-panel">
    <div class="panel-heading"><strong>Latest activity</strong></div>
    <div class="list-group">
      {foreach $notifications as $n}
      <a class="list-group-item{if !$n.read_at} chs-unread{/if}" href="{if $n.link neq ''}{$n.link}{else}{$modulelink}&action=notifications{/if}">
        <strong>{$n.subject|escape}</strong>
        <span class="text-muted pull-right small">{$n.created_at|date_format:"%e %b, %H:%M"}</span>
        <div class="text-muted">{$n.body|escape|truncate:140}</div>
      </a>
      {/foreach}
    </div>
  </div>
  {/if}

  {if $requests}
  <div class="panel panel-default chs-panel">
    <div class="panel-heading"><strong>Recent service requests</strong>
      <a class="btn btn-xs btn-primary pull-right" href="{$modulelink}&action=requestnew">New request</a></div>
    <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr><th>#</th><th>Title</th><th>Status</th><th>Opened</th></tr></thead>
      <tbody>
      {foreach $requests as $r}
      <tr>
        <td>{$r.id}</td>
        <td><a href="{$modulelink}&action=request&id={$r.id}">{$r.title|escape}</a></td>
        <td><span class="label label-default">{$r.status|replace:'_':' '|ucwords}</span></td>
        <td>{$r.created_at|date_format:"%e %b %Y"}</td>
      </tr>
      {/foreach}
      </tbody>
    </table>
    </div>
  </div>
  {/if}
</div>
