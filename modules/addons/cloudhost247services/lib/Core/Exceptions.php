<?php
/**
 * CloudHost247 Services Suite — exception hierarchy.
 *
 * One file on purpose: each class is a marker with an optional machine code,
 * and splitting them would produce twelve near-empty files.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class ChsException extends \RuntimeException
{
    /** @var string stable machine-readable code for logs and API responses */
    protected $machineCode = 'chs_error';

    public function machineCode()
    {
        return $this->machineCode;
    }
}

class ConfigurationException extends ChsException
{
    protected $machineCode = 'configuration_error';
}

class ServiceUnavailableException extends ChsException
{
    protected $machineCode = 'service_unavailable';
}

/** Input failed validation. Carries per-field messages. */
class ValidationException extends ChsException
{
    protected $machineCode = 'validation_failed';

    /** @var array<string,string> */
    private $fieldErrors = [];

    public function __construct(array $fieldErrors, $message = '')
    {
        $this->fieldErrors = $fieldErrors;
        parent::__construct($message !== '' ? $message : 'Validation failed: ' . json_encode($fieldErrors));
    }

    public function fieldErrors()
    {
        return $this->fieldErrors;
    }
}

class NotFoundException extends ChsException
{
    protected $machineCode = 'not_found';
}

/** Authenticated but not permitted. */
class ForbiddenException extends ChsException
{
    protected $machineCode = 'forbidden';
}

/** The request must be retried; abuse control tripped. */
class RateLimitException extends ChsException
{
    protected $machineCode = 'rate_limited';
}

/** A state-machine transition that the current state does not allow. */
class InvalidTransitionException extends ChsException
{
    protected $machineCode = 'invalid_transition';
}

/** The same write arrived twice and was already applied. */
class DuplicateOperationException extends ChsException
{
    protected $machineCode = 'duplicate_operation';
}

/** An external provider is missing credentials or configuration. */
class ProviderNotConfiguredException extends ChsException
{
    protected $machineCode = 'provider_not_configured';
}

/** An external provider was called and failed. */
class ProviderException extends ChsException
{
    protected $machineCode = 'provider_error';
}
