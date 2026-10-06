{*
 * CLOUDHOST247 — Unified Inbox landing.
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Every conversation, one view</h1>
                    <p class="hero-subtitle">Unread badges that match what you actually read, searchable
                        threads, and replies that never leave your dashboard.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Unified Inbox</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section">
    <div class="container">
        {if isset($chsUnavailable) && $chsUnavailable}
            <div class="card chs-card-notice border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h4"><i class="fas fa-tools" aria-hidden="true"></i> Service warming up</h2>
                    <p class="mb-3">{$chsUnavailableWhy}</p>
                    <a href="{$chsSupportLink}" class="btn btn-primary">Contact the team</a>
                </div>
            </div>
        {else}
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h2 class="h4">Visible about what it connects to</h2>
                <p class="text-muted">This page is honest about channels — it lists each one with its live
                    status, instead of drawing fake chat icons that open dead windows:</p>
                {foreach $chsChannels as $c}
                    <div class="card mb-2 chs-channel-card">
                        <div class="card-body py-2 d-flex align-items-center">
                            <div class="flex-grow-1">
                                <strong>{$c.label|escape}</strong>
                                <div class="text-muted small">{$c.desc|escape}</div>
                            </div>
                            {if $c.live}
                                <span class="badge badge-success">Live</span>
                            {else}
                                <span class="badge badge-secondary">Offline</span>
                            {/if}
                        </div>
                    </div>
                {foreachelse}
                    <div class="alert alert-info">The inbox channel list is loading from the service layer.</div>
                {/foreach}
                <p class="text-muted small mt-2">New channels are added only after a provider is wired end-to-end;
                    until then they show as offline — never as a mocked thread list.</p>
            </div>
            <div class="col-lg-5 offset-lg-1 mt-4 mt-lg-0">
                <div class="card border-0 shadow-sm chs-card-primary">
                    <div class="card-body text-center">
                        <h3 class="h5 mb-2">Your conversations are waiting</h3>
                        <p class="text-muted">Sign in and the unread snapshot is computed against what you
                            last opened — a new staff reply flips a thread back to unread instantly.</p>
                        <a class="btn btn-primary btn-lg btn-block" href="{$chsInboxUrl}">{if $chsLoggedIn}Open my inbox{else}Sign in to my inbox{/if}</a>
                        <a class="btn btn-outline-secondary btn-block" href="{$chsDashboardUrl}">All my services</a>
                    </div>
                </div>
            </div>
        </div>
        {/if}
    </div>
</section>
