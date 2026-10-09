<?php
/** Run once on the remote server from a timer/cron. Never execute over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/HeartbeatSender.php';

try {
    $sender = \Ch247Agent\HeartbeatSender::fromEnvironment();
    $result = $sender->send();
    // Opt-in: report only a real kernel counter after liveness succeeds. A
    // failed sample never fabricates a value or masks a missing heartbeat.
    if (getenv('CH247_AGENT_UPTIME_ENABLED') === '1') {
        require_once __DIR__ . '/UptimeReader.php';
        $sender->send(\Ch247Agent\UptimeReader::read());
    }
    // No UUID, secret, signed headers, provider body or endpoint in output.
    echo 'Heartbeat accepted: ' . $result['status'] . "\n";
    exit(0);
} catch (\Throwable $e) {
    // Deliberately do not dump the exception: configuration errors must not
    // expose secret-file paths, HTTP responses or hostnames into cron logs.
    fwrite(STDERR, "Agent report failed; check private node configuration and WHMCS gates.\n");
    exit(1);
}
