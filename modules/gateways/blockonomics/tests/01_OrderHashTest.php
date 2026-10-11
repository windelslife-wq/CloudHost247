<?php
/**
 * C-7: order-hash crypto (Encrypt-then-MAC) and invalid-hash guards.
 * Prints FAIL=<n>.
 */
namespace {
    use Blockonomics\TestBlockonomics;
    use Blockonomics\TestRejectException;
    use WHMCS\Database\Capsule;

    require_once __DIR__ . '/bootstrap.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
    }
    function flip($s, $pos)
    {
        $c = $s[$pos] === 'a' ? 'b' : 'a';
        return substr($s, 0, $pos) . $c . substr($s, $pos + 1);
    }

    echo "== encrypt/decrypt round trip ==\n";
    bn_reset_all();
    $b = new TestBlockonomics();
    $secret = BnTestState::$gatewayParams['CallbackSecret'];
    $h = $b->encryptHash('5:12.50:USD:12.50');
    check('hash is even-length hex of 66+ chars', ctype_xdigit($h) && strlen($h) >= 66 && strlen($h) % 2 === 0);
    $mac = substr($h, 0, 64);
    $ct = (string) hex2bin((string) substr($h, 64));
    check('leading 64 chars are the HMAC of the ciphertext', hash_equals(hash_hmac('sha256', $ct, $secret), $mac));
    $info = $b->decryptHash($h);
    check('round trip id', is_object($info) && $info->id_order === 5);
    check('round trip value', $info->value === 12.5);
    check('round trip currency', $info->currency === 'USD');
    check('round trip base', $info->basecurrencyamount === 12.5);
    check('same input encrypts deterministically (static IV, MAC still applies)', $b->encryptHash('5:12.50:USD:12.50') === $h);

    echo "== tamper and format rejection ==\n";
    check('flipped MAC nibble rejected', $b->decryptHash(flip($h, 3)) === null);
    check('flipped ciphertext nibble rejected', $b->decryptHash(flip($h, 70)) === null);
    check('truncated hash rejected', $b->decryptHash(substr($h, 0, 100)) === null);
    check('extended hash rejected', $b->decryptHash($h . '00') === null);
    check('empty rejected', $b->decryptHash('') === null);
    check('odd length rejected', $b->decryptHash('abc') === null);
    check('non-hex rejected', $b->decryptHash(str_repeat('zz', 40)) === null);
    check('short hex rejected', $b->decryptHash('ab') === null);
    check('66-char garbage hex rejected', $b->decryptHash(str_repeat('ab', 33)) === null);
    check('null rejected', $b->decryptHash(null) === null);
    check('non-string rejected', $b->decryptHash(12345) === null);
    check('two-part payload rejected', $b->decryptHash($b->encryptHash('a:b')) === null);
    check('three-part payload rejected', $b->decryptHash($b->encryptHash('1:2:USD')) === null);

    echo "== legacy (pre-C-7, MAC-less) hashes fail closed ==\n";
    $key = hash('sha256', $secret, true);
    $iv = substr($secret, 0, 16);
    $legacy = bin2hex((string) openssl_encrypt('9:1.00:USD:1.00', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv));
    check('legacy hash is too short to carry a MAC', strlen($legacy) < 66);
    check('legacy hash rejected', $b->decryptHash($legacy) === null);

    echo "== secret binding ==\n";
    BnTestState::$gatewayParams['CallbackSecret'] = 'differentsecret0123456789abcdef01234567';
    check('hash from another secret rejected', $b->decryptHash($h) === null);
    bn_reset_all();

    echo "== order-hash helpers ==\n";
    $b = new TestBlockonomics();
    $oh = $b->getOrderHash(7, '3.25', 'EUR', '3.25');
    $oi = $b->decryptHash($oh);
    check('getOrderHash round trips id', $oi->id_order === 7);
    check('getOrderHash round trips value/currency/base', $oi->value === 3.25 && $oi->currency === 'EUR' && $oi->basecurrencyamount === 3.25);
    check('getOrderIdByHash valid', $b->getOrderIdByHash($oh) === 7);
    check('getOrderIdByHash garbage is null', $b->getOrderIdByHash('xx') === null);

    echo "== invalid hashes never reach provider/DB (BN-1) ==\n";
    foreach (['', 'deadbeef', str_repeat('ab', 40), $legacy] as $i => $bad) {
        bn_reset_all();
        $b = new TestBlockonomics();
        TestBlockonomics::$newAddressResponse = (object) ['response_code' => 200, 'address' => 'burned?'];
        try {
            $b->processOrderHash($bad, 'btc');
            check("garbage hash #$i rejected", false);
        } catch (TestRejectException $e) {
            check("garbage hash #$i rejected", $e->getMessage() === 'Invalid payment reference.');
        }
        check("garbage hash #$i burns no provider address", TestBlockonomics::$calls === []);
        check("garbage hash #$i writes no rows", Capsule::$writes === []);
    }
    bn_reset_all();
    $b = new TestBlockonomics();
    try {
        $b->processOrderHash($b->encryptHash('0:1.00:USD:1.00'), 'btc');
        check('zero id rejected', false);
    } catch (TestRejectException $e) {
        check('zero id rejected', true);
    }
    check('zero id writes nothing', Capsule::$writes === [] && TestBlockonomics::$calls === []);

    echo "\norder hash: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
