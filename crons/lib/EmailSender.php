<?php
namespace CloudHost247\SoYouStart\Cron;

/** Dispatch a WHMCS client email without putting its merge data in module logs. */
final class EmailSender
{
    public static function send($template, $clientId, $customVars, $localApi = null, $moduleLogger = null)
    {
        $template = is_scalar($template) ? (string) $template : '';
        $clientId = (int) $clientId;
        $payload = [
            'messagename' => $template,
            'id' => $clientId,
            'customvars' => $customVars,
        ];

        if (!is_callable($localApi)) {
            $localApi = function ($request) {
                return \localAPI('SendEmail', $request);
            };
        }
        if (!is_callable($moduleLogger)) {
            $moduleLogger = function ($module, $action, $request, $response) {
                \logModuleCall($module, $action, $request, $response);
            };
        }

        try {
            $result = call_user_func($localApi, $payload);
        } catch (\Throwable $error) {
            // Preserve the legacy failure result without persisting exception or merge data.
            return null;
        }

        $apiResult = null;
        if (is_array($result) && isset($result['result']) && is_scalar($result['result'])) {
            $apiResult = (string) $result['result'];
        } elseif (is_object($result) && isset($result->result) && is_scalar($result->result)) {
            $apiResult = (string) $result->result;
        }
        $safeResult = is_string($apiResult) && strtolower($apiResult) === 'success' ? 'success' : 'error';

        // WHMCS module-call logs are durable and visible to administrators. Do not
        // pass customvars or the raw API response: either can contain credentials.
        $safeRequest = [
            'messagename' => $template,
            'id' => $clientId,
        ];
        $safeResponse = ['result' => $safeResult];
        try {
            call_user_func(
                $moduleLogger,
                'Soyoustart',
                'sending email for ' . $template,
                $safeRequest,
                $safeResponse
            );
        } catch (\Throwable $error) {
            // A logging outage must not turn a successful email dispatch into a retry.
        }

        return $result;
    }
}
