<?php
/**
 * Unconfigured AI provider. Fails loudly and honestly — the UI shows the
 * operator exactly which settings are missing instead of pretending to build.
 *
 * @package Chs\Providers\Ai
 */

namespace Chs\Providers\Ai;

use Chs\Core\ProviderNotConfiguredException;

class NullAiProvider implements AiProviderInterface
{
    public function providerId()
    {
        return 'none';
    }

    public function isConfigured()
    {
        return false;
    }

    public function generateSiteOutline($brief, array $opts = [])
    {
        throw new ProviderNotConfiguredException(
            'The AI website builder requires a provider: set ai_enabled=1 plus the endpoint, model and '
            . 'CHS_AI_API_KEY environment variable in Addon Modules → CloudHost247 Services → Settings.'
        );
    }
}
