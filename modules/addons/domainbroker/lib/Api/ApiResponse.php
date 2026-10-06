<?php
/**
 * Domain Broker — an API response.
 *
 * Responses are values, not side effects: the router returns one and only the
 * front controller sends it. That keeps the whole API testable without output
 * buffering.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api;

class ApiResponse
{
    /** @var int */
    public $status = 200;

    /** @var array */
    public $headers = [];

    /** @var array|string */
    public $body = [];

    /** @var bool when true the body is sent verbatim (CSV, file download) */
    public $raw = false;

    public function __construct($status = 200, $body = [], array $headers = [])
    {
        $this->status = (int) $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    public static function ok($data = [], array $meta = [])
    {
        return self::success(200, $data, $meta);
    }

    public static function created($data = [], array $meta = [])
    {
        return self::success(201, $data, $meta);
    }

    public static function accepted($data = [], array $meta = [])
    {
        return self::success(202, $data, $meta);
    }

    public static function noContent()
    {
        return new self(204, null);
    }

    public static function success($status, $data, array $meta = [])
    {
        $body = ['success' => true, 'data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }
        return new self($status, $body);
    }

    public static function error($status, $code, $message, array $details = [])
    {
        $body = ['success' => false, 'error' => [
            'code' => $code,
            'message' => $message,
        ]];
        if ($details) {
            $body['error']['details'] = $details;
        }
        return new self($status, $body);
    }

    /** A verbatim payload, e.g. a CSV export. */
    public static function download($body, $filename, $mime = 'text/csv; charset=UTF-8')
    {
        $response = new self(200, $body, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
        ]);
        $response->raw = true;
        return $response;
    }

    public function withHeader($name, $value)
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json()
    {
        return json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Emit the response. Only the front controller calls this. */
    public function send()
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            $headers = $this->headers;
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json; charset=UTF-8';
            }
            // The API is not a browsing surface: lock it down.
            $headers['X-Content-Type-Options'] = 'nosniff';
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate';
            $headers['Referrer-Policy'] = 'no-referrer';
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->status === 204) {
            return;
        }
        echo $this->raw ? (string) $this->body : $this->json();
    }
}
