{*
 * Domain Broker — acquisition request form.
 *}
<div class="domainbroker-portal">

    {foreach $flash as $message}
        <div class="alert alert-{$message.type} db-flash">{$message.message}</div>
    {/foreach}

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">Request a domain acquisition</h1>
            <p class="db-portal-subtitle">
                Tell us the domain and your ceiling. A broker approaches the current owner on your behalf.
            </p>
        </div>
    </div>

    <ul class="nav nav-pills db-nav">
        {foreach $nav as $item}
            <li role="presentation"{if $item.active} class="active"{/if}>
                <a href="{$item.url}"><i class="fa {$item.icon}"></i> {$item.label}</a>
            </li>
        {/foreach}
    </ul>

    <div class="row">
        <div class="col-md-8">
            <form method="post" action="index.php?m=domainbroker&amp;action=create" class="db-form db-card">
                {$csrf_field}

                <div class="form-group">
                    <label for="db-domain">Domain you want</label>
                    <input type="text" class="form-control input-lg" id="db-domain" name="domain"
                           value="{$prefill_domain}" placeholder="example.com" required
                           autocomplete="off" spellcheck="false">
                    <p class="help-block">Enter the exact domain, without http:// or www.</p>
                </div>

                {if $lookup}
                    <div class="alert alert-info db-lookup">
                        <strong>{$lookup.domain}</strong> &mdash;
                        {if $lookup.available}
                            this domain appears to be available to register directly, which is far cheaper than a
                            brokered acquisition.
                        {else}
                            this domain is registered. That is exactly what our brokers are for.
                        {/if}
                    </div>
                {/if}

                <div class="row">
                    <div class="col-sm-8">
                        <div class="form-group">
                            <label for="db-budget">Maximum budget</label>
                            <input type="text" class="form-control" id="db-budget" name="budget"
                                   inputmode="decimal" placeholder="{$min_budget}" required>
                            <p class="help-block">The most you are willing to pay. We never exceed it.</p>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label for="db-currency">Currency</label>
                            <select class="form-control" id="db-currency" name="currency">
                                {foreach $currencies as $code => $label}
                                    <option value="{$code}"{if $code == $default_currency} selected{/if}>{$label}</option>
                                {/foreach}
                            </select>
                        </div>
                    </div>
                </div>

                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="budget_includes_fees" value="1" checked>
                        My budget includes your brokerage fee and any tax
                    </label>
                </div>

                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="anonymous" value="1" checked>
                        Negotiate anonymously &mdash; do not reveal my identity to the owner
                    </label>
                </div>

                <div class="form-group">
                    <label for="db-message">Anything your broker should know</label>
                    <textarea class="form-control" id="db-message" name="message" rows="4"
                              placeholder="Why you want this domain, deadlines, alternatives you would accept…"></textarea>
                </div>

                <div class="form-group">
                    <label for="db-promo">Promotional code <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" id="db-promo" name="promo_code">
                </div>

                <button type="submit" class="btn btn-primary btn-lg">Submit request</button>
                <a href="{$urls.dashboard}" class="btn btn-link">Cancel</a>
            </form>
        </div>

        <div class="col-md-4">
            <div class="db-card db-card-muted">
                <h3>How it works</h3>
                <ol class="db-steps">
                    <li>You tell us the domain and your ceiling.</li>
                    <li>We assign a broker and approach the owner &mdash; anonymously if you asked us to.</li>
                    <li>You see every offer and counteroffer and decide.</li>
                    <li>You pay only once you have accepted an offer in writing.</li>
                    <li>Funds are held until the transfer completes and is verified.</li>
                </ol>
            </div>

            <div class="db-card db-card-muted">
                <h3>Fees</h3>
                <p>{$fee_note}</p>
                <p class="text-muted">
                    Acquisitions above {$kyc_threshold} require identity verification before completion.
                </p>
            </div>

            <div class="db-card db-card-muted">
                <h3>Questions?</h3>
                <p>Our FAQ answers the most common ones.</p>
                <a href="{$urls.help}" class="btn btn-default btn-block">Read the FAQ</a>
            </div>
        </div>
    </div>
</div>
