<?php

namespace Tackle;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Commands\CodeCommand;
use Tackle\Commands\ExplainCommand;
use Tackle\Commands\HealthCommand;
use Tackle\Commands\HealingLogCommand;
use Tackle\Commands\InstallCommand;
use Tackle\Commands\MakeAgentCommand;
use Tackle\Commands\MakeToolCommand;
use Tackle\Commands\PruneCommand;
use Tackle\Commands\ReplayCommand;
use Tackle\Commands\ReviewCommand;
use Tackle\Commands\TestCommand;
use Tackle\Contracts\CodingAgent;
use Tackle\Healing\JobFailureListener;
use Tackle\Support\WorktreeManager;
use Tackle\Healing\ScheduledTaskFailureListener;

class TackleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tackle')
            ->hasConfigFile('tackle')
            ->hasMigration('create_tackle_healing_log_table')
            ->hasCommands([
                InstallCommand::class,
                HealthCommand::class,
                CodeCommand::class,
                ReviewCommand::class,
                ExplainCommand::class,
                TestCommand::class,
                HealingLogCommand::class,
                ReplayCommand::class,
                PruneCommand::class,
                MakeToolCommand::class,
                MakeAgentCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(CodingAgent::class, DefaultCodingAgent::class);
        $this->app->singleton(WorktreeManager::class);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/stubs' => base_path('stubs/tackle'),
        ], 'tackle-stubs');

        if (config('tackle.healing.enabled', false)) {
            Event::listen(JobFailed::class, JobFailureListener::class);
            Event::listen(ScheduledTaskFailed::class, ScheduledTaskFailureListener::class);
        }
    }
}
