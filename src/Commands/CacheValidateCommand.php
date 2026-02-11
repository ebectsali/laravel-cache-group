<?php

namespace Ebects\LaravelCacheGroup\Commands;

use Ebects\LaravelCacheGroup\CacheRegistry;
use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use Ebects\LaravelCacheGroup\Strategies\HybridStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CacheValidateCommand extends Command
{
    protected $signature = 'cache:validate';

    protected $description = 'Validate cache group configuration and Redis connectivity';

    public function handle(): int
    {
        $this->info('Checking cache group configuration...');
        $this->newLine();

        $warnings = 0;
        $errors = 0;

        // Redis connection
        try {
            Redis::connection()->ping();
            $this->line('  ✅ Redis connection: OK');
        } catch (\Exception $e) {
            $this->line('  ❌ Redis connection: FAILED — ' . $e->getMessage());
            $errors++;
        }

        // Redis mode
        try {
            $strategy = app(HybridStrategy::class);
            $mode = $strategy->getActiveStrategyName();
            $this->line("  ✅ Redis mode: {$mode}");
        } catch (\Exception $e) {
            $this->line('  ⚠️  Redis mode: unknown');
            $warnings++;
        }

        // Scope resolver
        $resolverClass = config('cache-group.scope_resolver');
        if ($resolverClass) {
            if (class_exists($resolverClass) && is_subclass_of($resolverClass, ScopeResolver::class)) {
                $this->line("  ✅ Scope resolver: {$resolverClass}");
            } else {
                $this->line("  ❌ Scope resolver: invalid — {$resolverClass}");
                $errors++;
            }
        } else {
            $this->line('  ⚠️  Scope resolver: not configured (using DefaultScopeResolver)');
            $warnings++;
        }

        // Groups
        $groups = CacheRegistry::getRegisteredGroups();
        $this->line("  ✅ Registered groups: " . count($groups));

        $mapping = CacheRegistry::generateClassMapping();
        $this->line("  ✅ Class mapping: " . count($mapping) . " actions mapped");

        $this->newLine();

        // Validate
        $validationErrors = CacheRegistry::validate();
        foreach ($validationErrors as $error) {
            if (str_contains($error, 'Circular') || str_contains($error, 'Duplicate')) {
                $this->line("  ⚠️  WARNING: {$error}");
                $warnings++;
            } else {
                $this->line("  ❌ ERROR: {$error}");
                $errors++;
            }
        }

        // Summary
        $this->newLine();
        $this->info(count($groups) . " groups | {$warnings} warnings | {$errors} errors");

        if ($errors > 0) {
            $this->error('Configuration has errors.');
            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->warn('Valid with warnings.');
        } else {
            $this->info('All checks passed! ✅');
        }

        return self::SUCCESS;
    }
}
