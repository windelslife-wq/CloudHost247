<?php
/** All Ch247Ai exception classes in one file (autoloader resolves them here). */

namespace Ch247Ai\Core;

class Ch247AiException extends \RuntimeException
{
}
class ValidationException extends Ch247AiException
{
    protected $fields = [];
    public function __construct($message, array $fields = [])
    {
        parent::__construct($message);
        $this->fields = $fields;
    }
    public function fieldErrors()
    {
        return $this->fields;
    }
}
class NotFoundException extends Ch247AiException
{
}
class ForbiddenException extends Ch247AiException
{
}
class ProviderNotConfiguredException extends Ch247AiException
{
}
class ProviderException extends Ch247AiException
{
}
class ServiceUnavailableException extends Ch247AiException
{
}
class RateLimitException extends Ch247AiException
{
}
class BudgetExceededException extends Ch247AiException
{
}
class ApprovalRequiredException extends Ch247AiException
{
}
class Ch247AiRefused extends Ch247AiException
{
}
