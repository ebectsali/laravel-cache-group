<?php

namespace Ebects\LaravelCacheGroup\Commands;

use Ebects\LaravelCacheGroup\CacheKeyBuilder;
use Ebects\LaravelCacheGroup\CacheRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CacheInspectCommand extends Command
{
    protected $signature = 'cache:inspect
        {prefix? : Cache group prefix to inspect}
        {--scope= : Filter by scope identifier}
        {--identifier= : Filter by scope identifier value}
        {--summary : Show summary only (all prefixes)}';

    protected $description = 'Inspect Redis cache keys for a cache group (read-only)';

    public function handle(): int
    {
        if ($this->option('summary')) {
            return $this->showSummary();
        }

        $prefix = $this->argument('prefix');

        if (! $prefix) {
            $this->error('Please specify a prefix or use --summary');
            return self::FAILURE;
        }

        $config = CacheRegistry::getGroupConfig($prefix);
        if ($config === null) {
            $this->error("Cache group '{$prefix}' not found in registry.");
            return self::FAILURE;
        }

        return $this->inspectPrefix($prefix, $config);
    }

    protected function showSummary(): int
    {
        $groups = CacheRegistry::generateRoutesConfig();

        if (empty($groups)) {
            $this->warn('No cache groups registered.');
            return self::SUCCESS;
        }

        $this->info('Scanning Redis keys...');
        $this->newLine();

        $rows = [];
        $totalKeys = 0;
        $totalSize = 0;

        foreach ($groups as $prefix => $config) {
            $scope = $config['scope'] ?? 'global';
            $cachePrefix = CacheKeyBuilder::getCachePrefix();
            $pattern = "{$cachePrefix}:cache:*:{$prefix}:*";
            $stats = $this->scanForStats($pattern);

            $rows[] = [
                $prefix, $scope,
                number_format($stats['count']),
                $this->formatBytes($stats['size']),
                $stats['avg_ttl'] > 0 ? $stats['avg_ttl'] . 's' : 'N/A',
            ];

            $totalKeys += $stats['count'];
            $totalSize += $stats['size'];
        }

        $this->table(['Prefix', 'Scope', 'Key Count', 'Total Size', 'Avg TTL'], $rows);
        $this->newLine();
        $this->info("Total: " . number_format($totalKeys) . " keys | " . $this->formatBytes($totalSize));

        return self::SUCCESS;
    }

    protected function inspectPrefix(string $prefix, array $config): int
    {
        $scope = $config['scope'] ?? 'global';
        $cachePrefix = CacheKeyBuilder::getCachePrefix();

        $filterScope = $this->option('scope');
        $filterIdentifier = $this->option('identifier');

        $pattern = ($filterScope && $filterIdentifier)
            ? CacheKeyBuilder::buildPattern($prefix, $filterScope, $filterIdentifier)
            : "{$cachePrefix}:cache:*:{$prefix}:*";

        $this->info("Prefix: {$prefix} | Scope: {$scope} | Pattern: {$pattern}");
        $this->newLine();

        $keys = $this->scanForKeys($pattern);

        if (empty($keys)) {
            $this->warn('No cache keys found.');
            return self::SUCCESS;
        }

        $rows = [];
        $totalSize = 0;
        $ttls = [];

        foreach (array_slice($keys, 0, 100) as $index => $key) {
            $info = $this->getKeyInfo($key);
            $rows[] = [
                $index + 1,
                strlen($key) > 70 ? '...' . substr($key, -67) : $key,
                $info['ttl'] >= 0 ? $info['ttl'] . 's' : 'PERSIST',
                $this->formatBytes($info['size']),
            ];
            $totalSize += $info['size'];
            if ($info['ttl'] >= 0) $ttls[] = $info['ttl'];
        }

        $this->table(['#', 'Key', 'TTL', 'Size'], $rows);

        $avgTtl = ! empty($ttls) ? (int) (array_sum($ttls) / count($ttls)) : 0;
        $this->newLine();
        $this->info(sprintf('Total: %d keys | %s | Avg TTL: %ds', count($keys), $this->formatBytes($totalSize), $avgTtl));

        if (count($keys) > 100) {
            $this->warn('Showing first 100 keys. Total: ' . count($keys));
        }

        return self::SUCCESS;
    }

    protected function scanForKeys(string $pattern): array
    {
        $keys = [];
        $cursor = 0;
        $iterations = 0;

        try {
            $redis = Redis::connection();
            do {
                $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
                $cursor = $result[0];
                if (! empty($result[1])) $keys = array_merge($keys, $result[1]);
                if (++$iterations >= 50) break;
            } while ($cursor != 0);
        } catch (\Exception $e) {
            $this->error('Redis scan failed: ' . $e->getMessage());
        }

        return $keys;
    }

    protected function scanForStats(string $pattern): array
    {
        $keys = $this->scanForKeys($pattern);
        $totalSize = 0;
        $ttls = [];

        foreach ($keys as $key) {
            $info = $this->getKeyInfo($key);
            $totalSize += $info['size'];
            if ($info['ttl'] >= 0) $ttls[] = $info['ttl'];
        }

        return [
            'count' => count($keys),
            'size' => $totalSize,
            'avg_ttl' => ! empty($ttls) ? (int) (array_sum($ttls) / count($ttls)) : 0,
        ];
    }

    protected function getKeyInfo(string $key): array
    {
        try {
            $redis = Redis::connection();
            return ['ttl' => (int) $redis->ttl($key), 'size' => (int) ($redis->strlen($key) ?: 0)];
        } catch (\Exception $e) {
            return ['ttl' => -1, 'size' => 0];
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
