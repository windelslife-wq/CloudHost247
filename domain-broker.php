<?php
/**
 * CloudHost247 Isc - Domain Broker Service landing page
 *
 * Public marketing page for the brokered domain acquisition service. The page
 * itself holds no business logic: pricing language, availability checks and
 * every workflow decision come from the Domain Broker addon module, so this
 * file cannot drift out of step with what the service actually does.
 *
 * @package    WHMCS
 * @author     CloudHost247 Isc
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    https://www.cloudhost247.com/license
 */

use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';

$brokerModule = __DIR__ . '/modules/addons/domainbroker/autoload.php';
$brokerAvailable = file_exists($brokerModule);
if ($brokerAvailable) {
    require_once $brokerModule;
}

$ca = new ClientArea();

$ca->setPageTitle('Domain Broker Service');
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('domain-broker.php', 'Domain Broker Service');
$ca->initPage();

/* ------------------------------------------------------------------ copy -- */

$headline = 'Acquire the domain you actually want';
$subheadline = 'The perfect domain is usually already taken. Our brokers approach the current owner, '
    . 'negotiate on your behalf and manage payment and transfer from start to finish.';
$feeNote = 'Our brokerage fee is agreed in writing before you are asked to pay anything.';
$serviceEnabled = true;
$landingEnabled = true;
// Only a plain hostname may pre-fill the form. Anything else (quotes, angle
// brackets, whitespace) is dropped rather than echoed back into the page.
$prefillDomain = '';
if (isset($_GET['domain']) && is_string($_GET['domain'])
    && preg_match('/^[A-Za-z0-9-]{1,63}(\.[A-Za-z0-9-]{1,63})*\.[A-Za-z]{2,63}$/D', $_GET['domain'])) {
    $prefillDomain = $_GET['domain'];
}

if ($brokerAvailable) {
    try {
        $headline = \DomainBroker\Core\Settings::string('landing_headline', $headline);
        $subheadline = \DomainBroker\Core\Settings::string('landing_subheadline', $subheadline);
        $serviceEnabled = \DomainBroker\Core\Settings::bool('service_enabled', true);
        $landingEnabled = \DomainBroker\Core\Settings::bool('public_landing_enabled', true);

        // The headline fee figure is read from the live fee rules, never
        // hard-coded here, so marketing cannot contradict billing.
        $currency = \DomainBroker\Core\Settings::string('default_currency', 'USD');
        $rule = (new \DomainBroker\Services\FeeService())->resolveRule(1000000, $currency);
        if ($rule && $rule['calculation'] === 'percentage') {
            $feeNote = 'Our standard brokerage fee is '
                . \DomainBroker\Http\CustomerPortal::trimPercentage($rule['percentage'])
                . '% of the agreed acquisition price, confirmed in writing before any payment is requested.';
        }
    } catch (\Throwable $e) {
        // The landing page must still render if the module is mid-install.
    }
}

$steps = [
    [
        'icon' => 'fa-search',
        'title' => 'Tell us the domain',
        'body' => 'Give us the exact domain you want and the most you are prepared to pay. '
            . 'We never exceed your ceiling.',
    ],
    [
        'icon' => 'fa-user-secret',
        'title' => 'We approach the owner',
        'body' => 'A dedicated broker traces and contacts the registrant — anonymously, if you prefer, '
            . 'so the price is never inflated by who is asking.',
    ],
    [
        'icon' => 'fa-comments-o',
        'title' => 'You see every offer',
        'body' => 'Each offer and counteroffer is recorded in your client area with the full history. '
            . 'You accept, decline or counter.',
    ],
    [
        'icon' => 'fa-lock',
        'title' => 'Funds held securely',
        'body' => 'You pay only once you have accepted. Funds are held and released to the seller only '
            . 'after the transfer completes and is verified.',
    ],
    [
        'icon' => 'fa-exchange',
        'title' => 'We manage the transfer',
        'body' => 'Authorisation codes, registrar locks and the transfer itself are tracked through to '
            . 'completion — then the domain is yours.',
    ],
];

$assurances = [
    ['icon' => 'fa-shield', 'title' => 'Nothing to pay up front',
     'body' => 'No fee is charged unless we agree terms you have approved.'],
    ['icon' => 'fa-eye-slash', 'title' => 'Anonymous by default',
     'body' => 'Your identity is never disclosed to the owner unless you ask us to.'],
    ['icon' => 'fa-balance-scale', 'title' => 'Full written record',
     'body' => 'Every negotiation round, payment and transfer step is logged and visible to you.'],
    ['icon' => 'fa-globe', 'title' => 'Worldwide coverage',
     'body' => 'We broker across the extensions we are able to transfer and verify.'],
];

$faqs = [
    [
        'id' => 'db-faq-1',
        'question' => 'What does the Domain Broker Service do?',
        'answer' => 'When the domain you want is already registered, we act for you: we identify and approach '
            . 'the owner, negotiate a price inside your budget, hold your funds securely and manage the '
            . 'transfer into your account.',
    ],
    [
        'id' => 'db-faq-2',
        'question' => 'How much does it cost?',
        'answer' => $feeNote . ' You pay nothing unless an acquisition you have approved goes ahead.',
    ],
    [
        'id' => 'db-faq-3',
        'question' => 'Will the owner know it is me?',
        'answer' => 'Not unless you choose to be identified. Anonymous negotiation is the default: your broker '
            . 'acts as the intermediary throughout.',
    ],
    [
        'id' => 'db-faq-4',
        'question' => 'What if the owner refuses to sell?',
        'answer' => 'Some owners decline or never reply. If no agreement is reached the request is closed as '
            . 'unsuccessful and you are charged nothing.',
    ],
    [
        'id' => 'db-faq-5',
        'question' => 'How long does it take?',
        'answer' => 'Most negotiations conclude within two to six weeks. The transfer itself usually adds five '
            . 'to seven days, depending on the registry and the current registrar.',
    ],
    [
        'id' => 'db-faq-6',
        'question' => 'Is my money safe?',
        'answer' => 'Yes. Payment and transfer are tracked as separate stages: funds are held after you pay and '
            . 'are only released to the seller once the transfer has completed and been verified.',
    ],
];

$ca->assign('dbHeadline', $headline);
$ca->assign('dbSubheadline', $subheadline);
$ca->assign('dbFeeNote', $feeNote);
$ca->assign('dbSteps', $steps);
$ca->assign('dbAssurances', $assurances);
$ca->assign('dbFaqs', $faqs);
$ca->assign('dbPrefillDomain', $prefillDomain);
$ca->assign('dbEnabled', $serviceEnabled && $landingEnabled && $brokerAvailable);
$ca->assign('dbRequestUrl', 'index.php?m=domainbroker&action=new');
$ca->assign('dbPortalUrl', 'index.php?m=domainbroker');
$ca->assign('sidebarHostxRemove', 'true');

$ca->setTemplate('domainbroker-landing');
$ca->output();
