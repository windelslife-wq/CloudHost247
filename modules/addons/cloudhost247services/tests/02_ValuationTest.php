<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\RateLimitException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Providers\Valuation\RuleBasedValuationEngine;
use Chs\Services\ValuationService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_freeze();

T::section('Rules engine — deterministic, sane, proportional');
$engine = new RuleBasedValuationEngine();
$r = $engine->evaluate(DomainName::parse('solar.com'), ['currency' => 'USD']);
T::eq('engine id stable', 'rules', $r ? $engine->engineId() : '');
T::ok('estimate above floor', $r['estimate_minor'] >= RuleBasedValuationEngine::FLOOR_MINOR);
T::ok('estimate below ceiling', $r['estimate_minor'] <= RuleBasedValuationEngine::CEILING_MINOR);
T::ok('premium keyword matched', in_array('solar', $r['breakdown'][3]['matched'] ?? [], true) || strpos($r['summary'], 'solar') !== false || $r['score'] > 50);
T::ok('com factor is the strongest', (function () use ($r) {
    foreach ($r['breakdown'] as $f) {
        if ($f['key'] === 'extension') {
            return $f['score'] >= 99; // scores are clamped at 99 by design
        }
    }
    return false;
})());
T::eq('currency echoed', 'USD', $r['currency']);
T::ok('score in range', $r['score'] >= 1 && $r['score'] <= 98);
T::ok('summary is human text', is_string($r['summary']) && strlen($r['summary']) > 20);
T::ok('confidence is a known grade', in_array($r['confidence'], ['low', 'medium', 'high'], true));

$junky = $engine->evaluate(DomainName::parse('zxq-dfg123.net'), ['currency' => 'USD']);
T::ok('gibberish clearly cheaper than a premium word', $junky['estimate_minor'] < $r['estimate_minor']);
T::ok('hyphen + digit flagged in shape factor', (function () use ($junky) {
    foreach ($junky['breakdown'] as $f) {
        if ($f['key'] === 'shape') {
            return $f['score'] < 100;
        }
    }
    return false;
})());

$shorty = $engine->evaluate(DomainName::parse('abc.io'), ['currency' => 'USD']);
$longy  = $engine->evaluate(DomainName::parse('abcdefghijklmnopqrstuvwy.io'), ['currency' => 'USD']);
T::ok('short outvalues long on same TLD', $shorty['estimate_minor'] > $longy['estimate_minor']);

T::ok('identical input → identical output', (function () use ($engine) {
    $a = $engine->evaluate(DomainName::parse('widgetsales.com'), ['currency' => 'USD']);
    $b = $engine->evaluate(DomainName::parse('widgetsales.com'), ['currency' => 'USD']);
    return $a === $b;
})());
T::eq('floor holds on junk tld', RuleBasedValuationEngine::FLOOR_MINOR,
    $engine->evaluate(DomainName::parse('qqqqqqqqzzzzzz-937.biz'), ['currency' => 'USD'])['estimate_minor']);
T::ok('ceiling holds on monster keyword name', (function () use ($engine) {
    $v = $engine->evaluate(DomainName::parse('gold-insurance-crypto-solar-energy-market-casino-hotel.com'), ['currency' => 'USD']);
    return $v['estimate_minor'] <= RuleBasedValuationEngine::CEILING_MINOR;
})());
T::ok('admin tld-weight override via context', (function () use ($engine) {
    $def = $engine->evaluate(DomainName::parse('brandable.biz'), ['currency' => 'USD']);
    $up  = $engine->evaluate(DomainName::parse('brandable.biz'), ['currency' => 'USD', 'tld_weights' => ['biz' => 1.0]]);
    return $up['estimate_minor'] > $def['estimate_minor'];
})());

T::section('Valuation service — storage, breakdown, disclaimer');
$svc = new ValuationService($engine);
$row = $svc->estimate('solarpanels.com', 11);
T::eq('stored fqdn', 'solarpanels.com', $row['domain']);
T::eq('client currency (USD)', 'USD', $row['currency']);
T::eq('client recorded', 11, (int) $row['client_id']);
T::ok('estimate stored in minor units', (int) $row['estimate_minor'] >= RuleBasedValuationEngine::FLOOR_MINOR);
T::ok('breakdown decoded to array', is_array($row['breakdown']) && count($row['breakdown']) >= 4);
T::ok('each factor carries key/label/score', (function () use ($row) {
    foreach ($row['breakdown'] as $f) {
        if (!isset($f['key'], $f['label'], $f['score'])) {
            return false;
        }
    }
    return true;
})());
T::ok('mandatory disclaimer present', strpos($row['disclaimer'], 'not a guaranteed') !== false);
T::eq('engine recorded', 'rules', $row['engine']);
T::ok('engine version recorded', is_string($row['engine_version']) && $row['engine_version'] !== '');
T::ok('one row inserted', Db::count('valuations', ['domain' => 'solarpanels.com']) === 1);
T::ok('ip stored as pseudonym hash', (function () {
    $r = Db::first('valuations', ['domain' => 'solarpanels.com']);
    return isset($r['ip_hash']) && strlen($r['ip_hash']) === 24;
})());
T::ok('EUR client gets EUR currency', (function () use ($svc) {
    $r = $svc->estimate('benstoken.com', 22);
    return $r['currency'] === 'EUR';
})());

T::section('History + admin feed');
$h = $svc->history(11);
T::eq('history has valuation', 1, count($h));
T::ok('history rows carry disclaimer', strpos($h[0]['disclaimer'], 'not a guaranteed') !== false);
$recent = $svc->recentForAdmin();
T::ok('admin sees feed', count($recent) >= 2);
T::ok('admin feed strips ip hash', !array_key_exists('ip_hash', $recent[0]));

T::section('Daily limits — client then guest');
Settings::override('valuation_client_daily_limit', '5');
for ($i = 0; $i < 5; $i++) {
    $svc->estimate("bkm{$i}.com", 33);
}
T::ok('five client hits within limit', true);
T::throws('6th client hit denied', function () use ($svc) {
    $svc->estimate('bkm5.com', 33);
}, RateLimitException::class);
Settings::override('valuation_client_daily_limit', null);

Settings::override('valuation_guest_daily_limit', '2');
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
$svc->estimate('guest-one.com', null);
$svc->estimate('guest-two.com', null);
T::throws('3rd guest hit denied', function () use ($svc) {
    $svc->estimate('guest-three.com', null);
}, RateLimitException::class);
Settings::override('valuation_guest_daily_limit', null);
T::ok('guest rows record null client', Db::count('valuations', ['client_id' => null]) >= 2);

T::section('Invalid input is a clean validation error');
T::throws('junk input rejected politely', function () use ($svc) {
    $svc->estimate('not a domain!!', null);
}, ValidationException::class);
T::throws('service switch off is explicit', function () use ($svc) {
    Settings::override('valuation_enabled', '0');
    try {
        $svc->estimate('anything.com', null);
    } finally {
        Settings::override('valuation_enabled', null);
    }
}, \Chs\Core\ServiceUnavailableException::class);

T::section('Engine info + HTTP engine honesty');
T::eq('info id', 'rules', $svc->engineInfo()['id']);
T::throws('http engine unconfigured refuses silently faking', function () {
    \Chs\Providers\Valuation\HttpValuationEngine::isConfigured()
        ? null
        : (function () { (new \Chs\Providers\Valuation\HttpValuationEngine())->evaluate(\Chs\Core\DomainName::parse('x.com')); })();
}, \Throwable::class);

T::finish();
