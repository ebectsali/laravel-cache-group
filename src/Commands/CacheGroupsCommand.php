<?php

namespace Ebects\LaravelCacheGroup\Commands;

use Ebects\LaravelCacheGroup\CacheRegistry;
use Illuminate\Console\Command;

class CacheGroupsCommand extends Command
{
    protected $signature = 'cache:groups
        {--prefix= : Filter by prefix (partial match)}
        {--scope= : Filter by scope type}';

    protected $description = 'List all registered cache groups';

    public function handle(): int
    {
        $groups = CacheRegistry::generateCacheGroups();

        if (empty($groups)) {
            $this->warn('No cache groups registered.');
            return self::SUCCESS;
        }

        $filterPrefix = $this->option('prefix');
        $filterScope = $this->option('scope');

        $rows = [];
        $index = 1;

        foreach ($groups as $prefix => $info) {
            $config = $info['config'];
            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $ttl = $config['ttl'] ?? config('cache-group.default_ttl', 3600);
            $alsoInvalidate = $config['also_invalidate'] ?? [];
            $cacheClasses = count($info['cache_classes']);
            $invalidateClasses = count($info['invalidate_classes']);

            if ($filterPrefix && ! str_contains($prefix, $filterPrefix)) continue;
            if ($filterScope && $scope !== $filterScope) continue;

            $rows[] = [
                $index++, $prefix, $scope,
                $this->formatTtl($ttl),
                $cacheClasses, $invalidateClasses,
                empty($alsoInvalidate) ? '-' : implode(', ', (array) $alsoInvalidate),
            ];
        }

        if (empty($rows)) {
            $this->warn('No cache groups match the given filters.');
            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Prefix', 'Scope', 'TTL', 'Readers', 'Invalidators', 'Also Invalidate'],
            $rows
        );

        $this->newLine();
        $this->info("Total: " . count($rows) . " groups registered");

        return self::SUCCESS;
    }

    protected function formatTtl(int $seconds): string
    {
        if ($seconds >= 3600) return ($seconds / 3600) . 'h';
        if ($seconds >= 60) return ($seconds / 60) . 'm';
        return $seconds . 's';
    }
}
