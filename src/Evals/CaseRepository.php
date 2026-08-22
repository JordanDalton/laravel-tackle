<?php

namespace Tackle\Evals;

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
     * All cases: the built-in suite plus any the project defines in its evals
     * directory. A user case with the same id overrides a built-in.
     *
     * @return list<EvalCase>
     */
    public function all(): array
    {
        $cases = [];

        if ((bool) config('tackle.evals.include_builtin', true)) {
            foreach ($this->builtin() as $c) {
                $cases[$c->id] = $c;
            }
        }

        foreach ($this->userCases() as $c) {
            $cases[$c->id] = $c;
        }

        return array_values($cases);
    }

    /**
     * @return list<EvalCase>
     */
    public function builtin(): array
    {
        return [
            $this->divByZero(),
            $this->offByOne(),
            $this->discountMath(),
            $this->nullableRelation(),
            $this->crossFileTotal(),
        ];
    }

    /**
     * Load cases the project defines. Each *.php file under the evals directory
     * (config tackle.evals.path, default base_path('evals')) returns either an
     * EvalCase or an iterable of them. Files that return anything else are
     * ignored, so a stray file cannot break the suite.
     *
     * @return list<EvalCase>
     */
    public function userCases(): array
    {
        $path = config('tackle.evals.path') ?: (function_exists('base_path') ? base_path('evals') : null);

        if (! is_string($path) || ! is_dir($path)) {
            return [];
        }

        $cases = [];
        foreach (glob(rtrim($path, '/').'/*.php') ?: [] as $file) {
            $returned = require $file;

            foreach ($returned instanceof EvalCase ? [$returned] : (is_iterable($returned) ? $returned : []) as $c) {
                if ($c instanceof EvalCase) {
                    $cases[] = $c;
                }
            }
        }

        return $cases;
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
            grader: Probe::subprocess($file, <<<'PROBE'
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
            grader: Probe::subprocess($file, <<<'PROBE'
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
            grader: Probe::subprocess($file, <<<'PROBE'
                $d = new EvalDiscount();
                $target = abs($d->apply(200.0, 10.0) - 180.0) < 0.001;
                $happy = abs($d->apply(100.0, 0.0) - 100.0) < 0.001;
            PROBE),
        );
    }

    private function nullableRelation(): EvalCase
    {
        $file = 'EvalReceipt.php';
        $buggy = <<<'PHP'
        <?php

        class EvalReceipt
        {
            // BUG: crashes when an order has no customer (a null relation).
            public function customerName($order): string
            {
                return $order->customer->name;
            }
        }
        PHP;

        return new EvalCase(
            id: 'nullable-relation',
            title: 'Receipt crashes on an order with no customer',
            category: 'bug',
            files: [$file => $buggy],
            prompt: "EvalReceipt::customerName() in {$file} reads \$order->customer->name, which throws when an order has no customer (the relation is null). Return the string 'Guest' when there is no customer, and the customer's name when there is one.",
            grader: Probe::subprocess($file, <<<'PROBE'
                $r = new EvalReceipt();
                $withCustomer = (object) ['customer' => (object) ['name' => 'Sam']];
                $noCustomer = (object) ['customer' => null];

                $safe = true;
                try { $got = $r->customerName($noCustomer); } catch (\Throwable $e) { $safe = false; $got = null; }

                $target = $safe && $got === 'Guest';
                $happy = $r->customerName($withCustomer) === 'Sam';
            PROBE),
        );
    }

    private function crossFileTotal(): EvalCase
    {
        $cart = <<<'PHP'
        <?php

        class EvalCart
        {
            /** @param EvalLineItem[] $items */
            public function totalCents(array $items): int
            {
                $total = 0;
                foreach ($items as $item) {
                    $total += $item->subtotalCents();
                }

                return $total;
            }
        }
        PHP;

        $lineItem = <<<'PHP'
        <?php

        class EvalLineItem
        {
            public function __construct(
                public int $quantity,
                public int $unitPriceCents,
            ) {}

            // BUG: ignores quantity — a subtotal should be qty x unit price.
            public function subtotalCents(): int
            {
                return $this->unitPriceCents;
            }
        }
        PHP;

        return new EvalCase(
            id: 'cross-file-total',
            title: 'Cart total ignores line-item quantity',
            category: 'bug',
            files: ['EvalCart.php' => $cart, 'EvalLineItem.php' => $lineItem],
            prompt: 'EvalCart::totalCents() returns the wrong total — a cart of 2 items at 500 plus 1 at 300 should be 1300 but comes out lower. Find and fix the root cause (it is not necessarily in EvalCart.php).',
            grader: Probe::subprocess(['EvalLineItem.php', 'EvalCart.php'], <<<'PROBE'
                $cart = new EvalCart();
                $target = $cart->totalCents([
                    new EvalLineItem(2, 500),
                    new EvalLineItem(1, 300),
                ]) === 1300;
                $happy = $cart->totalCents([new EvalLineItem(1, 100)]) === 100;
            PROBE),
        );
    }
}
