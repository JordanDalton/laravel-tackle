# Laravel Tackle

**An interactive, terminal-based AI coding assistant for Laravel.**

Think Claude Code, but installed directly into your Laravel app via Composer. Run
`php artisan ai:code`, describe a task, and the agent reads your codebase, edits
files, runs tests, and formats code — all within your project.

Built on top of [`laravel/ai`](https://github.com/laravel/ai).

---

## Contents

- [Before you start](#before-you-start)
- [Requirements](#requirements)
- [Installation](#installation)
- [Environment variables](#environment-variables)
- [Usage](#usage)
- [Tips for better results](#tips-for-better-results)
- [Session memory](#session-memory)
- [Configuration](#configuration)
- [Built-in tools](#built-in-tools)
- [Self-healing queue workers](#self-healing-queue-workers)
- [Limitations](#limitations)
- [Customization](#customization)
  - [Adding your own tools](#adding-your-own-tools)
  - [Swapping the agent entirely](#swapping-the-agent-entirely)
  - [Changing the model or provider](#changing-the-model-or-provider)
- [Safety](#safety)
- [Troubleshooting](#troubleshooting)
- [Known risks](#known-risks)
- [Development](#development)

---

## Before you start

Run through this checklist once before your first session:

- [ ] Commit or stash any in-progress work — the agent will modify files, and a
      clean git state is your undo button.
- [ ] Set `ANTHROPIC_API_KEY` in `.env` (or the key for your chosen provider).
- [ ] Publish the `laravel/ai` config: `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`
- [ ] Publish the Tackle config: `php artisan vendor:publish --tag="laravel-tackle-config"`
- [ ] Run `php artisan ai:code` and type a small test task to confirm everything connects.

---

## Requirements

- PHP ^8.3
- Laravel ^12.0
- [`laravel/ai`](https://github.com/laravel/ai) ^0.1 (pinned — fast-moving package, see [Known Risks](#known-risks))

---

## Installation

```bash
composer require jordandalton/laravel-tackle
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan vendor:publish --tag="laravel-tackle-config"
```

The service provider and `ai:code` command register automatically via Laravel
package auto-discovery.

### API key setup

Tackle uses Anthropic (Claude) by default. Add your key to `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
```

The `config/ai.php` published above already includes the `anthropic` provider
block — just add the env var and you're ready.

---

## Environment variables

All config options can be set via `.env`. Nothing requires editing a PHP file.

| Variable | Default | Description |
|---|---|---|
| `ANTHROPIC_API_KEY` | — | Your Anthropic API key (required for the default provider) |
| `AI_CODE_PROVIDER` | `anthropic` | Provider name — must match a key in `config/ai.php` |
| `AI_CODE_MODEL` | `claude-sonnet-4-6` | Model to use |
| `AI_CODE_MAX_STEPS` | `40` | Max tool-call cycles per agent turn |
| `AI_CODE_BUDGET` | `1.00` | Hard spend limit in USD per session |
| `AI_CODE_SHELL` | `approve` | Shell mode: `off` \| `allowlist` \| `approve` \| `yolo` |
| `AI_CODE_MEMORY` | `file` | Session persistence: `none` \| `file` \| `database` |
| `AI_CODE_HEALING_ENABLED` | `false` | Enable the self-healing queue worker feature |
| `AI_CODE_HEALING_MODE` | `pr` | Healing mode: `pr` \| `patch` |
| `AI_CODE_HEALING_QUEUE` | `healer` | Queue name for the `HealJobFailure` job |
| `AI_CODE_HEALING_THRESHOLD` | `1` | Failures before healing is triggered |
| `AI_CODE_HEALING_BASE_BRANCH` | `main` | Base branch for fix pull requests |
| `GITHUB_TOKEN` | — | GitHub token for opening pull requests (pr mode) |

---

## Usage

```bash
php artisan ai:code
```

Type a task at the prompt. The agent maintains full conversation history within a
session, so you can follow up, ask questions, and give corrections naturally.

Type `exit` or `quit` to end the session.

### Shell mode flag

Pass `--shell` to override the configured shell mode for a single session without
touching your config or `.env`:

```bash
# Safe read-only exploration — no commands will run at all
php artisan ai:code --shell=off
php artisan ai:code --off          # shorthand

# Require your approval before every shell command (config default)
php artisan ai:code --shell=approve
php artisan ai:code --approve      # shorthand

# Only allow commands from shell_allowlist, no prompt
php artisan ai:code --shell=allowlist
php artisan ai:code --allowlist    # shorthand

# No restrictions, no prompts — CI or fully-trusted environments only
php artisan ai:code --shell=yolo
php artisan ai:code --yolo         # shorthand
```

The flag is session-scoped and does not persist to config.

### Example session

```
┌ What should I work on? ──────────────────────────────────────┐
│ Add a slug field to the Post model                           │
└──────────────────────────────────────────────────────────────┘

→ searching for Post model
→ reading app/Models/Post.php
→ creating database/migrations/2024_01_01_add_slug_to_posts.php
→ editing app/Models/Post.php
→ running tests

All done. Migration created, $fillable updated, and tests pass.

┌ What should I work on? ──────────────────────────────────────┐
│ Make the slug auto-generate from the title on creation       │
└──────────────────────────────────────────────────────────────┘
```

---

## Tips for better results

**Be specific about what you want.**
Vague tasks produce vague results. The more context you give upfront, the less
back-and-forth is needed.

| Instead of… | Try… |
|---|---|
| "Add a feature" | "Add a `published_at` timestamp to `Post` with a scope for published posts and a migration" |
| "Fix the bug" | "The `UserController@store` is returning a 500 when `email` is null — find out why and fix it" |
| "Refactor this" | "The `OrderService` class is doing too much — extract the payment logic into a `PaymentService`" |

**Use `--off` for questions and exploration.**
If you just want to understand the codebase without making changes, `--off` mode
prevents the agent from running any commands, so you can ask freely.

```bash
php artisan ai:code --off
# "How is authentication handled in this app?"
# "What does the Job queue setup look like?"
```

**Point it at the right place.**
If you know which file or module is relevant, say so. "Look at
`app/Services/BillingService.php`" is faster than letting it search from scratch.

**Correct it mid-session.**
If the agent does something wrong, just say so in the next prompt. It reads your
correction in context and adjusts. You don't need to restart the session.

**Keep tasks focused.**
One clear task per session works better than a long list. Once a task is done,
review the diff, commit it, then start a new session for the next task.

**Review before you move on.**
After each task the agent shows a `git diff --stat`. Look at it before typing
your next task. If something looks wrong, say so or discard with
`git checkout -- .`.

---

## Session memory

The `memory` config controls what happens to conversation history when you exit.

| Mode | Behaviour |
|---|---|
| `none` | History is lost when the session ends. Every `php artisan ai:code` starts fresh. |
| `file` | **(Default)** The session transcript is saved to `storage/ai-code/`. Re-running `ai:code` picks up where you left off. |
| `database` | Uses `laravel/ai`'s `RemembersConversations`. Requires publishing and running the `laravel/ai` migrations first. |

### File memory

With the default `file` mode, transcripts are stored as JSON in
`storage/ai-code/`. The directory is created automatically. You can delete files
there to clear history, or add `storage/ai-code/` to `.gitignore` to keep
session history out of your repository.

### Database memory

To use `database` mode, publish and run the `laravel/ai` migrations:

```bash
php artisan vendor:publish --tag="laravel-ai-migrations"
php artisan migrate
```

Then set `AI_CODE_MEMORY=database` in `.env`.

---

## Configuration

After publishing the config, edit `config/ai-code.php`. All values can be set
via environment variables — see the [Environment variables](#environment-variables)
table above.

```php
return [
    // laravel/ai provider name — must match a key in config/ai.php
    'provider' => env('AI_CODE_PROVIDER', 'anthropic'),

    // Model to use
    'model' => env('AI_CODE_MODEL', 'claude-sonnet-4-6'),

    // Maximum agent steps per turn (tool calls + reasoning cycles)
    'max_steps' => env('AI_CODE_MAX_STEPS', 40),

    // Hard spend limit for the session in USD — aborts when exceeded
    'budget_usd' => env('AI_CODE_BUDGET', 1.00),

    // Shell execution policy: off | allowlist | approve | yolo
    'shell'           => env('AI_CODE_SHELL', 'approve'),
    'shell_allowlist' => ['composer', 'npm', 'php artisan'],

    // Artisan commands the agent may run without a confirmation prompt
    'artisan_allowlist' => ['make:*', 'route:list', 'migrate', 'db:seed', 'test'],

    // Glob patterns (relative to workspace) the agent can never read or write
    'protected_paths' => ['.env', '.env.*', 'storage/*', 'vendor/*', '.git/*'],

    // Root directory for the agent — null defaults to base_path()
    'workspace' => null,

    // Session memory: none | file (default) | database
    'memory' => env('AI_CODE_MEMORY', 'file'),
];
```

### Shell modes

| Mode | Behaviour |
|---|---|
| `off` | `RunShell` refuses everything. Use `RunArtisan` / `RunTests` instead. |
| `allowlist` | Only commands whose first token matches `shell_allowlist` run unattended. |
| `approve` | **Default.** Every command shows a confirmation prompt before running. |
| `yolo` | Runs anything, no prompt. **Dangerous — CI or fully-trusted environments only.** |

### Artisan allowlist

The `artisan_allowlist` supports glob patterns, so `make:*` covers `make:model`,
`make:controller`, etc. Commands not matching any pattern are refused.

### Protected paths

The `protected_paths` globs prevent the agent from reading or writing sensitive
files regardless of what it is asked to do. This is enforced in PHP, not via
prompting. Add your own patterns here if your project has additional secrets.

---

## Built-in tools

These tools are available to the agent in every session.

| Tool | What it does |
|---|---|
| `ReadFile` | Reads a file's contents. Always runs through `PathGuard` first. |
| `Glob` | Lists files matching a pattern. Protected paths are excluded from results. |
| `SearchCode` | Grep-style search returning file + line + snippet. Capped at 50 results. |
| `EditFile` | `str_replace` edit — `old_str` must appear exactly once or the edit is refused. |
| `WriteFile` | Creates a new file. Refuses if the path already exists. |
| `RunArtisan` | Runs `php artisan <command>` in a subprocess. Allowlist-gated. |
| `RunTests` | Runs Pest or `php artisan test` in a subprocess. Returns full output. |
| `RunPint` | Runs Laravel Pint to format files. Called before finishing a task. |
| `RunShell` | General shell — governed by the `shell` config mode. |

All file reads happen in-process. Everything that executes code runs as a
subprocess, so a broken generated file cannot crash the agent session.

---

## Self-healing queue workers

When enabled, Tackle listens for every failed queue job and automatically
dispatches an AI agent to diagnose the exception, patch the code, verify the fix
with your test suite, and either open a pull request or apply the fix directly —
all without you lifting a finger.

### How it works

1. A job fails → Laravel fires the `JobFailed` event.
2. Tackle's `JobFailureListener` picks it up and dispatches a `HealJobFailure`
   job to the `healer` queue (a separate queue from your normal workers).
3. A dedicated queue worker picks up `HealJobFailure`. It:
   - Creates an **isolated git worktree** on a fresh branch (`tackle/heal-{id}`).
   - Spins up a `HealingAgent` pointed at that worktree.
   - Feeds the agent the exception class, message, stack trace, and (if
     [Telescope](https://laravel.com/docs/telescope) is installed) the full
     Telescope exception entry.
   - The agent reads the failing code, applies a minimal fix via `EditFile`, and
     runs your test suite to verify.
4. After the agent finishes:
   - **`pr` mode (default):** pushes the branch to GitHub and opens a pull
     request with the agent's reasoning as the description.
   - **`patch` mode:** merges the fix back into your main workspace branch and
     re-dispatches the original job.
5. The worktree is cleaned up regardless of outcome.

### Prerequisites

- Your project must be a **git repository** with a remote named `origin`.
- A **queue worker** must be running the `healer` queue (see below).
- For PR mode, a **GitHub personal access token** is required.
- For `patch` mode, the working tree must be clean when healing runs.

### Enabling the healer

In `.env`:

```env
AI_CODE_HEALING_ENABLED=true
```

That's it. The `JobFailed` listener registers automatically via the service
provider once this is set to `true`.

### Starting the healer worker

The healer runs on a dedicated queue to avoid competing with your normal
workers:

```bash
php artisan queue:work --queue=healer
```

Run this alongside your existing workers. In production (Supervisor, Forge, etc.)
add a separate process group for the `healer` queue.

### GitHub token setup

For PR mode, Tackle needs a GitHub token with the `repo` scope.

**Resolution order:**

1. `GITHUB_TOKEN` in `.env` (or the `ai-code.healing.github_token` config key)
2. GitHub CLI (`~/.config/gh/hosts.yml`) — if you have `gh` installed and
   authenticated, Tackle reads your token automatically with no extra config.
3. If no token is found, the branch is pushed but the PR is not opened. A log
   entry records that you need to configure a token.

```env
GITHUB_TOKEN=ghp_...
```

### Configuration

All healer options live under the `healing` key in `config/ai-code.php`:

| Option | Env var | Default | Description |
|---|---|---|---|
| `enabled` | `AI_CODE_HEALING_ENABLED` | `false` | Enable or disable the healer |
| `mode` | `AI_CODE_HEALING_MODE` | `pr` | `pr` = open a pull request; `patch` = apply directly |
| `queue` | `AI_CODE_HEALING_QUEUE` | `healer` | Queue name for the `HealJobFailure` job |
| `threshold` | `AI_CODE_HEALING_THRESHOLD` | `1` | Number of failures before healing triggers |
| `base_branch` | `AI_CODE_HEALING_BASE_BRANCH` | `main` | Branch PRs are opened against |
| `branch_prefix` | `AI_CODE_HEALING_BRANCH_PREFIX` | `tackle/heal-` | Prefix for fix branches |
| `github_token` | `GITHUB_TOKEN` | `null` | GitHub token for opening PRs |
| `telescope` | `AI_CODE_HEALING_TELESCOPE` | `true` | Use Telescope context if available |

### Failure threshold

By default (`threshold=1`) the healer triggers on the first failure. If you want
the healer to wait until a job has failed a certain number of times before
intervening (e.g. to let transient failures resolve themselves), set:

```env
AI_CODE_HEALING_THRESHOLD=3
```

### PR mode vs patch mode

| | `pr` (default) | `patch` |
|---|---|---|
| Human review required | Yes — merge the PR | No — merged automatically |
| Tests must pass | No (PR opened regardless) | Yes (only merges on green) |
| Job re-dispatched | No | Yes, after merge |
| Best for | Production / sensitive code | CI environments / trusted agents |

### Laravel Telescope integration

If [Laravel Telescope](https://laravel.com/docs/telescope) is installed in your
application, Tackle uses it to give the agent richer context: the full exception
entry including class, message, and stack frames. No extra configuration is
needed — Tackle detects Telescope automatically and degrades gracefully if it is
not present.

### Healer limitations

- The healer targets **code bugs** — logic errors the AI can diagnose and fix.
  It is not designed for infrastructure issues (database down, disk full, etc.).
- The fix branch is pushed to `origin` — your CI pipeline will run on it and
  can catch anything the local test run missed.
- In `patch` mode, if tests fail the healer falls back to PR mode automatically
  so nothing is merged without verification.
- The healer never modifies `.env`, `vendor/`, `storage/`, or `.git/` — the
  same path guards apply as in interactive mode.
- `HealJobFailure` itself has `$tries = 1`. A failing healer does not create a
  healing loop.

---

## Limitations

Things Tackle cannot do in v1:

- **No internet access.** The agent cannot fetch URLs, read documentation, or
  call external APIs. It works only with files in your workspace.
- **No binary files.** `ReadFile` reads text. Images, compiled assets, and other
  binaries are not readable by the agent.
- **No auto-commit or push.** All edits are left unstaged. The agent will never
  run `git commit` or `git push`.
- **History resets on exit** (unless `memory=file` or `memory=database`). With
  the default `file` mode, history persists between sessions automatically.
- **Budget is estimated, not exact.** The spend limit is calculated from token
  counts using approximate per-model pricing. Actual charges from your provider
  may differ slightly.
- **Tests need a working environment.** `RunTests` runs your actual test suite.
  If tests require a database or other services, those must be running and
  configured before starting a session.

---

## Customization

### Adding your own tools

Create a class that extends `Tackle\Tools\AbstractTool`, then extend
`DefaultCodingAgent` to merge it into the tool list, and rebind the contract.

**Step 1 — Write the tool:**

```php
// app/Ai/Tools/ReadDatabase.php
namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tackle\Tools\AbstractTool;

class ReadDatabase extends AbstractTool
{
    public function description(): string
    {
        return 'Run a read-only SQL query and return results as JSON. SELECT only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The SELECT query to run.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $sql = $request->string('query', '');

        if (! str_starts_with(strtolower(ltrim($sql)), 'select')) {
            return 'Only SELECT queries are allowed.';
        }

        return json_encode(DB::select($sql), JSON_PRETTY_PRINT);
    }
}
```

**Step 2 — Extend the agent:**

```php
// app/Ai/MyCodingAgent.php
namespace App\Ai;

use App\Ai\Tools\ReadDatabase;
use Tackle\Agents\DefaultCodingAgent;

class MyCodingAgent extends DefaultCodingAgent
{
    public function __construct(
        private ReadDatabase $readDatabase,
        ...$args,
    ) {
        parent::__construct(...$args);
    }

    public function tools(): iterable
    {
        return [...parent::tools(), $this->readDatabase];
    }
}
```

**Step 3 — Rebind in your service provider:**

```php
// app/Providers/AppServiceProvider.php
use App\Ai\MyCodingAgent;
use Tackle\Contracts\CodingAgent;

public function register(): void
{
    $this->app->bind(CodingAgent::class, MyCodingAgent::class);
}
```

The Laravel container resolves all constructor dependencies automatically, so
your tool class can type-hint anything it needs (DB connections, services, etc.).

#### Tool contract

Every tool receives a `Laravel\Ai\Tools\Request` object in `handle()`. It
behaves like a read-only request bag:

```php
$request->string('key', 'default');   // string value
$request->boolean('key', false);      // boolean value
$request->integer('key', 0);          // integer value
$request->get('key', 'default');      // raw value
$request->all();                      // all arguments as array
```

When a tool should refuse an action, **return a string explaining why** rather
than throwing an exception. The agent reads the refusal message and reroutes
itself accordingly.

---

### Swapping the agent entirely

If you need deeper control — different instructions, a different conversation
strategy, or a completely different set of tools — implement the `CodingAgent`
contract directly and rebind it:

```php
// app/Ai/MyAgent.php
namespace App\Ai;

use Laravel\Ai\Promptable;
use Tackle\Contracts\CodingAgent;

class MyAgent implements CodingAgent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a specialist in this project. Only touch the billing module.';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [
            // your tools here
        ];
    }
}
```

```php
$this->app->bind(\Tackle\Contracts\CodingAgent::class, MyAgent::class);
```

The `CodingAgent` contract extends `Laravel\Ai\Contracts\Agent`, `HasTools`, and
`Conversational`, so `laravel/ai`'s full streaming and tool-calling machinery
works automatically as long as you use the `Promptable` trait.

---

### Changing the model or provider

The quickest way is via `.env`:

```env
AI_CODE_PROVIDER=openai
AI_CODE_MODEL=gpt-4o
```

The provider name must match a key in `config/ai.php`. Any provider supported by
`laravel/ai` (Anthropic, OpenAI, Gemini, Groq, Ollama, etc.) works as long as it
supports tool calling.

---

## Safety

- **Protected paths** — the agent cannot read or write `.env`, `storage/`,
  `vendor/`, or `.git/` by default. Enforced in `PathGuard`, not via prompting.
  The model cannot talk its way around this.
- **Unstaged edits** — all file changes are left unstaged. Review with
  `git diff`; discard with `git checkout -- .`.
- **Budget cap** — the session aborts once estimated spend exceeds `budget_usd`.
- **Subprocess isolation** — `RunArtisan`, `RunTests`, `RunPint`, and `RunShell`
  all run as child processes. A broken generated file cannot crash the session.
- **Shell is gated** — the default `approve` mode requires your confirmation
  before any shell command runs. Use `--off` for read-only exploration.

---

## Troubleshooting

### `HTTP request returned status code 401`

Your API key is missing or incorrect. Check that `ANTHROPIC_API_KEY` (or the key
for your chosen provider) is set in `.env` and that `config/ai.php` has been
published and contains the matching provider block.

### `Agent error: ...` and the session continues

The agent caught an exception during a turn. The error is shown but the session
stays alive — type your next task to continue. If the same error repeats, check
the message for clues (auth issues, missing binaries, filesystem permissions).

### `Session aborted: estimated cost exceeds the budget limit`

You've hit the `budget_usd` cap. Increase it in `.env`:

```env
AI_CODE_BUDGET=5.00
```

Or pass a higher limit for a single session by editing the config temporarily.
The default $1.00 limit is intentionally conservative.

### `ai:code requires an interactive TTY`

The command must be run in a real terminal — not piped, not in a CI job, not
through a non-interactive shell. This is required because the approval prompts
need user input.

### `Path '...' is outside the workspace root`

The agent tried to access a file outside the configured workspace (defaults to
`base_path()`). If you're working in a monorepo or non-standard layout, set
`workspace` in `config/ai-code.php` to the correct root path.

### `Path '...' matches protected pattern`

The agent tried to read or write a protected file (`.env`, `vendor/`, etc.).
This is intentional — protected paths are blocked in code, not via prompting. If
you need to unblock a path (e.g. you're working on a package inside `vendor/`),
remove or narrow the relevant pattern in `protected_paths`.

### `old_str not found` / `old_str appears N times`

The agent is trying to edit a file but the string it wants to replace either
doesn't exist or appears more than once. This usually means the agent needs to
re-read the file to get the current content. Tell it: "read the file again before
editing."

### `Pint is not installed`

Install Pint as a dev dependency in the host app:

```bash
composer require laravel/pint --dev
```

### Tests fail during a session

This is expected behaviour. When `RunTests` returns failures, the agent reads the
output and attempts to fix the code. If it gets stuck, tell it what the failure
means or paste the relevant stack trace as your next message.

### Healer branch is pushed but no PR is opened

Tackle could not find a GitHub token. Check the resolution order:

1. `GITHUB_TOKEN` in `.env`
2. GitHub CLI: run `gh auth status` — if it shows "not logged in", run `gh auth login`
3. `ai-code.healing.github_token` in `config/ai-code.php`

### `git worktree add failed`

This means either:
- The project is not inside a git repository. Run `git init && git commit -am "initial"`.
- The branch name already exists. Delete it: `git branch -D tackle/heal-<id>` and retry.

### Healer ran but didn't fix the bug

The agent's fix will be in the PR (or logged). Review the PR description for the
agent's reasoning. If the diagnosis was wrong, close the PR and fix it manually —
the agent's attempt gives you a starting point and a working branch to build on.

### Healer triggered a loop

`HealJobFailure` has `$tries = 1`, so a failing healer cannot create a loop with
itself. If you see repeated healer jobs it means the original job keeps failing
and `threshold` is set to 1. Raise the threshold or disable healing until the
root cause is resolved:

```env
AI_CODE_HEALING_ENABLED=false
```

---

## Known Risks

> **`laravel/ai` is new and fast-moving.** The version is pinned to `^0.1`.
> Breaking changes upstream are likely. Check the changelog before upgrading.

> **This tool modifies your codebase and runs commands.** Always run it inside a
> committed git working tree so you have a clear undo path (`git checkout -- .`).

---

## Development

```bash
composer install
./vendor/bin/pest        # run tests
./vendor/bin/pint        # format code
```

---

## License

MIT
