{*
 * Domain Broker — a single acquisition.
 *
 * Nothing here decides what the customer may do: $can.* is computed
 * server-side and every form posts back through the CSRF-protected portal.
 *}
<div class="domainbroker-portal db-detail">

    {foreach $flash as $message}
        <div class="alert alert-{$message.type} db-flash">{$message.message}</div>
    {/foreach}

    <div class="db-portal-header">
        <div class="db-portal-header-text">
            <h1 class="db-portal-title">{$request.domain_display}</h1>
            <p class="db-portal-subtitle">
                {$request.reference} &middot; submitted {$request.submitted_display}
            </p>
        </div>
        <div class="db-portal-header-actions">
            <span class="db-status db-status-lg db-status-{$request.status_tone}">{$request.status_label}</span>
        </div>
    </div>

    <p class="db-status-note">{$request.status_description}</p>

    <ol class="db-tracker">
        {foreach $tracker as $step}
            <li class="db-tracker-step db-tracker-{$step.state}">
                <span class="db-tracker-dot"></span>
                <span class="db-tracker-label">{$step.label}</span>
            </li>
        {/foreach}
    </ol>

    {if $pending_offer && $can.respond_to_offer}
        <section class="db-panel db-panel-attention" id="offer">
            <div class="db-panel-head">
                <h2>An offer is waiting for your decision</h2>
                <p>Offer {$pending_offer.reference} &middot; expires {$pending_offer.expires_at}</p>
            </div>
            <div class="db-panel-body">
                <div class="db-offer-figures">
                    <div><span>Acquisition price</span><strong>{$pending_offer.amount.formatted}</strong></div>
                    <div><span>Brokerage fee</span><strong>{$pending_offer.fee.formatted}</strong></div>
                    <div class="db-offer-total"><span>Total payable</span><strong>{$pending_offer.total.formatted}</strong></div>
                </div>
                {if $pending_offer.message}
                    <blockquote class="db-offer-message">{$pending_offer.message}</blockquote>
                {/if}

                <div class="db-offer-actions">
                    <form method="post" action="index.php?m=domainbroker&amp;action=accept-offer" class="db-inline">
                        {$csrf_field}
                        <input type="hidden" name="id" value="{$request.id}">
                        <input type="hidden" name="offer_id" value="{$pending_offer.id}">
                        <button type="submit" class="btn btn-success btn-lg"
                                onclick="return confirm('Accept this offer? We will raise your invoice next.');">
                            Accept {$pending_offer.total.formatted}
                        </button>
                    </form>

                    <button type="button" class="btn btn-default btn-lg" data-db-toggle="db-counter">Counteroffer</button>

                    <form method="post" action="index.php?m=domainbroker&amp;action=reject-offer" class="db-inline">
                        {$csrf_field}
                        <input type="hidden" name="id" value="{$request.id}">
                        <input type="hidden" name="offer_id" value="{$pending_offer.id}">
                        <input type="hidden" name="reason" value="Declined by the customer">
                        <button type="submit" class="btn btn-link">Decline</button>
                    </form>
                </div>

                {if $can.counter}
                    <form method="post" action="index.php?m=domainbroker&amp;action=counter-offer"
                          class="db-form db-hidden" id="db-counter">
                        {$csrf_field}
                        <input type="hidden" name="id" value="{$request.id}">
                        <input type="hidden" name="offer_id" value="{$pending_offer.id}">
                        <div class="form-group">
                            <label for="db-counter-amount">Your counteroffer ({$request.currency})</label>
                            <input type="text" class="form-control" id="db-counter-amount" name="amount" required>
                        </div>
                        <div class="form-group">
                            <label for="db-counter-message">Message to your broker</label>
                            <textarea class="form-control" id="db-counter-message" name="message" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Send counteroffer</button>
                    </form>
                {/if}
            </div>
        </section>
    {/if}

    {if $can.pay}
        <section class="db-panel db-panel-attention" id="payment-action">
            <div class="db-panel-head">
                <h2>Payment</h2>
                <p>Your funds are held securely and only released once the transfer is complete and verified.</p>
            </div>
            <div class="db-panel-body">
                <div class="db-offer-figures">
                    <div><span>Acquisition price</span><strong>{$request.agreed_amount.formatted}</strong></div>
                    <div><span>Brokerage fee</span><strong>{$request.broker_fee.formatted}</strong></div>
                    <div><span>Tax</span><strong>{$request.tax.formatted}</strong></div>
                    <div class="db-offer-total"><span>Total</span><strong>{$request.total.formatted}</strong></div>
                </div>
                {if $invoice_url}
                    <a href="{$invoice_url}" class="btn btn-primary btn-lg">View and pay invoice</a>
                {else}
                    <form method="post" action="index.php?m=domainbroker&amp;action=pay">
                        {$csrf_field}
                        <input type="hidden" name="id" value="{$request.id}">
                        <button type="submit" class="btn btn-primary btn-lg">Generate my invoice</button>
                    </form>
                {/if}
            </div>
        </section>
    {/if}

    <div class="row">
        <div class="col-md-8">

            <section class="db-panel">
                <div class="db-panel-head"><h2>Acquisition details</h2></div>
                <div class="db-panel-body">
                    <dl class="db-dl">
                        <dt>Domain</dt><dd>{$request.domain_display}</dd>
                        <dt>Request ID</dt><dd>{$request.reference}</dd>
                        <dt>Status</dt><dd>{$request.status_label}</dd>
                        <dt>Broker</dt>
                        <dd>{if $broker}{$broker.display_name}{else}Not yet assigned{/if}</dd>
                        <dt>Your budget</dt>
                        <dd>{$request.budget.formatted}{if $request.budget_includes_fees} (fees included){/if}</dd>
                        <dt>Agreed amount</dt>
                        <dd>{if $request.agreed_amount.minor > 0}{$request.agreed_amount.formatted}{else}Not yet agreed{/if}</dd>
                        <dt>Brokerage fee</dt><dd>{$request.broker_fee.formatted}</dd>
                        <dt>Tax</dt><dd>{$request.tax.formatted}</dd>
                        <dt>Total</dt><dd><strong>{$request.total.formatted}</strong></dd>
                        <dt>Payment status</dt><dd>{$request.payment_status_label}</dd>
                        <dt>Transfer status</dt><dd>{$request.transfer_status_label}</dd>
                        <dt>Negotiation rounds</dt><dd>{$request.negotiation_rounds}</dd>
                        <dt>Anonymous</dt><dd>{if $request.anonymous}Yes{else}No{/if}</dd>
                    </dl>

                    {if $can.edit}
                        <button type="button" class="btn btn-default btn-sm" data-db-toggle="db-edit">Edit my brief</button>
                        <form method="post" action="index.php?m=domainbroker&amp;action=update" class="db-form db-hidden" id="db-edit">
                            {$csrf_field}
                            <input type="hidden" name="id" value="{$request.id}">
                            <div class="form-group">
                                <label for="db-edit-budget">Maximum budget ({$request.currency})</label>
                                <input type="text" class="form-control" id="db-edit-budget" name="budget"
                                       value="{$request.budget.amount}">
                            </div>
                            <div class="form-group">
                                <label for="db-edit-message">Brief</label>
                                <textarea class="form-control" id="db-edit-message" name="message" rows="4">{$request.message}</textarea>
                            </div>
                            <div class="checkbox">
                                <label><input type="checkbox" name="anonymous" value="1"{if $request.anonymous} checked{/if}>
                                    Keep my identity private</label>
                            </div>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </form>
                    {/if}
                </div>
            </section>

            <section class="db-panel" id="offers">
                <div class="db-panel-head"><h2>Negotiation history</h2></div>
                <div class="db-panel-body">
                    {if $offers}
                        <div class="table-responsive">
                            <table class="table db-table">
                                <thead>
                                <tr><th>Round</th><th>From</th><th>Amount</th><th>Total</th><th>Status</th><th>Date</th></tr>
                                </thead>
                                <tbody>
                                {foreach $offers as $offer}
                                    <tr>
                                        <td>{$offer.round}</td>
                                        <td>{if $offer.direction == 'to_customer'}Owner{else}You / your broker{/if}</td>
                                        <td>{$offer.amount.formatted}</td>
                                        <td>{$offer.total.formatted}</td>
                                        <td>{$offer.status_label}</td>
                                        <td>{$offer.created_at}</td>
                                    </tr>
                                {/foreach}
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted db-note">
                            Every round is kept permanently &mdash; nothing in this history is ever overwritten.
                        </p>
                    {else}
                        <p class="text-muted">No offers have been exchanged yet.</p>
                    {/if}
                </div>
            </section>

            {if $transfer}
                <section class="db-panel" id="transfer">
                    <div class="db-panel-head"><h2>Transfer</h2></div>
                    <div class="db-panel-body">
                        <dl class="db-dl">
                            <dt>Reference</dt><dd>{$transfer.reference}</dd>
                            <dt>Status</dt><dd>{$transfer.status_label}</dd>
                            <dt>Losing registrar</dt><dd>{if $transfer.losing_registrar}{$transfer.losing_registrar}{else}&mdash;{/if}</dd>
                            <dt>Gaining registrar</dt><dd>{if $transfer.gaining_registrar}{$transfer.gaining_registrar}{else}&mdash;{/if}</dd>
                            <dt>Authorisation code</dt><dd>{$transfer.auth_code_status}</dd>
                            <dt>Initiated</dt><dd>{if $transfer.initiated_at}{$transfer.initiated_at}{else}&mdash;{/if}</dd>
                            <dt>Completed</dt><dd>{if $transfer.completed_at}{$transfer.completed_at}{else}&mdash;{/if}</dd>
                        </dl>
                        {if $verification.outstanding > 0}
                            <p class="text-muted">
                                {$verification.outstanding} verification step(s) remain before this acquisition can be
                                marked complete. Your broker cannot close it early.
                            </p>
                        {/if}
                    </div>
                </section>
            {/if}

            <section class="db-panel" id="messages">
                <div class="db-panel-head"><h2>Messages with your broker</h2></div>
                <div class="db-panel-body">
                    {if $messages}
                        <ul class="db-messages">
                            {foreach $messages as $message}
                                <li class="db-message db-message-{$message.sender_type}">
                                    <div class="db-message-head">
                                        <strong>{$message.sender_label}</strong>
                                        <span class="text-muted">{$message.created_at}</span>
                                    </div>
                                    <div class="db-message-body">{$message.body|nl2br}</div>
                                </li>
                            {/foreach}
                        </ul>
                    {else}
                        <p class="text-muted">No messages yet.</p>
                    {/if}

                    {if $can.message}
                        <form method="post" action="index.php?m=domainbroker&amp;action=send-message" class="db-form">
                            {$csrf_field}
                            <input type="hidden" name="id" value="{$request.id}">
                            <div class="form-group">
                                <label class="sr-only" for="db-message-body">Message</label>
                                <textarea class="form-control" id="db-message-body" name="body" rows="3"
                                          placeholder="Write to your broker…" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Send message</button>
                        </form>
                    {/if}
                </div>
            </section>

            <section class="db-panel" id="documents">
                <div class="db-panel-head"><h2>Documents</h2></div>
                <div class="db-panel-body">
                    {if $documents}
                        <ul class="db-documents">
                            {foreach $documents as $document}
                                <li>
                                    <a href="index.php?m=domainbroker&amp;action=document&amp;id={$document.id}">
                                        <i class="fa fa-file-o"></i> {$document.name}
                                    </a>
                                    <small class="text-muted">{$document.category} &middot; {$document.created_at}</small>
                                </li>
                            {/foreach}
                        </ul>
                    {else}
                        <p class="text-muted">No documents have been shared with you yet.</p>
                    {/if}

                    {if $can.upload}
                        <form method="post" enctype="multipart/form-data" class="db-form"
                              action="index.php?m=domainbroker&amp;action=upload-document">
                            {$csrf_field}
                            <input type="hidden" name="id" value="{$request.id}">
                            <div class="form-group">
                                <label for="db-file">Upload a document</label>
                                <input type="file" id="db-file" name="document" required>
                                <p class="help-block">Up to {$max_upload_mb} MB. Documents are stored privately.</p>
                            </div>
                            <div class="form-group">
                                <label for="db-category">Category</label>
                                <select class="form-control" id="db-category" name="category">
                                    {foreach $document_types as $value => $label}
                                        <option value="{$value}">{$label}</option>
                                    {/foreach}
                                </select>
                            </div>
                            <button type="submit" class="btn btn-default">Upload</button>
                        </form>
                    {/if}
                </div>
            </section>

            <section class="db-panel" id="timeline">
                <div class="db-panel-head"><h2>Timeline</h2></div>
                <div class="db-panel-body">
                    {if $timeline}
                        <ul class="db-timeline">
                            {foreach $timeline as $entry}
                                <li>
                                    <span class="db-timeline-dot"></span>
                                    <div class="db-timeline-body">
                                        <strong>{$entry.description}</strong>
                                        <small class="text-muted">{$entry.created_at} &middot; {$entry.actor_label}</small>
                                        {if $entry.reason}<p class="db-timeline-reason">{$entry.reason}</p>{/if}
                                    </div>
                                </li>
                            {/foreach}
                        </ul>
                    {else}
                        <p class="text-muted">Nothing recorded yet.</p>
                    {/if}
                </div>
            </section>
        </div>

        <div class="col-md-4">
            {if $broker}
                <div class="db-card">
                    <h3>Your broker</h3>
                    <p class="db-broker-name">{$broker.display_name}</p>
                    {if $broker.specialities}<p class="text-muted">{$broker.specialities}</p>{/if}
                    {if $broker.biography}<p>{$broker.biography}</p>{/if}
                    <a href="#messages" class="btn btn-default btn-block">Message your broker</a>
                </div>
            {/if}

            {if $payment}
                <div class="db-card">
                    <h3>Payment</h3>
                    <dl class="db-dl db-dl-compact">
                        <dt>Reference</dt><dd>{$payment.reference}</dd>
                        <dt>Status</dt><dd>{$payment.status_label}</dd>
                        <dt>Total</dt><dd>{$payment.total.formatted}</dd>
                        <dt>Paid</dt><dd>{$payment.paid.formatted}</dd>
                        {if $payment.refunded.minor > 0}
                            <dt>Refunded</dt><dd>{$payment.refunded.formatted}</dd>
                        {/if}
                        {if $payment.due_at}<dt>Due</dt><dd>{$payment.due_at}</dd>{/if}
                    </dl>
                    {if $invoice_url}
                        <a href="{$invoice_url}" class="btn btn-default btn-block">Open invoice</a>
                    {/if}
                </div>
            {/if}

            {if $dispute}
                <div class="db-card db-card-warning">
                    <h3>Dispute {$dispute.reference}</h3>
                    <p>Status: {$dispute.status}</p>
                    <p class="text-muted">Our team is reviewing this acquisition. We will be in touch.</p>
                </div>
            {/if}

            <div class="db-card db-card-muted">
                <h3>Actions</h3>
                {if $can.cancel}
                    <button type="button" class="btn btn-default btn-block" data-db-toggle="db-cancel">
                        Cancel this request
                    </button>
                    <form method="post" action="index.php?m=domainbroker&amp;action=cancel" class="db-form db-hidden" id="db-cancel">
                        {$csrf_field}
                        <input type="hidden" name="id" value="{$request.id}">
                        <div class="form-group">
                            <label for="db-cancel-reason">Reason (optional)</label>
                            <input type="text" class="form-control" id="db-cancel-reason" name="reason">
                        </div>
                        <button type="submit" class="btn btn-warning btn-block"
                                onclick="return confirm('Cancel this acquisition request?');">
                            Confirm cancellation
                        </button>
                    </form>
                {/if}

                {if $can.dispute && !$dispute}
                    <button type="button" class="btn btn-link btn-block" data-db-toggle="db-dispute">
                        Something is wrong &mdash; raise a dispute
                    </button>
                    <form method="post" action="index.php?m=domainbroker&amp;action=open-dispute" class="db-form db-hidden" id="db-dispute">
                        {$csrf_field}
                        <input type="hidden" name="id" value="{$request.id}">
                        <div class="form-group">
                            <label for="db-dispute-reason">Reason</label>
                            <select class="form-control" id="db-dispute-reason" name="reason_code">
                                {foreach $dispute_reasons as $value => $label}
                                    <option value="{$value}">{$label}</option>
                                {/foreach}
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="db-dispute-description">What happened?</label>
                            <textarea class="form-control" id="db-dispute-description" name="description"
                                      rows="4" minlength="20" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-warning btn-block">Open dispute</button>
                    </form>
                {/if}

                <a href="{$urls.requests}" class="btn btn-link btn-block">Back to my acquisitions</a>
            </div>
        </div>
    </div>
</div>
