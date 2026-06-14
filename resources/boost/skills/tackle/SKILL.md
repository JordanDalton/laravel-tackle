---
name: laravel-tackle
description: Configure, extend, and build on Laravel Tackle — an AI agent harness for Laravel (similar to Claude Code or Codex, but installed into the app via Composer). Use this skill when installing Tackle, writing custom tools or agents, configuring the self-healer, or opting jobs out of healing.
---

# Laravel Tackle

Laravel Tackle is an AI agent harness for Laravel. It provides the runtime layer — tool infrastructure, safety boundaries (`PathGuard`, `BudgetTracker`, shell modes), and a `CodingAgent` contract — that agents run inside. The harness ships with three built-in agents and can be extended with custom tools and agents without forking the package.

## When to use this skill

Use this skill when:
- Installing or configuring Laravel Tackle
- Writing a custom tool (`tackle:tool`) or agent (`tackle:agent`)
- Extending or swapping the coding agent
- Configuring the self-healing queue worker (modes, thresholds, GitHub tokens)
- Opting a job or scheduled task out of self-healing
- Running `ai:review` for code review
- Querying the healing audit log

---

## Installation

```bash
composer require jordandalton/laravel-tackle
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan vendor:publish --tag="tackle-config"
```

Add your API key to `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
```

---

## Available tools (DefaultCodingAgent)

| Tool | What it does |
|---|---|
| `ReadFile` | Read any file in the workspace |
| `Glob` | List files by pattern |
| `SearchCode` | Grep-style search — file, line, snippet |
| `EditFile` | str_replace edit — unique match required |
| `WriteFile` | Create a new file |
| `RunArtisan` | Run an artisan command (allowlist-gated) |
| `RunTests` | Run Pest / PHPUnit |
| `RunPint` | Format files with Laravel Pint |
| `RunShell` | General shell (governed by shell mode) |
| `QueryDatabase` | Read-only SELECT query → JSON (100 row cap) |
| `ReadLog` | Tail `storage/logs/laravel.log` with optional filter |
| `ListRoutes` | Registered routes — method, URI, name, action |
| `GitDiff` | Git diff — supports staged, commit, against, path, stat |
| `ReadTelescopeEntry` | Telescope exception entries — by job UUID or recent list |
| `ReadSentryIssue` | Fetch a Sentry issue by ID (exception, stacktrace, breadcrumbs, request). Omit ID to list recent unresolved issues. No-ops if `SENTRY_AUTH_TOKEN`/`SENTRY_ORG` unset. |
| `ReadGitHubIssue` | Fetch a GitHub issue by number (title, body, labels, all comments). Omit number to list recent open issues. No-ops if `GITHUB_TOKEN`/`GITHUB_REPO` unset. |
| `AskUser` | Present the user with a `select()` or `multiselect()` to choose between options |
| `ConfirmAction` | Ask the user to `confirm()` before a destructive or irreversible operation |

---

## Generating tools and agents

Use the built-in generators to scaffold new classes:

```bash
php artisan tackle:tool MyTool        # → app/Ai/Tools/MyTool.php
php artisan tackle:agent MyAgent      # → app/Ai/MyAgent.php (extends DefaultCodingAgent)
php artisan tackle:agent MyAgent --full  # → bare CodingAgent implementation
```

Stubs can be published and customised:

```bash
php artisan vendor:publish --tag="tackle-stubs"
# publishes to stubs/tackle/ — commands pick up published stubs automatically
```

---

## Writing a custom tool

Extend `Tackle\Tools\AbstractTool` and implement `description()`, `schema()`, and `handle()`.

```php
namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Tools\AbstractTool;

class ReadDatabase extends AbstractTool
{
    public function description(): string
    {
        return 'Run a read-only SQL SELECT query and return results as JSON.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The SELECT query to run.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $sql = $request->string('query', '');

        if (! str_starts_with(strtolower(ltrim($sql)), 'select')) {
            return 'Only SELECT queries are allowed.';
        }

        return json_encode(\DB::select($sql), JSON_PRETTY_PRINT);
    }
}
```

**Rules:**
- Return a string from `handle()` — the agent reads it as tool output.
- To refuse an action, return a descriptive string rather than throwing.
- File access tools should accept a `Tackle\Support\PathGuard` via constructor injection.

---

## Adding a tool to the agent

Extend `DefaultCodingAgent` and merge your tool into `tools()`, then rebind the contract.

```php
// app/Ai/MyCodingAgent.php
namespace App\Ai;

use App\Ai\Tools\ReadDatabase;
use Tackle\Agents\DefaultCodingAgent;

class MyCodingAgent extends DefaultCodingAgent
{
    public function __construct(
        private ReadDatabase $readDatabase,
        mixed ...$args,
    ) {
        parent::__construct(...$args);
    }

    public function tools(): iterable
    {
        return [...parent::tools(), $this->readDatabase];
    }
}
```

```php
// app/Providers/AppServiceProvider.php
use App\Ai\MyCodingAgent;
use Tackle\Contracts\CodingAgent;

public function register(): void
{
    $this->app->bind(CodingAgent::class, MyCodingAgent::class);
}
```

---

## Swapping the agent entirely

Implement `Tackle\Contracts\CodingAgent` directly (it extends `laravel/ai`'s `Agent`, `HasTools`, and `Conversational`) and use the `Promptable` trait.

```php
use Laravel\Ai\Promptable;
use Tackle\Contracts\CodingAgent;

class MyAgent implements CodingAgent
{
    use Promptable;

    public function instructions(): string { return 'You are a billing specialist.'; }
    public function messages(): iterable  { return []; }
    public function tools(): iterable     { return []; }
}
```

```php
$this->app->bind(\Tackle\Contracts\CodingAgent::class, MyAgent::class);
```

---

## Self-healing queue workers

### Setup

```bash
php artisan vendor:publish --tag="tackle-migrations"
php artisan migrate
```

```env
AI_CODE_HEALING_ENABLED=true
AI_CODE_HEALING_MODE=pr        # pr | patch
AI_CODE_HEALING_THRESHOLD=1    # failures before healing triggers
AI_CODE_HEALING_QUEUE=healer
GITHUB_TOKEN=ghp_...           # required for pr mode
```

Start the healer on its own worker process:

```bash
php artisan queue:work --queue=healer
```

### Modes

| Mode | Behaviour |
|---|---|
| `pr` (default) | Pushes a fix branch and opens a GitHub pull request |
| `patch` | Merges the fix directly if tests pass; falls back to `pr` if they fail |

### GitHub token resolution order

1. `GITHUB_TOKEN` in `.env`
2. GitHub CLI — Tackle runs `gh auth token` automatically if `gh` is installed
3. `ai-code.healing.github_token` in `config/ai-code.php`

### Opting a job out of healing

```php
use Tackle\Attributes\Healable;

#[Healable(false)]
class ChargeSubscription implements ShouldQueue
{
    public function handle(): void { /* ... */ }
}
```

Jobs without the attribute, or with `#[Healable(true)]`, are healed normally.

---

## Scheduled task healing

Tackle listens to `ScheduledTaskFailed` automatically when `AI_CODE_HEALING_ENABLED=true`. No extra configuration needed. Patched scheduled tasks are not re-dispatched — the fix takes effect on the next scheduled run.

---

## Code review

```bash
# Review all uncommitted changes
php artisan ai:review

# Review only staged changes
php artisan ai:review --staged

# PR-style review against another branch
php artisan ai:review --against=main

# Review a specific commit
php artisan ai:review --commit=abc1234

# Focus the reviewer
php artisan ai:review --focus=security,performance
```

The `ReviewAgent` is read-only — it has `ReadFile`, `Glob`, and `SearchCode` but no editing tools. It reads full files for context before commenting on any changed function.

---

## Explain code

```bash
php artisan ai:explain app/Services/BillingService.php
php artisan ai:explain app/Services/BillingService.php --method=charge
```

The `ExplainAgent` is read-only. It reads the full file and any related classes before explaining.

---

## Generate tests

```bash
php artisan ai:test app/Services/BillingService.php
php artisan ai:test app/Services/BillingService.php --method=charge
php artisan ai:test app/Http/Controllers/UserController.php --feature
php artisan ai:test app/Services/BillingService.php --unit
```

The `TestWriterAgent` reads the class, checks existing test conventions, writes a Pest test file, then runs the tests to confirm they pass. Test type is inferred from the path when no flag is given.

---

## Health check

```bash
php artisan tackle:health
```

Checks: config published, API key set, git repo with commits, `.env.testing` present, and (if healing enabled) migration run and GitHub token available.

---

## Replay a healing attempt

```bash
php artisan tackle:replay                                    # last attempt
php artisan tackle:replay --class="App\Jobs\ProcessPayment" # last for a class
php artisan tackle:replay --id=42                           # specific log entry
```

---

## Healing audit log

```bash
php artisan tackle:healing-log

# Filter by type
php artisan tackle:healing-log --type=job
php artisan tackle:healing-log --type=scheduled_task

# Filter by outcome
php artisan tackle:healing-log --outcome=pr_opened
php artisan tackle:healing-log --outcome=patched
php artisan tackle:healing-log --outcome=failed

# Show more entries
php artisan tackle:healing-log --limit=50
```

---

## Contextual attributes

Tackle provides three Laravel contextual attributes for constructor injection:

```php
use Tackle\Attributes\AiProvider;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\Workspace;

public function __construct(
    #[AiProvider] string $provider,   // config('ai-code.provider')
    #[AiModel]    string $model,      // config('ai-code.model')
    #[Workspace]  PathGuard $guard,   // PathGuard for the app workspace
) {}
```

---

## Common pitfalls

- **`git worktree add failed`** — the project must have at least one commit: `git add -A && git commit -m "initial"`.
- **Healer branch pushed but no PR** — GitHub token not found. Run `gh auth status` or set `GITHUB_TOKEN` in `.env`.
- **Tests always fail in sandbox** — ensure `.env` and `.env.testing` exist and are committed or that the project has them; the worktree symlinks them automatically.
- **Config not updated after publishing** — run `php artisan config:clear` after editing `config/ai-code.php`.
- **Healer skipping a job** — check for `#[Healable(false)]` on the job class or a `threshold` setting higher than the current failure count.
