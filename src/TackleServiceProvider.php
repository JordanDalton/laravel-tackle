<?php

namespace Tackle;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Commands\CodeCommand;
use Tackle\Contracts\CodingAgent;
use Tackle\Healing\JobFailureListener;

class TackleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tackle')
            ->hasConfigFile('ai-code')
            ->hasCommand(CodeCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(CodingAgent::class, DefaultCodingAgent::class);
    }

    public function packageBooted(): void
    {
        if (config('ai-code.healing.enabled', false)) {
            Event::listen(JobFailed::class, JobFailureListener::class);
        }
    }
}
