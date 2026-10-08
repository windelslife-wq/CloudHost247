<?php
/** Strictly normalized provider-resource response. Raw adapter payloads are not persisted. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\ValidationException;

class ProviderResource
{
    public static function normalise(array $input, $expectedId = null)
    {
        $id = trim((string) (isset($input['id']) ? $input['id'] : ''));
        if ($id === '' && $expectedId !== null) {
            $id = trim((string) $expectedId);
        }
        if ($id === '' || strlen($id) > 191 || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new ProviderOperationException('The provider did not return a valid resource identifier.');
        }
        if ($expectedId !== null && (string) $expectedId !== $id) {
            throw new ProviderOperationException('The provider returned a different resource than the requested server.');
        }

        $status = strtolower(trim((string) (isset($input['status']) ? $input['status'] : '')));
        if ($status === '' || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $status)) {
            throw new ProviderOperationException('The provider did not return a valid resource state.');
        }

        $ipv4 = self::ip(isset($input['ipv4']) ? $input['ipv4'] : null, FILTER_FLAG_IPV4, 'IPv4');
        $ipv6 = self::ip(isset($input['ipv6']) ? $input['ipv6'] : null, FILTER_FLAG_IPV6, 'IPv6');
        $operationId = isset($input['operation_id']) ? trim((string) $input['operation_id']) : '';
        if (strlen($operationId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $operationId)) {
            throw new ProviderOperationException('The provider returned an invalid operation identifier.');
        }

        return [
            'id' => $id,
            'status' => $status,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'operation_id' => $operationId !== '' ? $operationId : null,
        ];
    }

    public static function isReady(array $resource)
    {
        return in_array((string) $resource['status'], ['running', 'ready', 'active'], true);
    }

    public static function isFailed(array $resource)
    {
        return in_array((string) $resource['status'], ['error', 'failed', 'deleted'], true);
    }

    private static function ip($value, $flag, $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            throw new ProviderOperationException('The provider returned an invalid ' . $label . ' address.');
        }
        return $value;
    }
}
