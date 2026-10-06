<div class="domainbroker-portal">
    <div class="db-empty">
        <i class="fa fa-lock"></i>
        {if $is_staff}
            <p>You are signed in as staff. The broker desk and the administrative console live in the admin area.</p>
            <a href="{$staff_url}" class="btn btn-primary">Open the Domain Broker admin area</a>
        {else}
            <p>Please sign in to your account to manage domain acquisitions.</p>
            <a href="clientarea.php" class="btn btn-primary">Sign in</a>
            <a href="{$landing_url}" class="btn btn-link">Learn about the service</a>
        {/if}
    </div>
</div>
