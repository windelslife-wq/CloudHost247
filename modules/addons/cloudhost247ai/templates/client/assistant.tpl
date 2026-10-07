<div class="ch247ai-assistant">

  <div class="alert alert-info small">
    <strong>What this can do:</strong> look things up in your own account — invoices,
    payments, services, domains, orders and your support tickets.
    <strong>What it cannot do:</strong> change anything. It cannot pay an invoice, cancel a
    service, edit your details or see any other customer's account.
    Answers are built only from your account records, and the records used are listed
    underneath each answer.
  </div>

  {if $error}
    <div class="alert alert-warning">{$error|escape}</div>
  {/if}

  <form method="post" action="{$modulelink|escape}&amp;action=assistant">
    <input type="hidden" name="ch247ai_csrf" value="{$csrf_token|escape}">
    <div class="form-group">
      <label for="ch247ai_question">Ask about your account</label>
      <textarea class="form-control" id="ch247ai_question" name="ch247ai_question"
                rows="3" maxlength="{$max_question|escape}"
                placeholder="For example: which of my invoices are unpaid?">{$question|escape}</textarea>
    </div>
    <button type="submit" class="btn btn-primary">Ask</button>
    <span class="small text-muted" style="margin-left:10px">
      Up to {$rate_max|escape} questions every {$rate_minutes|escape} minutes.
    </span>
  </form>

  {if $answer}
    <div class="panel panel-default" style="margin-top:18px">
      <div class="panel-heading"><strong>Answer</strong></div>
      <div class="panel-body">
        <p style="white-space:pre-wrap">{$answer|escape}</p>

        {if $citations}
          <hr>
          <p class="small"><strong>Based on these records from your account:</strong></p>
          <ul class="small text-muted">
            {foreach from=$citations item=citation}
              <li><code>{$citation|escape}</code></li>
            {/foreach}
          </ul>
        {else}
          <hr>
          <p class="small text-muted">No account records were read for this answer.</p>
        {/if}

        {if $run_id}
          <p class="small text-muted">Reference #{$run_id|escape} — this question and answer are
             recorded, and you can review them on the
             <a href="{$modulelink|escape}&amp;action=activity">AI activity</a> page.</p>
        {/if}
      </div>
    </div>
  {/if}

  {if $recent}
    <h4 style="margin-top:24px">Your recent questions</h4>
    <ul class="list-group">
      {foreach from=$recent item=run}
        <li class="list-group-item small">
          <span class="text-muted">{$run.started_at|escape} UTC</span> —
          {$run.question|escape}
        </li>
      {/foreach}
    </ul>
    <p><a href="{$modulelink|escape}&amp;action=activity">See everything the assistant has done on your account</a></p>
  {/if}

</div>
