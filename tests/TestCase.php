<?php

namespace Tackle\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tackle\TackleServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TackleServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('tackle.workspace', sys_get_temp_dir().'/tackle-tests');
        config()->set('tackle.protected_paths', ['.env', '.env.*', 'storage/*', 'vendor/*', '.git/*']);
        config()->set('tackle.shell', 'off');
        config()->set('tackle.artisan_allowlist', ['make:*', 'route:list', 'migrate', 'test']);
        config()->set('tackle.shell_allowlist', ['composer', 'npm', 'php artisan']);
        config()->set('tackle.budget_usd', 1.00);
    }
}
