<?php
/**
 * CLOUDHOST247 — Public WHOIS lookup.
 *
 * Real registry lookups through the module's caching WHOIS provider. Public
 * results show exactly what the registries publish (GDPR redaction respected);
 * secret IP addresses are pseudonymised in storage, rate limits are honest.
 */

use Chs\Core\Csrf;
use Chs\Core\Money;
use Chs\Core\RateLimitException;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\ValidationException;
use Chs\Http\Landing;
use Chs\Services\WhoisService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-whois', 'WHOIS Lookup', 'whois-lookup.php');

Landing::seo([
    'title'       => 'WHOIS Lookup — public registry data, GDPR-respecting | CLOUDHOST247',
    'description' => 'Look up any registered domain live at the registry. We show everything the registry '
        . 'publishes — including honest privacy-redacted placeholders — with a cached, rate-limited service that respects the registries\' rules.',
    'canonical'   => 'whois-lookup.php',
    'og_title'    => 'WHOIS Lookup — CLOUDHOST247',
    'og_desc'     => 'Live registry WHOIS with caching, rate limits and GDPR redaction preserved.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => 'WHOIS Lookup', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-whois', Landing::unavailableVars('WHOIS lookup'));
    exit;
}

$errors = [];
$result = null;
$old = ['domain' => isset($_POST['chs_domain']) ? (string) $_POST['chs_domain'] : ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = isset($_POST['_chs_token']) ? (string) $_POST['_chs_token'] : '';
    if (!Csrf::verify($token)) {
        $errors['token'] = 'Your session expired — submit the form again.';
    } else {
        try {
            $svc = new WhoisService();
            $info = $svc->lookup($old['domain']);
            $result = [
                'domain'  => $info['domain'],
                'tld'     => $info['tld'],
                'server'  => $info['server'],
                'parsed'  => $info['parsed'],
                'raw'     => $info['raw'],
                'privacy_protected' => $info['privacy_protected'],
                'registered' => $info['parsed']['registrar'] !== null
                    || $info['parsed']['raw_available'],
            ];
        } catch (ValidationException $e) {
            $errors = $e->fieldErrors();
        } catch (RateLimitException $e) {
            $errors['limit'] = $e->getMessage();
        } catch (ServiceUnavailableException $e) {
            $errors['service'] = $e->getMessage();
        } catch (\Throwable $e) {
            $errors['service'] = 'The registry for that extension cannot be reached right now. Try again in a few minutes.';
        }
    }
}

$vars['chsErrors'] = $errors;
$vars['chsOld'] = $old;
$vars['chsResult'] = $result;
$vars['csrf_field'] = Csrf::field();
$vars['chsAvailabilityUrl'] = 'cart.php?a=add&domain=register&query='
    . ($result ? urlencode($result['domain']) : '');
$vars['chsAvailCheck'] = $result ? 'domain-search.php?q=' . urlencode($result['domain'] ?? '') : 'domain-search.php';

Landing::render($ca, 'chs-whois', $vars);
