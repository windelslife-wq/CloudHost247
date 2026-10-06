{*
 * Domain Broker — the customer's acquisition list.
 *}
<div class="domainbroker-portal">

    {foreach $flash as $message}
        <div class="alert alert-{$message.type} db-flash">{$message.message}</div>
    {/foreach}

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">My acquisitions</h1>
            <p class="db-portal-subtitle">{$pagination.total} request{if $pagination.total != 1}s{/if} in total.</p>
        </div>
        <div class="db-portal-header-actions">
            <a href="{$urls.new}" class="btn btn-primary"><i class="fa fa-plus"></i> New request</a>
        </div>
    </div>

    <ul class="nav nav-pills db-nav">
        {foreach $nav as $item}
            <li role="presentation"{if $item.active} class="active"{/if}>
                <a href="{$item.url}"><i class="fa {$item.icon}"></i> {$item.label}</a>
            </li>
        {/foreach}
    </ul>

    <form method="get" action="index.php" class="db-filterbar form-inline">
        <input type="hidden" name="m" value="domainbroker">
        <input type="hidden" name="action" value="requests">
        <div class="form-group">
            <label class="sr-only" for="db-search">Search</label>
            <input type="text" class="form-control" id="db-search" name="search"
                   value="{$filters.search}" placeholder="Reference or domain">
        </div>
        <div class="form-group">
            <label class="sr-only" for="db-status">Status</label>
            <select class="form-control" id="db-status" name="status">
                <option value="">Any status</option>
                {foreach $status_options as $value => $label}
                    <option value="{$value}"{if $filters.status == $value} selected{/if}>{$label}</option>
                {/foreach}
            </select>
        </div>
        <button type="submit" class="btn btn-default">Filter</button>
        <a href="{$urls.requests}" class="btn btn-link">Reset</a>
    </form>

    {if $requests}
        <div class="db-request-list">
            {foreach $requests as $request}
                <a class="db-request-row" href="{$request.url}">
                    <span class="db-request-row-domain">
                        {$request.domain_display}
                        <small>{$request.reference}</small>
                    </span>
                    <span class="db-request-row-budget">
                        <small>Budget</small>
                        {$request.budget.formatted}
                    </span>
                    <span class="db-request-row-offer">
                        <small>Agreed</small>
                        {if $request.agreed_amount.minor > 0}{$request.agreed_amount.formatted}{else}&mdash;{/if}
                    </span>
                    <span class="db-request-row-status">
                        <span class="db-status db-status-{$request.status_tone}">{$request.status_label}</span>
                    </span>
                    <span class="db-request-row-date">
                        <small>Updated</small>
                        {$request.updated_display}
                    </span>
                </a>
            {/foreach}
        </div>

        {if $pagination.pages > 1}
            <nav class="db-pagination">
                <ul class="pagination">
                    <li{if !$pagination.has_previous} class="disabled"{/if}>
                        <a href="{if $pagination.has_previous}{$pagination.previous_url}{else}#{/if}">&laquo;</a>
                    </li>
                    {foreach $pagination.window as $page}
                        <li{if $page.active} class="active"{/if}><a href="{$page.url}">{$page.number}</a></li>
                    {/foreach}
                    <li{if !$pagination.has_next} class="disabled"{/if}>
                        <a href="{if $pagination.has_next}{$pagination.next_url}{else}#{/if}">&raquo;</a>
                    </li>
                </ul>
            </nav>
        {/if}
    {else}
        <div class="db-empty">
            <i class="fa fa-folder-open-o"></i>
            <p>No acquisition requests match those filters.</p>
            <a href="{$urls.new}" class="btn btn-primary">Start a request</a>
        </div>
    {/if}
</div>
