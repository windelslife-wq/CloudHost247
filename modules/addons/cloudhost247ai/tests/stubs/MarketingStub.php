<?php
/**
 * Test double for the marketing module's audience store. Required only by
 * 15_SupportOperatorTest.php, after the no-marketing fallback path has been
 * asserted, to prove the bridge delegates when the class exists.
 *
 * (Not a *Test.php file, so the runner never executes it directly.)
 */

namespace Ch247Mkt\Audience;

class SubscriberService
{
    public static $calls = [];

    public static function upsert(array $data, array $options = [])
    {
        self::$calls[] = ['data' => $data, 'options' => $options];
        return ['status' => 'subscribed', 'email' => isset($data['email']) ? $data['email'] : ''];
    }

    public static function reset()
    {
        self::$calls = [];
    }
}
