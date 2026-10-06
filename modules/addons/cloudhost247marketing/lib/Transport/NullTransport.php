<?php
/**
 * Captures messages instead of sending them.
 *
 * Used by the test suite and by the "dry run" switch, so an operator can
 * exercise the whole pipeline — queue, personalisation, tracking rewrite,
 * per-recipient ledger — without a single byte reaching a real inbox.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

class NullTransport implements TransportInterface
{
    /** @var Message[] */
    public static $sent = [];

    /** @var callable|null returns a Result to simulate failures in tests */
    private static $responder;

    public function name()
    {
        return 'null';
    }

    public function isConfigured()
    {
        return true;
    }

    public function configurationProblem()
    {
        return '';
    }

    public function send(Message $message)
    {
        self::$sent[] = $message;
        if (self::$responder !== null) {
            $result = call_user_func(self::$responder, $message, count(self::$sent));
            if ($result instanceof Result) {
                return $result;
            }
        }
        return Result::success('null-' . count(self::$sent));
    }

    public static function reset()
    {
        self::$sent = [];
        self::$responder = null;
    }

    /** Test seam: decide the Result per message. */
    public static function respondWith($fn)
    {
        self::$responder = $fn;
    }

    public static function lastMessage()
    {
        return self::$sent === [] ? null : self::$sent[count(self::$sent) - 1];
    }
}
