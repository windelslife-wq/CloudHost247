<?php
/**
 * CloudHost247 App Cloud — exception hierarchy.
 *
 * Every failure the platform can report carries a stable machine code and a
 * human message. The API maps exceptions onto HTTP status + `error.code`; the
 * deployment engine records `error_code`/`error_message` on the deployment and
 * on the exact step that failed. Nothing is thrown as a bare \Exception, so a
 * caller can always distinguish "the customer did something wrong" from "the
 * infrastructure did".
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class AppsException extends \RuntimeException
{
    /** @var string stable machine code, e.g. DEPLOYMENT_SERVER_UNAVAILABLE */
    protected $code_name = 'APPS_ERROR';

    /** @var int HTTP status the API should return */
    protected $status = 500;

    /** @var array safe context (already redacted by the thrower) */
    protected $context = [];

    public function __construct($message = '', array $context = [], $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * Machine-readable error code (never localised, safe to branch on).
     *
     * A thrower may supply a more specific code in `context['error_code']`
     * (SERVER_CAPACITY_LOCKED, AGENT_NOT_REGISTERED, …); otherwise the class code
     * applies. Either way callers get one stable string per failure mode.
     */
    public function errorCode()
    {
        if (isset($this->context['error_code']) && is_string($this->context['error_code'])
            && $this->context['error_code'] !== '') {
            return $this->context['error_code'];
        }
        return $this->code_name;
    }

    public function status()
    {
        return $this->status;
    }

    public function context()
    {
        return $this->context;
    }

    /** True when retrying the same job could plausibly succeed. */
    public function isRetryable()
    {
        return false;
    }

    public function toArray()
    {
        return [
            'code' => $this->errorCode(),
            'message' => $this->getMessage(),
            'status' => $this->status,
            'context' => $this->context,
            'retryable' => $this->isRetryable(),
        ];
    }
}

/** The module is misconfigured (missing key, missing table, bad setting). */
class ConfigurationException extends AppsException
{
    protected $code_name = 'CONFIGURATION_ERROR';
    protected $status = 500;
}

/** Caller input failed validation. */
class ValidationException extends AppsException
{
    protected $code_name = 'VALIDATION_FAILED';
    protected $status = 422;
}

/** No/invalid credentials presented. */
class AuthenticationException extends AppsException
{
    protected $code_name = 'AUTHENTICATION_FAILED';
    protected $status = 401;
}

/** Authenticated but not permitted. */
class AuthorizationException extends AppsException
{
    protected $code_name = 'AUTHORIZATION_FAILED';
    protected $status = 403;
}

/** Requested record does not exist (or is not visible to this actor). */
class NotFoundException extends AppsException
{
    protected $code_name = 'NOT_FOUND';
    protected $status = 404;
}

/** Two writers collided, or an idempotency key was reused with a new payload. */
class ConflictException extends AppsException
{
    protected $code_name = 'CONFLICT';
    protected $status = 409;
}

/** Too many requests from this principal. */
class RateLimitException extends AppsException
{
    protected $code_name = 'RATE_LIMITED';
    protected $status = 429;
}

/** The operation is not allowed in the current state of the state machine. */
class StateException extends AppsException
{
    protected $code_name = 'INVALID_STATE_TRANSITION';
    protected $status = 409;
}

/** A deployment step failed. Retryable subclasses drive the queue's backoff. */
class DeploymentException extends AppsException
{
    protected $code_name = 'DEPLOYMENT_FAILED';
    protected $status = 500;
}

class ServerUnavailableException extends DeploymentException
{
    protected $code_name = 'DEPLOYMENT_SERVER_UNAVAILABLE';

    public function isRetryable()
    {
        return true;
    }
}

class ResourceInsufficientException extends DeploymentException
{
    protected $code_name = 'DEPLOYMENT_RESOURCE_INSUFFICIENT';
}

class ImagePullException extends DeploymentException
{
    protected $code_name = 'APPLICATION_IMAGE_PULL_FAILED';

    public function isRetryable()
    {
        return true;
    }
}

class AgentException extends DeploymentException
{
    protected $code_name = 'AGENT_REQUEST_FAILED';

    public function isRetryable()
    {
        return true;
    }
}

class HealthCheckException extends DeploymentException
{
    protected $code_name = 'HEALTH_CHECK_FAILED';

    public function isRetryable()
    {
        return true;
    }
}

/** Domain/DNS/SSL failures. */
class DomainException extends AppsException
{
    protected $code_name = 'DOMAIN_VERIFICATION_FAILED';
    protected $status = 422;
}

class SslException extends AppsException
{
    protected $code_name = 'SSL_PROVISION_FAILED';
    protected $status = 500;

    public function isRetryable()
    {
        return true;
    }
}

/** Billing gate failures — provisioning must never proceed on these. */
class PaymentException extends AppsException
{
    protected $code_name = 'PAYMENT_NOT_CONFIRMED';
    protected $status = 402;
}

/** cPanel/WHM/UAPI failures. */
class CpanelException extends AppsException
{
    protected $code_name = 'CPANEL_PROVISIONING_FAILED';
    protected $status = 502;

    public function isRetryable()
    {
        return true;
    }
}

/** Kubernetes API failures. */
class KubernetesException extends AppsException
{
    protected $code_name = 'KUBERNETES_PROVISIONING_FAILED';
    protected $status = 502;

    public function isRetryable()
    {
        return true;
    }
}

/** Backup / restore / object storage failures. */
class BackupException extends AppsException
{
    protected $code_name = 'BACKUP_FAILED';
    protected $status = 500;

    public function isRetryable()
    {
        return true;
    }
}
