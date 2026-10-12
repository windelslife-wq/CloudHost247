<?php
/** Real adapter contracts (no licensed WHMCS needed): native preferences and product binding. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\I18n;
use CloudHost247\I18n\Preference\SessionPreferenceStore;
use CloudHost247\I18n\Whmcs\WhmcsClientPreferenceStore;
use CloudHost247\I18n\Whmcs\WhmcsLocale;
use CloudHost247\I18n\Whmcs\ProductPricePresenter;

section('native language values and malformed request shapes');
$i18n = ch247_make_i18n(array('language_value' => array(WhmcsLocale::class, 'toWhmcsName')));
foreach (array('french' => 'fr', 'spanish' => 'es', 'german' => 'de', 'portuguese-br' => 'pt-BR', 'english' => 'en') as $name => $code) {
    $i18n->applyRequest(array('language' => $name));
    T::is($name . ' selects the correct catalog', $code, $i18n->preferences()->getLanguage());
}
$i18n->applyRequest(array('language' => 'fr', 'currency' => 'EUR'));
T::ok('language[]= malformed request is rejected', !$i18n->applyRequest(array('language' => array('es')))['language']->isOk());
T::is('malformed language does not clear preference', 'fr', $i18n->preferences()->getLanguage());
T::ok('currency[]= malformed request is rejected', !$i18n->applyRequest(array('currency' => array(2)))['currency']->isOk());
T::is('malformed currency does not clear preference', 'EUR', $i18n->preferences()->getCurrency()->getCode());
$switcher = $i18n->switcher(array(), '/web-hosting.php?pid=42');
foreach ($switcher['language']['options'] as $option) {
    T::contains('native switch URL for ' . $option['code'], 'language=' . WhmcsLocale::toWhmcsName($option['code']), $option['url']);
    T::contains('real path retained', '/web-hosting.php?pid=42', $option['url']);
}
T::is('the grouping label is translated', 'Langue et devise', $switcher['labels']['switcher_group_label']);
T::ok('language-panel heading supplied to the template', !empty($switcher['labels']['switcher_choose_language']));
T::ok('currency-panel heading supplied to the template', !empty($switcher['labels']['switcher_choose_currency']));
T::ok('default badge supplied to the template', !empty($switcher['labels']['currency_default_badge']));

section('native WHMCS session feeds the real guest cart');
$_SESSION = array();
$session = new SessionPreferenceStore();
$session->set('language', 'pt-BR');
$session->set('currency', 3);
T::is('native Language is the installed filename', 'portuguese-br', $_SESSION['Language']);
T::is('guest cart session gets the selected currency id', 3, $_SESSION['currency']);
T::is('a second session-store instance sees the language', 'pt-BR', (new SessionPreferenceStore())->get('language'));
T::is('a second instance sees the currency', 3, (new SessionPreferenceStore())->get('currency'));
$_SESSION = array('Language' => 'german', 'currency' => 2);
T::is('native language is read without private state', 'de', (new SessionPreferenceStore())->get('language'));
T::is('native guest currency is read without private state', 2, (new SessionPreferenceStore())->get('currency'));
$_SESSION = array('uid' => 42, 'currency' => 1);
$session = new SessionPreferenceStore();
$session->set('currency', 4);
T::is('account browsing choice saved separately', 4, $_SESSION[SessionPreferenceStore::NAMESPACE]['currency']);
T::is('account native transaction session untouched', 1, $_SESSION['currency']);
$_SESSION['uid'] = 43;
T::is('another account does not inherit the private preference', null, (new SessionPreferenceStore())->get('currency'));
T::is('another account can read its own saved language instead of the guest language', null, (new SessionPreferenceStore())->get('language'));

section('account persistence cannot rewrite historical invoice currency');
$client = array('id' => 42, 'language' => 'french', 'currency' => 1);
$writes = array();
$account = new WhmcsClientPreferenceStore(function () { return 42; }, function () use (&$client) { return $client; },
    function ($id, $values) use (&$client, &$writes) { $writes[] = $values; $client = array_merge($client, $values); });
T::is('account language decoded from WHMCS name', 'fr', $account->get('language'));
T::is('initial display currency comes from billing', 1, $account->get('currency'));
$account->set('language', 'pt-BR');
$account->set('currency', 3);
T::is('language written in WHMCS format', 'portuguese-br', $client['language']);
T::is('billing currency unchanged', 1, $client['currency']);
T::is('browsing currency is a separate field', 3, $client['display_currency']);
T::is('subsequent account visit reads browsing preference', 3, $account->get('currency'));
T::ok('no persistence write ever targets billing currency', !array_key_exists('currency', $writes[0]) && !array_key_exists('currency', $writes[1]));
$_SESSION = array();

section('configured prices and conversion rates preserve the real charged amount');
$i18n = ch247_make_i18n();
$i18n->applyRequest(array('language' => 'fr', 'currency' => 'USD'));
$ngn = $i18n->currencies()->findByCode('NGN');
$usd = $i18n->currencies()->findByCode('USD');
$eur = $i18n->currencies()->findByCode('EUR');
$gbp = $i18n->currencies()->findByCode('GBP');
$jpy = $i18n->currencies()->findByCode('JPY');
$r = $i18n->prices()->resolve(array('NGN' => '18500.00', 'USD' => '12.00'), $usd, $ngn);
T::is('configured USD display amount', '12.00', $r->getDisplayAmount());
T::is('configured NGN charged amount, not USD digits with NGN code', '18500.00', $r->getTransactionAmount()->toMajorString());
T::is('charged money object is denominated correctly', 'NGN', $r->getTransactionAmount()->getCurrency());
T::ok('a foreign catalog price is reference-only for a locked account', $r->isIndicative());
$r = $i18n->prices()->resolve(array('NGN' => '18500.00'), $eur, $usd);
T::ok('missing locked currency price cannot fall back to another charge', !$r->isAvailable());
T::is('locked USD currency preserved even when unavailable', 'USD', $r->getTransactionCurrency());
T::ok('WHMCS -1 disabled-cycle sentinel is never displayed', !$i18n->prices()->resolve(array('USD' => '-1'), $usd)->isAvailable());
T::ok('infinite price is rejected', !$i18n->prices()->resolve(array('USD' => INF), $usd)->isAvailable());
T::is('two-argument rate is the actual cross-pair ratio', 0.00051 / 0.00065, $i18n->rates()->rate('USD', 'GBP'));
T::is('missing pair is null, never the base rate 1', null, $i18n->rates()->rate('NGN', 'JPY'));
$r = $i18n->prices()->resolve(array('USD' => '12.00'), $gbp, $usd);
T::is('converted result records the actual cross-pair rate', 0.00051 / 0.00065, $r->getRate());

section('real GetProducts binding is mounted on every product controller');
$product = array('paytype' => 'recurring', 'pricing' => array(
    'NGN' => array('monthly' => '18500.00', 'quarterly' => '-1', 'annually' => '180000.00'),
    'USD' => array('monthly' => '12.00', 'quarterly' => '-1', 'annually' => '120.00'),
));
$presenter = new ProductPricePresenter($i18n);
$out = $presenter->present($product, $ngn);
T::is('display is the configured USD price', '12.00', $out['raw']['monthly']);
T::contains('amount formatted for French', '$ 12,00', $out['formatted']['monthly']);
T::contains('charged amount shown next to reference', '18 500,00', $out['formatted']['monthly']);
T::contains('charged code shown next to reference', 'NGN', $out['formatted']['monthly']);
T::is('disabled billing cycle remains disabled', -1, $out['raw']['quarterly']);
T::is('order link uses real account currency', 1, $out['charge_ids']['monthly']);
T::is('symbols are not appended twice', '', $out['formatted']['prefix']);
$i18n->applyRequest(array('currency' => 'JPY'));
$out = $presenter->present($product, $ngn);
T::is('missing rate disables price, does not manufacture zero', -1, $out['raw']['monthly']);
T::is('missing rate leaves no formatted foreign price', '', $out['formatted']['monthly']);
T::is('missing rate leaves no guessed order-currency id', null, $out['charge_ids']['monthly']);
$i18n->applyRequest(array('currency' => 'USD'));
$out = $presenter->present(array('paytype' => 'free', 'pricing' => array()), $ngn);
T::is('free product stays free', 0, $out['raw']['free']);
T::contains('free amount is genuinely zero', '0,00', $out['formatted']['free']);
foreach (array('cpanel-hosting', 'plesk-hosting', 'ssl-certificate', 'vps-hosting', 'vps-privatecloud', 'web-hosting', 'website-design', 'windows-hosting', 'wordpress-hosting', 'tables', 'hostx-vps-sample') as $page) {
    $source = file_get_contents(CH247_REPO_ROOT . '/' . $page . '.php');
    T::contains($page . ' binds through the shared pricing presenter', 'wgs_prepare_product_prices($pData)', $source);
    T::notContains($page . ' no longer blindly indexes a missing currency row', "$" . "pData['pricing'][$" . "currenciCode]", $source);
}

section('real landing helper delegates to the same presenter');
if (!defined('WHMCS')) define('WHMCS', true);
require_once CH247_REPO_ROOT . '/includes/ch247_content.php';
require_once CH247_REPO_ROOT . '/includes/hostx_page_functions.php';
$out = wgs_prepare_product_prices($product, $ngn);
T::is('helper returns the same configured amount', '12.00', $out['raw']['monthly']);
T::is('helper preserves actual charge currency', 1, $out['charge_ids']['monthly']);
T::is('title helper resolves the real page title', 'Hébergement web', ch247_page_title('web-hosting', 'Web Hosting'));
T::is('invalid page locale cannot traverse catalogs', array('title' => 'English'), ch247_content('legal', array('title' => 'English'), '../..'));
T::ok('ISO dates produce locale-aware French month names', strpos(ch247_date('2025-08-14'), 'août') !== false);
section('admin coverage includes plural keys without PHP type errors');
require_once CH247_REPO_ROOT . '/modules/addons/cloudhost247_i18n/cloudhost247_i18n.php';
$coverage = cloudhost247_i18n_completeness($i18n);
T::is('admin includes every reference key', count($i18n->catalogs()->messages('en')), $coverage['fr']['total']);
T::is('admin coverage has no missing keys', array(), $coverage['fr']['missing']);
T::is('admin coverage has no invalid/empty plural values', array(), $coverage['fr']['empty']);
T::is('admin English language name is readable', 'French', $coverage['fr']['name']);
T::summary();
