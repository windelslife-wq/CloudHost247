<?php
/**
 * Domain Broker — exception hierarchy.
 *
 * Every exception carries a stable machine code so the API layer can map it to
 * an HTTP status and an error identifier without string matching.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class DomainBrokerException extends \RuntimeException
{
    /** @var string Stable machine readable error code. */
    protected $errorCode = 'domain_broker_error';

    /** @var int Suggested HTTP status. */
    protected $httpStatus = 500;

    /** @var array Structured context (never contains secrets). */
    protected $context = [];

    public function __construct($message = '', array $context = [], $previous = null)
    {
        parent::__construct($message ?: 'Domain Broker error', 0, $previous);
        $this->context = $context;
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function httpStatus()
    {
        return $this->httpStatus;
    }

    public function context()
    {
        return $this->context;
    }
}

/** Input failed validation. */
class ValidationException extends DomainBrokerException
{
    protected $errorCode = 'validation_failed';
    protected $httpStatus = 422;

    /** @var array field => message */
    protected $errors = [];

    public function __construct($message = 'The submitted data is invalid.', array $errors = [])
    {
        parent::__construct($message, ['errors' => $errors]);
        $this->errors = $errors;
    }

    public function errors()
    {
        return $this->errors;
    }
}

/** Caller is not authenticated. */
class AuthenticationException extends DomainBrokerException
{
    protected $errorCode = 'authentication_required';
    protected $httpStatus = 401;
}

/** Caller is authenticated but lacks the permission / ownership. */
class AuthorizationException extends DomainBrokerException
{
    protected $errorCode = 'forbidden';
    protected $httpStatus = 403;
}

/** Record does not exist (or is not visible to the caller). */
class NotFoundException extends DomainBrokerException
{
    protected $errorCode = 'not_found';
    protected $httpStatus = 404;
}

/** The requested state change is not legal from the current state. */
class InvalidTransitionException extends DomainBrokerException
{
    protected $errorCode = 'invalid_transition';
    protected $httpStatus = 409;
}

/** A conflicting operation is already in flight / the resource changed. */
class ConflictException extends DomainBrokerException
{
    protected $errorCode = 'conflict';
    protected $httpStatus = 409;
}

/** Too many requests. */
class RateLimitException extends DomainBrokerException
{
    protected $errorCode = 'rate_limited';
    protected $httpStatus = 429;

    /** @var int */
    public $retryAfter = 60;

    public function __construct($message = 'Too many requests.', $retryAfter = 60)
    {
        parent::__construct($message, ['retry_after' => (int) $retryAfter]);
        $this->retryAfter = (int) $retryAfter;
    }
}

/** A payment / escrow operation failed. */
class PaymentException extends DomainBrokerException
{
    protected $errorCode = 'payment_failed';
    protected $httpStatus = 402;
}

/** Configuration is missing or invalid (e.g. no encryption key). */
class ConfigurationException extends DomainBrokerException
{
    protected $errorCode = 'configuration_error';
    protected $httpStatus = 500;
}

/** Upload rejected by the file-security policy. */
class FileRejectedException extends DomainBrokerException
{
    protected $errorCode = 'file_rejected';
    protected $httpStatus = 422;
}
