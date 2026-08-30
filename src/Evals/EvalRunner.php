<?php

namespace Tackle\Evals;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
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
    private bool $keep = false;

    public function __construct(private readonly ?string $baseDir = null) {}

    /**
     * @param  callable(string, EvalCase): array{inputTokens?: int, outputTokens?: int, cacheReadTokens?: int, cacheWriteTokens?: int, costUsd?: float, toolCalls?: list<string>}  $solve
     *                                                                                                                                                                                     Given the case directory and case, mutate the files to solve it and
     *                                                                                                                                                                                     return usage. May throw — the case is then recorded as an error.
     */
    /**
     * Keep case directories after grading instead of deleting them.
     *
     * A false fix is the interesting failure — the agent satisfied the stated
     * example and broke something the prompt never mentioned — and until now
     * the evidence was deleted the moment it was graded, leaving only the
     * verdict. Off by default: a full suite would otherwise leave a directory
     * per case behind on every run.
     */
    public function keepDirectories(bool $keep = true): self
    {
        $this->keep = $keep;

        return $this;
    }

    public function run(EvalCase $case, callable $solve): EvalResult
    {
        $dir = $this->seed($case);
        $start = (int) (microtime(true) * 1000);
        $usage = ['inputTokens' => 0, 'outputTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'costUsd' => 0.0, 'toolCalls' => []];
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

        if (! $this->keep) {
            $this->cleanup($dir);
        }

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
            toolCalls: array_values((array) $usage['toolCalls']),
            keptDir: $this->keep ? $dir : null,
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

        $this->seedTestRunner($dir);

        return $dir;
    }

    /**
     * Give the case a working test runner.
     *
     * Without one, RunTests reports "no tests found" and the agent draws the
     * reasonable conclusion that it should build a suite: one run watched here
     * wrote a composer.json, a phpunit.xml, an artisan stub and six tests, then
     * installed 16MB of Pest — fifteen steps and $0.23 to verify a one-method
     * fix. No real repository makes an agent do that, so every cost this corpus
     * reported was measuring scaffolding as much as work.
     *
     * The vendor directory is symlinked rather than installed, so this costs
     * nothing and RunTests finds ./vendor/bin/pest exactly as it would in a
     * real project.
     *
     * The tests directory is left EMPTY on purpose. Seeding a green smoke test
     * here was tried and was worse than the problem: RunTests returned a pass
     * regardless of the fix, the agent ran it once, saw green and stopped —
     * turning a case it had solved correctly in fifteen steps into a false fix
     * in seven. A meaningless green light is more expensive than a composer
     * install, just not in tokens.
     */
    private function seedTestRunner(string $dir): void
    {
        $vendor = $this->vendorPath();

        if ($vendor === null) {
            return;
        }

        @symlink($vendor, $dir.'/vendor');

        file_put_contents($dir.'/phpunit.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="vendor/autoload.php" colors="true">
                <testsuites>
                    <testsuite name="Suite">
                        <directory>tests</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML);

        @mkdir($dir.'/tests', 0755, true);
    }

    /** The installed vendor directory, wherever Tackle happens to live. */
    private function vendorPath(): ?string
    {
        if (! class_exists(ClassLoader::class)) {
            return null;
        }

        $loader = (new ReflectionClass(ClassLoader::class))->getFileName();

        if ($loader === false) {
            return null;
        }

        $vendor = dirname($loader, 2);

        return is_file($vendor.'/autoload.php') ? $vendor : null;
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
            // The seeded vendor is a symlink to the real installation. The
            // iterator does not descend into it, but rmdir() cannot remove a
            // link either — so unlink it explicitly. Getting this wrong would
            // delete the vendor directory this package is running from.
            if ($f->isLink()) {
                @unlink($f->getPathname());

                continue;
            }

            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }
}
