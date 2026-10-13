<?php
/** Offline regressions for OVH / SoYouStart cron safeguards and pure helpers. */
namespace CloudHost247\SoYouStart\Cron {
    function curl_init()
    {
        $GLOBALS['cronCurlCalls']++;
        $GLOBALS['cronCurlOptions'] = [];
        return new \stdClass();
    }

    function curl_setopt($handle, $option, $value)
    {
        $GLOBALS['cronCurlOptions'][$option] = $value;
        return true;
    }

    function curl_exec($handle)
    {
        return $GLOBALS['cronCurlResponse'];
    }

    function curl_getinfo($handle, $option)
    {
        return $GLOBALS['cronCurlHttpCode'];
    }

    function curl_close($handle)
    {
        $GLOBALS['cronCurlClosed']++;
    }
}

namespace {
    foreach ([
        'CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_HTTPGET', 'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_TIMEOUT', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST',
        'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS', 'CURLOPT_HTTPHEADER', 'CURLINFO_HTTP_CODE',
    ] as $index => $name) {
        if (!defined($name)) {
            define($name, 7000 + $index);
        }
    }

    require_once dirname(__DIR__) . '/lib/CronRuntime.php';
    require_once dirname(__DIR__) . '/lib/EmailSender.php';
    require_once dirname(__DIR__) . '/lib/GoogleMailClient.php';

    $pass = 0;
    $fail = 0;
    $failures = [];
    $GLOBALS['cronCurlCalls'] = 0;
    $GLOBALS['cronCurlOptions'] = [];
    $GLOBALS['cronCurlResponse'] = '{"messages":[]}';
    $GLOBALS['cronCurlHttpCode'] = 200;
    $GLOBALS['cronCurlClosed'] = 0;

    function cronCheck($label, $condition)
    {
        global $pass, $fail, $failures;
        if ($condition) {
            $pass++;
            return;
        }
        $fail++;
        $failures[] = $label;
        echo "FAIL: {$label}\n";
    }

    function cronAttempt(callable $callback)
    {
        try {
            $callback();
            return null;
        } catch (\Throwable $error) {
            return $error;
        }
    }

    echo "== CLI guard ==\n";
    cronCheck('CLI SAPI is allowed', \CloudHost247\SoYouStart\Cron\CronGuard::isCliSapi('cli'));
    cronCheck('PHP built-in web server SAPI is denied', !\CloudHost247\SoYouStart\Cron\CronGuard::isCliSapi('cli-server'));
    cronCheck('FPM SAPI is denied', !\CloudHost247\SoYouStart\Cron\CronGuard::isCliSapi('fpm-fcgi'));
    cronCheck('non-string SAPI is denied', !\CloudHost247\SoYouStart\Cron\CronGuard::isCliSapi(null));

    $cronFiles = ['priceSync.php', 'getServer.php', 'getIpStatus.php', 'emailSend.php'];
    foreach ($cronFiles as $cronFile) {
        $source = file_get_contents(dirname(__DIR__) . '/' . $cronFile);
        cronCheck($cronFile . ' parses without executing it', is_array(token_get_all($source, TOKEN_PARSE)));
        $guard = strpos($source, 'CronGuard::requireCli();');
        $config = strpos($source, 'config.php');
        $init = strpos($source, 'init.php');
        cronCheck($cronFile . ' blocks web access before WHMCS bootstrap/config',
            $guard !== false && $config !== false && $init !== false && $guard < $config && $guard < $init);
    }

    echo "== priceSync batch cursor ==\n";
    $batch = '\\CloudHost247\\SoYouStart\\Cron\\PriceSyncBatch';
    cronCheck('first batch begins at zero', $batch::normalizeOffset(0, 25) === 0);
    cronCheck('valid persisted offset is retained', $batch::normalizeOffset(10, 25) === 10);
    cronCheck('negative persisted offset resets safely', $batch::normalizeOffset(-4, 25) === 0);
    cronCheck('offset at total wraps to zero', $batch::normalizeOffset(25, 25) === 0);
    cronCheck('offset beyond a shrunken catalog wraps to zero', $batch::normalizeOffset(40, 25) === 0);
    cronCheck('empty catalog always uses offset zero', $batch::normalizeOffset(4, 0) === 0);
    cronCheck('next page advances by batch size', $batch::nextOffset(0, 25, 10) === 10);
    cronCheck('middle page advances deterministically', $batch::nextOffset(10, 25, 10) === 20);
    cronCheck('last partial page clamps to catalog size', $batch::nextOffset(20, 25, 10) === 25);
    cronCheck('invalid batch size cannot stall on a non-empty catalog', $batch::nextOffset(10, 25, 0) === 0);

    echo "== additional-IP quantity boundaries ==\n";
    cronCheck('minimum additional-IP quantity is accepted', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('1') === 1);
    cronCheck('maximum additional-IP quantity is accepted', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('256') === 256);
    cronCheck('integer additional-IP quantity is accepted', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse(12) === 12);
    cronCheck('zero additional-IP quantity is rejected', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('0') === false);
    cronCheck('negative additional-IP quantity is rejected', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('-1') === false);
    cronCheck('quantity above 256 is rejected', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('257') === false);
    cronCheck('fractional additional-IP quantity is rejected', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('1.5') === false);
    cronCheck('scientific notation quantity is rejected', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse('2e0') === false);
    cronCheck('array quantity is rejected without coercion', \CloudHost247\SoYouStart\Cron\AdditionalIpQuantity::parse(['2']) === false);

    $priceSync = file_get_contents(dirname(__DIR__) . '/priceSync.php');
    cronCheck('priceSync cursor updates select by key, not a string id',
        strpos($priceSync, "['key' => 'cronHit']") !== false
            && strpos($priceSync, "['id' => 'cronHit']") === false);
    cronCheck('priceSync advances by the fixed page boundary even when a product is opt-out',
        strpos($priceSync, '$key >= $nextCronHit || $product->pricesync') !== false
            && strpos($priceSync, "'value' => \$nextCronHit") !== false);

    echo "== WHMCS email dispatch redaction ==\n";
    $secret = 'CRON-PRIVATE-PASSWORD-DO-NOT-LOG-91e3';
    $customVars = base64_encode(serialize(['username' => 'root', 'password' => $secret]));
    $sentPayload = null;
    $logged = [];
    $apiResult = ['result' => 'success', 'message' => $secret];
    $returned = \CloudHost247\SoYouStart\Cron\EmailSender::send(
        'Rescue-Pro Mode',
        '42',
        $customVars,
        function ($payload) use (&$sentPayload, $apiResult) {
            $sentPayload = $payload;
            return $apiResult;
        },
        function ($module, $action, $request, $response) use (&$logged) {
            $logged = [$module, $action, $request, $response];
        }
    );
    cronCheck('WHMCS receives the required secret-bearing custom variables',
        is_array($sentPayload) && $sentPayload['customvars'] === $customVars);
    cronCheck('email sender preserves localAPI result for existing callers', $returned === $apiResult);
    cronCheck('audit log keeps only the template and integer client id',
        isset($logged[2]) && $logged[2] === ['messagename' => 'Rescue-Pro Mode', 'id' => 42]);
    cronCheck('audit log reports outcome without raw WHMCS response data',
        isset($logged[3]) && $logged[3] === ['result' => 'success']);
    cronCheck('neither merge secret nor encoded merge data reaches the audit log',
        strpos(serialize($logged), $secret) === false && strpos(serialize($logged), $customVars) === false);
    $loggerFailureResult = \CloudHost247\SoYouStart\Cron\EmailSender::send(
        'Hardware Reboot',
        7,
        'safe-vars',
        function ($payload) { return ['result' => 'success']; },
        function () { throw new \RuntimeException('test logger failure'); }
    );
    cronCheck('logging failure does not convert successful email dispatch into a retry',
        is_array($loggerFailureResult) && $loggerFailureResult['result'] === 'success');
    $localApiFailure = \CloudHost247\SoYouStart\Cron\EmailSender::send(
        'Hardware Reboot',
        7,
        'safe-vars',
        function () { throw new \RuntimeException('test localAPI failure'); },
        function () { throw new \RuntimeException('must not be called'); }
    );
    cronCheck('localAPI exception preserves legacy null failure return', $localApiFailure === null);

    echo "== Gmail transport constraints ==\n";
    $validListUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?q=in%3Ainbox%20after%3A2026%2F10%2F11';
    $response = \CloudHost247\SoYouStart\Cron\GoogleMailClient::request('GET', $validListUrl, 'test-access-token');
    cronCheck('allowed Gmail list URL uses offline transport and returns decoded data',
        $response['httpcode'] === 200 && isset($response['result']->messages));
    cronCheck('Gmail request verifies TLS and hostname',
        ($GLOBALS['cronCurlOptions'][CURLOPT_SSL_VERIFYPEER] ?? null) === true
            && ($GLOBALS['cronCurlOptions'][CURLOPT_SSL_VERIFYHOST] ?? null) === 2);
    cronCheck('Gmail request uses bounded connection and total timeouts',
        ($GLOBALS['cronCurlOptions'][CURLOPT_CONNECTTIMEOUT] ?? null) === 10
            && ($GLOBALS['cronCurlOptions'][CURLOPT_TIMEOUT] ?? null) === 30);
    cronCheck('Gmail bearer-token request never follows redirects',
        ($GLOBALS['cronCurlOptions'][CURLOPT_FOLLOWLOCATION] ?? null) === false
            && ($GLOBALS['cronCurlOptions'][CURLOPT_MAXREDIRS] ?? null) === 0);
    cronCheck('Gmail API request sends bearer token only to the fixed HTTPS host',
        ($GLOBALS['cronCurlOptions'][CURLOPT_URL] ?? null) === $validListUrl
            && in_array('Authorization: Bearer test-access-token', $GLOBALS['cronCurlOptions'][CURLOPT_HTTPHEADER] ?? [], true));
    cronCheck('cURL handle is closed after request', $GLOBALS['cronCurlClosed'] === 1);

    $allowedMessageUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/abc_123-XYZ?fields=payload';
    $messageResponse = \CloudHost247\SoYouStart\Cron\GoogleMailClient::request('GET', $allowedMessageUrl, 'another-token');
    cronCheck('Gmail message path with a validated provider ID is allowed', $messageResponse['httpcode'] === 200);

    $callsBeforeInvalid = $GLOBALS['cronCurlCalls'];
    $badUrls = [
        'http://gmail.googleapis.com/gmail/v1/users/me/messages',
        'https://gmail.googleapis.com.evil.example/gmail/v1/users/me/messages',
        'https://user@gmail.googleapis.com/gmail/v1/users/me/messages',
        'https://gmail.googleapis.com:8443/gmail/v1/users/me/messages',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages#fragment',
        'https://gmail.googleapis.com/gmail/v1/users/me/other',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/abc%2F..',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages?redirect_uri=https%3A%2F%2Fevil.example',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages?q[]=bad',
    ];
    foreach ($badUrls as $badUrl) {
        $error = cronAttempt(function () use ($badUrl) {
            \CloudHost247\SoYouStart\Cron\GoogleMailClient::request('GET', $badUrl, 'test-token');
        });
        cronCheck('untrusted Gmail URL is rejected before transport', $error instanceof \InvalidArgumentException);
    }
    $badMethod = cronAttempt(function () use ($validListUrl) {
        \CloudHost247\SoYouStart\Cron\GoogleMailClient::request('POST', $validListUrl, 'test-token');
    });
    cronCheck('unused Gmail write methods fail closed', $badMethod instanceof \InvalidArgumentException);
    $badToken = cronAttempt(function () use ($validListUrl) {
        \CloudHost247\SoYouStart\Cron\GoogleMailClient::request('GET', $validListUrl, "bad\r\nX-Injected: yes");
    });
    cronCheck('control characters in Gmail bearer token are rejected', $badToken instanceof \InvalidArgumentException);
    cronCheck('invalid URLs, methods, and tokens never initialize cURL', $GLOBALS['cronCurlCalls'] === $callsBeforeInvalid);

    echo "== cron source safety regressions ==\n";
    $emailSource = file_get_contents(dirname(__DIR__) . '/emailSend.php');
    cronCheck('IMAP connection no longer disables certificate validation',
        strpos($emailSource, 'novalidate-cert') === false
            && strpos($emailSource, "'/imap/' . \$layer . '}INBOX'") !== false);
    cronCheck('email cron delegates module logging to redacting sender',
        strpos($emailSource, 'EmailSender::send($template, $clientId, $customVars)') !== false
            && strpos($emailSource, 'logModuleCall("Soyoustart", "sending email for $template", $postData, $results)') === false);
    cronCheck('Gmail entry point delegates all requests to constrained transport',
        strpos($emailSource, 'GoogleMailClient::request($method, $apiUrl, $accessToken)') !== false
            && strpos($emailSource, 'CURLOPT_FOLLOWLOCATION, 1') === false
            && strpos($emailSource, 'CURLOPT_CONNECTTIMEOUT, 0') === false);
    cronCheck('Gmail message IDs and mailbox-derived OVH server names are validated',
        strpos($emailSource, 'skipped a Gmail message with an invalid identifier') !== false
            && strpos($emailSource, 'skipped an invalid server identifier in an IMAP message') !== false
            && strpos($emailSource, 'skipped an invalid server identifier in a Gmail message') !== false);
    cronCheck('Gmail failure logging no longer dereferences undefined message/service variables',
        strpos($emailSource, '$allEmailIds["result"]') === false
            && strpos($emailSource, '$emailData["result"]->subject') === false
            && strpos($emailSource, '$emailId') === false);

    $serverSource = file_get_contents(dirname(__DIR__) . '/getServer.php');
    cronCheck('getServer parses a defaulted, bounded additional-IP count before a cart',
        strpos($serverSource, "['configoptions']['additional ips'] ?? ''") !== false
            && strpos($serverSource, 'AdditionalIpQuantity::parse($noOfIps)') !== false
            && strpos($serverSource, 'if ($quantity !== false && !$chkexistIp)') !== false);
    cronCheck('getServer creates an additional-IP cart under the service-selected OVH account',
        strpos($serverSource, '$apiCall->createCart($location, $planCode . "add Additional IPs", $ovhSubsidiary, $accountId)') !== false);
    cronCheck('getServer reads the new order ID from the checkout response, not an undefined variable',
        strpos($serverSource, '$orderId = $ipcheckout["result"]->orderId;') !== false
            && strpos($serverSource, '$response["result"]->orderId') === false);
    cronCheck('getServer inserts the tracking row with the schema-required invoiceid (0 = paid outside WHMCS invoices)',
        strpos($serverSource, "insert(array('service_id' => \$service->id, 'invoiceid' => 0, 'iporderid' => \$orderId, 'status' => '0'))") !== false);
    $ipStatusSource = file_get_contents(dirname(__DIR__) . '/getIpStatus.php');
    cronCheck('getIpStatus skips stale orders and missing account rows before dereferencing them',
        strpos($ipStatusSource, 'if (!isset($serviceMatches[0]))') !== false
            && strpos($ipStatusSource, 'if (!is_object($accountInfo) || empty($accountInfo->id) || empty($accountInfo->location))') !== false);

    echo "== CR-O1..CR-O5 operational regressions ==\n";
    // CR-O1: OrderPaymentJudge
    cronCheck('CR-O1: splitConfigOption handles pipe-separated and plain strings',
        \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::splitConfigOption('yes|Auto-Pay', 1) === 'Auto-Pay'
            && \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::splitConfigOption('yes', 1) === 'yes'
            && \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::splitConfigOption('12345|Card', 0) === '12345');
    cronCheck('CR-O1: isPaymentSucceeded accepts "success" and HTTP 200 array without error message',
        \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::isPaymentSucceeded('success') === true
            && \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::isPaymentSucceeded(['httpcode' => 200, 'result' => (object) ['orderId' => 99]]) === true);
    cronCheck('CR-O1: isPaymentSucceeded rejects error strings, non-200 arrays, and error objects',
        \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::isPaymentSucceeded('Please add default payment method first.') === false
            && \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::isPaymentSucceeded('Error description: card declined') === false
            && \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::isPaymentSucceeded(['httpcode' => 400, 'result' => (object) ['message' => 'Bad request']]) === false
            && \CloudHost247\SoYouStart\Cron\OrderPaymentJudge::isPaymentSucceeded(null) === false);
    cronCheck('CR-O1: getServer reads configoption3 from $productDetails, disables checkout autoPay, and transitions paid IP orders to status 2',
        strpos($serverSource, "\$params['configoption3']") === false
            && strpos($serverSource, "'autoPayWithPreferredPaymentMethod' => false") !== false
            && strpos($serverSource, 'OrderPaymentJudge::isPaymentSucceeded($paymentresponse)') !== false
            && strpos($serverSource, "->update(['status' => '2'])") !== false);

    // CR-O2: IpDestinationValidator
    $destResponse = [
        'httpcode' => 200,
        'result' => (object) [
            'vps' => [(object) ['serviceName' => 'vps-abc.ovh.net']],
            'dedicatedServer' => [(object) ['serviceName' => 'ns12345.ip-1-2-3.eu']],
        ],
    ];
    cronCheck('CR-O2: findEligibleService matches VPS and dedicatedServer destinations and rejects unknown targets',
        \CloudHost247\SoYouStart\Cron\IpDestinationValidator::findEligibleService($destResponse, 'vps-abc.ovh.net') === 'vps-abc.ovh.net'
            && \CloudHost247\SoYouStart\Cron\IpDestinationValidator::findEligibleService($destResponse, 'ns12345.ip-1-2-3.eu') === 'ns12345.ip-1-2-3.eu'
            && \CloudHost247\SoYouStart\Cron\IpDestinationValidator::findEligibleService($destResponse, 'unlisted.ovh.net') === null);
    cronCheck('CR-O2: getIpStatus verifies eligible destination before moveIpToService',
        strpos($ipStatusSource, 'IpDestinationValidator::findEligibleService(') !== false
            && strpos($ipStatusSource, 'if ($matchedDestination === null)') !== false);

    // CR-O4: ImapCredential + deferred seen-message persistence + Gmail Installation fix
    $legacyCipher = base64_encode(openssl_encrypt('secret-imap-pass', 'AES-256-CBC', 'encryptionKey1234567891234567', OPENSSL_RAW_DATA, '1234567891011121'));
    cronCheck('CR-O4: ImapCredential decrypts legacy AES-256-CBC and plaintext values',
        \CloudHost247\SoYouStart\Cron\ImapCredential::decrypt($legacyCipher) === 'secret-imap-pass'
            && \CloudHost247\SoYouStart\Cron\ImapCredential::decrypt('plain-user@example.com') === 'plain-user@example.com');
    cronCheck('CR-O4: emailSend uses ImapCredential::decrypt and removes hardcoded AES key',
        strpos($emailSource, 'ImapCredential::decrypt(') !== false
            && strpos($emailSource, '$key = "encryptionKey1234567891234567"') === false);
    cronCheck('CR-O4: emailSend persists seen message IDs only after downstream delivery succeeds',
        substr_count($emailSource, 'if (!$messageDeliveryFailed)') === 2
            && strpos($emailSource, '$ftpServer, "new_password" => $password];' . "\n" . '                                            $sendEmailResponse = sendEmail("Ftp Backup Password change"') === false);

    // CR-O5: CronLock single-flight guard
    $tmpDir = sys_get_temp_dir();
    $lock1 = \CloudHost247\SoYouStart\Cron\CronLock::acquire('test_job_' . getmypid(), $tmpDir);
    $lock2 = \CloudHost247\SoYouStart\Cron\CronLock::acquire('test_job_' . getmypid(), $tmpDir);
    cronCheck('CR-O5: CronLock acquires primary lock and rejects overlapping acquisition',
        is_resource($lock1) && $lock2 === null);
    \CloudHost247\SoYouStart\Cron\CronLock::release($lock1);
    $lock3 = \CloudHost247\SoYouStart\Cron\CronLock::acquire('test_job_' . getmypid(), $tmpDir);
    cronCheck('CR-O5: CronLock allows re-acquisition after release', is_resource($lock3));
    \CloudHost247\SoYouStart\Cron\CronLock::release($lock3);
    $priceSyncSource = file_get_contents(dirname(__DIR__) . '/priceSync.php');
    cronCheck('CR-O5: all four cron entrypoints acquire and release CronLock',
        strpos($priceSyncSource, "CronLock::acquire('priceSync')") !== false
            && strpos($serverSource, "CronLock::acquire('getServer')") !== false
            && strpos($ipStatusSource, "CronLock::acquire('getIpStatus')") !== false
            && strpos($emailSource, "CronLock::acquire('emailSend')") !== false);

    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "PASS={$pass} FAIL={$fail}\n";
    exit($fail === 0 ? 0 : 1);
}
