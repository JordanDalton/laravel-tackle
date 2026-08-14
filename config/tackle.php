<?php

use Tackle\Agents\ExplorerAgent;
use Tackle\Agents\TestWriterAgent;
use Tackle\Tools\GitDiff;
use Tackle\Tools\Glob;
use Tackle\Tools\ListRoutes;
use Tackle\Tools\QueryDatabase;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadGitHubIssue;
use Tackle\Tools\ReadLog;
use Tackle\Tools\ReadPullRequest;
use Tackle\Tools\ReadSentryIssue;
use Tackle\Tools\ReadTelescopeEntry;
use Tackle\Tools\RunLarastan;
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | The laravel/ai provider name to use. Must match a key in config/ai.php.
    | Defaults to 'anthropic' — set ANTHROPIC_API_KEY in your .env.
    |
    */
    'provider' => env('AI_CODE_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | The model used by the coding agent. Defaults to Claude Sonnet which
    | offers a good balance of capability and cost. Override via env.
    |
    */
    'model' => env('AI_CODE_MODEL', 'claude-sonnet-4-6'),

    /*
    |--------------------------------------------------------------------------
    | Max Steps
    |--------------------------------------------------------------------------
    |
    | Maximum number of tool calls before `ai:run` aborts the run. Prevents a
    | runaway loop from burning the budget with nobody watching. Override for a
    | single run with `--max-steps`.
    |
    | This is a ceiling, not a grant: each agent also declares its own
    | #[MaxSteps] attribute (40 on DefaultCodingAgent), which laravel/ai reads by
    | reflection and which cannot be raised at runtime. Setting this higher than
    | the agent's attribute has no effect. Interactive sessions are bounded by
    | the attribute alone — you are there to stop them.
    |
    */
    'max_steps' => env('AI_CODE_MAX_STEPS', 40),

    /*
    |--------------------------------------------------------------------------
    | Budget (USD)
    |--------------------------------------------------------------------------
    |
    | Hard spend limit for the session. The agent will abort once the
    | estimated cost (tracked via token counts) exceeds this amount.
    |
    */
    'budget_usd' => env('AI_CODE_BUDGET', 1.00),

    /*
    |--------------------------------------------------------------------------
    | Token Pricing
    |--------------------------------------------------------------------------
    |
    | Per-million-token rates used to estimate spend against the budget. The
    | defaults approximate Claude Sonnet. When using another model or provider
    | (OpenAI, Gemini, Groq, Ollama, ...), set these to its actual rates so
    | budget enforcement stays meaningful — for local models, set both to 0.
    |
    */
    'pricing' => [
        'input_per_mtok' => env('AI_CODE_PRICE_INPUT', 3.00),
        'output_per_mtok' => env('AI_CODE_PRICE_OUTPUT', 15.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Compaction
    |--------------------------------------------------------------------------
    |
    | Long ai:code sessions re-send their whole history every turn. When the
    | conversation exceeds threshold_chars, Tackle summarizes the older part
    | and keeps the last keep_recent messages verbatim. Compact manually at
    | any time with /compact; disable auto-compaction by setting the
    | threshold very high.
    |
    */
    'compaction' => [
        'threshold_chars' => env('AI_CODE_COMPACTION_THRESHOLD', 60000),
        'keep_recent' => env('AI_CODE_COMPACTION_KEEP', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shell Execution Policy
    |--------------------------------------------------------------------------
    |
    | Controls whether and how RunShell executes arbitrary commands.
    | Can be a single string (applies to all environments) or an array keyed
    | by APP_ENV. Use '*' as a catch-all fallback.
    |
    |   off        - RunShell refuses everything. Use RunArtisan/RunTests.
    |   allowlist  - Only commands whose first token is in shell_allowlist run.
    |   approve    - Every command shows a confirmation prompt.
    |   yolo       - Runs anything with no prompt. WARNING: dangerous.
    |
    | Production defaults to 'off' — set AI_CODE_SHELL=approve in .env to opt in.
    |
    */
    'shell' => [
        'local' => env('AI_CODE_SHELL', 'approve'),
        'staging' => env('AI_CODE_SHELL', 'approve'),
        'production' => env('AI_CODE_SHELL', 'off'),
    ],

    'shell_allowlist' => [
        'composer',
        'npm',
        'php artisan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan Allowlist
    |--------------------------------------------------------------------------
    |
    | Glob patterns for Artisan commands the agent may run unattended via
    | RunArtisan. Keyed by APP_ENV; use '*' as a catch-all. A flat (non-keyed)
    | array is also accepted and applies to all environments (legacy format).
    |
    | Commands not matching any pattern are refused outright.
    |
    */
    'artisan_allowlist' => [
        'local' => [
            'make:*',
            'migrate:*',
            'db:seed',
            'route:list',
            'test',
        ],
        'staging' => [
            'migrate',
            'route:list',
        ],
        'production' => [
            'route:list',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan Destructive List
    |--------------------------------------------------------------------------
    |
    | Commands that match these patterns trigger a hard terminal confirmation
    | prompt before running — enforced in PHP, not by the model. An empty list
    | for an environment means no destructive commands are permitted there at
    | all (the agent will be refused even if the user confirms).
    |
    */
    'artisan_destructive' => [
        'local' => [
            'migrate:fresh',
            'migrate:reset',
            'migrate:refresh',
            'db:wipe',
        ],
        'staging' => [],
        'production' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected Paths
    |--------------------------------------------------------------------------
    |
    | Glob patterns (relative to workspace) that the agent can NEVER read or
    | write. This is the credential-exfiltration defense — do not weaken it.
    |
    */
    'protected_paths' => [
        '.env',
        '.env.*',
        'storage/*',
        'vendor/*',
        '.git/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Worktree Isolation
    |--------------------------------------------------------------------------
    |
    | When enabled, ai:code creates a temporary git worktree at the start of
    | each session. The agent edits files in the worktree copy — live production
    | files are never touched. Changes are committed and pushed via CreatePullRequest.
    |
    | Can be a boolean or an APP_ENV-keyed array. Use AI_CODE_WORKTREE=true/false
    | in .env to override. The --worktree / --no-worktree flags on ai:code also
    | override for a single session.
    |
    */
    'worktree' => [
        'local' => env('AI_CODE_WORKTREE', false),
        'staging' => env('AI_CODE_WORKTREE', false),
        'production' => env('AI_CODE_WORKTREE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace
    |--------------------------------------------------------------------------
    |
    | The root directory the agent operates within. null defaults to the app's
    | base path. Set to an absolute path to restrict the agent further.
    |
    */
    'workspace' => null,

    /*
    |--------------------------------------------------------------------------
    | Memory / Persistence
    |--------------------------------------------------------------------------
    |
    |   file      - Persist the transcript to storage/ai-code/*.json after
    |               every turn (default). ai:code resumes it on the next run;
    |               --session=name keeps separate histories per workstream.
    |   none      - Ephemeral; each session starts fresh.
    |
    */
    'memory' => env('AI_CODE_MEMORY', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Subagents
    |--------------------------------------------------------------------------
    |
    | Agents the main coding agent may delegate to via the Delegate tool. A
    | subagent runs the delegated task in its own fresh context with its own
    | (usually narrower) toolset and returns only its final report — the
    | delegating agent's context stays clean.
    |
    | Each entry maps a name to an agent class and a description. The
    | description is what the delegating model reads when deciding whether
    | and where to delegate — write it like you would a tool description.
    |
    | Subagents share the session's budget, their tools pass through the same
    | safety layer (protected paths, allowlists, hooks, events), and they can
    | never delegate further or prompt the user. Any class implementing
    | Tackle\Contracts\CodingAgent works — including your own. Set to an
    | empty array to remove the Delegate tool entirely.
    |
    */
    'subagents' => [
        'explorer' => [
            'agent' => ExplorerAgent::class,
            'description' => 'Read-only codebase exploration: locates files, traces how a feature works across classes, and reports back with precise file references. Delegate broad "find/understand X" research here.',
        ],
        'test-writer' => [
            'agent' => TestWriterAgent::class,
            'description' => 'Writes a Pest test file for a class or behaviour and runs it. Give it the class or method to cover and any edge cases worth testing.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hooks
    |--------------------------------------------------------------------------
    |
    | User-defined hooks run around agent activity — deterministic policy
    | enforced in PHP or shell, not by the model. Four events:
    |
    |   pre_tool       before a tool executes. May block the call or rewrite
    |                  its arguments. First block wins; rewrites chain.
    |   post_tool      after a tool executes. Observe only.
    |   session_start  when an agent session begins. Observe only.
    |   session_end    when an agent session ends. Observe only.
    |
    | Each hook is an array with either `run` (a shell command) or `using`
    | (a class name — implements Tackle\Contracts\ToolHook or is invokable).
    | Tool hooks may also set `match` (a tool-name glob or array of globs,
    | e.g. 'Run*' or ['EditFile', 'WriteFile']; default '*') and `timeout`
    | (seconds, default 10).
    |
    | Shell protocol: the JSON event payload arrives on stdin. Exit 0 allows
    | the call — for pre_tool, stdout may contain {"arguments": {...}} to
    | rewrite the tool arguments. Exit 2 blocks the call, and stderr becomes
    | the refusal message the agent sees. Any other exit code, a timeout, or
    | a crash is logged and ignored — a broken hook never blocks a session.
    |
    | Class hooks receive the payload array and return null (allow), false
    | (block), a string (block with that message), or an array (pre_tool
    | only: replacement arguments).
    |
    */
    'hooks' => [
        'pre_tool' => [
            // ['match' => 'RunShell', 'run' => 'scripts/tackle/guard-shell.sh'],
            // ['match' => '*', 'using' => \App\Hooks\AuditToolCalls::class],
        ],
        'post_tool' => [
            // ['match' => ['EditFile', 'WriteFile'], 'run' => 'vendor/bin/pint --dirty'],
        ],
        'session_start' => [],
        'session_end' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard Pack
    |--------------------------------------------------------------------------
    |
    | First-party pre_tool hooks that block the concrete exfiltration and
    | circumvention paths described in the README's Safety section: writing
    | code that dumps env() secrets, outbound-network exfiltration transport,
    | and composer scripts (arbitrary PHP). Register them by adding the guards
    | to tackle.hooks.pre_tool, or run `php artisan tackle:install guard`.
    |
    | IMPORTANT: this is DEFENSE-IN-DEPTH, not containment. These guards run
    | in-process at the same privilege as the agent — they raise the cost of
    | an attack and catch mistakes and unsophisticated prompt injection, but
    | a determined agent that avoids the known signatures is not stopped. Real
    | containment is OS-level isolation (throwaway credentials in a jailed
    | container). See "What the guards do and don't stop" in the README.
    |
    | Each mode is 'block' (default) or 'off'; network also accepts 'confirm'.
    |
    */
    'guard' => [
        'secrets' => env('AI_CODE_GUARD_SECRETS', 'block'),
        'network' => env('AI_CODE_GUARD_NETWORK', 'block'),
        'composer_scripts' => env('AI_CODE_GUARD_COMPOSER', 'block'),

        // Extra regex bodies (no delimiters) appended to SecretExfiltrationGuard.
        'secret_patterns' => [],

        /*
        | Injection classifier (experimental, off by default). Screens the
        | untrusted-input readers — Sentry issues, GitHub issues, PR comments,
        | the inbound prompt-injection surface — with a cheap model. Flagged
        | content is fenced and labelled as untrusted data rather than blocked,
        | so the readers still work. Fails OPEN: a classifier error passes the
        | content through unshielded. It is itself an LLM and can be injected —
        | it lowers the odds a crafted payload steers the agent, it does not
        | eliminate them. Defense-in-depth, below OS isolation. Enabling it adds
        | one cheap model call per untrusted read.
        */
        'injection_classifier' => [
            'enabled' => env('AI_CODE_GUARD_INJECTION', false),
            'provider' => env('AI_CODE_GUARD_INJECTION_PROVIDER'),
            'model' => env('AI_CODE_GUARD_INJECTION_MODEL'),
            'tools' => ['ReadSentryIssue', 'ReadGitHubIssue', 'ReadPullRequest'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Healing Queue Workers
    |--------------------------------------------------------------------------
    |
    | When enabled, every failed queue job triggers the Tackle Healer:
    | an AI agent that reads the exception, locates the failing code, applies
    | a minimal patch in an isolated git worktree, runs your test suite, then
    | either commits the fix directly (mode=patch) or opens a GitHub PR
    | (mode=pr) for human review. Set AI_CODE_HEALING_ENABLED=true to opt in.
    |
    | mode:
    |   pr    - Push a fix branch and open a GitHub PR (default — safest).
    |   patch - Merge the fix back to the working tree and re-dispatch the job.
    |
    | threshold:
    |   Number of times a job class must fail before healing is triggered.
    |   Default 1 = heal on the first failure.
    |
    | queue:
    |   The queue name the HealJobFailure job runs on. Run a separate worker:
    |   php artisan queue:work --queue=healer
    |
    | base_branch:
    |   The branch that fix PRs are opened against.
    |
    | github_token:
    |   Personal access token used to open PRs. Resolution order:
    |   GITHUB_TOKEN env var → GitHub CLI (~/.config/gh/hosts.yml) → null.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Sentry Integration
    |--------------------------------------------------------------------------
    |
    | When set, the ReadSentryIssue tool can fetch issue details (exception,
    | stacktrace, breadcrumbs, request context) directly from the Sentry API.
    |
    | auth_token  - A Sentry auth token with issue:read scope.
    |               Generate one at https://sentry.io/settings/account/api/auth-tokens/
    | org         - Your Sentry organisation slug (visible in the Sentry URL).
    | project     - Your Sentry project slug (optional — used for listing issues).
    |
    | These match the standard Sentry CLI env vars (SENTRY_AUTH_TOKEN, SENTRY_ORG,
    | SENTRY_PROJECT) so no extra setup is needed if you already use the Sentry CLI.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | GitHub Integration
    |--------------------------------------------------------------------------
    |
    | When set, the ReadGitHubIssue tool can fetch issue details (title, body,
    | labels, and all comments) directly from the GitHub API.
    |
    | token  - A GitHub personal access token with repo scope (or a fine-grained
    |           token with Issues: read permission). Shared with the self-healer.
    |           Generate one at https://github.com/settings/tokens
    | repo   - The owner/repo slug, e.g. "acme/my-app".
    |
    */
    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'repo' => env('GITHUB_REPO'),
    ],

    'sentry' => [
        'auth_token' => env('SENTRY_AUTH_TOKEN'),
        'org' => env('SENTRY_ORG'),
        'project' => env('SENTRY_PROJECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | `php artisan tackle:mcp` serves the tools listed below over the Model
    | Context Protocol (stdio), so external agents — Claude Code, Cursor,
    | Zed, or any MCP client — can use Tackle's Laravel-aware tools with the
    | same safety guards (protected paths, artisan allowlist, SELECT-only
    | queries) enforced in PHP.
    |
    | The default set is read/inspect + analysis only. You may add write
    | tools (Tackle\Tools\EditFile, WriteFile, RunPint, ...) if you trust
    | the connected client — but never AskUser or ConfirmAction (they need
    | an interactive terminal and are refused by tackle:mcp), and avoid
    | tools that prompt for terminal confirmation, such as CommitAndPush,
    | which would hang the stdio session.
    |
    */
    'mcp' => [
        'tools' => [
            ReadFile::class,
            Glob::class,
            SearchCode::class,
            GitDiff::class,
            ListRoutes::class,
            ReadLog::class,
            QueryDatabase::class,
            ReadTelescopeEntry::class,
            ReadSentryIssue::class,
            ReadGitHubIssue::class,
            ReadPullRequest::class,
            RunLarastan::class,
            RunTests::class,
        ],
    ],

    'healing' => [
        'enabled' => env('AI_CODE_HEALING_ENABLED', false),
        'mode' => env('AI_CODE_HEALING_MODE', 'pr'),
        'queue' => env('AI_CODE_HEALING_QUEUE', 'healer'),
        'threshold' => (int) env('AI_CODE_HEALING_THRESHOLD', 1),
        'branch_prefix' => env('AI_CODE_HEALING_BRANCH_PREFIX', 'tackle/heal-'),
        'base_branch' => env('AI_CODE_HEALING_BASE_BRANCH', 'main'),
        'github_token' => env('GITHUB_TOKEN', null),
        'telescope' => env('AI_CODE_HEALING_TELESCOPE', true),
    ],

];
