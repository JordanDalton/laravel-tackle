<?php

namespace Tackle\Healing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
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
        $path = sys_get_temp_dir() . '/tackle-' . md5($branchName . microtime());

        $result = Process::path($this->repoRoot)
            ->timeout(60)
            ->run(['git', 'worktree', 'add', '-b', $branchName, $path, 'HEAD']);

        if (!$result->successful()) {
            throw new RuntimeException(
                "git worktree add failed: " . $result->errorOutput()
            );
        }

        // Symlink vendor so tests run without a full composer install
        $vendorSrc = $this->repoRoot . '/vendor';
        $vendorDst = $path . '/vendor';
        if (is_dir($vendorSrc) && !file_exists($vendorDst)) {
            symlink($vendorSrc, $vendorDst);
        }

        return $path;
    }

    /**
     * Run the test suite inside the worktree. Returns true if all tests pass.
     */
    public function runTests(string $worktreePath): bool
    {
        $binary = file_exists($worktreePath . '/vendor/bin/pest')
            ? './vendor/bin/pest'
            : 'php artisan test';

        $result = Process::path($worktreePath)
            ->timeout(120)
            ->run($binary);

        return $result->successful();
    }

    /**
     * Push the healing branch to the remote origin.
     */
    public function push(string $branchName, string $worktreePath): void
    {
        $result = Process::path($worktreePath)
            ->timeout(60)
            ->run(['git', 'push', '-u', 'origin', $branchName]);

        if (!$result->successful()) {
            throw new RuntimeException(
                "git push failed: " . $result->errorOutput()
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

        if (!$result->successful()) {
            throw new RuntimeException(
                "git commit failed: " . $result->errorOutput()
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

        if (!$result->successful()) {
            throw new RuntimeException(
                "git merge failed: " . $result->errorOutput()
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
            $vendorLink = $worktreePath . '/vendor';
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
        if (!$token) {
            return null;
        }

        [$owner, $repo] = $this->parseRemote();

        if (!$owner || !$repo) {
            return null;
        }

        $baseBranch = config('ai-code.healing.base_branch', 'main');

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->post("https://api.github.com/repos/{$owner}/{$repo}/pulls", [
                    'title' => $title,
                    'body'  => $body,
                    'head'  => $branchName,
                    'base'  => $baseBranch,
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

        if (!$result->successful()) {
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
