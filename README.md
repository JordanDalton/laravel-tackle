<p align="center">
  <img src="art/mascot.png" width="200" alt="The Tackle mascot — a determined toolbox character with a sledgehammer, smashing through rubble">
</p>

<h1 align="center">Laravel Tackle</h1>

<p align="center">
  <a href="https://packagist.org/packages/jordandalton/laravel-tackle"><img src="https://img.shields.io/packagist/v/jordandalton/laravel-tackle.svg?style=flat-square" alt="Latest Version"></a>
  <a href="https://github.com/JordanDalton/laravel-tackle/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/JordanDalton/laravel-tackle/tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://packagist.org/packages/jordandalton/laravel-tackle"><img src="https://img.shields.io/packagist/dt/jordandalton/laravel-tackle.svg?style=flat-square" alt="Total Downloads"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/packagist/l/jordandalton/laravel-tackle.svg?style=flat-square" alt="License"></a>
</p>

<p align="center"><strong>An AI agent harness for Laravel</strong> — the runtime layer that lets AI agents operate <em>inside</em> your app: reading code, running tools and tests, and taking action, with safety boundaries enforced at the framework level.</p>

Think Claude Code or Codex, but purpose-built for Laravel and installed via Composer. Built on [`laravel/ai`](https://github.com/laravel/ai) and **provider-agnostic** — Anthropic (Claude) by default; OpenAI, Gemini, Groq, or local Ollama are two env vars away.

> 📚 **Full documentation → [tackle.jordandalton.com](https://tackle.jordandalton.com)**

## Quick start

```bash
composer require jordandalton/laravel-tackle
```

Set a provider key in `.env` (Anthropic by default):

```env
ANTHROPIC_API_KEY=sk-ant-...
```

Then start an interactive coding session:

```bash
php artisan ai:code
```

For a server deployment, verify that the configured key is not merely present
but accepted by the provider:

```bash
php artisan tackle:health --probe-provider
```

The probe makes one minimal model request. If interactive or remote chat shows
an HTTP 401, the model provider rejected the key loaded by that deployment;
this is separate from Tackler mobile and connector authentication. Correct the
provider key or URL, run `php artisan optimize:clear`, and restart the worker.

That's it — see [Your First Session](https://tackle.jordandalton.com/guide/first-session.html) for the guided tour. Requires **PHP 8.3+** and **Laravel 12 or 13**.

## What's in the box

A family of agents, all sharing one tool infrastructure and safety layer:

| Command | What it does |
|---|---|
| [`ai:code`](https://tackle.jordandalton.com/agents/interactive.html) | Interactive coding agent — reads the codebase, edits files, runs tests, plan mode, slash commands, context compaction. |
| [`ai:run`](https://tackle.jordandalton.com/agents/headless.html) | The same agent headless — one task, a JSON result, an exit code. For CI and cron. |
| [`ai:fix`](https://tackle.jordandalton.com/agents/fix.html) | Focused fix session — paste an exception or point it at a Sentry / GitHub issue; it diagnoses, patches, and verifies. |
| [`ai:review`](https://tackle.jordandalton.com/agents/review.html) | Read-only diff review with severity levels; posts inline comments on a PR. |
| [`ai:onboard`](https://tackle.jordandalton.com/agents/onboard.html) | A read-only tour of a codebase for a new developer; `--write` saves `docs/ONBOARDING.md`. |
| [`ai:upgrade`](https://tackle.jordandalton.com/agents/upgrade.html) | Safe major-version Composer upgrades — audit, plan, fix, verify — delivered as a PR. |
| [`ai:eval`](https://tackle.jordandalton.com/agents/eval.html) | Benchmark the agent against seeded bugs — fix rate, false-fix rate, tokens, cost. |
| [Self-healer](https://tackle.jordandalton.com/agents/self-healing.html) | Autonomous agent that heals failed jobs, scheduled tasks, and [Nightwatch](https://tackle.jordandalton.com/integrations/nightwatch.html) production issues — verifies the fix, opens a PR. |

Plus [`ai:explain`](https://tackle.jordandalton.com/agents/explain-and-test.html), [`ai:test`](https://tackle.jordandalton.com/agents/explain-and-test.html#generate-tests), and [`ai:respond`](https://tackle.jordandalton.com/agents/review.html).

Every agent also sees the **[application map](https://tackle.jordandalton.com/guide/app-map.html)** — your real columns and types off the live connection, relationships, scopes, observers, policies, factory states, and the fully resolved middleware and validation on any route, read from the booted application rather than inferred from files. An index of your models rides in every session's prompt; the detail is one tool call away. It's the thing an agent running outside your app can't have.

Every agent is extensible — [add your own tools](https://tackle.jordandalton.com/extending/custom-tools.html), [write new agents](https://tackle.jordandalton.com/extending/custom-agents.html), [hook the tool lifecycle](https://tackle.jordandalton.com/extending/hooks.html), or [swap the default agent](https://tackle.jordandalton.com/extending/custom-agents.html) — without forking. And the terminal isn't the only way in: [Tackle Remote](https://tackle.jordandalton.com/integrations/remote.html) drives the same harness from your phone's browser, and [Tackle Telegram](https://tackle.jordandalton.com/integrations/telegram.html) drives it from a chat — outbound-only, so it works from anywhere without your phone needing to reach your machine.

## Documentation

Everything lives at **[tackle.jordandalton.com](https://tackle.jordandalton.com)**:

- **Guide** — [What is Tackle?](https://tackle.jordandalton.com/guide/what-is-tackle.html) · [Installation](https://tackle.jordandalton.com/guide/installation.html) · [First Session](https://tackle.jordandalton.com/guide/first-session.html) · [Configuration](https://tackle.jordandalton.com/guide/configuration.html) · [Project Instructions](https://tackle.jordandalton.com/guide/project-instructions.html) · [Session Memory](https://tackle.jordandalton.com/guide/session-memory.html) · [Safety](https://tackle.jordandalton.com/guide/safety.html)
- **The Agents** — [interactive](https://tackle.jordandalton.com/agents/interactive.html) · [headless](https://tackle.jordandalton.com/agents/headless.html) · [fix](https://tackle.jordandalton.com/agents/fix.html) · [review](https://tackle.jordandalton.com/agents/review.html) · [onboard](https://tackle.jordandalton.com/agents/onboard.html) · [upgrade](https://tackle.jordandalton.com/agents/upgrade.html) · [eval](https://tackle.jordandalton.com/agents/eval.html) · [self-healing](https://tackle.jordandalton.com/agents/self-healing.html)
- **Integrations** — [GitHub](https://tackle.jordandalton.com/integrations/github.html) · [Sentry](https://tackle.jordandalton.com/integrations/sentry.html) · [Nightwatch](https://tackle.jordandalton.com/integrations/nightwatch.html) · [MCP](https://tackle.jordandalton.com/integrations/mcp.html) · [Remote](https://tackle.jordandalton.com/integrations/remote.html) · [Telegram](https://tackle.jordandalton.com/integrations/telegram.html)
- **Extending** — [tools](https://tackle.jordandalton.com/extending/custom-tools.html) · [agents](https://tackle.jordandalton.com/extending/custom-agents.html) · [hooks](https://tackle.jordandalton.com/extending/hooks.html) · [subagents](https://tackle.jordandalton.com/extending/subagents.html) · [models & providers](https://tackle.jordandalton.com/extending/models.html)
- **Reference** — [commands](https://tackle.jordandalton.com/reference/commands.html) · [tools](https://tackle.jordandalton.com/reference/tools.html)

## Safety

Tackle edits code and runs commands, and the boundaries are enforced in PHP, not by prompting: protected paths, per-environment [shell modes](https://tackle.jordandalton.com/guide/configuration.html#shell-modes), artisan allowlists, spend budgets, and worktree isolation. See [Safety](https://tackle.jordandalton.com/guide/safety.html).

Two things worth knowing before you start:

- **Run it inside a committed git tree** so you always have a clean undo (`git checkout -- .`).
- **`laravel/ai` is new and fast-moving.** It reshapes its `Agent` contract on most 0.x minors, and an incompatible signature is a compile-time fatal. Tackle supports `>=0.1 <0.11` and CI runs the suite across that range on PHP 8.3 and 8.4.

## Contributing

```bash
composer install
./vendor/bin/pest    # run the test suite
./vendor/bin/pint    # format
```

Bug reports and pull requests are welcome on [GitHub](https://github.com/JordanDalton/laravel-tackle/issues).

## Security

If you discover a security issue, please email jordan@daltonsolutions.com rather than opening a public issue.

## License

Laravel Tackle is open-sourced software licensed under the [MIT license](LICENSE.md).
