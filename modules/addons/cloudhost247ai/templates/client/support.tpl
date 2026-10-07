<div class="ch247ai-support">

  <div class="alert alert-info small">
    <strong>AI Support Operator:</strong> answers from the CloudHost247 knowledge base
    and the live product catalog — never guesses. It cannot see or change account
    records. Anything needing a human goes to CloudHost247 Support with this
    whole conversation attached.
  </div>

  {if $error}
    <div class="alert alert-warning">{$error|escape}</div>
  {/if}

  {if !$conversation}
    <div class="panel panel-default">
      <div class="panel-heading"><strong>Start a conversation</strong></div>
      <div class="panel-body">
        <form method="post" action="{$modulelink|escape}&amp;action=support">
          <input type="hidden" name="ch247ai_csrf" value="{$csrf_token|escape}">
          <input type="hidden" name="ch247ai_support" value="start">
          {if !$client_name}
            <div class="form-group">
              <label for="ch247ai_name">Your name</label>
              <input class="form-control" id="ch247ai_name" name="name" maxlength="120"
                     value="{$guest_name|escape}" placeholder="Jane Appleseed">
            </div>
            <div class="form-group">
              <label for="ch247ai_email">Email (so Support can reach you if a human is needed)</label>
              <input class="form-control" id="ch247ai_email" name="email" type="email" maxlength="190"
                     value="{$guest_email|escape}" placeholder="jane@example.com">
            </div>
          {else}
            <p class="text-muted">Chatting as <strong>{$client_name|escape}</strong>.</p>
          {/if}
          <div class="form-group">
            <label for="ch247ai_first">How can we help?</label>
            <textarea class="form-control" id="ch247ai_first" name="message" rows="3"
                      maxlength="{$max_message|escape}"
                      placeholder="For example: how much is Cloud Pro?">{$message|escape}</textarea>
          </div>
          <button type="submit" class="btn btn-primary">Start chatting</button>
        </form>
      </div>
    </div>
  {else}
    {if $conversation.status == 'waiting_for_human' || $conversation.status == 'human_active'}
      <div class="alert alert-success">
        <strong>With CloudHost247 Support</strong>
        {if $conversation.ticket_id} — ticket #{$conversation.ticket_id|escape}{/if}.
        An agent will reply here; anything you write is added to the thread.
      </div>
    {elseif $conversation.status == 'resolved' || $conversation.status == 'closed'}
      <div class="alert alert-default">
        This conversation is {$conversation.status|escape}. Write below to reopen it.
      </div>
    {/if}

    <div class="ch247ai-chat" style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;background:#fff;margin-bottom:14px">
      {foreach $messages as $m}
        {if $m.author == 'customer'}
          <div class="ch247ai-msg user" style="margin-bottom:10px;padding:10px 12px;border-radius:8px;background:#eef2ff;border-left:3px solid #6366f1;white-space:pre-wrap;word-break:break-word">
            <strong>You</strong> <span class="text-muted small">{$m.created_at|escape}</span><br>
            {$m.body|escape}
          </div>
        {elseif $m.author == 'ai'}
          <div class="ch247ai-msg assistant" style="margin-bottom:10px;padding:10px 12px;border-radius:8px;background:#f0fdf4;border-left:3px solid #16a34a;white-space:pre-wrap;word-break:break-word">
            <strong>AI Operator</strong> <span class="text-muted small">{$m.created_at|escape}</span><br>
            {$m.body|escape}
            {if $m.citations}
              <br><span class="small text-muted">Sources: {foreach $m.citations as $c}{$c|escape} {/foreach}</span>
            {/if}
          </div>
        {elseif $m.author == 'agent'}
          <div class="ch247ai-msg agent" style="margin-bottom:10px;padding:10px 12px;border-radius:8px;background:#fef9c3;border-left:3px solid #ca8a04;white-space:pre-wrap;word-break:break-word">
            <strong>Support agent</strong> <span class="text-muted small">{$m.created_at|escape}</span><br>
            {$m.body|escape}
          </div>
        {else}
          <div class="ch247ai-msg system text-muted small" style="margin-bottom:10px">
            {$m.body|escape}
          </div>
        {/if}
      {/foreach}
    </div>

    <form method="post" action="{$modulelink|escape}&amp;action=support&amp;c={$conversation.public_id|escape}">
      <input type="hidden" name="ch247ai_csrf" value="{$csrf_token|escape}">
      <input type="hidden" name="ch247ai_support" value="message">
      <input type="hidden" name="c" value="{$conversation.public_id|escape}">
      <div class="form-group">
        <label for="ch247ai_message">Your message</label>
        <textarea class="form-control" id="ch247ai_message" name="message" rows="3"
                  maxlength="{$max_message|escape}" placeholder="Type your message…"></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Send</button>
      <span class="small text-muted" style="margin-left:10px">
        Up to {$rate_max|escape} messages every {$rate_minutes|escape} minutes.
      </span>
    </form>

    <form method="post" action="{$modulelink|escape}&amp;action=support&amp;c={$conversation.public_id|escape}" style="margin-top:10px">
      <input type="hidden" name="ch247ai_csrf" value="{$csrf_token|escape}">
      <input type="hidden" name="ch247ai_support" value="human">
      <input type="hidden" name="c" value="{$conversation.public_id|escape}">
      <button type="submit" class="btn btn-default btn-sm">Talk to a human instead</button>
    </form>
  {/if}

  <p class="small text-muted" style="margin-top:14px">
    Availability: {$availability|escape}. Conversations are logged for support quality.
  </p>
</div>
