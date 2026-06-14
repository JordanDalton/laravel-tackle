## Laravel Tackle

Laravel Tackle is an AI agent harness for Laravel — the runtime layer that lets AI agents operate inside a Laravel application with tool infrastructure, safety boundaries, and a `CodingAgent` contract for extension. Think of it as Claude Code or Codex, but purpose-built for Laravel and installed directly into the app via Composer.

The harness ships with three built-in agents (`ai:code`, `ai:review`, self-healer) and a full tool set. New tools and agents can be scaffolded with `tackle:tool` and `tackle:agent` and wired in without forking the package.

### Commands

- `php artisan ai:code` — interactive coding agent session. The agent reads files, edits code, runs tests, and formats with Pint.
- `php artisan ai:review` — one-shot read-only review of a git diff. Accepts `--staged`, `--against=<branch>`, `--commit=<sha>`, and `--focus=<areas>`.
- `php artisan tackle:healing-log` — view the audit log of all healing attempts. Accepts `--type`, `--outcome`, and `--limit` filters.
- `php artisan tackle:tool ToolName` — scaffold a new tool class at `app/Ai/Tools/ToolName.php`.
- `php artisan tackle:agent AgentName` — scaffold a new agent at `app/Ai/AgentName.php`. Pass `--full` for a bare `CodingAgent` implementation instead of a `DefaultCodingAgent` subclass.

### Key environment variables

- `AI_CODE_PROVIDER` — laravel/ai provider name (default: `anthropic`)
- `AI_CODE_MODEL` — model identifier (default: `claude-sonnet-4-6`)
- `AI_CODE_HEALING_ENABLED` — enable the self-healing system (default: `false`)
- `AI_CODE_HEALING_MODE` — `pr` (open a pull request) or `patch` (apply directly)
- `AI_CODE_HEALING_THRESHOLD` — failures before healing triggers (default: `1`)
- `AI_CODE_HEALING_QUEUE` — queue name for heal jobs (default: `healer`)
- `GITHUB_TOKEN` — GitHub token for opening PRs in `pr` mode

### Self-healing setup

The healer listens to `JobFailed` and `ScheduledTaskFailed` events. Enable it, publish and run the migration, then start a dedicated worker:

@verbatim
<code-snippet name="Self-healing setup" lang="bash">
php artisan vendor:publish --tag="laravel-tackle-migrations"
php artisan migrate

# .env
AI_CODE_HEALING_ENABLED=true

# Start the healer worker (separate from your normal workers)
php artisan queue:work --queue=healer
</code-snippet>
@endverbatim

### Per-class opt-out

Use `#[Healable(false)]` on any job class to prevent the healer from touching it:

@verbatim
<code-snippet name="Opt a job out of self-healing" lang="php">
use Tackle\Attributes\Healable;

#[Healable(false)]
class ChargeSubscription implements ShouldQueue
{
    public function handle(): void { /* ... */ }
}
</code-snippet>
@endverbatim

### Custom contextual attributes

Tackle ships three Laravel contextual attributes for constructor injection:

- `#[AiProvider]` — injects `config('ai-code.provider')`
- `#[AiModel]` — injects `config('ai-code.model')`
- `#[Workspace]` — injects a `PathGuard` configured for the application workspace

### Built-in tools

The coding agent has access to these tools:

- `ReadFile`, `Glob`, `SearchCode`, `EditFile`, `WriteFile` — filesystem access through `PathGuard`
- `RunArtisan`, `RunTests`, `RunPint`, `RunShell` — subprocess execution
- `QueryDatabase` — read-only SELECT queries, results as JSON (100 row cap)
- `ReadLog` — tail `storage/logs/laravel.log` with optional filter string
- `ListRoutes` — formatted route table with method, URI, name, action
- `GitDiff` — git diff with support for `staged`, `commit`, `against`, `path`, and `stat` options

### Customization

Use the generator commands to scaffold new tools and agents:

@verbatim
<code-snippet name="Generate a tool and agent" lang="bash">
php artisan tackle:tool MyTool       # → app/Ai/Tools/MyTool.php
php artisan tackle:agent MyAgent     # → app/Ai/MyAgent.php (extends DefaultCodingAgent)
php artisan tackle:agent MyAgent --full  # → bare CodingAgent implementation
</code-snippet>
@endverbatim

To activate a custom agent, bind it over the default in `AppServiceProvider::register()`:

@verbatim
<code-snippet name="Bind a custom agent" lang="php">
$this->app->bind(\Tackle\Contracts\CodingAgent::class, \App\Ai\MyAgent::class);
</code-snippet>
@endverbatim

Stubs can be published and customised:

@verbatim
<code-snippet name="Publish stubs" lang="bash">
php artisan vendor:publish --tag="laravel-tackle-stubs"
</code-snippet>
@endverbatim
