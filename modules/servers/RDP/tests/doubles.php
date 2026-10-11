<?php
/**
 * C-1: scriptable stand-in for WHMCS\Module\Server\RDP\Helper.
 * Behaviour suites (02/03/04) load this + the real entry file, so the panel
 * is doubled while every entry line under test is production code.
 */
namespace WHMCS\Module\Server\RDP {
    class Helper
    {
        public static $stocksResult = null;
        public static $orderResult = null;
        public static $renewResult = null;
        public static $detailResult = null;
        public static $testResult = null;
        public static $saveResult = true;
        public static $emailResult = ['result' => 'success'];
        public static $throwOn = null;
        public static $instances = [];
        public static $calls = [];
        public static $customFieldPids = [];
        public static $templatesCreated = 0;
        public static $emails = [];
        public static $savedValues = [];
        public static function reset()
        {
            self::$stocksResult = null;
            self::$orderResult = null;
            self::$renewResult = null;
            self::$detailResult = null;
            self::$testResult = null;
            self::$saveResult = true;
            self::$emailResult = ['result' => 'success'];
            self::$throwOn = null;
            self::$instances = [];
            self::$calls = [];
            self::$customFieldPids = [];
            self::$templatesCreated = 0;
            self::$emails = [];
            self::$savedValues = [];
        }
        public function __construct($params)
        {
            self::$instances[] = $params;
        }
        private function maybeThrow($method)
        {
            if (self::$throwOn === $method) {
                throw new \Exception('panel down');
            }
        }
        public function getStocks()
        {
            $this->maybeThrow('getStocks');
            self::$calls[] = ['getStocks'];
            return self::$stocksResult;
        }
        public function createOrder($productid)
        {
            $this->maybeThrow('createOrder');
            self::$calls[] = ['createOrder', $productid];
            return self::$orderResult;
        }
        public function renewService($serviceid)
        {
            $this->maybeThrow('renewService');
            self::$calls[] = ['renewService', $serviceid];
            return self::$renewResult;
        }
        public function getServiceDetail($serviceid)
        {
            $this->maybeThrow('getServiceDetail');
            self::$calls[] = ['getServiceDetail', $serviceid];
            return self::$detailResult;
        }
        public function testConnection()
        {
            $this->maybeThrow('testConnection');
            self::$calls[] = ['testConnection'];
            return self::$testResult;
        }
        public function CreateCustomFields($pid)
        {
            self::$customFieldPids[] = $pid;
        }
        public function createTemplate()
        {
            self::$templatesCreated++;
        }
        public function saveCustomFiledValue($pid, $serviceid, $value)
        {
            self::$savedValues[] = [$pid, $serviceid, $value];
            return self::$saveResult;
        }
        public function sendEmail($postData)
        {
            self::$emails[] = $postData;
            return self::$emailResult;
        }
    }
}
namespace {
    require_once __DIR__ . '/bootstrap.php';
    function rdp_full_reset()
    {
        rdp_reset();
        \WHMCS\Module\Server\RDP\Helper::reset();
    }
}
