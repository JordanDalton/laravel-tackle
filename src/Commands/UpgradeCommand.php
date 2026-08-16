<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Prompts\Stream;
use Tackle\Agents\UpgradeAgent;
use Tackle\Commands\Concerns\ResolvesSessionOptions;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Events\SessionEnded;
use Tackle\Events\SessionStarted;
use Tackle\Exceptions\AgentInterruptedException;
use Tackle\Prompts\TackleSuggestPrompt;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\BudgetTracker;
use Tackle\Support\Reporting\JsonReporter;
use Tackle\Support\Reporting\RunReporter;
use Tackle\Support\Reporting\TextReporter;
use Tackle\Support\WorktreeManager;
use Tackle\Upgrade\AuditIssueReporter;
use Tackle\Upgrade\Auditor;
use Throwable;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class UpgradeCommand extends Command
{
    use ResolvesSessionOptions;

    /** Completed normally. */
    public const EXIT_OK = 0;

    /** The agent, composer, or provider errored. */
    public const EXIT_ERROR = 1;

    /** Stopped because the spend limit was reached. */
    public const EXIT_BUDGET = 2;

    /** Hit the step ceiling without finishing. */
    public const EXIT_MAX_STEPS = 4;

    protected $signature = 'ai:upgrade
        {packages?*     : One or more Composer packages to upgrade (vendor/name). Each gets its own session and PR. Omit to pick from available majors.}
        {--audit        : Print available major upgrades and what blocks them, then exit (no AI involved)}
        {--issue        : Also create/update/close a GitHub issue mirroring the audit (implies --audit; requires GITHUB_TOKEN + GITHUB_REPO)}
        {--headless     : Run unattended — no prompts, plan confirmation folded into the PR body, PR opened automatically; for CI and schedulers}
        {--output=text  : Headless result format (text|json)}
        {--ref-issue=   : GitHub issue number the headless PR should reference (Refs #N)}
        {--budget=      : Override the spend limit in USD (applies per package)}
        {--max-steps=   : Stop a package session after this many tool calls (headless)}
        {--model=       : Override the model for this session}
        {--provider=    : Override the laravel/ai provider for this session}
        {--shell=       : Override the shell mode for this session (off|allowlist|approve|yolo)}
        {--off          : Shorthand for --shell=off}
        {--allowlist    : Shorthand for --shell=allowlist}
        {--approve      : Shorthand for --shell=approve}
        {--yolo         : Shorthand for --shell=yolo}
        {--worktree     : Force worktree isolation for this session}
        {--no-worktree  : Disable worktree isolation for this session}';

    protected $description = 'Safely upgrade a Composer dependency across a major version — audits what is upgradable, plans from the package upgrade guide, resolves constraints, fixes breaking changes, and verifies with your test suite.';

    private ?Stream $activeStream = null;

    private array $history = [];

    private int $steps = 0;

    private ?string $pullRequestUrl = null;

    public function handle(WorktreeManager $worktrees): int
    {
        if (! App::runningInConsole()) {
            $this->error('ai:upgrade must be run from the terminal.');

            return self::FAILURE;
        }

        $auditor = new Auditor(config('tackle.workspace') ?? base_path());

        if ($this->option('audit') || $this->option('issue')) {
            return $this->renderAudit($auditor, (bool) $this->option('issue'));
        }

        if (! $this->applyShellOverride()) {
            return self::EXIT_ERROR;
        }

        $this->applyModelOverride();

        if (($budgetOverride = $this->option('budget')) !== null) {
            if (! is_numeric($budgetOverride) || (float) $budgetOverride <= 0) {
                $this->error("Invalid --budget value '{$budgetOverride}'. Must be a positive number.");

                return self::EXIT_ERROR;
            }

            config(['tackle.budget_usd' => (float) $budgetOverride]);
        }

        if (($stepsOverride = $this->option('max-steps')) !== null) {
            if (! ctype_digit((string) $stepsOverride) || (int) $stepsOverride < 1) {
                $this->error("Invalid --max-steps value '{$stepsOverride}'. Must be a positive integer.");

                return self::EXIT_ERROR;
            }

            config(['tackle.max_steps' => (int) $stepsOverride]);
        }

        if ($this->option('headless')) {
            return $this->runHeadless($worktrees, $auditor);
        }

        if (! $this->isTty()) {
            $this->error('ai:upgrade requires an interactive TTY. Use --audit for a non-interactive report, or --headless for an unattended upgrade.');

            return self::FAILURE;
        }

        // Audit against the live workspace before any worktree exists —
        // the worktree checkout has no vendor/ to inspect yet.
        $targets = $this->resolveTargets($auditor);

        if ($targets === []) {
            return self::SUCCESS;
        }

        $total = count($targets);
        $results = [];

        foreach (array_values($targets) as $i => [$package, $context]) {
            // Each package gets a fresh session: its own worktree, its own
            // agent context, and its own budget — package three must not
            // start with package one's spend or its conversation.
            app()->forgetInstance(BudgetTracker::class);
            $budget = app(BudgetTracker::class);
            $this->history = [];

            $useWorktree = $this->resolveWorktreeMode();

            if ($useWorktree) {
                try {
                    $worktrees->create();
                } catch (\RuntimeException $e) {
                    $this->warn('Could not create worktree: '.$e->getMessage().' — falling back to live workspace.');
                    $useWorktree = false;
                }
            }

            try {
                $code = $this->runSession(app(UpgradeAgent::class), $budget, $useWorktree, $package, $context, $i + 1, $total);
            } finally {
                if ($worktrees->active()) {
                    $worktrees->cleanup();
                }
            }

            $results[$package] = ['code' => $code, 'spend' => $budget->summary()];
        }

        if ($total > 1) {
            $this->renderBatchSummary($results);
        }

        return collect($results)->contains(fn ($result) => $result['code'] !== self::SUCCESS)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @param  array<string, array{code: int, spend: string}>  $results */
    private function renderBatchSummary(array $results): void
    {
        $this->line('');
        $this->line('<options=bold>Batch summary:</>');

        foreach ($results as $package => $result) {
            $status = $result['code'] === self::SUCCESS
                ? '<fg=green>✓ session completed</>'
                : '<fg=red>✗ session failed</>';

            $this->line("  {$status}  <fg=cyan>{$package}</>  <fg=gray>({$result['spend']})</>");
        }

        $this->line('');
        $this->line('<fg=gray>Each upgrade touches composer.lock — after merging one PR, rebase the next and re-run composer update on its branch.</>');
    }

    /**
     * Unattended upgrades: one non-interactive session per package, no
     * prompts, PR opened automatically. The PR is the human gate — and
     * because the bound interaction policy is never interactive, composer
     * lifecycle scripts cannot be enabled in this mode at all.
     */
    private function runHeadless(WorktreeManager $worktrees, Auditor $auditor): int
    {
        $format = (string) $this->option('output');

        if (! in_array($format, ['text', 'json'], strict: true)) {
            $this->error("Invalid --output value '{$format}'. Must be one of: text, json.");

            return self::EXIT_ERROR;
        }

        $refIssue = $this->option('ref-issue');

        if ($refIssue !== null && ! ctype_digit((string) $refIssue)) {
            $this->error("Invalid --ref-issue value '{$refIssue}'. Must be an issue number.");

            return self::EXIT_ERROR;
        }

        $packages = array_values(array_unique($this->argument('packages')));

        if ($packages === []) {
            $this->error('Headless mode requires explicit package names — it will not pick targets itself. Run ai:upgrade --audit to see what is available.');

            return self::EXIT_ERROR;
        }

        // Confirmations auto-approve so the playbook proceeds to the PR with
        // nobody watching. This never reaches composer scripts: RunComposer
        // requires an *interactive* approval, and this policy is final-false.
        $this->laravel->instance(InteractionPolicy::class, new AutoApproveInteraction);

        try {
            $majors = $auditor->majors();
        } catch (\RuntimeException $e) {
            $this->getOutput()->getErrorStyle()->writeln('<comment>'.$e->getMessage().' — continuing without audit context.</comment>');
            $majors = [];
        }

        $maxSteps = (int) config('tackle.max_steps', 40);
        $worstExit = self::EXIT_OK;

        foreach ($packages as $package) {
            // Fresh budget and agent per package, same isolation as the
            // interactive batch.
            app()->forgetInstance(BudgetTracker::class);
            $budget = app(BudgetTracker::class);
            $this->steps = 0;
            $this->pullRequestUrl = null;

            $reporter = $format === 'json'
                ? new JsonReporter($this->output)
                : new TextReporter($this->output);

            $useWorktree = $this->resolveWorktreeMode();

            if ($useWorktree) {
                try {
                    $worktrees->create();
                } catch (\RuntimeException $e) {
                    $reporter->note('Could not create worktree: '.$e->getMessage().' — falling back to live workspace.');
                    $useWorktree = false;
                }
            }

            SessionStarted::dispatch('ai:upgrade', (string) config('tackle.provider', 'anthropic'), (string) config('tackle.model'));

            $reporter->starting([
                'package' => $package,
                'model' => config('tackle.model'),
                'shell' => $this->resolveShellMode(),
                'worktree' => $useWorktree ? $worktrees->path() : 'off',
                'budget' => sprintf('$%.2f', $budget->budgetUsd()),
                'max_steps' => $maxSteps,
            ]);

            $context = $auditor->promptContext($package, $packages, $majors);
            $outcome = 'completed';
            $exit = self::EXIT_OK;
            $error = null;

            try {
                app(UpgradeAgent::class)
                    ->stream($this->headlessPrompt($package, $context, $refIssue !== null ? (int) $refIssue : null))
                    ->each(fn ($event) => $this->handleHeadlessEvent($event, $budget, $reporter, $maxSteps));
            } catch (AgentInterruptedException $e) {
                $outcome = $e->getMessage();
                $exit = $outcome === 'budget_exceeded' ? self::EXIT_BUDGET : self::EXIT_MAX_STEPS;
            } catch (Throwable $e) {
                $outcome = 'error';
                $exit = self::EXIT_ERROR;
                $error = $e->getMessage();
            }

            $root = $worktrees->active() ? $worktrees->path() : base_path();

            $reporter->finish([
                'ok' => $exit === self::EXIT_OK,
                'package' => $package,
                'outcome' => $outcome,
                'error' => $error,
                'steps' => $this->steps,
                'diff_stat' => trim((string) shell_exec('git -C '.escapeshellarg($root).' diff --stat 2>/dev/null')),
                'usage' => [
                    'input_tokens' => $budget->inputTokens(),
                    'output_tokens' => $budget->outputTokens(),
                    'estimated_cost_usd' => round($budget->estimatedCost(), 4),
                ],
                'budget_usd' => $budget->budgetUsd(),
                'pr_url' => $this->pullRequestUrl,
            ]);

            if ($worktrees->active()) {
                $worktrees->cleanup();
            }

            SessionEnded::dispatch(
                'ai:upgrade',
                $budget->inputTokens(),
                $budget->outputTokens(),
                round($budget->estimatedCost(), 4),
            );

            $worstExit = max($worstExit, $exit);
        }

        return $worstExit;
    }

    private function headlessPrompt(string $package, string $context, ?int $refIssue): string
    {
        $refLine = $refIssue !== null
            ? " Include `Refs #{$refIssue}` on its own line in the PR body — Refs, not Closes: the audit issue closes itself once no majors remain."
            : '';

        return "Upgrade the Composer package `{$package}` to its next major version in this Laravel application.\n\n"
            ."--- Pre-session audit (from the live workspace) ---\n{$context}---\n\n"
            .'This session is HEADLESS: no user is present, so never ask questions or wait for input. '
            .'Follow the upgrade playbook autonomously, with these adjustments: '
            .'(1) Skip the plan-confirmation step — write the plan summary into the PR body instead. '
            .'(2) Composer lifecycle scripts cannot be enabled in this session; work with --no-scripts throughout. '
            .'(3) When verification passes, open the pull request immediately via CreatePullRequest, with the honest summary as the body.'.$refLine.' '
            .'(4) If the upgrade cannot be completed safely, do NOT open a PR — stop and state exactly where it is stuck and why.';
    }

    private function handleHeadlessEvent(mixed $event, BudgetTracker $budget, RunReporter $reporter, int $maxSteps): void
    {
        if ($event instanceof TextDelta) {
            $reporter->text($event->delta);

            return;
        }

        if ($event instanceof ToolCall) {
            $this->steps++;

            $reporter->toolCall($event->toolCall->name, (array) $event->toolCall->arguments);

            // Enforced here because laravel/ai resolves its own MaxSteps from a
            // class attribute, which cannot be overridden at runtime.
            if ($this->steps > $maxSteps) {
                throw new AgentInterruptedException('max_steps_reached');
            }

            return;
        }

        if ($event instanceof ToolResult) {
            $result = (string) ($event->toolResult->result ?? '');

            $reporter->toolResult($event->toolResult->name, $result);

            if ($event->toolResult->name === 'CreatePullRequest'
                && preg_match('#https://github\.com/\S+/pull/\d+#', $result, $matches)) {
                $this->pullRequestUrl = $matches[0];
            }

            return;
        }

        if ($event instanceof StreamEnd) {
            $budget->record($event->usage->promptTokens, $event->usage->completionTokens);

            if ($budget->overBudget()) {
                throw new AgentInterruptedException('budget_exceeded');
            }
        }
    }

    private function renderAudit(Auditor $auditor, bool $syncIssue = false): int
    {
        try {
            $majors = $auditor->majors();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($majors as $i => $major) {
            $majors[$i]['blockers'] = $auditor->whyNot($major['name'], Auditor::constraintFor($major['latest']));
        }

        if ($majors === []) {
            $this->info('Every direct dependency is on its latest major version.');
            $this->line('Minor and patch updates (if any) are listed by: composer outdated --direct');
        } else {
            $this->line('');
            $this->line('<options=bold>Major upgrades available:</>');
            $this->line('');

            foreach ($majors as $major) {
                $this->line("  <fg=cyan>{$major['name']}</>  {$major['version']} → <options=bold>{$major['latest']}</>");

                if ($major['blockers'] !== '') {
                    foreach (explode("\n", $major['blockers']) as $line) {
                        $this->line('    <fg=gray>'.$line.'</>');
                    }
                }

                $this->line('');
            }

            $this->line('Start one with: <options=bold>php artisan ai:upgrade vendor/package</>');
        }

        if ($syncIssue) {
            try {
                $this->info(app(AuditIssueReporter::class)->sync($majors));
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Determine which packages to upgrade and build the audit context that
     * seeds each session's first prompt.
     *
     * @return list<array{string, string}>
     */
    private function resolveTargets(Auditor $auditor): array
    {
        try {
            $majors = $auditor->majors();
        } catch (\RuntimeException $e) {
            $this->warn($e->getMessage().' — continuing without audit context.');
            $majors = [];
        }

        $packages = array_values(array_unique($this->argument('packages')));

        if ($packages === []) {
            if ($majors === []) {
                $this->info('Every direct dependency is on its latest major version — nothing to upgrade.');

                return [];
            }

            $packages = multiselect(
                label: 'Which packages do you want to upgrade? Each gets its own session and PR.',
                options: collect($majors)->mapWithKeys(fn ($m) => [
                    $m['name'] => "{$m['name']}  ({$m['version']} → {$m['latest']})",
                ])->all(),
                required: true,
                scroll: 10,
            );
        }

        return array_map(
            fn (string $package) => [$package, $auditor->promptContext($package, $packages, $majors)],
            $packages,
        );
    }

    private function runSession(UpgradeAgent $agent, BudgetTracker $budget, bool $worktree, string $package, string $context, int $position, int $total): int
    {
        $model = config('tackle.model', 'claude-sonnet-4-6');
        $budgetUsd = config('tackle.budget_usd', 1.00);
        $wtLabel = $worktree ? ' · worktree: on' : '';
        $batchLabel = $total > 1 ? " · package {$position}/{$total}" : '';

        title('Tackle Upgrade — Ready');
        intro("Laravel Tackle Upgrade  ·  {$model}  ·  \${$budgetUsd} budget{$wtLabel}{$batchLabel}");

        if ($worktree) {
            note('Worktree mode — the upgrade happens in an isolated copy of the repo. Live files (and live vendor/) are untouched until you open a PR.');
        }

        $firstPrompt = "Upgrade the Composer package `{$package}` to its next major version in this Laravel application.\n\n"
            ."--- Pre-session audit (from the live workspace) ---\n{$context}---\n\n"
            .'Follow the upgrade playbook: audit, then present a plan grounded in the package upgrade guide and this '
            .'app\'s actual usage, and confirm with me before mutating anything. Resolve constraints, fix breaking '
            .'changes, verify with the test suite, and finish with an honest summary and a pull request offer.';

        $this->line("<fg=cyan>  Target: {$package}</>");
        $this->line('');

        title('Tackle Upgrade — Thinking…');
        $this->line('');

        try {
            $this->runAgentTurn($agent, $budget, $firstPrompt);
        } catch (Throwable $e) {
            $this->closeStream();
            promptError('Agent error: '.$e->getMessage());
            note('The session is still active — continue with a new task.');
        }

        $this->showGitDiff();
        $this->history[] = "Upgrade {$package}";

        while (true) {
            title('Tackle Upgrade — Ready');
            $this->line('');
            $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');

            $exitLabel = $position < $total ? '"exit" to move to the next package' : '"exit" to quit';

            $task = (new TackleSuggestPrompt(
                label: 'Follow up or type '.$exitLabel,
                options: fn (string $value) => array_reverse($this->history),
                placeholder: 'e.g. "continue", "run the tests again", "open the PR", or type "exit"',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history' : '',
                scroll: 10,
            ))->prompt();

            if (in_array(strtolower(trim($task)), ['exit', 'quit', 'q'], strict: true)) {
                title('');
                outro($budget->summary().($position < $total ? " · {$package} session closed — next package coming up." : ' · Goodbye!'));

                return self::SUCCESS;
            }

            $this->history[] = $task;

            if ($budget->overBudget()) {
                title('Tackle Upgrade — Budget Exceeded');
                promptError(sprintf(
                    'Session aborted: estimated cost ($%.4f) exceeds the budget limit ($%.2f).',
                    $budget->estimatedCost(),
                    $budget->budgetUsd(),
                ));

                return self::FAILURE;
            }

            title('Tackle Upgrade — Thinking…');
            $this->line('');

            try {
                $this->runAgentTurn($agent, $budget, $task);
            } catch (Throwable $e) {
                $this->closeStream();
                promptError('Agent error: '.$e->getMessage());
                note('The session is still active — continue with a new task.');
            }

            $this->showGitDiff();
        }
    }

    private function runAgentTurn(UpgradeAgent $agent, BudgetTracker $budget, string $task): void
    {
        try {
            $response = $agent->stream($task);

            $response->each(function ($event) use ($budget) {
                if ($event instanceof TextDelta) {
                    if ($this->activeStream === null) {
                        $this->line('');
                        $this->activeStream = stream();
                    }
                    $this->activeStream->append($event->delta);

                    return;
                }

                if ($event instanceof ToolCall) {
                    $this->closeStream();
                    $this->renderToolCall($event);

                    return;
                }

                if ($event instanceof ToolResult) {
                    $this->renderToolResult($event);

                    return;
                }

                if ($event instanceof StreamEnd) {
                    $this->closeStream();
                    $budget->record($event->usage->promptTokens, $event->usage->completionTokens);

                    if ($budget->overBudget()) {
                        promptError(sprintf(
                            'Budget limit reached ($%.4f / $%.2f). Stopping.',
                            $budget->estimatedCost(),
                            $budget->budgetUsd(),
                        ));
                    } elseif ($budget->estimatedCost() / $budget->budgetUsd() >= 0.8) {
                        warning(sprintf(
                            'Budget at %.0f%% ($%.4f / $%.2f) — consider wrapping up soon.',
                            ($budget->estimatedCost() / $budget->budgetUsd()) * 100,
                            $budget->estimatedCost(),
                            $budget->budgetUsd(),
                        ));
                    }
                }
            });
        } finally {
            $this->closeStream();
        }
    }

    private function closeStream(): void
    {
        if ($this->activeStream !== null) {
            $this->activeStream->close();
            $this->activeStream = null;
        }
    }

    private function renderToolCall(ToolCall $event): void
    {
        $tool = $event->toolCall->name;
        $args = $event->toolCall->arguments;

        if (in_array($tool, ['AskUser', 'ConfirmAction'], strict: true)) {
            return;
        }

        $summary = match ($tool) {
            'ReadFile' => '📖 reading '.($args['path'] ?? '?'),
            'Glob' => '🔍 listing '.($args['pattern'] ?? '?'),
            'SearchCode' => '🔍 searching for '.($args['query'] ?? '?'),
            'EditFile' => '✏️  editing '.($args['path'] ?? '?'),
            'WriteFile' => '📝 creating '.($args['path'] ?? '?'),
            'RunComposer' => '📦 composer '.trim(($args['subcommand'] ?? '?').' '.($args['args'] ?? '')),
            'ReadPackageDocs' => '📚 '.(empty($args['file'])
                ? 'listing docs for '.($args['package'] ?? '?')
                : 'reading '.($args['package'] ?? '?').'/'.$args['file']),
            'RunArtisan' => '⚡ artisan '.($args['command'] ?? '?'),
            'RunTests' => '🧪 running tests'.(! empty($args['filter']) ? ' (filter: '.$args['filter'].')' : ''),
            'RunPint' => '✨ formatting with pint',
            'RunLarastan' => '🔎 running larastan'.(! empty($args['path']) ? ' on '.$args['path'] : ''),
            'RunShell' => '💻 shell: '.($args['command'] ?? '?'),
            'GitDiff' => '🔀 git diff'.(! empty($args['path']) ? ' '.$args['path'] : ''),
            'CreatePullRequest' => '🚀 opening pull request',
            'CommitAndPush' => '📤 committing and pushing',
            default => '→ '.$tool,
        };

        title('Tackle Upgrade — '.strip_tags($summary));
        $this->line("<fg=cyan>  {$summary}</>");
    }

    private function renderToolResult(ToolResult $event): void
    {
        $tool = $event->toolResult->name;
        $result = (string) ($event->toolResult->result ?? '');

        if ($tool === 'RunComposer') {
            if (str_contains($result, 'is not permitted')) {
                $this->line('<fg=yellow>  ⚠ Refused — '.strtok($result, "\n").'</>');
            } elseif (str_contains($result, 'Your requirements could not be resolved')) {
                $this->line('<fg=red>  ✗ Solver conflict — agent will diagnose with why-not.</>');
            } elseif (str_starts_with($result, 'Command failed')) {
                $this->line('<fg=red>  ✗ Composer reported an error — agent will handle it.</>');
            } else {
                $this->line('<fg=green>  ✓ Done</>');
            }
        }

        if (in_array($tool, ['RunTests', 'RunArtisan', 'RunShell'], strict: true)) {
            if (str_starts_with($result, 'Shell execution is disabled')) {
                $this->line('<fg=yellow>  ⚠ Refused — shell is disabled in this environment.</>');
            } elseif (str_starts_with($result, "Command '") && str_contains($result, 'not in the allowlist')) {
                $this->line('<fg=yellow>  ⚠ Refused — command not in allowlist.</>');
            } elseif (str_starts_with($result, 'RunTests is disabled')) {
                $this->line('<fg=yellow>  ⚠ Refused — tests are disabled in this environment.</>');
            } elseif (str_contains($result, 'FAILED') || str_contains($result, 'Error')) {
                $this->line('<fg=red>  ✗ Command reported failures — agent will handle them.</>');
            } else {
                $this->line('<fg=green>  ✓ Done</>');
            }
        }

        if ($tool === 'RunLarastan') {
            if (str_contains($result, 'not installed')) {
                $this->line('<fg=yellow>  ⚠ PHPStan not installed — skipping static analysis.</>');
            } elseif (str_contains($result, '[ERROR]') || str_contains($result, 'error')) {
                $this->line('<fg=red>  ✗ Larastan found issues — agent will handle them.</>');
            } else {
                $this->line('<fg=green>  ✓ No issues found</>');
            }
        }

        if ($tool === 'CommitAndPush') {
            if ($result === 'Changes committed and pushed to the existing PR branch.') {
                $this->line('<fg=green>  ✓ Committed and pushed.</>');
            } elseif ($result === 'No changes to commit.' || str_starts_with($result, 'No changes to commit —')) {
                $this->line('<fg=yellow>  ⚠ No changes to commit.</>');
            } elseif ($result === 'Cancelled by user.') {
                $this->line('<fg=yellow>  ⚠ Push cancelled.</>');
            } else {
                $this->line('<fg=red>  ✗ '.$result.'</>');
            }
        }

        if ($tool === 'EditFile' || $tool === 'WriteFile') {
            if (str_contains($result, 'outside the workspace')
                || str_contains($result, 'could not be resolved')
                || str_contains($result, 'protected pattern')
                || str_contains($result, 'not found')
                || str_contains($result, 'not unique')) {
                $this->line('<fg=yellow>  ⚠ '.$result.'</>');
            } else {
                $this->line('<fg=green>  ✓ File saved</>');
            }
        }
    }

    private function showGitDiff(): void
    {
        $wt = app(WorktreeManager::class);
        $root = $wt->active() ? $wt->path() : base_path();

        if (! is_dir($root.'/.git') && ! $wt->active()) {
            return;
        }

        $output = shell_exec('git -C '.escapeshellarg($root).' diff --stat 2>/dev/null');

        if ($output && trim($output) !== '') {
            $this->line('');
            $label = $wt->active() ? 'Worktree changes (live files untouched)' : 'Uncommitted changes';
            note($label."\n".trim($output));
        }
    }

    private function resolveWorktreeMode(): bool
    {
        if ($this->option('worktree')) {
            return true;
        }

        if ($this->option('no-worktree')) {
            return false;
        }

        // ai:upgrade defaults worktree on — a failed resolution must never
        // leave the live composer.lock or vendor/ in a broken state.
        return true;
    }

    private function isTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
