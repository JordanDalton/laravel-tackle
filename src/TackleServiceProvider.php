<?php

namespace Tackle;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Commands\CodeCommand;
use Tackle\Commands\HealingLogCommand;
use Tackle\Commands\ReviewCommand;
use Tackle\Contracts\CodingAgent;
use Tackle\Healing\JobFailureListener;
use Tackle\Healing\ScheduledTaskFailureListener;

class TackleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tackle')
            ->hasConfigFile('ai-code')
            ->hasMigration('create_tackle_healing_log_table')
            ->hasCommands([CodeCommand::class, ReviewCommand::class, HealingLogCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(CodingAgent::class, DefaultCodingAgent::class);
    }

    public function packageBooted(): void
    {
        if (config('ai-code.healing.enabled', false)) {
            Event::listen(JobFailed::class, JobFailureListener::class);
            Event::listen(ScheduledTaskFailed::class, ScheduledTaskFailureListener::class);
        }
    }
}
