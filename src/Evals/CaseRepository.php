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
            $this->boundaryEmpty(),
            $this->taxRounding(),
            $this->slugify(),
            $this->statusLabel(),
            $this->factorialBaseCase(),
            $this->locateAmongDecoys(),
            $this->locateByBehaviour(),
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

    private function boundaryEmpty(): EvalCase
    {
        $file = 'EvalAverage.php';
        $buggy = <<<'PHP'
        <?php

        class EvalAverage
        {
            // BUG: divides by count with no guard — crashes on an empty array.
            public function mean(array $numbers): float
            {
                return array_sum($numbers) / count($numbers);
            }
        }
        PHP;

        return new EvalCase(
            id: 'boundary-empty',
            title: 'mean() crashes on an empty array',
            category: 'bug',
            files: [$file => $buggy],
            prompt: 'EvalAverage::mean() throws a DivisionByZeroError when given an empty array. Return 0.0 for an empty array; keep the correct average otherwise.',
            grader: Probe::subprocess($file, <<<'PROBE'
                $a = new EvalAverage();
                $safe = true; try { $z = $a->mean([]); } catch (\Throwable $e) { $safe = false; $z = null; }
                $target = $safe && $z === 0.0;
                $happy = $a->mean([2, 4]) === 3.0;
            PROBE),
        );
    }

    private function taxRounding(): EvalCase
    {
        $file = 'EvalTax.php';
        $buggy = <<<'PHP'
        <?php

        class EvalTax
        {
            // BUG: truncates the tax instead of rounding to the nearest cent.
            public function withTax(int $cents, int $ratePercent): int
            {
                return $cents + (int) ($cents * $ratePercent / 100);
            }
        }
        PHP;

        return new EvalCase(
            id: 'tax-rounding',
            title: 'withTax() truncates instead of rounding',
            category: 'bug',
            files: [$file => $buggy],
            prompt: 'EvalTax::withTax() truncates the tax: 8% on 1099 cents should add 88 (87.92 rounded) for 1187, but it adds 87. Round the tax to the nearest cent.',
            grader: Probe::subprocess($file, <<<'PROBE'
                $t = new EvalTax();
                $target = $t->withTax(1099, 8) === 1187;
                $happy = $t->withTax(1000, 0) === 1000;
            PROBE),
        );
    }

    private function slugify(): EvalCase
    {
        $file = 'EvalSlug.php';
        $buggy = <<<'PHP'
        <?php

        class EvalSlug
        {
            // BUG: only lowercases — leaves spaces and punctuation in the slug.
            public function make(string $title): string
            {
                return strtolower($title);
            }
        }
        PHP;

        return new EvalCase(
            id: 'slugify',
            title: 'make() leaves spaces in the slug',
            category: 'bug',
            files: [$file => $buggy],
            // "no punctuation" used to be in this prompt. A hyphen is punctuation,
            // so stripping it was compliance — and three runs in a row wrote
            // tests asserting exactly that, then scored as false fixes against
            // a grader with a private opinion. The invariant is stated now; the
            // case still tests whether the agent thinks past the one example.
            prompt: "EvalSlug::make() should turn a title into a URL slug: lowercase, words joined by single hyphens, and 'Hello, World!' should become 'hello-world'. A title that is already a valid slug must come through unchanged.",
            grader: Probe::subprocess($file, <<<'PROBE'
                $s = new EvalSlug();
                $target = $s->make('Hello, World!') === 'hello-world';
                $happy = $s->make('already-a-slug') === 'already-a-slug';
            PROBE),
        );
    }

    /**
     * Find the right file when the prompt names a symptom, not a path.
     *
     * Every other case here hands the agent the filename. Real tasks do not:
     * an issue says "the total is wrong on the invoice", and the agent spends
     * its first few steps working out where that lives. A run watched in
     * production burned a third of its steps on exactly this and still edited
     * the wrong file — a failure the rest of this corpus cannot detect.
     *
     * The decoys are the point. Fixing the named symptom is easy once found;
     * editing a plausible neighbour instead is the failure being graded.
     */
    private function locateAmongDecoys(): EvalCase
    {
        $files = [
            'EvalInvoiceTotal.php' => <<<'PHP'
            <?php
            class EvalInvoiceTotal
            {
                /** @param list<int> $lineCents */
                public function total(array $lineCents, int $taxPercent): int
                {
                    $subtotal = array_sum($lineCents);

                    // BUG: tax is added to each line, then again to the subtotal.
                    return $subtotal + intdiv($subtotal * $taxPercent, 100) + $taxPercent;
                }
            }
            PHP,
            'EvalInvoiceRenderer.php' => <<<'PHP'
            <?php
            class EvalInvoiceRenderer
            {
                public function render(int $totalCents): string
                {
                    return '$'.number_format($totalCents / 100, 2);
                }
            }
            PHP,
            'EvalOrderTotal.php' => <<<'PHP'
            <?php
            class EvalOrderTotal
            {
                /** @param list<int> $lineCents */
                public function total(array $lineCents): int
                {
                    return array_sum($lineCents);
                }
            }
            PHP,
            'EvalTaxTable.php' => <<<'PHP'
            <?php
            class EvalTaxTable
            {
                public function percentFor(string $region): int
                {
                    return ['uk' => 20, 'de' => 19][$region] ?? 0;
                }
            }
            PHP,
        ];

        return new EvalCase(
            id: 'locate-among-decoys',
            title: 'Find the miscalculated invoice total without being told where it lives',
            category: 'navigation',
            files: $files,
            prompt: 'An invoice for two lines of 1000 cents each at 20% tax is charging 2420 cents instead of 2400. Find the cause and fix it. Do not change behaviour that is already correct.',
            grader: Probe::subprocess(array_keys($files), <<<'PROBE'
                $i = new EvalInvoiceTotal();
                $target = $i->total([1000, 1000], 20) === 2400;

                // The decoys must survive untouched: a plausible neighbour
                // edited into agreement is a false fix, not a fix.
                $happy = $i->total([1000, 1000], 0) === 2000
                    && (new EvalOrderTotal())->total([500, 250]) === 750
                    && (new EvalTaxTable())->percentFor('uk') === 20
                    && (new EvalInvoiceRenderer())->render(2400) === '$24.00';
            PROBE),
        );
    }

    /**
     * The same skill without a number to grep for: the prompt describes
     * behaviour, and the only way to the right file is reading what each one
     * does. Searching for a literal from the prompt will not shortcut it.
     */
    private function locateByBehaviour(): EvalCase
    {
        $files = [
            'EvalUserNameFormatter.php' => <<<'PHP'
            <?php
            class EvalUserNameFormatter
            {
                public function initials(string $first, string $last): string
                {
                    // BUG: an empty last name yields a trailing dot.
                    return strtoupper($first[0] ?? '').'.'.strtoupper($last[0] ?? '').'.';
                }
            }
            PHP,
            'EvalUserGreeting.php' => <<<'PHP'
            <?php
            class EvalUserGreeting
            {
                public function greet(string $name): string
                {
                    return 'Hello, '.$name.'!';
                }
            }
            PHP,
            'EvalUserSlug.php' => <<<'PHP'
            <?php
            class EvalUserSlug
            {
                public function slug(string $first, string $last): string
                {
                    return trim(strtolower($first.'-'.$last), '-');
                }
            }
            PHP,
        ];

        return new EvalCase(
            id: 'locate-by-behaviour',
            title: 'Find the formatter that leaves a trailing separator',
            category: 'navigation',
            files: $files,
            prompt: 'Somewhere in this code a user with no surname is displayed with a stray trailing dot after their initial. Find it and stop it, leaving the two-name case exactly as it is.',
            grader: Probe::subprocess(array_keys($files), <<<'PROBE'
                $f = new EvalUserNameFormatter();
                $target = $f->initials('Ada', '') === 'A.';
                $happy = $f->initials('Ada', 'Lovelace') === 'A.L.'
                    && (new EvalUserGreeting())->greet('Ada') === 'Hello, Ada!'
                    && (new EvalUserSlug())->slug('Ada', '') === 'ada';
            PROBE),
        );
    }

    private function statusLabel(): EvalCase
    {
        $file = 'EvalStatus.php';
        $buggy = <<<'PHP'
        <?php

        class EvalStatus
        {
            // BUG: missing the 'refunded' case — returns 'Unknown' for it.
            public function label(string $status): string
            {
                return match ($status) {
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'shipped' => 'Shipped',
                    default => 'Unknown',
                };
            }
        }
        PHP;

        return new EvalCase(
            id: 'status-label',
            title: 'label() is missing the refunded status',
            category: 'bug',
            files: [$file => $buggy],
            prompt: "EvalStatus::label() returns 'Unknown' for the 'refunded' status. It should return 'Refunded', without breaking the existing statuses.",
            grader: Probe::subprocess($file, <<<'PROBE'
                $s = new EvalStatus();
                $target = $s->label('refunded') === 'Refunded';
                $happy = $s->label('paid') === 'Paid';
            PROBE),
        );
    }

    private function factorialBaseCase(): EvalCase
    {
        $file = 'EvalMath.php';
        $buggy = <<<'PHP'
        <?php

        class EvalMath
        {
            // BUG: wrong base case — returns 0, so every factorial is 0.
            public function factorial(int $n): int
            {
                if ($n <= 1) {
                    return 0;
                }

                return $n * $this->factorial($n - 1);
            }
        }
        PHP;

        return new EvalCase(
            id: 'factorial-base-case',
            title: 'factorial() has the wrong base case',
            category: 'bug',
            files: [$file => $buggy],
            prompt: 'EvalMath::factorial() always returns 0 because its base case is wrong. Fix it so factorial(5) is 120 and factorial(0) is 1.',
            grader: Probe::subprocess($file, <<<'PROBE'
                $m = new EvalMath();
                $target = $m->factorial(5) === 120;
                $happy = $m->factorial(0) === 1;
            PROBE),
        );
    }
}
