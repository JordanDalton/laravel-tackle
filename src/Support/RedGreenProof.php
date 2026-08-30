<?php

namespace Tackle\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Prove a change's new tests actually test the change, before the PR opens.
 *
 * The healer has done this since v1.43: run the new test with the fix
 * reverted (it must fail), then restored (it must pass). A test that passes
 * either way is decoration — it looks like verification and verifies
 * nothing, which is worse than no test. Task runs never had the proof, and
 * when we asked developers what they'd need before trusting an agent's PR,
 * every answer was some form of "verification, not narration".
 *
 * Unlike the healer there is no commit to revert to — the whole change is
 * uncommitted when CreatePullRequest runs. So the revert is done by hand:
 * save the non-test files aside, restore them from git (or remove them if
 * they are new), run the tests, then put everything back in a finally block.
 * Restoring is the part that must never fail half-way; everything is held in
 * memory before the first file is touched.
 *
 * Best-effort by design: any surprise — not a git repo, no test runner, too
 * many files — reports "not run" rather than blocking the pull request.
 */
class RedGreenProof
{
    /** More changed files than this and reverting is too invasive to risk. */
    private const MAX_FIX_FILES = 20;

    /**
     * @param  (\Closure(string, list<string>): bool)|null  $testRunner  test override: given the
     *                                                                   workspace and test paths, do they pass? Tests inject this so the
     *                                                                   revert/restore mechanics run against a real git repo while the
     *                                                                   test execution is scripted.
     */
    public function __construct(
        private readonly PathGuard $guard,
        private readonly ?\Closure $testRunner = null,
    ) {}

    /**
     * @return array{ran: bool, red: bool, green: bool, tests: list<string>}
     */
    public function run(): array
    {
        $none = ['ran' => false, 'red' => false, 'green' => false, 'tests' => []];

        if (! (bool) config('tackle.verify.red_green', true)) {
            return $none;
        }

        $workspace = $this->guard->workspace();

        try {
            [$testPaths, $fixFiles] = $this->changedFiles($workspace);

            if ($testPaths === [] || $fixFiles === [] || count($fixFiles) > self::MAX_FIX_FILES) {
                return $none;
            }

            // Green first: with the fix in place, the new tests must pass.
            // If they don't, red/green would only prove the suite is broken.
            $green = $this->testsPass($workspace, $testPaths);

            if (! $green) {
                return ['ran' => true, 'red' => false, 'green' => false, 'tests' => $testPaths];
            }

            $red = ! $this->withFixReverted($workspace, $fixFiles, fn () => $this->testsPass($workspace, $testPaths));

            return ['ran' => true, 'red' => $red, 'green' => true, 'tests' => $testPaths];
        } catch (Throwable) {
            return $none;
        }
    }

    /**
     * The verification block for a PR body, or '' when there is nothing to say.
     *
     * @param  array{ran: bool, red: bool, green: bool, tests: list<string>}  $proof
     */
    public static function markdown(array $proof): string
    {
        if (! $proof['ran']) {
            return '';
        }

        $tests = implode(', ', array_map(fn ($t) => "`{$t}`", $proof['tests']));

        $red = $proof['red']
            ? '✅ fails without the change'
            : '⚠️ passes even without the change — it may not cover it';
        $green = $proof['green'] ? '✅ passes with the change' : '❌ failing';

        return "\n\n---\n**Verification** (test run twice: with the change, and with it reverted)\n"
            ."- Test: {$tests}\n"
            ."- {$green}\n"
            ."- {$red}";
    }

    /**
     * Uncommitted changes, split into test files and the files under test.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function changedFiles(string $workspace): array
    {
        // --untracked-files=all: a brand-new directory otherwise shows as one
        // "?? tests/" entry and the file inside it is invisible — which is
        // exactly the first-test-in-a-new-subdirectory case.
        $status = Process::path($workspace)->timeout(30)
            ->run(['git', 'status', '--porcelain', '--untracked-files=all']);

        if (! $status->successful()) {
            return [[], []];
        }

        $tests = [];
        $fixes = [];

        // No trim on the whole output: porcelain lines are "XY path" and the
        // first line's status often BEGINS with a space — trimming it shifts
        // the parse by one character and mangles the first path.
        foreach (explode("\n", $status->output()) as $line) {
            if (strlen($line) < 4) {
                continue;
            }

            $path = trim(substr($line, 3));

            if (! str_ends_with($path, '.php') || str_contains($path, '..')) {
                continue;
            }

            if (str_starts_with($path, 'tests/')) {
                $tests[] = $path;
            } else {
                $fixes[] = $path;
            }
        }

        return [$tests, $fixes];
    }

    /**
     * Run the callback with the non-test changes reverted, restoring them
     * whatever happens.
     *
     * @param  list<string>  $fixFiles
     */
    private function withFixReverted(string $workspace, array $fixFiles, callable $callback): mixed
    {
        // Hold every file in memory before touching anything.
        $saved = [];

        foreach ($fixFiles as $file) {
            $absolute = $workspace.'/'.$file;
            $saved[$file] = is_file($absolute) ? (string) file_get_contents($absolute) : null;
        }

        try {
            foreach ($fixFiles as $file) {
                // Tracked file: back to the last committed contents. New file:
                // checkout fails, so remove it — it did not exist before.
                $restore = Process::path($workspace)->timeout(30)
                    ->run(['git', 'checkout', '--', $file]);

                if (! $restore->successful()) {
                    @unlink($workspace.'/'.$file);
                }
            }

            return $callback();
        } finally {
            foreach ($saved as $file => $contents) {
                if ($contents !== null) {
                    file_put_contents($workspace.'/'.$file, $contents);
                }
            }
        }
    }

    /** @param  list<string>  $paths */
    private function testsPass(string $workspace, array $paths): bool
    {
        if ($this->testRunner !== null) {
            return ($this->testRunner)($workspace, $paths);
        }

        $binary = file_exists($workspace.'/vendor/bin/pest')
            ? ['./vendor/bin/pest']
            : ['php', 'artisan', 'test'];

        return Process::path($workspace)
            ->env(['APP_ENV' => 'testing'])
            ->timeout(120)
            ->run(array_merge($binary, $paths))
            ->successful();
    }
}
