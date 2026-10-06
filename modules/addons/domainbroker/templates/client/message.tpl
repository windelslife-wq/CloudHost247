<div class="domainbroker-portal">
    <div class="db-empty">
        <i class="fa {$message_icon}"></i>
        <h3>{$message_title}</h3>
        <p>{$message_body}</p>
        <a href="{$urls.requests}" class="btn btn-primary">Back to my acquisitions</a>
        {if $support_email}
            <a href="mailto:{$support_email}" class="btn btn-link">Contact your broker team</a>
        {/if}
    </div>
</div>
