# Laravel Tackle

**An AI agent harness for Laravel.**

Tackle is the runtime layer that lets AI agents operate inside your Laravel
application — reading code, executing tools, running tests, and taking action,
with safety boundaries enforced at the framework level.

Think of it the way you think of Claude Code, Codex, or GitHub Copilot — but
purpose-built for Laravel and installed directly into your app via Composer.
The harness ships with three built-in agents and a full tool infrastructure you
can extend or build on top of:

- **`ai:code`** — an interactive coding agent that reads your codebase, edits files, runs tests, and formats code
- **`ai:fix`** — a focused fix session: paste an exception, point it at a Sentry issue (`--sentry=ID`) or GitHub issue (`--issue=N`), and the agent diagnoses, patches, and verifies the fix. Runs in worktree mode by default.
- **`ai:review`** — a read-only agent that reviews git diffs and surfaces real issues with severity levels
- **`ai:explain`** — explains what a file, class, or method does in plain English
- **`ai:test`** — generates a Pest test file for any class or method
- **Self-healer** — an autonomous agent that listens for failed jobs and scheduled tasks, diagnoses the exception, patches the code, and opens a PR or applies the fix — without you lifting a finger

Every agent runs through the same tool infrastructure and safety layer. You can
add your own tools, write new agents, and swap the default agent entirely —
all without forking the package.

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
- [MCP server](#mcp-server)
- [Self-healing queue workers](#self-healing-queue-workers)
  - [Scheduled command healing](#scheduled-command-healing)
  - [Per-class opt-out](#per-class-opt-out)
  - [Audit log](#audit-log)
- [Fix an issue](#fix-an-issue)
- [Code review](#code-review)
- [Explain code](#explain-code)
- [Generate tests](#generate-tests)
- [Health check](#health-check)
- [Replay a healing attempt](#replay-a-healing-attempt)
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

## How the harness works

Tackle has three layers:

| Layer | What it is |
|---|---|
| **Tools** | Action primitives agents can call — `ReadFile`, `EditFile`, `RunTests`, `RunShell`, etc. Every tool goes through `PathGuard` and shell policy before executing. |
| **Agents** | Classes implementing `CodingAgent` that receive a prompt, call tools, and return a result. Tackle ships three; you can add your own. |
| **Safety** | `PathGuard` blocks reads/writes outside the workspace and protected paths. `BudgetTracker` aborts the session when estimated spend exceeds the limit. Shell modes gate command execution. All enforced in PHP — not advisory. |

The self-healer adds a fourth piece: an event-driven runtime that spins up an
agent autonomously in an isolated git worktree whenever a job or scheduled task
fails. It is the same harness, running unattended.

---

## Before you start

Run through this checklist once before your first session:

- [ ] Commit or stash any in-progress work — the agent will modify files, and a
      clean git state is your undo button.
- [ ] Set `ANTHROPIC_API_KEY` in `.env` (or the key for your chosen provider).
- [ ] Publish the `laravel/ai` config: `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`
- [ ] Publish the Tackle config: `php artisan vendor:publish --tag="tackle-config"` (publishes as `config/tackle.php`)
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
php artisan vendor:publish --tag="tackle-config"
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
| `AI_CODE_SHELL` | `approve` | Shell mode: `off` \| `allowlist` \| `approve` \| `yolo`. Can be set per-environment in `config/tackle.php` — production defaults to `off`. |
| `AI_CODE_WORKTREE` | `false` | Enable worktree isolation (production defaults to `true`). |
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

### Worktree mode

Worktree mode runs the agent against an isolated git worktree rather than your
live files. All edits land in a temp directory; nothing touches your working
tree until you open a PR.

```bash
php artisan ai:code --worktree      # force on for this session
php artisan ai:code --no-worktree   # force off for this session
```

When active, the intro line shows `· worktree: on` and a note box explains that
live files are untouched. After each turn, the git diff stat is labelled
**"Worktree changes (live files untouched)"** so it's clear no production code
has been modified.

Production environments default to `worktree: on` (see [Configuration](#configuration)).
Worktrees are cleaned up automatically when the session ends. Use `tackle:prune`
to remove any that were left behind by interrupted sessions.

### Interactive UX

`ai:code` uses [Laravel Prompts](https://laravel.com/docs/13.x/prompts) throughout for a fully interactive terminal experience:

- **`suggest()`** — the task prompt shows your previous tasks as autocomplete suggestions. Use ↑↓ to browse history.
- **`stream()`** — AI text responses stream to the terminal in real time, token by token.
- **`title()`** — the terminal tab title updates dynamically as the agent works: "Tackle — Thinking…", "Tackle — Reading files", "Tackle — Running tests", "Tackle — Ready".
- **`select()` / `multiselect()`** — when the agent calls `AskUser`, you're presented with a styled selection list rather than a raw text prompt.
- **`confirm()`** — when the agent calls `ConfirmAction` before a destructive operation, you see a styled yes/no prompt.
- **`note()`** — after each turn a `git diff --stat` is shown as a note block so you can see what changed.
- **`warning()`** — a styled warning appears when you approach 80% of your session budget.
- **`error()`** — styled errors on agent failures or budget overruns.
- **`intro()` / `outro()`** — session start and end use styled banners showing the model, budget, and shell mode.

### Example session

```
 ┌──────────────────────────────────────────────────────────────┐
 │  Laravel Tackle  ·  claude-sonnet-4-6  ·  $1.00  ·  approve │
 └──────────────────────────────────────────────────────────────┘

 ┌ What should I work on? ─────────────────────────────────────┐
 │ Add a slug field to the Post model                          │
 └─────────────────────────────────────────────────────────────┘

  🔍 searching for Post model
  📖 reading app/Models/Post.php
  📝 creating database/migrations/2024_01_01_add_slug_to_posts.php
  ✓ File saved
  ✏️  editing app/Models/Post.php
  ✓ File saved
  🧪 running tests
  ✓ Done

 Migration created, `$fillable` updated, and all tests pass.

 ╭─────────────────────────────────────────────────────────────╮
 │  app/Models/Post.php | 2 +-                                 │
 │  1 migration file    | 15 +++++++++++++++                   │
 ╰─────────────────────────────────────────────────────────────╯

 ┌ What should I work on? ─────────────────────────────────────┐
 │ Make the slug auto-generate from the title on creation  ▲   │
 │ Add a slug field to the Post model                      ▼   │
 └─────────────────────────────────────────────────────────────┘
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

After publishing the config, edit `config/tackle.php`. All values can be set
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

    // Shell execution policy — string or per-environment array.
    // String form (backward-compatible): applies to all environments.
    // Array form: keyed by environment name; production defaults to 'off'.
    'shell' => [
        'local'      => env('AI_CODE_SHELL', 'approve'),
        'staging'    => env('AI_CODE_SHELL', 'approve'),
        'production' => env('AI_CODE_SHELL', 'off'),
    ],

    'shell_allowlist' => ['composer', 'npm', 'php artisan'],

    // Artisan commands the agent may run without confirmation — per environment.
    // Flat array form is still accepted for backward compatibility.
    'artisan_allowlist' => [
        'local'      => ['make:*', 'migrate:*', 'db:seed', 'route:list', 'test'],
        'staging'    => ['migrate', 'route:list'],
        'production' => ['route:list'],
    ],

    // Artisan commands that require an interactive confirmation before running.
    'artisan_destructive' => [
        'local'      => ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db:wipe'],
        'staging'    => [],
        'production' => [],
    ],

    // Worktree isolation — edits go to a temp worktree instead of live files.
    // Production defaults to true; other environments default to false.
    'worktree' => [
        'local'      => env('AI_CODE_WORKTREE', false),
        'staging'    => env('AI_CODE_WORKTREE', false),
        'production' => env('AI_CODE_WORKTREE', true),
    ],

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

Shell mode can be set as a plain string (applies to all environments) or as a
per-environment array (shown above). The `production` key defaults to `off`.

### Artisan allowlist and destructive list

`artisan_allowlist` controls which commands the agent may run freely. `artisan_destructive`
lists commands that require an interactive terminal confirmation before running. Commands
in neither list are refused outright. Both support glob patterns (`make:*` covers
`make:model`, `make:controller`, etc.) and can be a flat array (all environments) or a
per-environment keyed array.

`RunTests` also respects the allowlist — if `test` is not in the allowlist for the
current environment, the tool is refused.

### Protected paths

The `protected_paths` globs prevent the agent from reading or writing sensitive
files regardless of what it is asked to do. This is enforced in PHP, not via
prompting. Add your own patterns here if your project has additional secrets.

---

## Built-in tools

These tools are available to the agent in every session.

**Filesystem**

| Tool | What it does |
|---|---|
| `ReadFile` | Reads a file's contents. Always runs through `PathGuard` first. |
| `Glob` | Lists files matching a pattern. Protected paths are excluded from results. |
| `SearchCode` | Grep-style search returning file + line + snippet. Capped at 50 results. |
| `EditFile` | `str_replace` edit — `old_str` must appear exactly once or the edit is refused. |
| `WriteFile` | Creates a new file. Refuses if the path already exists. |

**Execution**

| Tool | What it does |
|---|---|
| `RunArtisan` | Runs `php artisan <command>` in a subprocess. Allowlist-gated. |
| `RunTests` | Runs Pest or `php artisan test` in a subprocess. Returns full output. |
| `RunPint` | Runs Laravel Pint to format files. Called before finishing a task. |
| `RunLarastan` | Runs PHPStan / Larastan static analysis and returns the findings. Accepts an optional `path` and `level` override. No-ops gracefully if `vendor/bin/phpstan` is not present. |
| `RunShell` | General shell — governed by the `shell` config mode. |

**Observability**

| Tool | What it does |
|---|---|
| `ReadLog` | Returns the last N lines of `storage/logs/laravel.log`. Accepts an optional filter string. |
| `QueryDatabase` | Runs a read-only `SELECT` query and returns results as JSON. Capped at 100 rows. |
| `ListRoutes` | Returns a formatted table of all registered routes with method, URI, name, and action. |
| `GitDiff` | Shows a git diff — supports staged, a specific commit, a branch range, or a path. |
| `ReadTelescopeEntry` | Reads Telescope exception entries. Pass a job UUID for a specific lookup, or omit to return recent exceptions. No-ops gracefully if Telescope is not installed. |
| `ReadSentryIssue` | Fetches a Sentry issue by ID — exception, stacktrace, breadcrumbs, and request context. Omit the ID to list recent unresolved issues for the configured project. No-ops gracefully if `SENTRY_AUTH_TOKEN` / `SENTRY_ORG` are not set. |
| `ReadGitHubIssue` | Fetches a GitHub issue by number — title, body, labels, and all comments. Omit the number to list recent open issues. No-ops gracefully if `GITHUB_TOKEN` / `GITHUB_REPO` are not set. |
| `ReadPullRequest` | Fetches a GitHub pull request by number — title, body, **branch name (head ref)**, base branch, state, author, and comments. Use this (not `ReadGitHubIssue`) when the user references a PR number, especially when the branch name is needed for `CommitAndPush`. |
| `CreateGitHubIssue` | Opens a new GitHub issue with a title and body. |
| `CreatePullRequest` | Creates a branch, commits all worktree changes, pushes to origin, and opens a GitHub pull request. |
| `CommitAndPush` | Stages all changes, fetches the remote branch tip, rebases onto it, commits, and pushes via `HEAD:<branch>` — without checking out the branch. Use this to add follow-up commits to an existing PR from a worktree session. Always pass the `branch` parameter (get it from `ReadPullRequest`). |
| `AskUser` | Presents the user with a `select()` or `multiselect()` prompt and returns their choice. The agent calls this when there are multiple valid paths and it wants the user to decide. |
| `ConfirmAction` | Presents the user with a `confirm()` prompt before a destructive or irreversible operation. Returns `"confirmed"` or `"cancelled"`. |

All file reads happen in-process. Everything that executes code runs as a
subprocess, so a broken generated file cannot crash the agent session.

---

## MCP server

Tackle's tools aren't only for Tackle's agents. `tackle:mcp` serves them over
the [Model Context Protocol](https://modelcontextprotocol.io) (stdio), so any
MCP client — Claude Code, Cursor, Zed — can use Laravel-aware tools like
`ListRoutes`, `QueryDatabase`, `ReadTelescopeEntry`, and `RunLarastan` against
your app, with Tackle's safety layer still enforced in PHP: protected paths,
the artisan allowlist, and SELECT-only database queries all apply exactly as
they do for Tackle's own agents.

Register it with Claude Code from your app directory:

```bash
claude mcp add tackle -- php artisan tackle:mcp
```

Or add it to `.mcp.json` manually:

```json
{
  "mcpServers": {
    "tackle": {
      "command": "php",
      "args": ["artisan", "tackle:mcp"]
    }
  }
}
```

The exposed tool set is controlled by `config('tackle.mcp.tools')` and
defaults to read/inspect and analysis tools only — no file writes, no shell.
Add write tools (`EditFile`, `WriteFile`, `RunPint`, …) to the list if you
trust the connected client. Interactive tools (`AskUser`, `ConfirmAction`)
are refused outright: an MCP client has no terminal to answer their prompts.
Avoid tools that ask for terminal confirmation, such as `CommitAndPush` —
they would hang the stdio session.

---

## GitHub Issues integration

When `GITHUB_TOKEN` and `GITHUB_REPO` are set, the `ReadGitHubIssue` tool becomes active. The agent can fetch any issue by number — title, description, labels, and all comments — giving it full context before starting work.

### Configuration

Add these to your `.env`:

```env
GITHUB_TOKEN=ghp_...        # personal access token with repo scope
GITHUB_REPO=owner/repo      # e.g. acme/my-app
```

The `GITHUB_TOKEN` is shared with the self-healer (PR mode), so no extra setup is needed if healing is already configured. Generate a token at **GitHub → Settings → Developer settings → Personal access tokens** with `repo` scope (or a fine-grained token with **Issues: read** permission).

### How it works

Ask the agent naturally:

```
> implement issue #42
> what are the open GitHub issues?
```

When given an issue number, the tool fetches the issue body plus all comments and returns them as a single block of context. When no number is given, it returns a summary list of recent open issues (pull requests are filtered out automatically).

### Health check

```bash
php artisan tackle:health
```

Reports `✓ GitHub configured (owner/repo) — ReadGitHubIssue tool is active` when both vars are present.

---

## Sentry integration

When `SENTRY_AUTH_TOKEN` and `SENTRY_ORG` are set, the `ReadSentryIssue` tool becomes active. The agent can fetch the latest event for any Sentry issue — including the full exception, stacktrace, breadcrumbs, and HTTP request context — and use it as additional context when diagnosing bugs.

### Configuration

Add these to your `.env`:

```env
SENTRY_AUTH_TOKEN=sntrys_...   # auth token with issue:read scope
SENTRY_ORG=your-org-slug       # visible in your Sentry URL (sentry.io/organizations/<slug>/)
SENTRY_PROJECT=your-project    # project slug — required for listing recent issues
```

These are the same env vars used by the [Sentry CLI](https://docs.sentry.io/cli/), so no extra setup is needed if you already use it.

Generate a token at **Sentry → Settings → Account → API → Auth Tokens** with the `issue:read` scope.

### How it works

Ask the agent naturally:

```
> there's a DivisionByZeroError in Sentry (#4821) — can you fix it?
> what are my recent unresolved Sentry issues?
```

When given an issue ID, the tool calls `GET /api/0/organizations/{org}/issues/{id}/events/latest/` and returns the exception type, message, stacktrace (top 15 frames, most recent first), breadcrumbs (last 10), and request method/URL.

When no ID is given, it calls `GET /api/0/projects/{org}/{project}/issues/` and returns a summary list of recent unresolved issues.

### Health check

```bash
php artisan tackle:health
```

Reports `✓ Sentry configured — ReadSentryIssue tool is active` when credentials are present, or a warning with setup instructions if they are missing.

---

## Self-healing queue workers

When enabled, Tackle listens for failed queue jobs and failed scheduled commands,
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

Publish and run the migration, then enable via `.env`:

```bash
php artisan vendor:publish --tag="tackle-migrations"
php artisan migrate
```

```env
AI_CODE_HEALING_ENABLED=true
```

The event listeners register automatically once this is set to `true`.

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

1. `GITHUB_TOKEN` in `.env` (or the `tackle.healing.github_token` config key)
2. GitHub CLI (`~/.config/gh/hosts.yml`) — if you have `gh` installed and
   authenticated, Tackle reads your token automatically with no extra config.
3. If no token is found, the branch is pushed but the PR is not opened. A log
   entry records that you need to configure a token.

```env
GITHUB_TOKEN=ghp_...
```

### Configuration

All healer options live under the `healing` key in `config/tackle.php`:

| Option | Env var | Default | Description |
|---|---|---|---|
| `enabled` | `AI_CODE_HEALING_ENABLED` | `false` | Enable or disable the healer |
| `mode` | `AI_CODE_HEALING_MODE` | `pr` | `pr` = open a pull request; `patch` = apply directly |
| `queue` | `AI_CODE_HEALING_QUEUE` | `healer` | Queue name for the `HealJobFailure` job |
| `threshold` | `AI_CODE_HEALING_THRESHOLD` | `1` | Number of failures before healing triggers |
| `base_branch` | `AI_CODE_HEALING_BASE_BRANCH` | `main` | Branch PRs are opened against |
| `branch_prefix` | `AI_CODE_HEALING_BRANCH_PREFIX` | `tackle/heal-` | Prefix for fix branches |
| `github_token` | `GITHUB_TOKEN` | — | GitHub token for opening PRs |
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

### Scheduled command healing

Tackle also listens to the `ScheduledTaskFailed` event, which Laravel fires when
a task registered in `App\Console\Kernel::schedule()` (or a `Schedule` class)
throws an exception.

The healing flow is identical to queue jobs — an isolated git worktree, an AI
agent, a test run, then a PR or patch. The one difference: scheduled tasks are
not re-dispatched after a patch (they run on their own schedule). The fix simply
takes effect the next time the task runs.

No extra configuration is needed beyond `AI_CODE_HEALING_ENABLED=true`.

### Per-class opt-out

Some jobs should never be auto-patched — payment processors, email senders,
anything where an untested change would be worse than the failure. Use the
`#[Healable(false)]` attribute to opt out:

```php
use Tackle\Attributes\Healable;

#[Healable(false)]
class ChargeSubscription implements ShouldQueue
{
    public function handle(): void
    {
        // Tackle will skip this job entirely — even when AI_CODE_HEALING_ENABLED=true.
    }
}
```

The listener checks for the attribute via reflection before dispatching a heal
job. Jobs without the attribute, or with `#[Healable(true)]`, are healed
normally.

### Audit log

Every healing attempt — successful or not — is written to the
`tackle_healing_log` table. View recent entries with:

```bash
php artisan tackle:healing-log
```

The table output shows when, what failed, whether tests passed, the outcome,
and a link to the PR or branch:

```
+-------------+----------------+--------------------+-----------+-------+------------+
| When        | Type           | Subject            | Tests     | Out.  | PR / Branch|
+-------------+----------------+--------------------+-----------+-------+------------+
| 2 mins ago  | job            | BrokenJob          | ✗         | PR    | github.com/|
| 1 hour ago  | scheduled_task | SendWeeklyReport   | ✓         | patched| tackle/... |
+-------------+----------------+--------------------+-----------+-------+------------+
```

**Filters:**

```bash
# Show only job failures
php artisan tackle:healing-log --type=job

# Show only scheduled task failures
php artisan tackle:healing-log --type=scheduled_task

# Show only successful patches
php artisan tackle:healing-log --outcome=patched

# Show only PR-mode results
php artisan tackle:healing-log --outcome=pr_opened

# Show more entries
php artisan tackle:healing-log --limit=50
```

The audit log requires the migration to have been run:

```bash
php artisan vendor:publish --tag="tackle-migrations"
php artisan migrate
```

If the migration has not been run, healing continues normally — the log write
degrades gracefully.

### Healer limitations

- The healer targets **code bugs** — logic errors the AI can diagnose and fix.
  It is not designed for infrastructure issues (database down, disk full, etc.).
- The fix branch is pushed to `origin` — your CI pipeline will run on it and
  can catch anything the local test run missed.
- In `patch` mode, if tests fail the healer falls back to PR mode automatically
  so nothing is merged without verification.
- The healer never modifies `.env`, `vendor/`, `storage/`, or `.git/` — the
  same path guards apply as in interactive mode.
- Healer jobs have `$tries = 1`. A failing healer does not create a healing loop.

---

## Fix an issue

`php artisan ai:fix` opens a focused fix session. It loads context from a Sentry issue, a GitHub issue, or a pasted exception — then fires the agent immediately, without you having to describe the task. Worktree mode is on by default so live files are never touched until you open a PR.

```bash
# Paste or describe the exception at the prompt
php artisan ai:fix

# Load context from a Sentry issue
php artisan ai:fix --sentry=4821

# Load context from a GitHub issue
php artisan ai:fix --issue=42
```

After the agent applies the fix, the session stays open for follow-up:

```
> add a regression test for this
> open a pull request
> exit
```

All shell and worktree flags from `ai:code` are supported:

```bash
php artisan ai:fix --sentry=4821 --no-worktree   # edit live files directly
php artisan ai:fix --issue=42 --yolo              # skip shell approval prompts
```

---

## Code review

`php artisan ai:review` feeds your git diff to a read-only AI agent that reads
the surrounding codebase for context, then surfaces real issues grouped by file
with severity levels.

```bash
# Review everything since your last commit (staged + unstaged)
php artisan ai:review

# Review only staged changes
php artisan ai:review --staged

# PR-style review — your branch vs. another branch
php artisan ai:review --against=main

# Review a specific commit
php artisan ai:review --commit=abc1234

# Tell the agent what to prioritise
php artisan ai:review --against=main --focus=security,performance
```

### Output format

Findings are grouped by file with three severity levels:

| Level | Meaning |
|---|---|
| 🔴 Critical | Bugs that will cause failures, security vulnerabilities, data loss risks |
| 🟡 Warning | Edge cases, missing error handling, performance concerns, breaking changes |
| 🟢 Suggestion | Improvements worth considering but not blocking |

The review ends with a one-line verdict: **LGTM** / **LGTM with minor notes** /
**Needs changes**.

### How it works

The `ReviewAgent` is a read-only agent — it has access to `ReadFile`, `Glob`,
and `SearchCode` but no editing tools. Before commenting on any changed function
or class it reads the full file for context, so findings are grounded in the
actual codebase rather than the diff alone.

### Focus areas

Pass `--focus` with a comma-separated list to direct the agent's attention:

```bash
php artisan ai:review --focus=security
php artisan ai:review --focus=performance,tests
php artisan ai:review --staged --focus=bugs,security
```

Any plain-language description works — `security`, `performance`, `n+1 queries`,
`missing tests`, `breaking changes`, etc.

---

## Explain code

`php artisan ai:explain` reads a file or class and explains what it does in plain
English — inputs, outputs, side effects, and any non-obvious behaviour. The agent
reads the full file and any closely-related classes before responding.

```bash
# Explain a whole file
php artisan ai:explain app/Services/BillingService.php

# Focus on a specific method
php artisan ai:explain app/Services/BillingService.php --method=charge
```

---

## Generate tests

`php artisan ai:test` reads a class, checks your existing test conventions, and
writes a Pest test file covering the happy path, edge cases, and error conditions.
It runs the tests after writing to confirm they pass.

```bash
# Generate tests for a class
php artisan ai:test app/Services/BillingService.php

# Focus on a single method
php artisan ai:test app/Services/BillingService.php --method=charge

# Force a feature or unit test
php artisan ai:test app/Http/Controllers/UserController.php --feature
php artisan ai:test app/Services/BillingService.php --unit
```

Test type is inferred from the path when no flag is given — controllers, jobs,
commands, listeners, and middleware default to `Feature`; everything else defaults
to `Unit`.

---

## Health check

`php artisan tackle:health` verifies that the package is correctly set up. Run it
after installation or when something isn't working as expected.

```bash
php artisan tackle:health
```

It checks:

- `config/tackle.php` and `config/ai.php` are published
- An API key is configured for the active provider
- The project is a git repository with at least one commit
- `.env.testing` exists (warns if missing)
- If healing is enabled: migration has been run, GitHub token is available

---

## Pruning dangling worktrees

If a session is interrupted before cleanup (e.g. a crash or `kill -9`), worktrees
may be left behind in `/tmp`. Use `tackle:prune` to remove them:

```bash
php artisan tackle:prune

# Preview without removing
php artisan tackle:prune --dry-run
```

Only directories matching the `tackle-worktree-*` pattern are touched — the
command will never remove your main working tree.

---

## Replay a healing attempt

`php artisan tackle:replay` re-dispatches a previous healing attempt — useful when
you want to retry after adjusting config or fixing something manually.

```bash
# Replay the most recent healing attempt
php artisan tackle:replay

# Replay the last attempt for a specific job class
php artisan tackle:replay --class="App\Jobs\ProcessPayment"

# Replay a specific log entry by ID
php artisan tackle:replay --id=42
```

---

## Limitations

Things Tackle cannot do in v1:

- **No internet access.** The agent cannot fetch URLs, read documentation, or
  call external APIs. It works only with files in your workspace.
- **No binary files.** `ReadFile` reads text. Images, compiled assets, and other
  binaries are not readable by the agent.
- **No auto-commit or push in standard mode.** In a normal session all edits are
  left unstaged. In worktree mode the agent can commit and push to an existing PR
  branch using the `CommitAndPush` tool, but it will always call `ConfirmAction`
  first.
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

### Generators

Tackle ships generator commands so you don't have to look up method signatures:

```bash
# Scaffold a tool at app/Ai/Tools/MyTool.php
php artisan tackle:tool MyTool

# Scaffold an agent that extends DefaultCodingAgent (most common)
php artisan tackle:agent MyAgent

# Scaffold a bare CodingAgent implementation
php artisan tackle:agent MyAgent --full
```

To customise the generated stubs, publish them first:

```bash
php artisan vendor:publish --tag="tackle-stubs"
```

This copies the stubs to `stubs/tackle/` in your project root. Both commands
check for published stubs before falling back to the package defaults.

---

### Adding your own tools

Create a class that extends `Tackle\Tools\AbstractTool`, then extend
`DefaultCodingAgent` to merge it into the tool list, and rebind the contract.

**Step 1 — Generate the tool (or write it manually):**

```bash
php artisan tackle:tool ReadDatabase
```

**Step 2 — Implement the tool:**

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

**Step 3 — Extend the agent (or generate it):**

```bash
php artisan tackle:agent MyCodingAgent
```

**Step 4 — Wire in your tool:**

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

**Step 5 — Rebind in your service provider:**

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

Internally, Tackle injects provider and model values via two custom Laravel
contextual attributes — `#[AiProvider]` and `#[AiModel]` — so any agent you
write by extending `DefaultCodingAgent` inherits these config values
automatically through the container.

---

## Safety

- **Protected paths** — the agent cannot read or write `.env`, `storage/`,
  `vendor/`, or `.git/` by default. Enforced in `PathGuard`, not via prompting.
  The model cannot talk its way around this.
- **Unstaged edits** — in standard mode all file changes are left unstaged.
  Review with `git diff`; discard with `git checkout -- .`.
- **Worktree isolation** — in worktree mode all edits go to a temp copy of the
  repo. Live files are untouched until you open a PR. The worktree is cleaned up
  automatically when the session ends.
- **Environment-aware defaults** — production defaults to `shell: off` and
  `worktree: on`. The artisan allowlist is narrowed per environment (e.g. `test`
  and `migrate:*` are only allowed locally). All of this is enforced in PHP, not
  via prompting.
- **Allowlist-gated commands** — `RunArtisan` and `RunTests` check the allowlist
  for the current environment before executing. Commands not in the list are
  refused with a clear message. Destructive commands (e.g. `migrate:fresh`) require
  an interactive confirmation even when allowlisted.
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
`workspace` in `config/tackle.php` to the correct root path.

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
3. `tackle.healing.github_token` in `config/tackle.php`

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
