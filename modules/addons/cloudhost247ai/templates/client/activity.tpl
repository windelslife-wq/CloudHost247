<div class="ch247ai-activity">

  <div class="alert alert-info small">
    This is the full record of what the AI assistant has done on your account: every
    question asked, every answer given, and which of your records it read to answer.
    Nothing the assistant does on your account is hidden from this page.
  </div>

  <p><a class="btn btn-default btn-sm" href="{$modulelink|escape}&amp;action=assistant">&larr; Back to the assistant</a></p>

  {if !$has_runs}
    <div class="panel panel-default"><div class="panel-body text-muted">
      The assistant has not been used on your account yet.
    </div></div>
  {else}
    {foreach from=$runs item=run}
      <div class="panel panel-default">
        <div class="panel-heading">
          <strong>#{$run.id|escape}</strong>
          <span class="text-muted small">{$run.started_at|escape} UTC · status {$run.status|escape}</span>
        </div>
        <div class="panel-body">
          <p><strong>You asked:</strong> {$run.question|escape}</p>
          {if $run.answer}
            <p><strong>Answer:</strong> <span style="white-space:pre-wrap">{$run.answer|escape}</span></p>
          {/if}
          {if $run.tools}
            <p class="small"><strong>Records read:</strong></p>
            <ul class="small text-muted">
              {foreach from=$run.tools item=tool}
                <li><code>{$tool.tool|escape}</code> — {$tool.status|escape} at {$tool.at|escape} UTC</li>
              {/foreach}
            </ul>
          {else}
            <p class="small text-muted">No account records were read for this question.</p>
          {/if}
        </div>
      </div>
    {/foreach}
  {/if}

</div>
