<?php
/**
 * CLOUDHOST247 — Domain Valuation landing page.
 *
 * Public estimate form wired straight into the module's rules engine. Every
 * appraisal is computed live, stored, rate-limited per client/IP and rendered
 * with the mandatory "not a guaranteed selling price" disclaimer — the same
 * result the logged-in portal would show for the same domain.
 *
 * @package    WHMCS
 * @author     CloudHost247
 */

use Chs\Core\Csrf;
use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Http\Landing;
use Chs\Services\ValuationService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-valuation', 'Domain Valuation', 'domain-valuation.php');

Landing::seo([
    'title'       => 'Free Domain Valuation — CLOUDHOST247',
    'description' => 'Run a free, rules-based domain appraisal on any name: length, brandability, '
        . 'commercial keywords and extension weight, with a clear non-guarantee disclaimer.',
    'canonical'   => 'domain-valuation.php',
    'og_title'    => 'Free Domain Valuation — CLOUDHOST247',
    'og_desc'     => 'A transparent, factor-by-factor domain appraisal. No fake numbers: the method '
        . 'breakdown ships with every estimate.',
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'WebPage',
        'name'     => 'Domain Valuation',
        'description' => 'Free rules-based domain appraisal with factor breakdown.',
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [
    'chsHeroTitle' => 'What is your domain worth?',
    'chsHeroSub'   => 'A transparent, factor-by-factor appraisal — length, hyphens, keywords, brandability and extension strength. Every figure comes with its method, and with an honest disclaimer.',
];

$errors = [];
$result = null;
$old = ['domain' => isset($_POST['chs_domain']) ? (string) $_POST['chs_domain'] : ''];

if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-valuation', array_merge($vars, Landing::unavailableVars('Domain valuation')));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = isset($_POST['_chs_token']) ? (string) $_POST['_chs_token'] : '';
    if (!Csrf::verify($token)) {
        $errors['token'] = 'Your session expired — please submit the form again.';
    } else {
        try {
            $clientId = Identity::clientId();
            $service = new ValuationService();
            $row = $service->estimate($old['domain'], $clientId);
            $result = [
                'domain'      => $row['domain'],
                'estimate'    => Money::format((int) $row['estimate_minor'], $row['currency']),
                'estimate_raw' => (int) $row['estimate_minor'],
                'currency'    => $row['currency'],
                'score'       => (int) $row['score'],
                'confidence'  => $row['confidence'],
                'summary'     => $row['summary'],
                'breakdown'   => $row['breakdown'],
                'engine'      => $row['engine'],
                'disclaimer'  => $row['disclaimer'],
            ];
        } catch (\Chs\Core\ValidationException $e) {
            $errors = $e->fieldErrors();
        } catch (\Chs\Core\RateLimitException $e) {
            $errors['limit'] = $e->getMessage();
        } catch (\Chs\Core\ServiceUnavailableException $e) {
            $errors['service'] = $e->getMessage();
        } catch (\Throwable $e) {
            $errors['service'] = 'Valuation is temporarily unavailable. Please try again later or ask support.';
        }
    }
}

$vars['chsErrors']  = $errors;
$vars['chsOld']     = $old;
$vars['chsResult']  = $result;
$vars['chsFactors'] = $result ? $result['breakdown'] : [];
$vars['csrf_field'] = Csrf::field();
$vars['chsSellUrl'] = 'index.php?m=cloudhost247services&action=sell'
    . ($result ? '&domain=' . urlencode($result['domain']) : '');
Landing::seo(['og_title' => $result
    ? 'Appraisal for ' . $result['domain'] . ' — CLOUDHOST247'
    : 'Free Domain Valuation — CLOUDHOST247']);

Landing::render($ca, 'chs-valuation', $vars);
