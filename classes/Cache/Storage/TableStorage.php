<?php
namespace UKModules\ADIF\Cache\Storage;

use UKModules\ADIF\Cache\CacheEntry;

/**
 * Persistent backend on a module-owned table. Chosen over the EM log table
 * because API responses can exceed what a log parameter holds, and lookups
 * by (namespace, key) need an index.
 *
 * $query has the signature of the EM framework's $module->query($sql, $params),
 * so this class has no hard REDCap dependency.
 */
final class TableStorage implements StorageBackend
{
    public const SCHEMA_VERSION = 1;

    /** @var callable(string, array): mixed */
    private $query;

    public function __construct(callable $query, private string $table = 'adif_api_cache')
    {
        $this->query = $query;
    }

    public function createTableSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS {$this->table} (
            ns           VARCHAR(100) NOT NULL,
            cache_key    VARCHAR(255) NOT NULL,
            provider     VARCHAR(50)  NOT NULL,
            version      VARCHAR(50)  NOT NULL,
            retrieved_at INT UNSIGNED NOT NULL,
            origin       VARCHAR(20)  NOT NULL,
            payload      MEDIUMTEXT   NOT NULL,
            PRIMARY KEY (ns, cache_key),
            KEY provider_idx (ns, provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function ensureTable(): void
    {
        ($this->query)($this->createTableSql(), []);
    }

    public function getMany(string $namespace, array $keys): array
    {
        if (!$keys) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values($keys), 500) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '?'));
            $res = ($this->query)(
                "SELECT cache_key, payload FROM {$this->table} WHERE ns = ? AND cache_key IN ($ph)",
                array_merge([$namespace], $chunk)
            );
            while ($row = $res->fetch_assoc()) {
                $decoded = json_decode($row['payload'], true);
                if (is_array($decoded)) {
                    $out[$row['cache_key']] = CacheEntry::fromArray($decoded);
                }
            }
        }
        return $out;
    }

    public function putMany(string $namespace, array $entries): void
    {
        foreach ($entries as $key => $e) {
            ($this->query)(
                "REPLACE INTO {$this->table} (ns, cache_key, provider, version, retrieved_at, origin, payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$namespace, $key, $e->providerId, $e->version, $e->retrievedAt, $e->origin, json_encode($e->toArray())]
            );
        }
    }

    public function purge(string $namespace, ?string $providerId = null): int
    {
        $sql    = "DELETE FROM {$this->table} WHERE ns = ?" . ($providerId ? ' AND provider = ?' : '');
        $params = $providerId ? [$namespace, $providerId] : [$namespace];
        ($this->query)($sql, $params);
        return -1; // TODO: affected-row count from the EM query wrapper
    }

    public function purgeProjectData(int $projectId): int
    {
        // "record:5:%" can't match project 50's "record:50:..." - the colon ends the id
        $where  = 'ns = ? OR ns LIKE ?';
        $params = ["project:$projectId", "record:$projectId:%"];
        $row = ($this->query)("SELECT COUNT(*) AS n FROM {$this->table} WHERE $where", $params)->fetch_assoc();
        $count = (int) ($row['n'] ?? 0);
        if ($count > 0) {
            ($this->query)("DELETE FROM {$this->table} WHERE $where", $params);
        }
        return $count;
    }

    public function usageByProvider(int $projectId): array
    {
        return $this->usage(
            'ns = ? OR ns LIKE ?',
            ["project:$projectId", "record:$projectId:%"]
        );
    }

    public function sharedUsageByProvider(array $providerIds): array
    {
        if (!$providerIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($providerIds), '?'));
        return $this->usage("ns = 'system' AND provider IN ($ph)", array_values($providerIds));
    }

    public function purgeProjectProvider(int $projectId, string $providerId): int
    {
        $where  = '(ns = ? OR ns LIKE ?) AND provider = ?';
        $params = ["project:$projectId", "record:$projectId:%", $providerId];
        $row = ($this->query)("SELECT COUNT(*) AS n FROM {$this->table} WHERE $where", $params)->fetch_assoc();
        $count = (int) ($row['n'] ?? 0);
        if ($count > 0) {
            ($this->query)("DELETE FROM {$this->table} WHERE $where", $params);
        }
        return $count;
    }

    /** Entry counts, stored size (the payload column, in bytes) and age range by provider */
    private function usage(string $where, array $params): array
    {
        $res = ($this->query)(
            "SELECT provider, COUNT(*) AS entries, SUM(LENGTH(payload)) AS bytes,"
            . " MIN(retrieved_at) AS oldest, MAX(retrieved_at) AS newest"
            . " FROM {$this->table} WHERE $where GROUP BY provider",
            $params
        );
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(string) $row['provider']] = [
                'entries' => (int) $row['entries'],
                'bytes'   => (int) $row['bytes'],
                'oldest'  => (int) $row['oldest'],
                'newest'  => (int) $row['newest'],
            ];
        }
        return $out;
    }

    public function projectIds(): array
    {
        $res = ($this->query)(
            "SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(ns, ':', 2), ':', -1) AS pid FROM {$this->table}"
            . " WHERE ns LIKE 'project:%' OR ns LIKE 'record:%'",
            []
        );
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            if (ctype_digit((string) $row['pid'])) {
                $ids[] = (int) $row['pid'];
            }
        }
        return $ids;
    }
}
