<?php
namespace CloudHost247\Cloudflare\Core;

class CloudflareException extends \RuntimeException
{
    private $errorCode;
    private $httpStatus;
    private $retryAfter;

    public function __construct($errorCode, $message, $httpStatus = 0, $retryAfter = null, \Throwable $previous = null)
    {
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $this->retryAfter = $retryAfter === null ? null : (int) $retryAfter;
        parent::__construct((string) $message, 0, $previous);
    }
    public function errorCode() { return $this->errorCode; }
    public function httpStatus() { return $this->httpStatus; }
    public function retryAfter() { return $this->retryAfter; }
}

class ConfigurationException extends CloudflareException
{
    public function __construct($message = 'Cloudflare is not configured.')
    {
        parent::__construct('CONFIGURATION_REQUIRED', $message, 0);
    }
}

class ValidationException extends CloudflareException
{
    public function __construct($message)
    {
        parent::__construct('VALIDATION_ERROR', $message, 422);
    }
}

class NotFoundException extends CloudflareException
{
    public function __construct($message = 'The requested Cloudflare service was not found.')
    {
        parent::__construct('NOT_FOUND', $message, 404);
    }
}

class AuthorizationException extends CloudflareException
{
    public function __construct($message = 'You are not authorized to manage this Cloudflare service.')
    {
        parent::__construct('FORBIDDEN', $message, 403);
    }
}
