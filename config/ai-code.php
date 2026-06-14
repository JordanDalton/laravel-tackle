<?php

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
    | Maximum number of tool-call / reasoning steps per agent turn. Prevents
    | runaway loops.
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
    | Shell Execution Policy
    |--------------------------------------------------------------------------
    |
    | Controls whether and how RunShell executes arbitrary commands.
    |
    |   off        - RunShell refuses everything. Use RunArtisan/RunTests.
    |   allowlist  - Only commands whose first token is in shell_allowlist run.
    |   approve    - Every command shows a confirmation prompt. (default)
    |   yolo       - Runs anything with no prompt. WARNING: dangerous.
    |
    */
    'shell' => env('AI_CODE_SHELL', 'approve'),

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
    | RunArtisan. Commands not matching any pattern are refused.
    |
    */
    'artisan_allowlist' => [
        'make:*',
        'migrate',
        'migrate:*',
        'db:seed',
        'route:list',
        'test',
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
    |   none      - Ephemeral; each session starts fresh.
    |   file      - Persist transcript to storage/ai-code/*.json (default).
    |   database  - Use laravel/ai RemembersConversations (requires running
    |               the laravel/ai migrations first).
    |
    */
    'memory' => env('AI_CODE_MEMORY', 'file'),

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
        'repo'  => env('GITHUB_REPO'),
    ],

    'sentry' => [
        'auth_token' => env('SENTRY_AUTH_TOKEN'),
        'org'        => env('SENTRY_ORG'),
        'project'    => env('SENTRY_PROJECT'),
    ],

    'healing' => [
        'enabled'       => env('AI_CODE_HEALING_ENABLED', false),
        'mode'          => env('AI_CODE_HEALING_MODE', 'pr'),
        'queue'         => env('AI_CODE_HEALING_QUEUE', 'healer'),
        'threshold'     => (int) env('AI_CODE_HEALING_THRESHOLD', 1),
        'branch_prefix' => env('AI_CODE_HEALING_BRANCH_PREFIX', 'tackle/heal-'),
        'base_branch'   => env('AI_CODE_HEALING_BASE_BRANCH', 'main'),
        'github_token'  => env('GITHUB_TOKEN', null),
        'telescope'     => env('AI_CODE_HEALING_TELESCOPE', true),
    ],

];
