## Laravel Tackle

Laravel Tackle is an AI coding assistant and self-healing queue worker package built on `laravel/ai`. It provides an interactive terminal agent (`ai:code`), a read-only code reviewer (`ai:review`), and a self-healing system that automatically diagnoses and patches failing queue jobs and scheduled tasks.

### Commands

- `php artisan ai:code` — interactive coding agent session. The agent reads files, edits code, runs tests, and formats with Pint.
- `php artisan ai:review` — one-shot read-only review of a git diff. Accepts `--staged`, `--against=<branch>`, `--commit=<sha>`, and `--focus=<areas>`.
- `php artisan tackle:healing-log` — view the audit log of all healing attempts. Accepts `--type`, `--outcome`, and `--limit` filters.

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

### Customization

To add tools or change agent behaviour, extend `DefaultCodingAgent` and rebind `Tackle\Contracts\CodingAgent` in a service provider. The `CodingAgent` contract extends `laravel/ai`'s `Agent`, `HasTools`, and `Conversational` contracts.
