<?php

namespace Tackle\Evals;

use Throwable;

/**
 * Runs one eval case: seed the buggy files into an isolated directory, hand
 * that directory to a solver, then grade the result and record what it cost.
 *
 * The solver is injected — the real one runs Tackle's coding agent against the
 * directory; tests pass a scripted one — so the seed/grade/score machinery is
 * verifiable without spending a token.
 */
class EvalRunner
{
    public function __construct(private readonly ?string $baseDir = null) {}

    /**
     * @param  callable(string, EvalCase): array{inputTokens?: int, outputTokens?: int, cacheReadTokens?: int, cacheWriteTokens?: int, costUsd?: float}  $solve
     *                                                                                                                                                           Given the case directory and case, mutate the files to solve it and
     *                                                                                                                                                           return usage. May throw — the case is then recorded as an error.
     */
    public function run(EvalCase $case, callable $solve): EvalResult
    {
        $dir = $this->seed($case);
        $start = (int) (microtime(true) * 1000);
        $usage = ['inputTokens' => 0, 'outputTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'costUsd' => 0.0];
        $error = null;

        try {
            $usage = array_merge($usage, $solve($dir, $case) ?: []);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $grade = $error !== null
            ? new EvalGrade(fixed: false, brokeHappyPath: false, note: 'solver error')
            : $case->grade($dir);

        $durationMs = (int) (microtime(true) * 1000) - $start;

        $this->cleanup($dir);

        return new EvalResult(
            caseId: $case->id,
            category: $case->category,
            grade: $grade,
            inputTokens: (int) $usage['inputTokens'],
            outputTokens: (int) $usage['outputTokens'],
            costUsd: (float) $usage['costUsd'],
            durationMs: $durationMs,
            error: $error,
            cacheReadTokens: (int) $usage['cacheReadTokens'],
            cacheWriteTokens: (int) $usage['cacheWriteTokens'],
        );
    }

    /**
     * @param  list<EvalCase>  $cases
     * @param  callable(string, EvalCase): array  $solve
     */
    public function runAll(array $cases, callable $solve): EvalReport
    {
        return new EvalReport(array_map(fn (EvalCase $c) => $this->run($c, $solve), $cases));
    }

    private function seed(EvalCase $case): string
    {
        $base = $this->baseDir ?? sys_get_temp_dir();
        $dir = $base.'/tackle-eval-'.$case->id.'-'.substr(md5($case->id.microtime()), 0, 8);
        @mkdir($dir, 0755, true);

        foreach ($case->files as $relative => $contents) {
            $path = $dir.'/'.ltrim($relative, '/');
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $contents);
        }

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
