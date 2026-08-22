<?php

namespace Tackle\Evals;

use Illuminate\Support\Facades\Process;

/**
 * The built-in benchmark cases. Each seeds a single buggy PHP class and grades
 * the result in a subprocess — so a fix that leaves the file unparseable, or
 * throws, is scored as a failure rather than taking the harness down with it,
 * and cases never collide in one PHP process.
 *
 * Extend by adding cases here (or, later, loading from a project `evals/` dir).
 * Keep each case small, pure, and unambiguous so grading stays deterministic.
 */
class CaseRepository
{
    /**
     * @return list<EvalCase>
     */
    public function all(): array
    {
        return [
            $this->divByZero(),
            $this->offByOne(),
            $this->discountMath(),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<EvalCase>
     */
    public function only(array $ids): array
    {
        return array_values(array_filter($this->all(), fn (EvalCase $c) => in_array($c->id, $ids, true)));
    }

    private function divByZero(): EvalCase
    {
        $file = 'EvalOrderStats.php';
        $buggy = <<<'PHP'
        <?php
        class EvalOrderStats
        {
            // BUG: throws DivisionByZeroError when $orderCount is 0.
            public function averageCents(int $totalCents, int $orderCount): int
            {
                return intdiv($totalCents, $orderCount);
            }
        }
        PHP;

        return new EvalCase(
            id: 'div-by-zero',
            title: 'Guard averageCents against zero orders',
            category: 'bug',
            files: [$file => $buggy],
            prompt: "The class in {$file} throws DivisionByZeroError when averageCents() is called with an order count of 0. Fix it so a zero order count returns 0, without changing the result for non-zero counts.",
            grader: $this->probe($file, 'EvalOrderStats', <<<'PROBE'
                $o = new EvalOrderStats();
                $target = false; try { $target = $o->averageCents(1000, 0) === 0; } catch (\Throwable $e) { $target = false; }
                $happy = false; try { $happy = $o->averageCents(1000, 4) === 250; } catch (\Throwable $e) { $happy = false; }
            PROBE),
        );
    }

    private function offByOne(): EvalCase
    {
        $file = 'EvalPaginator.php';
        $buggy = <<<'PHP'
        <?php
        class EvalPaginator
        {
            // BUG: off-by-one — drops the final partial page.
            public function lastPage(int $total, int $perPage): int
            {
                return intdiv($total, $perPage);
            }
        }
        PHP;

        return new EvalCase(
            id: 'off-by-one',
            title: 'lastPage() drops the final partial page',
            category: 'bug',
            files: [$file => $buggy],
            prompt: "lastPage() in {$file} returns the wrong number of pages when the items don't divide evenly — it drops the final partial page. Fix it so 10 items at 3 per page gives 4 pages.",
            grader: $this->probe($file, 'EvalPaginator', <<<'PROBE'
                $p = new EvalPaginator();
                $target = $p->lastPage(10, 3) === 4;
                $happy = $p->lastPage(9, 3) === 3;
            PROBE),
        );
    }

    private function discountMath(): EvalCase
    {
        $file = 'EvalDiscount.php';
        $buggy = <<<'PHP'
        <?php
        class EvalDiscount
        {
            // BUG: subtracts the percent as an absolute amount instead of a percentage.
            public function apply(float $price, float $percent): float
            {
                return $price - $percent;
            }
        }
        PHP;

        return new EvalCase(
            id: 'discount-math',
            title: 'apply() subtracts percent as an absolute amount',
            category: 'bug',
            files: [$file => $buggy],
            prompt: "apply() in {$file} is supposed to reduce a price by a percentage, but it subtracts the percent as a flat amount. Fix the math so a 10% discount on 200 gives 180, and 0% leaves the price unchanged.",
            grader: $this->probe($file, 'EvalDiscount', <<<'PROBE'
                $d = new EvalDiscount();
                $target = abs($d->apply(200.0, 10.0) - 180.0) < 0.001;
                $happy = abs($d->apply(100.0, 0.0) - 100.0) < 0.001;
            PROBE),
        );
    }

    /**
     * Build a grader that runs a probe in a subprocess. The probe must set
     * $target (the bug is fixed) and $happy (previously-correct behaviour still
     * holds). Output line "TARGET:0|1 HAPPY:0|1" is parsed into a grade.
     */
    private function probe(string $file, string $class, string $probe): \Closure
    {
        return function (string $dir) use ($file, $probe): EvalGrade {
            $script = '<?php require '.var_export($dir.'/'.$file, true).";\n"
                .$probe."\n"
                .'echo "TARGET:".(($target ?? false) ? 1 : 0)." HAPPY:".(($happy ?? false) ? 1 : 0);';

            $scriptPath = $dir.'/__probe.php';
            file_put_contents($scriptPath, $script);

            $result = Process::timeout(30)->run('php '.escapeshellarg($scriptPath));
            @unlink($scriptPath);

            $out = trim($result->output().$result->errorOutput());

            if (! preg_match('/TARGET:([01])\s+HAPPY:([01])/', $out, $m)) {
                // No parseable verdict — the fix likely broke the file.
                return new EvalGrade(fixed: false, brokeHappyPath: true, note: 'unparseable / fatal after fix');
            }

            $target = $m[1] === '1';
            $happy = $m[2] === '1';

            return new EvalGrade(
                fixed: $target,
                brokeHappyPath: ! $happy,
                note: $target ? ($happy ? '' : 'fixed but broke happy path') : 'not fixed',
            );
        };
    }
}
