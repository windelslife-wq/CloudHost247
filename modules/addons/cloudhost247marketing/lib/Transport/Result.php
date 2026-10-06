<?php
/**
 * The outcome of one delivery attempt.
 *
 * `permanent` is what drives the queue: a permanent failure (bad address,
 * rejected sender, 5xx) is never retried and suppresses the recipient; a
 * transient failure (connection reset, 4xx greylisting, rate limit) goes back
 * on the queue with exponential backoff.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

class Result
{
    /** @var bool */
    public $ok = false;
    /** @var string */
    public $messageId = '';
    /** @var string */
    public $error = '';
    /** @var bool true = do not retry */
    public $permanent = false;
    /** @var string 'hard'|'soft'|'' — how to classify the bounce */
    public $bounceType = '';
    /** @var array raw provider response, for the delivery log */
    public $raw = [];

    public static function success($messageId = '', array $raw = [])
    {
        $r = new self();
        $r->ok = true;
        $r->messageId = (string) $messageId;
        $r->raw = $raw;
        return $r;
    }

    /** Transient: the worker will retry with backoff. */
    public static function transient($error, array $raw = [])
    {
        $r = new self();
        $r->ok = false;
        $r->error = (string) $error;
        $r->permanent = false;
        $r->bounceType = 'soft';
        $r->raw = $raw;
        return $r;
    }

    /** Permanent: never retried; recipient is marked bounced/failed. */
    public static function permanent($error, $bounceType = 'hard', array $raw = [])
    {
        $r = new self();
        $r->ok = false;
        $r->error = (string) $error;
        $r->permanent = true;
        $r->bounceType = $bounceType;
        $r->raw = $raw;
        return $r;
    }

    /**
     * Classify an SMTP reply code.
     * 5xx = permanent, 4xx = transient, anything else = transient.
     */
    public static function fromSmtpCode($code, $reply)
    {
        $code = (int) $code;
        if ($code >= 200 && $code < 400) {
            return self::success();
        }
        if ($code >= 500) {
            return self::permanent('SMTP ' . $code . ': ' . $reply, 'hard', ['code' => $code]);
        }
        return self::transient('SMTP ' . $code . ': ' . $reply, ['code' => $code]);
    }
}
