<?php
/** All Ch247Mkt exception classes in one file (autoloader resolves them here). */

namespace Ch247Mkt\Core;

class Ch247MktException extends \RuntimeException
{
}
class ValidationException extends Ch247MktException
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
class NotFoundException extends Ch247MktException
{
}
class ForbiddenException extends Ch247MktException
{
}
class ProviderNotConfiguredException extends Ch247MktException
{
}
class ProviderException extends Ch247MktException
{
}
class ServiceUnavailableException extends Ch247MktException
{
}
class RateLimitException extends Ch247MktException
{
}
class BudgetExceededException extends Ch247MktException
{
}
class ApprovalRequiredException extends Ch247MktException
{
}
class Ch247MktRefused extends Ch247MktException
{
}
