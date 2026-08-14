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
use Tackle\Commands\FixCommand;
use Tackle\Commands\HealingLogCommand;
use Tackle\Commands\HealthCommand;
use Tackle\Commands\InitCommand;
use Tackle\Commands\InstallCommand;
use Tackle\Commands\MakeAgentCommand;
use Tackle\Commands\MakeToolCommand;
use Tackle\Commands\McpCommand;
use Tackle\Commands\PruneCommand;
use Tackle\Commands\ReplayCommand;
use Tackle\Commands\RespondCommand;
use Tackle\Commands\ReviewCommand;
use Tackle\Commands\RunCommand;
use Tackle\Commands\TestCommand;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Events\SessionEnded;
use Tackle\Events\SessionStarted;
use Tackle\Healing\JobFailureListener;
use Tackle\Healing\ScheduledTaskFailureListener;
use Tackle\Support\BudgetTracker;
use Tackle\Support\HookRunner;
use Tackle\Support\TerminalInteraction;
use Tackle\Support\WorktreeManager;

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
                InitCommand::class,
                HealthCommand::class,
                CodeCommand::class,
                RunCommand::class,
                FixCommand::class,
                ReviewCommand::class,
                RespondCommand::class,
                ExplainCommand::class,
                TestCommand::class,
                HealingLogCommand::class,
                ReplayCommand::class,
                PruneCommand::class,
                MakeToolCommand::class,
                MakeAgentCommand::class,
                McpCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(CodingAgent::class, DefaultCodingAgent::class);
        $this->app->singleton(WorktreeManager::class);
        $this->app->singleton(HookRunner::class);

        // One budget per process: the Delegate tool records subagent usage
        // into the same tracker the driving command enforces, so delegated
        // work counts against the session's spend limit.
        $this->app->singleton(BudgetTracker::class);

        // Prompting through the terminal is the default. Contexts without one
        // (ai:run, tackle:mcp, the healer) rebind this before resolving an agent.
        $this->app->singleton(InteractionPolicy::class, TerminalInteraction::class);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__.'/../resources/stubs' => base_path('stubs/tackle'),
        ], 'tackle-stubs');

        if (config('tackle.healing.enabled', false)) {
            Event::listen(JobFailed::class, JobFailureListener::class);
            Event::listen(ScheduledTaskFailed::class, ScheduledTaskFailureListener::class);
        }

        Event::listen(SessionStarted::class, function (SessionStarted $event) {
            $this->app->make(HookRunner::class)->sessionEvent('session_start', [
                'command' => $event->command,
                'provider' => $event->provider,
                'model' => $event->model,
            ]);
        });

        Event::listen(SessionEnded::class, function (SessionEnded $event) {
            $this->app->make(HookRunner::class)->sessionEvent('session_end', [
                'command' => $event->command,
                'input_tokens' => $event->inputTokens,
                'output_tokens' => $event->outputTokens,
                'estimated_cost_usd' => $event->estimatedCostUsd,
            ]);
        });
    }
}
