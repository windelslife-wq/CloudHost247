{*
 * CloudHost247 Isc — Domain Broker Service landing page
 * Uses the HostX hero/section conventions so the page sits inside the existing
 * site chrome (header, mega menu, footer) rather than looking like a bolt-on.
 *}

<section class="hero-banner domainbroker-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>{$dbHeadline}</h1>
                    <p class="hero-subtitle">{$dbSubheadline}</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Domain Broker Service</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="domainbroker-start-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card border-0 shadow-sm domainbroker-start-card">
                    <div class="card-body">
                        {if $dbEnabled}
                            <h2 class="h4 text-center mb-3">Which domain do you want?</h2>
                            <form method="get" action="index.php" class="domainbroker-start-form">
                                <input type="hidden" name="m" value="domainbroker">
                                <input type="hidden" name="action" value="new">
                                <div class="input-group input-group-lg">
                                    <label class="sr-only" for="db-landing-domain">Domain name</label>
                                    <input type="text" class="form-control" id="db-landing-domain" name="domain"
                                           value="{$dbPrefillDomain|escape:'html'}" placeholder="example.com"
                                           autocomplete="off" spellcheck="false" required>
                                    <span class="input-group-btn">
                                        <button class="btn btn-primary btn-lg" type="submit">
                                            Start a request
                                        </button>
                                    </span>
                                </div>
                                <p class="help-block text-center mt-3">
                                    {$dbFeeNote}
                                </p>
                            </form>
                        {else}
                            <h2 class="h4 text-center mb-3">Domain Broker Service</h2>
                            <p class="text-center text-muted mb-0">
                                The brokerage desk is not accepting new requests at the moment.
                                <a href="contact.php">Contact our team</a> and we will let you know as soon as it reopens.
                            </p>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="domainbroker-steps-section">
    <div class="container">
        <div class="row">
            <div class="col-12 text-center">
                <h2 class="section-title">How a brokered acquisition works</h2>
                <p class="section-subtitle text-muted">
                    Five stages, fully tracked in your client area from the first approach to the final transfer.
                </p>
            </div>
        </div>
        <div class="row domainbroker-steps">
            {foreach from=$dbSteps item=step name=stepLoop}
                <div class="col-md-6 col-lg-4">
                    <div class="domainbroker-step">
                        <div class="domainbroker-step-number">{$smarty.foreach.stepLoop.iteration}</div>
                        <div class="domainbroker-step-icon"><i class="fa {$step.icon}"></i></div>
                        <h3 class="domainbroker-step-title">{$step.title}</h3>
                        <p class="domainbroker-step-body">{$step.body}</p>
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
</section>

<section class="domainbroker-assurance-section">
    <div class="container">
        <div class="row">
            {foreach from=$dbAssurances item=item}
                <div class="col-sm-6 col-lg-3">
                    <div class="domainbroker-assurance">
                        <i class="fa {$item.icon}"></i>
                        <h4>{$item.title}</h4>
                        <p>{$item.body}</p>
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
</section>

<section class="domainbroker-faq-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <h2 class="section-title text-center">Common questions</h2>

                <div class="faq-accordion" id="domainBrokerFaq">
                    {foreach from=$dbFaqs item=faq}
                        <div class="faq-item" data-faq-id="{$faq.id}">
                            <button class="faq-question" type="button" aria-expanded="false" aria-controls="{$faq.id}">
                                <span class="faq-question-text">{$faq.question}</span>
                                <span class="faq-icon">
                                    <span class="icon-plus">+</span>
                                    <span class="icon-minus">&minus;</span>
                                </span>
                            </button>
                            <div class="faq-answer" id="{$faq.id}">
                                <div class="faq-answer-content">{$faq.answer}</div>
                            </div>
                        </div>
                    {/foreach}
                </div>

                <div class="faq-contact-cta text-center mt-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-4">
                            <h5 class="card-title">Ready to go after it?</h5>
                            <p class="card-text text-muted">
                                Start a request and a broker will be in touch with next steps.
                            </p>
                            {if $dbEnabled}
                                <a href="{$dbRequestUrl}" class="btn btn-primary">Start a request</a>
                                <a href="{$dbPortalUrl}" class="btn btn-default">My acquisitions</a>
                            {else}
                                <a href="contact.php" class="btn btn-primary">Contact support</a>
                            {/if}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    .domainbroker-start-section { padding: 0 0 40px; }
    .domainbroker-start-card { margin-top: -40px; }
    .domainbroker-start-card .card-body { padding: 32px; }

    .domainbroker-steps-section,
    .domainbroker-faq-section { padding: 60px 0; }
    .domainbroker-assurance-section { padding: 50px 0; background: #f6f8fb; }

    .section-title { font-weight: 600; margin-bottom: 10px; }
    .section-subtitle { margin-bottom: 40px; }

    .domainbroker-step {
        position: relative;
        height: 100%;
        padding: 28px 24px;
        margin-bottom: 26px;
        background: #fff;
        border: 1px solid #e3e8ef;
        border-radius: 10px;
    }

    .domainbroker-step-number {
        position: absolute;
        top: 18px;
        right: 20px;
        font-size: 34px;
        font-weight: 700;
        color: #eef2f8;
        line-height: 1;
    }

    .domainbroker-step-icon .fa {
        font-size: 28px;
        color: #1a63d8;
        margin-bottom: 14px;
    }

    .domainbroker-step-title { font-size: 17px; font-weight: 600; margin: 0 0 8px; }
    .domainbroker-step-body { color: #6b7686; margin: 0; }

    .domainbroker-assurance { text-align: center; padding: 16px 12px; }
    .domainbroker-assurance .fa { font-size: 26px; color: #1a63d8; margin-bottom: 12px; }
    .domainbroker-assurance h4 { font-size: 16px; font-weight: 600; }
    .domainbroker-assurance p { color: #6b7686; margin: 0; }

    @media (max-width: 767px) {
        .domainbroker-start-card { margin-top: 0; }
        .domainbroker-start-card .card-body { padding: 20px; }
        .domainbroker-steps-section,
        .domainbroker-faq-section { padding: 40px 0; }
        .domainbroker-start-form .input-group,
        .domainbroker-start-form .input-group .form-control,
        .domainbroker-start-form .input-group-btn,
        .domainbroker-start-form .input-group-btn .btn { display: block; width: 100%; }
        .domainbroker-start-form .input-group .form-control { margin-bottom: 10px; border-radius: 4px; }
    }
</style>
