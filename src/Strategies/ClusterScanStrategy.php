<?php

namespace Ebects\LaravelCacheGroup\Strategies;

use Ebects\LaravelCacheGroup\CacheKeyBuilder;
use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * ClusterScanStrategy
 *
 * Uses SCAN + DEL per cluster node for invalidation.
 * Works with Redis Cluster where Cache::tags() is not supported.
 *
 * Automatically resolves cluster hosts from Laravel Redis config.
 */
class ClusterScanStrategy implements InvalidationStrategy
{
    /**
     * {@inheritdoc}
     */
    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int
    {
        $pattern = CacheKeyBuilder::buildClusterPattern($prefix, $scope, $identifier);

        $this->debug('Cluster invalidation starting', [
            'prefix' => $prefix,
            'scope' => $scope,
            'identifier' => $identifier,
            'pattern' => $pattern,
        ]);

        $deleted = $this->scanAndDelete($pattern);

        $this->debug('Cluster invalidation completed', [
            'prefix' => $prefix,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateAll(string $prefix, string $scope): int
    {
        // Use wildcard for scope identifier to match all
        $pattern = CacheKeyBuilder::buildClusterPattern($prefix, $scope, '*');

        $this->debug('Cluster invalidation all starting', [
            'prefix' => $prefix,
            'scope' => $scope,
            'pattern' => $pattern,
        ]);

        $deleted = $this->scanAndDelete($pattern);

        $this->debug('Cluster invalidation all completed', [
            'prefix' => $prefix,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateResource(string $prefix, string $scope = 'global', ?string $identifier = null): int
    {
        $pattern = CacheKeyBuilder::buildClusterResourcePattern($prefix, $scope, $identifier);

        $deleted = $this->scanAndDelete($pattern);

        $this->debug('Cluster resource invalidation completed', [
            'prefix' => $prefix,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        $hosts = $this->resolveClusterHosts();

        return ! empty($hosts);
    }

    /**
     * Scan and delete keys matching pattern across all cluster nodes.
     *
     * @param string $pattern Hash-aware pattern
     * @return int Total keys deleted
     */
    protected function scanAndDelete(string $pattern): int
    {
        $totalDeleted = 0;
        $hosts = $this->resolveClusterHosts();
        $maxIterations = (int) config('cache-group.cluster_scan.max_iterations', 100);
        $scanCount = (int) config('cache-group.cluster_scan.scan_count', 100);

        foreach ($hosts as $host) {
            try {
                $client = $this->createNodeClient($host);
                $nodeDeleted = 0;
                $cursor = 0;
                $iterations = 0;

                do {
                    $result = $client->scan($cursor, [
                        'MATCH' => $pattern,
                        'COUNT' => $scanCount,
                    ]);

                    $cursor = $result[0];
                    $keys = $result[1];

                    if (! empty($keys)) {
                        // Delete one by one to avoid cross-slot errors
                        foreach ($keys as $key) {
                            try {
                                $client->del($key);
                                $nodeDeleted++;
                            } catch (\Exception $e) {
                                // Skip individual key failures
                                $this->debug('Failed to delete key', [
                                    'key' => $key,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

                    $iterations++;

                    if ($iterations >= $maxIterations) {
                        Log::warning('CacheGroup: Max SCAN iterations reached', [
                            'host' => $host,
                            'pattern' => $pattern,
                            'iterations' => $iterations,
                            'deleted_so_far' => $nodeDeleted,
                        ]);
                        break;
                    }

                } while ($cursor != 0);

                $totalDeleted += $nodeDeleted;

                // Clean up connection
                $client->disconnect();

            } catch (\Exception $e) {
                Log::warning('CacheGroup: Failed to scan cluster node', [
                    'host' => $host,
                    'pattern' => $pattern,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $totalDeleted;
    }

    /**
     * Create a Predis client for a specific cluster node.
     *
     * Uses config() instead of env() for config:cache compatibility.
     *
     * @param string $host
     * @return \Predis\Client
     */
    protected function createNodeClient(string $host, ?int $port = null): \Predis\Client
    {
        $connection = config('cache-group.redis_connection', 'cache');

        // 1. Try flat connection config: database.redis.{connection}
        $redisConfig = config("database.redis.{$connection}", []);

        // 2. Fallback to cluster node config: database.redis.clusters.{connection}.0
        if (empty($redisConfig)) {
            $redisConfig = config("database.redis.clusters.{$connection}.0", []);
        }

        // 3. Fallback to global cluster parameters for password
        $password = $redisConfig['password'] ?? null;
        if ($password === null) {
            $password = config('database.redis.options.parameters.password');
        }

        return new \Predis\Client([
            'scheme' => $redisConfig['scheme'] ?? 'tcp',
            'host' => $host,
            'port' => $port ?? (int) ($redisConfig['port'] ?? 6379),
            'password' => $password,
            'database' => (int) ($redisConfig['database'] ?? 0),
            'timeout' => (float) config('cache-group.cluster_scan.connection_timeout', 5),
            'read_write_timeout' => (float) config('cache-group.cluster_scan.read_write_timeout', 10),
        ]);
    }

    /**
     * Resolve Redis Cluster hosts — auto-discover via CLUSTER NODES, fallback to config.
     *
     * @return array<string>
     */
    protected function resolveClusterHosts(): array
    {
        $discovered = $this->discoverClusterNodes();
        if (! empty($discovered)) {
            return $discovered;
        }

        return $this->resolveHostsFromConfig();
    }

    /**
     * Discover actual Redis Cluster master nodes via CLUSTER NODES command.
     *
     * Connects to any seed host (from config or service DNS), runs CLUSTER NODES,
     * and parses the response to extract all master node IPs.
     *
     * Results are cached in-memory for the request lifecycle.
     *
     * @return array<string>
     */
    protected function discoverClusterNodes(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $seedHosts = $this->resolveHostsFromConfig();

        foreach ($seedHosts as $seedHost) {
            try {
                $client = $this->createNodeClient($seedHost);
                $response = $client->executeRaw(['CLUSTER', 'NODES']);
                $client->disconnect();

                if (! is_string($response) || empty($response)) {
                    continue;
                }

                $masters = $this->parseClusterNodes($response);
                if (! empty($masters)) {
                    return $cached = $masters;
                }
            } catch (\Exception $e) {
                Log::debug('CacheGroup: CLUSTER NODES failed on seed', [
                    'host' => $seedHost,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $cached = [];
    }

    /**
     * Parse CLUSTER NODES response to extract master node hosts.
     *
     * Response format (one line per node):
     *   <id> <ip:port@cport> <flags> <master|-> <pingsent> <pongreceived> <epoch> <linkstate> <slot>...
     *
     * @param string $response Raw CLUSTER NODES output
     * @return array<string>
     */
    protected function parseClusterNodes(string $response): array
    {
        $masters = [];

        foreach (explode("\n", trim($response)) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 3) {
                continue;
            }

            $address = $parts[1];   // e.g., '10.244.0.15:6379@16379'
            $flags = $parts[2];     // e.g., 'master' or 'myself,master' or 'slave'

            // Only include master nodes (not slaves/replicas)
            if (! str_contains($flags, 'master')) {
                continue;
            }

            // Skip nodes that are failing
            if (str_contains($flags, 'fail')) {
                continue;
            }

            // Extract host from address (strip port and cluster bus port)
            $host = explode(':', $address)[0] ?? null;
            if ($host && $host !== '') {
                $masters[] = $host;
            }
        }

        $masters = array_unique($masters);

        if (! empty($masters)) {
            Log::debug('CacheGroup: Discovered cluster master nodes', [
                'count' => count($masters),
                'nodes' => $masters,
            ]);
        }

        return $masters;
    }

    /**
     * Resolve hosts from Laravel's database config (used as seed and fallback).
     *
     * @return array<string>
     */
    protected function resolveHostsFromConfig(): array
    {
        $hosts = [];

        $clusters = config('database.redis.clusters', []);
        foreach ($clusters as $clusterName => $nodes) {
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    if (is_array($node) && isset($node['host'])) {
                        $hosts[] = $node['host'];
                    } elseif (is_string($node)) {
                        $hosts[] = $node;
                    }
                }
            }
        }

        if (empty($hosts)) {
            $connection = config('cache-group.redis_connection', 'cache');
            $host = config("database.redis.{$connection}.host")
                ?? config("database.redis.clusters.{$connection}.0.host");
            if ($host) {
                $hosts[] = $host;
            }
        }

        if (empty($hosts)) {
            $host = config('database.redis.default.host', '127.0.0.1');
            $hosts[] = $host;
        }

        return array_unique($hosts);
    }

    /**
     * Log debug message if debug mode is enabled.
     */
    protected function debug(string $message, array $context = []): void
    {
        if (! config('cache-group.debug', false)) {
            return;
        }

        $channel = config('cache-group.debug_channel', 'cache-group');

        try {
            Log::channel($channel)->debug("CacheGroup: {$message}", $context);
        } catch (\Exception $e) {
            Log::debug("CacheGroup: {$message}", $context);
        }
    }
}
