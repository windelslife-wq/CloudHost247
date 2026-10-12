<?php
/** Offline regression tests for query values selected by the client-area route registry. */

namespace WHMCS {
    class ClientArea {
        public static $lastInstance;
        public $assigned = [];
        public $template = '';
        public function __construct() { self::$lastInstance = $this; }
        public function setPageTitle($title) {}
        public function addToBreadCrumb($url, $label) {}
        public function assign($key, $value) { $this->assigned[$key] = $value; }
        public function setTemplate($template) { $this->template = $template; }
        public function output() {}
    }
}
namespace WHMCS\Database {
    class Capsule {}
}
namespace {
    define('WHMCS', true);
    $_SESSION = ['uid' => 7];
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    $_POST = [];
    $_COOKIE = [];

    function tools_center_check_access($clientId) { return $clientId === 7; }
    function getToolsCenterSettings() { return ['module_link_name' => 'Tools Center', 'api_endpoint' => '']; }
    class Lang { public static function trans($key) { return $key; } }

    $pass = 0;
    $fail = 0;
    function route_chk($label, $ok) {
        global $pass, $fail;
        if ($ok) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
    }

    $cases = [
        [
            'label' => 'registered category and action select the tool route',
            'query' => ['cat' => 'webmaster', 'tool' => 'pageRankChecker'],
            'category' => 'webmaster',
            'action' => 'pageRankChecker',
            'template' => 'tools/tool',
        ],
        [
            'label' => 'HTML-bearing category is rejected before template assignment',
            'query' => ['cat' => 'webmaster"><svg/onload=alert(1)>', 'tool' => 'pageRankChecker'],
            'category' => 'all',
            'action' => '',
            'template' => 'tools/dashboard',
        ],
        [
            'label' => 'HTML-bearing action is rejected before template assignment',
            'query' => ['cat' => 'webmaster', 'tool' => 'pageRankChecker"><svg/onload=alert(1)>'],
            'category' => 'webmaster',
            'action' => '',
            'template' => 'tools/category',
        ],
        [
            'label' => 'array route parameters are rejected',
            'query' => ['cat' => ['webmaster'], 'tool' => ['pageRankChecker']],
            'category' => 'all',
            'action' => '',
            'template' => 'tools/dashboard',
        ],
    ];

    foreach ($cases as $case) {
        $_GET = $case['query'];
        \WHMCS\ClientArea::$lastInstance = null;
        include __DIR__ . '/../clientarea.php';
        $instance = \WHMCS\ClientArea::$lastInstance;
        route_chk($case['label'], $instance instanceof \WHMCS\ClientArea
            && ($instance->assigned['currentCategory'] ?? null) === $case['category']
            && ($instance->assigned['currentTool'] ?? null) === $case['action']
            && $instance->template === $case['template']);
    }

    echo "\nPASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
