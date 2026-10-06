<?php
/**
 * Fail-closed provider. Fails with CONFIGURATION_REQUIRED naming the exact
 * missing settings — it never invents an answer.
 */

namespace Ch247Ai\Model;

use Ch247Ai\Core\ProviderNotConfiguredException;

class NullProvider
{
    public function providerId()
    {
        return 'none';
    }
    public function isConfigured()
    {
        return false;
    }
    public function complete(array $messages, array $opts = [])
    {
        throw new ProviderNotConfiguredException(
            'CONFIGURATION_REQUIRED: no AI model provider is configured. An administrator must set '
            . 'the model endpoint(s) and model name(s) in Addon Modules → CloudHost247 AI → Settings, '
            . 'plus the CH247AI_API_KEY environment variable if the endpoint requires one. '
            . 'The AI layer refuses to answer without a provider rather than invent data.'
        );
    }
}
