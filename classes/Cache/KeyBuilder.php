<?php
namespace UKModules\ADIF\Cache;

/**
 * Builds cache namespaces and keys.
 *
 * Namespace encodes scope so a project-scoped entry can never be read from
 * another project:   system | project:<pid> | record:<pid>:<recordId>
 *
 * Key encodes provider + version + input. Identifying inputs are HMAC'd with
 * a secret that should live OUTSIDE the database (env var / file), so a DB dump
 * alone can't be used to brute-force addresses back out of the keys.
 */
final class KeyBuilder
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Cache HMAC secret must be at least 32 bytes.');
        }
    }

    public function namespace(string $scope, FetchContext $ctx): string
    {
        return match ($scope) {
            CachePolicy::SCOPE_SYSTEM  => 'system',
            CachePolicy::SCOPE_PROJECT => 'project:' . $ctx->projectId,
            CachePolicy::SCOPE_RECORD  => 'record:' . $ctx->projectId . ':' . $ctx->recordId,
        };
    }

    public function key(string $providerId, string $version, string $normalizedInput, string $sensitivity): string
    {
        $input = $sensitivity === CachePolicy::SENSITIVITY_IDENTIFYING
            ? 'h:' . hash_hmac('sha256', $normalizedInput, $this->secret)
            : 'p:' . $normalizedInput;

        return $providerId . '|' . $version . '|' . $input;
    }
}
