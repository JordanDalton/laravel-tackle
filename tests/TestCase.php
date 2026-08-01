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
        $workspace = sys_get_temp_dir().'/tackle-tests';

        // Anything that shells out runs with this as its working directory, and
        // Process throws if it does not exist. Only ToolsTest used to create it,
        // so every other test that ran a command depended on having run after
        // that file — order-dependence that passed locally and failed on a
        // clean runner.
        @mkdir($workspace, 0755, true);

        config()->set('tackle.workspace', $workspace);
        config()->set('tackle.protected_paths', ['.env', '.env.*', 'storage/*', 'vendor/*', '.git/*']);
        config()->set('tackle.shell', 'off');
        config()->set('tackle.artisan_allowlist', ['make:*', 'route:list', 'migrate', 'test']);
        config()->set('tackle.shell_allowlist', ['composer', 'npm', 'php artisan']);
        config()->set('tackle.budget_usd', 1.00);
    }
}
