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
use Tackle\Prompts\TackleSuggestPrompt;
use Tackle\Support\BudgetTracker;
use Tackle\Support\WorktreeManager;
use Tackle\Upgrade\Auditor;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class UpgradeCommand extends Command
{
    protected $signature = 'ai:upgrade
        {package?       : The Composer package to upgrade (vendor/name). Omit to pick from available majors.}
        {--audit        : Print available major upgrades and what blocks them, then exit (no AI involved)}
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

    public function handle(BudgetTracker $budget, WorktreeManager $worktrees): int
    {
        if (! App::runningInConsole()) {
            $this->error('ai:upgrade must be run from the terminal.');

            return self::FAILURE;
        }

        $auditor = new Auditor(config('tackle.workspace') ?? base_path());

        if ($this->option('audit')) {
            return $this->renderAudit($auditor);
        }

        if (! $this->isTty()) {
            $this->error('ai:upgrade requires an interactive TTY. Use --audit for a non-interactive report.');

            return self::FAILURE;
        }

        $shell = match (true) {
            (bool) $this->option('off') => 'off',
            (bool) $this->option('allowlist') => 'allowlist',
            (bool) $this->option('approve') => 'approve',
            (bool) $this->option('yolo') => 'yolo',
            default => $this->option('shell'),
        };

        if ($shell !== null) {
            if (! in_array($shell, ['off', 'allowlist', 'approve', 'yolo'], strict: true)) {
                $this->error("Invalid --shell value '{$shell}'. Must be one of: off, allowlist, approve, yolo.");

                return self::FAILURE;
            }
            config(['tackle.shell' => $shell]);
        }

        // Audit against the live workspace before any worktree exists —
        // the worktree checkout has no vendor/ to inspect yet.
        [$package, $context] = $this->resolveTarget($auditor);

        if ($package === null) {
            return self::SUCCESS;
        }

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
            return $this->runSession(app(UpgradeAgent::class), $budget, $useWorktree, $package, $context);
        } finally {
            if ($worktrees->active()) {
                $worktrees->cleanup();
            }
        }
    }

    private function renderAudit(Auditor $auditor): int
    {
        try {
            $majors = $auditor->majors();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($majors === []) {
            $this->info('Every direct dependency is on its latest major version.');
            $this->line('Minor and patch updates (if any) are listed by: composer outdated --direct');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>Major upgrades available:</>');
        $this->line('');

        foreach ($majors as $major) {
            $this->line("  <fg=cyan>{$major['name']}</>  {$major['version']} → <options=bold>{$major['latest']}</>");

            $blockers = $auditor->whyNot($major['name'], Auditor::constraintFor($major['latest']));

            if ($blockers !== '') {
                foreach (explode("\n", $blockers) as $line) {
                    $this->line('    <fg=gray>'.$line.'</>');
                }
            }

            $this->line('');
        }

        $this->line('Start one with: <options=bold>php artisan ai:upgrade vendor/package</>');

        return self::SUCCESS;
    }

    /**
     * Determine which package to upgrade and build the audit context that
     * seeds the agent's first prompt.
     *
     * @return array{string|null, string}
     */
    private function resolveTarget(Auditor $auditor): array
    {
        try {
            $majors = $auditor->majors();
        } catch (\RuntimeException $e) {
            $this->warn($e->getMessage().' — continuing without audit context.');
            $majors = [];
        }

        $package = $this->argument('package');

        if ($package === null) {
            if ($majors === []) {
                $this->info('Every direct dependency is on its latest major version — nothing to upgrade.');

                return [null, ''];
            }

            $package = select(
                label: 'Which package do you want to upgrade?',
                options: collect($majors)->mapWithKeys(fn ($m) => [
                    $m['name'] => "{$m['name']}  ({$m['version']} → {$m['latest']})",
                ])->all(),
                scroll: 10,
            );
        }

        $target = collect($majors)->firstWhere('name', $package);

        $context = "Audit of direct dependencies with a new major available:\n";
        foreach ($majors as $major) {
            $context .= "- {$major['name']}: {$major['version']} installed, {$major['latest']} available\n";
        }

        if ($target !== null) {
            $blockers = $auditor->whyNot($package, Auditor::constraintFor($target['latest']));
            if ($blockers !== '') {
                $context .= "\n`composer why-not {$package} ".Auditor::constraintFor($target['latest'])."` reports:\n{$blockers}\n";
            }
        } else {
            $context .= "\nNote: {$package} did not appear in the major-upgrade audit — verify its installed and latest versions yourself before planning.\n";
        }

        return [$package, $context];
    }

    private function runSession(UpgradeAgent $agent, BudgetTracker $budget, bool $worktree, string $package, string $context): int
    {
        $model = config('tackle.model', 'claude-sonnet-4-6');
        $budgetUsd = config('tackle.budget_usd', 1.00);
        $wtLabel = $worktree ? ' · worktree: on' : '';

        title('Tackle Upgrade — Ready');
        intro("Laravel Tackle Upgrade  ·  {$model}  ·  \${$budgetUsd} budget{$wtLabel}");

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
        } catch (\Throwable $e) {
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

            $task = (new TackleSuggestPrompt(
                label: 'Follow up or type "exit" to quit',
                options: fn (string $value) => array_reverse($this->history),
                placeholder: 'e.g. "continue", "run the tests again", "open the PR", or type "exit"',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history' : '',
                scroll: 10,
            ))->prompt();

            if (in_array(strtolower(trim($task)), ['exit', 'quit', 'q'], strict: true)) {
                title('');
                outro($budget->summary().' · Goodbye!');

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
            } catch (\Throwable $e) {
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
