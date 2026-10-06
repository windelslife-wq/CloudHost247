{*
 * Domain Broker — transaction history.
 *}
<div class="domainbroker-portal">

    {foreach $flash as $message}
        <div class="alert alert-{$message.type} db-flash">{$message.message}</div>
    {/foreach}

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">Transaction history</h1>
            <p class="db-portal-subtitle">Every invoice, payment and refund across your acquisitions.</p>
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
        <input type="hidden" name="action" value="transactions">
        <div class="form-group">
            <label class="sr-only" for="db-tx-status">Status</label>
            <select class="form-control" id="db-tx-status" name="status">
                <option value="">Any status</option>
                {foreach $status_options as $value => $label}
                    <option value="{$value}"{if $filters.status == $value} selected{/if}>{$label}</option>
                {/foreach}
            </select>
        </div>
        <div class="form-group">
            <label class="sr-only" for="db-tx-from">From</label>
            <input type="date" class="form-control" id="db-tx-from" name="from" value="{$filters.from}">
        </div>
        <div class="form-group">
            <label class="sr-only" for="db-tx-to">To</label>
            <input type="date" class="form-control" id="db-tx-to" name="to" value="{$filters.to}">
        </div>
        <button type="submit" class="btn btn-default">Filter</button>
        <a href="{$urls.transactions}" class="btn btn-link">Reset</a>
    </form>

    {if $transactions}
        <div class="table-responsive">
            <table class="table db-table">
                <thead>
                <tr>
                    <th>Date</th><th>Reference</th><th>Domain</th><th>Type</th>
                    <th>Status</th><th class="text-right">Total</th><th class="text-right">Refunded</th><th></th>
                </tr>
                </thead>
                <tbody>
                {foreach $transactions as $tx}
                    <tr>
                        <td>{$tx.created_at}</td>
                        <td>{$tx.reference}</td>
                        <td><a href="{$tx.request_url}">{$tx.domain}</a></td>
                        <td>{$tx.type}</td>
                        <td><span class="db-status db-status-{$tx.status_tone}">{$tx.status_label}</span></td>
                        <td class="text-right">{$tx.total.formatted}</td>
                        <td class="text-right">{if $tx.refunded.minor > 0}{$tx.refunded.formatted}{else}&mdash;{/if}</td>
                        <td class="text-right">
                            {if $tx.invoice_url}<a href="{$tx.invoice_url}" class="btn btn-xs btn-default">Invoice</a>{/if}
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="db-empty">
            <i class="fa fa-credit-card"></i>
            <p>You have no transactions yet.</p>
        </div>
    {/if}

    {if $spend_rows}
        <section class="db-panel">
            <div class="db-panel-head"><h2>Totals</h2></div>
            <div class="db-panel-body">
                <div class="table-responsive">
                    <table class="table db-table">
                        <thead>
                        <tr><th>Currency</th><th>Acquisitions</th><th>Fees</th><th>Tax</th><th>Refunded</th><th>Net</th></tr>
                        </thead>
                        <tbody>
                        {foreach $spend_rows as $row}
                            <tr>
                                <td>{$row.currency}</td><td>{$row.acquisition}</td><td>{$row.fees}</td>
                                <td>{$row.tax}</td><td>{$row.refunded}</td><td><strong>{$row.net}</strong></td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    {/if}
</div>
