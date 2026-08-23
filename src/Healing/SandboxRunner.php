<?php

namespace Tackle\Healing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tackle\Support\TestOutputParser;
use Throwable;

class SandboxRunner
{
    private string $repoRoot;

    public function __construct()
    {
        $this->repoRoot = base_path();
    }

    /**
     * Create a git worktree on a new branch and return the worktree path.
     *
     * @throws RuntimeException if git is unavailable or the worktree cannot be created
     */
    public function prepare(string $branchName): string
    {
        $path = sys_get_temp_dir().'/tackle-'.md5($branchName.microtime());

        // Ensure there is at least one commit before attempting a worktree.
        $headCheck = Process::path($this->repoRoot)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        if (! $headCheck->successful()) {
            throw new RuntimeException(
                'Cannot create a healing worktree — the repository has no commits yet. '.
                'Run: git add -A && git commit -m "initial commit"'
            );
        }

        $result = Process::path($this->repoRoot)
            ->timeout(60)
            ->run(['git', 'worktree', 'add', '-b', $branchName, $path, 'HEAD']);

        if (! $result->successful()) {
            throw new RuntimeException(
                'git worktree add failed: '.$result->errorOutput()
            );
        }

        // Symlink vendor so tests run without a full composer install
        $vendorSrc = $this->repoRoot.'/vendor';
        $vendorDst = $path.'/vendor';
        if (is_dir($vendorSrc) && ! file_exists($vendorDst)) {
            symlink($vendorSrc, $vendorDst);
        }

        // Symlink env files so the test suite can connect to the database.
        // These are gitignored and therefore absent from the worktree.
        foreach (['.env', '.env.testing'] as $envFile) {
            $src = $this->repoRoot.'/'.$envFile;
            $dst = $path.'/'.$envFile;
            if (file_exists($src) && ! file_exists($dst)) {
                symlink($src, $dst);
            }
        }

        return $path;
    }

    /**
     * Run the test suite inside the worktree. Returns true if all tests pass.
     */
    public function runTests(string $worktreePath): bool
    {
        return $this->testFailures($worktreePath)['ok'];
    }

    /**
     * Run the test suite and return structured results: whether it ran at all,
     * whether it passed, and the titles of the failing tests. The healer runs
     * this before and after the fix to detect NEW failures the fix introduced.
     *
     * @return array{ran: bool, ok: bool, failures: list<string>}
     */
    public function testFailures(string $worktreePath): array
    {
        $binary = file_exists($worktreePath.'/vendor/bin/pest')
            ? './vendor/bin/pest'
            : 'php artisan test';

        $result = Process::path($worktreePath)
            ->timeout(120)
            ->run($binary);

        $output = $result->output().$result->errorOutput();
        $ran = trim($output) !== '';

        return [
            'ran' => $ran,
            'ok' => $result->successful(),
            'failures' => TestOutputParser::failureTitles($output),
        ];
    }

    /**
     * Prove the regression test actually reproduces the bug: run the new test
     * with the fix reverted (expect red), then with the fix restored (expect
     * green). Confirms the test isn't vacuous or passing for the wrong reason.
     *
     * The heal is already committed as HEAD; `git checkout HEAD~1 -- <fix>`
     * reverts only the fix files (the new test didn't exist in HEAD~1, so it
     * stays in place), then we restore with `git checkout HEAD -- <fix>`.
     *
     * @param  list<string>  $newTestPaths  test files the heal added
     * @param  list<string>  $fixPaths  non-test files the heal changed
     * @return array{ran: bool, red: bool, green: bool}
     */
    public function redGreenCheck(string $worktreePath, array $newTestPaths, array $fixPaths): array
    {
        if ($newTestPaths === [] || $fixPaths === []) {
            return ['ran' => false, 'red' => false, 'green' => false];
        }

        try {
            // Revert the fix, keep the test → the test should now FAIL.
            Process::path($worktreePath)->timeout(30)
                ->run(array_merge(['git', 'checkout', 'HEAD~1', '--'], $fixPaths));
            $red = ! $this->runTestPaths($worktreePath, $newTestPaths);

            // Restore the fix → the test should now PASS.
            Process::path($worktreePath)->timeout(30)
                ->run(array_merge(['git', 'checkout', 'HEAD', '--'], $fixPaths));
            $green = $this->runTestPaths($worktreePath, $newTestPaths);

            return ['ran' => true, 'red' => $red, 'green' => $green];
        } catch (Throwable) {
            // Best-effort — never let the proof step fail the heal.
            return ['ran' => false, 'red' => false, 'green' => false];
        }
    }

    /**
     * Run only the given test files. True if they pass.
     *
     * @param  list<string>  $paths
     */
    public function runTestPaths(string $worktreePath, array $paths): bool
    {
        $binary = file_exists($worktreePath.'/vendor/bin/pest')
            ? ['./vendor/bin/pest']
            : ['php', 'artisan', 'test'];

        return Process::path($worktreePath)->timeout(120)
            ->run(array_merge($binary, $paths))
            ->successful();
    }

    /**
     * The diff of the healing commit (HEAD vs its parent) as a status map plus
     * line counts. Uses the committed diff so newly-added files (a regression
     * test) are included, which a working-tree diff against HEAD would miss.
     *
     * @return array{files: array<string, string>, insertions: int, deletions: int}
     */
    public function diff(string $worktreePath): array
    {
        $files = [];
        $insertions = 0;
        $deletions = 0;

        $nameStatus = Process::path($worktreePath)->timeout(30)
            ->run(['git', 'diff', '--name-status', 'HEAD~1', 'HEAD']);

        if ($nameStatus->successful()) {
            foreach (preg_split('/\r?\n/', trim($nameStatus->output())) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\t+/', $line);
                if (count($parts) >= 2) {
                    // Rename lines are "R100	old	new" — take the status letter
                    // and the final path.
                    $files[end($parts)] = substr($parts[0], 0, 1);
                }
            }
        }

        $numstat = Process::path($worktreePath)->timeout(30)
            ->run(['git', 'diff', '--numstat', 'HEAD~1', 'HEAD']);

        if ($numstat->successful()) {
            foreach (preg_split('/\r?\n/', trim($numstat->output())) ?: [] as $line) {
                $parts = preg_split('/\t+/', $line);
                if (count($parts) >= 3) {
                    $insertions += is_numeric($parts[0]) ? (int) $parts[0] : 0;
                    $deletions += is_numeric($parts[1]) ? (int) $parts[1] : 0;
                }
            }
        }

        return ['files' => $files, 'insertions' => $insertions, 'deletions' => $deletions];
    }

    /**
     * Auto-format the heal's changes with Pint before committing, so the PR
     * diff is clean rather than carrying the model's whitespace quirks.
     * Best-effort — silently skipped if Pint is absent.
     */
    public function format(string $worktreePath): void
    {
        if (! file_exists($worktreePath.'/vendor/bin/pint')) {
            return;
        }

        Process::path($worktreePath)->timeout(60)->run('./vendor/bin/pint --dirty');
    }

    /**
     * Run Larastan/PHPStan over the given files (the heal's changed code) and
     * report whether it is clean. Scoped to the changed files so pre-existing
     * errors elsewhere do not gate the heal. Skipped (ran=false) when PHPStan
     * is not installed — never a failure by absence.
     *
     * @param  list<string>  $paths
     * @return array{ran: bool, ok: bool, summary: string}
     */
    public function analyzeChanged(string $worktreePath, array $paths): array
    {
        $paths = array_values(array_filter($paths, fn ($p) => str_ends_with($p, '.php')));

        if ($paths === [] || ! file_exists($worktreePath.'/vendor/bin/phpstan')) {
            return ['ran' => false, 'ok' => true, 'summary' => ''];
        }

        $args = ['php', './vendor/bin/phpstan', 'analyse', '--no-progress', '--no-interaction', '--no-ansi'];
        foreach ($paths as $path) {
            $args[] = escapeshellarg($path);
        }

        $result = Process::path($worktreePath)->timeout(300)->run(implode(' ', $args));
        $output = trim($result->output().$result->errorOutput());

        // A missing/empty phpstan config makes analysis of ad-hoc paths error
        // out ("No files found to analyse" / config errors); treat an inability
        // to analyse as "not run" rather than a failed gate.
        if (str_contains($output, 'No files found') || str_contains($output, 'not find a configuration')) {
            return ['ran' => false, 'ok' => true, 'summary' => ''];
        }

        $ok = $result->successful();
        $summary = '';
        if (! $ok && preg_match('/\[ERROR\][^\n]*|Found \d+ error/i', $output, $m)) {
            $summary = trim($m[0]);
        }

        return ['ran' => true, 'ok' => $ok, 'summary' => $summary];
    }

    /**
     * Push the healing branch to the remote origin.
     */
    public function push(string $branchName, string $worktreePath): void
    {
        $result = Process::path($worktreePath)
            ->timeout(60)
            ->run(['git', 'push', '-u', 'origin', $branchName]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'git push failed: '.$result->errorOutput()
            );
        }
    }

    /**
     * Stage all changes in the worktree and commit them.
     */
    public function commit(string $worktreePath, string $message): void
    {
        Process::path($worktreePath)->run(['git', 'add', '-A']);

        $result = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'commit', '-m', $message]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'git commit failed: '.$result->errorOutput()
            );
        }
    }

    /**
     * Merge the healing branch into the current branch of the main workspace.
     */
    public function applyToMain(string $branchName): void
    {
        $result = Process::path($this->repoRoot)
            ->timeout(30)
            ->run(['git', 'merge', '--no-ff', $branchName, '-m', "tackle: apply healer fix from {$branchName}"]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'git merge failed: '.$result->errorOutput()
            );
        }
    }

    /**
     * Remove the worktree and delete the healing branch.
     */
    public function cleanup(string $worktreePath, string $branchName): void
    {
        try {
            // Remove vendor symlink before removing the worktree
            $vendorLink = $worktreePath.'/vendor';
            if (is_link($vendorLink)) {
                unlink($vendorLink);
            }

            Process::path($this->repoRoot)
                ->timeout(30)
                ->run(['git', 'worktree', 'remove', '--force', $worktreePath]);

            Process::path($this->repoRoot)
                ->timeout(15)
                ->run(['git', 'branch', '-D', $branchName]);
        } catch (Throwable) {
            // Best-effort cleanup; never fail the caller.
        }
    }

    /**
     * Open a GitHub pull request and return the PR URL.
     * Returns null if the token is missing or the API call fails.
     */
    public function createPullRequest(
        string $branchName,
        string $title,
        string $body,
        ?string $token,
    ): ?string {
        if (! $token) {
            return null;
        }

        [$owner, $repo] = $this->parseRemote();

        if (! $owner || ! $repo) {
            return null;
        }

        $baseBranch = config('tackle.healing.base_branch', 'main');

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->post("https://api.github.com/repos/{$owner}/{$repo}/pulls", [
                    'title' => $title,
                    'body' => $body,
                    'head' => $branchName,
                    'base' => $baseBranch,
                ]);

            return $response->json('html_url');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse `git remote get-url origin` and return [owner, repo].
     */
    private function parseRemote(): array
    {
        $result = Process::path($this->repoRoot)
            ->timeout(10)
            ->run(['git', 'remote', 'get-url', 'origin']);

        if (! $result->successful()) {
            return [null, null];
        }

        $url = trim($result->output());

        // SSH  : git@github.com:Owner/repo.git
        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }
}
