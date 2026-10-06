{*
 * Domain Broker — client dashboard.
 * Every value arrives pre-formatted from the controller; templates never
 * compute money, status or authorisation.
 *}
<div class="domainbroker-portal">

    {foreach $flash as $message}
        <div class="alert alert-{$message.type} db-flash">{$message.message}</div>
    {/foreach}

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">{$brand}</h1>
            <p class="db-portal-subtitle">Track every domain acquisition we are running for you.</p>
        </div>
        <div class="db-portal-header-actions">
            <a href="{$urls.new}" class="btn btn-primary btn-lg"><i class="fa fa-plus"></i> New acquisition request</a>
        </div>
    </div>

    <ul class="nav nav-pills db-nav">
        {foreach $nav as $item}
            <li role="presentation"{if $item.active} class="active"{/if}>
                <a href="{$item.url}"><i class="fa {$item.icon}"></i> {$item.label}</a>
            </li>
        {/foreach}
    </ul>

    <div class="row db-metrics">
        <div class="col-xs-6 col-md-3">
            <div class="db-metric">
                <span class="db-metric-value">{$summary.active}</span>
                <span class="db-metric-label">Active acquisitions</span>
            </div>
        </div>
        <div class="col-xs-6 col-md-3">
            <div class="db-metric{if $summary.offers_awaiting > 0} db-metric-attention{/if}">
                <span class="db-metric-value">{$summary.offers_awaiting}</span>
                <span class="db-metric-label">Offers awaiting you</span>
            </div>
        </div>
        <div class="col-xs-6 col-md-3">
            <div class="db-metric">
                <span class="db-metric-value">{$summary.in_transfer}</span>
                <span class="db-metric-label">In transfer</span>
            </div>
        </div>
        <div class="col-xs-6 col-md-3">
            <div class="db-metric db-metric-success">
                <span class="db-metric-value">{$summary.completed}</span>
                <span class="db-metric-label">Completed</span>
            </div>
        </div>
    </div>

    {if $actionable}
        <section class="db-panel db-panel-attention">
            <div class="db-panel-head">
                <h2>Needs your attention</h2>
                <p>These acquisitions are waiting on a decision or a payment from you.</p>
            </div>
            <div class="db-panel-body">
                {foreach $actionable as $request}
                    <div class="db-request-card">
                        <div class="db-request-card-main">
                            <a class="db-request-domain" href="{$request.url}">{$request.domain_display}</a>
                            <div class="db-request-meta">
                                <span class="db-request-ref">{$request.reference}</span>
                                <span class="db-request-sep">&middot;</span>
                                <span>Budget {$request.budget.formatted}</span>
                            </div>
                        </div>
                        <div class="db-request-card-side">
                            <span class="db-status db-status-{$request.status_tone}">{$request.status_label}</span>
                            <div class="db-request-updated">Updated {$request.updated_display}</div>
                        </div>
                    </div>
                {/foreach}
            </div>
        </section>
    {/if}

    <section class="db-panel">
        <div class="db-panel-head">
            <h2>Recent requests</h2>
            <a href="{$urls.requests}" class="db-panel-link">View all</a>
        </div>
        <div class="db-panel-body">
            {if $recent}
                {foreach $recent as $request}
                    <div class="db-request-card">
                        <div class="db-request-card-main">
                            <a class="db-request-domain" href="{$request.url}">{$request.domain_display}</a>
                            <div class="db-request-meta">
                                <span class="db-request-ref">{$request.reference}</span>
                                <span class="db-request-sep">&middot;</span>
                                <span>Budget {$request.budget.formatted}</span>
                                {if $request.agreed_amount.minor > 0}
                                    <span class="db-request-sep">&middot;</span>
                                    <span>Agreed {$request.agreed_amount.formatted}</span>
                                {/if}
                            </div>
                        </div>
                        <div class="db-request-card-side">
                            <span class="db-status db-status-{$request.status_tone}">{$request.status_label}</span>
                            <div class="db-request-updated">Updated {$request.updated_display}</div>
                        </div>
                    </div>
                {/foreach}
            {else}
                <div class="db-empty">
                    <i class="fa fa-search"></i>
                    <p>You have not asked us to acquire a domain yet.</p>
                    <a href="{$urls.new}" class="btn btn-primary">Start a request</a>
                </div>
            {/if}
        </div>
    </section>

    {if $spend_rows}
        <section class="db-panel">
            <div class="db-panel-head"><h2>Total spend</h2></div>
            <div class="db-panel-body">
                <div class="table-responsive">
                    <table class="table db-table">
                        <thead>
                        <tr>
                            <th>Currency</th><th>Acquisitions</th><th>Fees</th>
                            <th>Tax</th><th>Refunded</th><th>Net</th>
                        </tr>
                        </thead>
                        <tbody>
                        {foreach $spend_rows as $row}
                            <tr>
                                <td>{$row.currency}</td>
                                <td>{$row.acquisition}</td>
                                <td>{$row.fees}</td>
                                <td>{$row.tax}</td>
                                <td>{$row.refunded}</td>
                                <td><strong>{$row.net}</strong></td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    {/if}

    <div class="db-helpstrip">
        <div>
            <strong>Not sure where to start?</strong>
            Tell us the domain you want and the most you are willing to pay &mdash; we handle the rest.
        </div>
        <a href="{$urls.help}" class="btn btn-default">Read the FAQ</a>
    </div>
</div>
