<?php
/**
 * Domain Broker — a parsed API request.
 *
 * Built once from the superglobals (or constructed directly in tests), so the
 * router and controllers never touch $_GET / $_POST / php://input themselves.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api;

use DomainBroker\Core\Http;
use DomainBroker\Core\Str;

class ApiRequest
{
    /** @var string */
    public $method = 'GET';

    /** @var string normalised path, e.g. "requests/12/offers" */
    public $path = '';

    /** @var array */
    public $query = [];

    /** @var array decoded body */
    public $body = [];

    /** @var array header name (canonical) => value */
    public $headers = [];

    /** @var string */
    public $rawBody = '';

    /** @var array uploaded files, $_FILES shaped */
    public $files = [];

    /** @var string */
    public $ip = '';

    /** @var array path parameters filled in by the router */
    public $params = [];

    public function __construct($method, $path, array $query = [], array $body = [], array $headers = [], $rawBody = '')
    {
        $this->method = strtoupper((string) $method);
        $this->path = self::normalisePath($path);
        $this->query = $query;
        $this->body = $body;
        $this->headers = self::canonicalHeaders($headers);
        $this->rawBody = (string) $rawBody;
        $this->ip = (string) Http::clientIp();
    }

    /** Build from the live request. */
    public static function capture()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

        // The path arrives either as PATH_INFO (pretty URLs) or ?path= so the
        // endpoint works on any WHMCS install without rewrite rules.
        $path = '';
        if (!empty($_SERVER['PATH_INFO'])) {
            $path = (string) $_SERVER['PATH_INFO'];
        } elseif (isset($_GET['path'])) {
            $path = (string) $_GET['path'];
        }

        $raw = Http::rawBody();
        $body = [];
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        } elseif (!empty($_POST)) {
            $body = $_POST;
        } elseif ($raw !== '' && strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($raw, $body);
        }

        $query = $_GET;
        unset($query['path']);

        $request = new self($method, $path, $query, is_array($body) ? $body : [], self::serverHeaders(), $raw);
        $request->files = isset($_FILES) ? $_FILES : [];
        return $request;
    }

    public function header($name, $default = '')
    {
        $key = self::canonical($name);
        return isset($this->headers[$key]) ? $this->headers[$key] : $default;
    }

    public function bearerToken()
    {
        $auth = (string) $this->header('Authorization');
        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $alt = (string) $this->header('X-Api-Token');
        return $alt !== '' ? trim($alt) : '';
    }

    public function idempotencyKey()
    {
        $key = (string) $this->header('Idempotency-Key');
        if ($key === '' && isset($this->body['idempotency_key'])) {
            $key = (string) $this->body['idempotency_key'];
        }
        return Str::clip(trim($key), 190);
    }

    /** Body value, falling back to the query string for GET-style filters. */
    public function input($key, $default = null)
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    public function param($key, $default = null)
    {
        return isset($this->params[$key]) ? $this->params[$key] : $default;
    }

    public function intParam($key)
    {
        return (int) $this->param($key, 0);
    }

    /** Body merged with the query string — what a controller usually wants. */
    public function all()
    {
        return array_merge($this->query, $this->body);
    }

    public function pagination()
    {
        $perPage = (int) $this->input('per_page', 25);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, (int) $this->input('page', 1));
        return ['limit' => $perPage, 'offset' => ($page - 1) * $perPage, 'page' => $page, 'per_page' => $perPage];
    }

    public static function normalisePath($path)
    {
        $path = trim((string) $path);
        $path = strtok($path, '?');
        $path = trim((string) $path, "/ \t\n\r\0\x0B");
        return preg_replace('#/{2,}#', '/', (string) $path);
    }

    public function segments()
    {
        return $this->path === '' ? [] : explode('/', $this->path);
    }

    protected static function canonicalHeaders(array $headers)
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[self::canonical($name)] = is_array($value) ? reset($value) : $value;
        }
        return $out;
    }

    protected static function canonical($name)
    {
        return strtolower(str_replace('_', '-', (string) $name));
    }

    protected static function serverHeaders()
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                $headers = $all;
            }
        }
        if (!$headers && isset($_SERVER) && is_array($_SERVER)) {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $headers[str_replace('_', '-', substr($key, 5))] = $value;
                }
            }
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
            }
        }
        return $headers;
    }
}
