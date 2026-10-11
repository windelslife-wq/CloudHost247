<?php
/**
 * C-2: scriptable ApiClient stand-in for the ajax-handler suites.
 * Load AFTER the real lib classes (one ApiClient per process otherwise).
 */
namespace Ch247Test {
    class FakeApi extends \CloudHost247\LTEProxy\ApiClient
    {
        /** @var array method => return value or Throwable to throw */
        public $script = [];
        /** @var array list of [method, args] */
        public $calls = [];

        public function __construct($tmpBase)
        {
            parent::__construct(
                [
                    'api_key' => 'fake-key-for-tests',
                    'log_directory' => $tmpBase . '/logs',
                    'cache_directory' => $tmpBase . '/cache',
                ],
                new \CloudHost247\LTEProxy\Logger(['log_directory' => $tmpBase . '/logs'])
            );
        }

        private function play($method, array $args)
        {
            $this->calls[] = [$method, $args];
            if (array_key_exists($method, $this->script)) {
                $s = $this->script[$method];
                if ($s instanceof \Throwable) {
                    throw $s;
                }
                if (is_callable($s)) {
                    return call_user_func_array($s, $args);
                }
                return $s;
            }
            return ['data' => []];
        }

        public function callsTo($method)
        {
            $out = [];
            foreach ($this->calls as $c) {
                if ($c[0] === $method) {
                    $out[] = $c[1];
                }
            }
            return $out;
        }

        public function getAccountInfo(): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getBalance(): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getOrders(array $filters = []): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getOrderProxies(string $orderId): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getExpiredOrders(array $filters = []): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getAvailable1By1Proxies(): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getAvailableUsProxies(): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getAvailableNonUsProxies(): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function getAvailableUsCarrierProxies(?string $carrier = null): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function buyProxy1By1(array $params): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function buyProxy(array $params): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function updateClientIp(string $proxyId, string $clientIp): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function updateRegion(string $proxyId, string $region): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function updateCarrier(string $proxyId, string $carrier): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function updateProxyType(string $proxyId, string $proxyType): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function updateProxyAuth(string $proxyId, array $authParams): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function updateProxyRotation(string $proxyId, array $rotationParams): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function rotateProxyIp(string $proxyId): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function reveal1By1ProxyIp(string $proxyId): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function extendOrder(string $orderId, int $durationDays): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function cancelOrder(string $orderId, string $reason = ''): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function addTrial(array $params = []): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function testProxyAlive(string $proxyId): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
        public function testProxySpeed(string $proxyId): array
        {
            return $this->play(__FUNCTION__, func_get_args());
        }
    }
}
