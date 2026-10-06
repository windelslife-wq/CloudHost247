<?php
/**
 * Model router — turns "fast" / "reasoning" profiles into a configured
 * provider, or into the fail-closed NullProvider when nothing is set.
 * It also decides when the answer must be metrics-only (no provider).
 */

namespace Ch247Ai\Model;

use Ch247Ai\Core\Ch247AiException;
use Ch247Ai\Core\ProviderNotConfiguredException;
use Ch247Ai\Core\Settings;

class ModelRouter
{
    const PROFILES = ['fast', 'reasoning'];

    /** @return object a provider with complete(array $messages, array $opts) */
    public static function forProfile($profile)
    {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new Ch247AiException('Unknown model profile: ' . $profile);
        }
        if (!Settings::bool('service_enabled', true) || Settings::bool('kill_switch', false)) {
            return new NullProvider();
        }
        $endpoint = Settings::string('model_' . $profile . '_endpoint');
        $model = Settings::string('model_' . $profile . '_model');
        if ($endpoint === '' || $model === '') {
            $endpoint = Settings::string('model_fast_endpoint');
            $model = Settings::string('model_fast_model');
        }
        if ($endpoint === '' || $model === '') {
            return new NullProvider();
        }
        return new OpenAiCompatibleProvider($endpoint, $model);
    }

    /** Is the copilot able to call a model at all right now? */
    public static function copilotConfigured()
    {
        try {
            $provider = self::forProfile('fast');
            return $provider->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
