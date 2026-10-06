{*
 * Domain Broker — in-app notification inbox.
 *}
<div class="domainbroker-portal">

    {foreach $flash as $message}
        <div class="alert alert-{$message.type} db-flash">{$message.message}</div>
    {/foreach}

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">Notifications</h1>
            <p class="db-portal-subtitle">Everything we have told you about your acquisitions.</p>
        </div>
        <div class="db-portal-header-actions">
            <form method="post" action="index.php?m=domainbroker&amp;action=mark-read">
                {$csrf_field}
                <button type="submit" class="btn btn-default">Mark all as read</button>
            </form>
        </div>
    </div>

    <ul class="nav nav-pills db-nav">
        {foreach $nav as $item}
            <li role="presentation"{if $item.active} class="active"{/if}>
                <a href="{$item.url}"><i class="fa {$item.icon}"></i> {$item.label}</a>
            </li>
        {/foreach}
    </ul>

    {if $notifications}
        <ul class="db-notifications">
            {foreach $notifications as $notification}
                <li class="db-notification{if !$notification.read_at} db-notification-unread{/if}">
                    <div class="db-notification-head">
                        <strong>{$notification.subject}</strong>
                        <small class="text-muted">{$notification.created_at}</small>
                    </div>
                    <p>{$notification.body}</p>
                    {if $notification.request_id}
                        <a href="index.php?m=domainbroker&amp;action=view&amp;id={$notification.request_id}">
                            View acquisition
                        </a>
                    {/if}
                </li>
            {/foreach}
        </ul>
    {else}
        <div class="db-empty"><i class="fa fa-bell-o"></i><p>No notifications yet.</p></div>
    {/if}
</div>
