<?php

use Tackle\Evals\CaseRepository;
use Tackle\Evals\EvalCase;
use Tackle\Evals\EvalGrade;
use Tackle\Evals\EvalReport;
use Tackle\Evals\EvalResult;
use Tackle\Evals\EvalRunner;

// ---------------------------------------------------------------------------
// EvalGrade / EvalResult
// ---------------------------------------------------------------------------

it('classifies grades', function () {
    expect((new EvalGrade(fixed: true))->isClean())->toBeTrue();
    expect((new EvalGrade(fixed: true, brokeHappyPath: true))->isClean())->toBeFalse();
    expect((new EvalGrade(fixed: true, brokeHappyPath: true))->isFalseFix())->toBeTrue();
    expect((new EvalGrade(fixed: false))->isFalseFix())->toBeFalse();
});

it('derives a status from grade and error', function () {
    $mk = fn (EvalGrade $g, ?string $err = null) => new EvalResult('c', 'bug', $g, 0, 0, 0.0, 0, $err);

    expect($mk(new EvalGrade(true))->status())->toBe('fixed');
    expect($mk(new EvalGrade(true, true))->status())->toBe('false-fix');
    expect($mk(new EvalGrade(false))->status())->toBe('not-fixed');
    expect($mk(new EvalGrade(false), 'boom')->status())->toBe('error');
});

// ---------------------------------------------------------------------------
// EvalReport aggregation
// ---------------------------------------------------------------------------

it('aggregates rates, tokens, and cost', function () {
    $report = new EvalReport([
        new EvalResult('a', 'bug', new EvalGrade(true), 100, 10, 0.01, 500),
        new EvalResult('b', 'bug', new EvalGrade(true, true), 200, 20, 0.02, 600), // false fix
        new EvalResult('c', 'bug', new EvalGrade(false), 150, 15, 0.015, 400),
        new EvalResult('d', 'bug', new EvalGrade(false), 0, 0, 0.0, 10, 'crash'),  // error
    ]);

    expect($report->total())->toBe(4)
        ->and($report->fixed())->toBe(1)
        ->and($report->falseFixes())->toBe(1)
        ->and($report->notFixed())->toBe(1)
        ->and($report->errors())->toBe(1)
        ->and($report->fixRate())->toBe(0.25)
        ->and($report->falseFixRate())->toBe(0.25)
        ->and($report->totalInputTokens())->toBe(450)
        ->and($report->totalOutputTokens())->toBe(45)
        ->and(round($report->totalCost(), 3))->toBe(0.045);
});

it('renders a report and JSON', function () {
    $report = new EvalReport([new EvalResult('a', 'bug', new EvalGrade(true), 100, 10, 0.01, 500)]);

    expect($report->render())->toContain('fixed: 1 (100%)')->toContain('a');
    expect($report->toArray())->toMatchArray(['total' => 1, 'fixed' => 1, 'fix_rate' => 1.0]);
});

// ---------------------------------------------------------------------------
// EvalRunner — seed, solve, grade, cleanup (no API)
// ---------------------------------------------------------------------------

function fixtureCase(): EvalCase
{
    return new EvalCase(
        id: 'fixture',
        title: 'toggle a flag',
        category: 'bug',
        files: ['answer.txt' => 'wrong', 'keep.txt' => '1'],
        prompt: 'make it right',
        grader: fn (string $dir) => new EvalGrade(
            fixed: trim(@file_get_contents($dir.'/answer.txt') ?: '') === 'right',
            brokeHappyPath: ! file_exists($dir.'/keep.txt'),
        ),
    );
}

it('seeds files, runs the solver, grades, and cleans up', function () {
    $seenDir = null;

    $result = (new EvalRunner)->run(fixtureCase(), function (string $dir) use (&$seenDir) {
        $seenDir = $dir;
        expect(file_get_contents($dir.'/answer.txt'))->toBe('wrong'); // seeded buggy
        file_put_contents($dir.'/answer.txt', 'right');               // "fix"
        file_put_contents($dir.'/keep.txt', '1');                     // happy path intact

        return ['inputTokens' => 300, 'outputTokens' => 30, 'costUsd' => 0.02];
    });

    expect($result->grade->isClean())->toBeTrue()
        ->and($result->status())->toBe('fixed')
        ->and($result->inputTokens)->toBe(300)
        ->and($result->costUsd)->toBe(0.02)
        ->and(is_dir($seenDir))->toBeFalse(); // cleaned up
});

it('scores an unsolved case as not-fixed', function () {
    $result = (new EvalRunner)->run(fixtureCase(), fn (string $dir) => ['inputTokens' => 10]);

    expect($result->status())->toBe('not-fixed');
});

it('records a throwing solver as an error without crashing', function () {
    $result = (new EvalRunner)->run(fixtureCase(), function () {
        throw new RuntimeException('provider down');
    });

    expect($result->status())->toBe('error')
        ->and($result->error)->toBe('provider down');
});

// ---------------------------------------------------------------------------
// Built-in cases grade correctly against known-good and known-bad fixes
// ---------------------------------------------------------------------------

it('every built-in case is unsolved as seeded and solvable by a correct fix', function () {
    $runner = new EvalRunner;

    foreach ((new CaseRepository)->all() as $case) {
        // Seeded, no change → not fixed.
        $asIs = $runner->run($case, fn () => []);
        expect($asIs->grade->fixed)->toBeFalse("case {$case->id} should be broken as seeded");

        // Apply the canonical correct fix → clean.
        $fixed = $runner->run($case, fn (string $dir) => applyCanonicalFix($dir, $case->id));
        expect($fixed->grade->isClean())->toBeTrue("case {$case->id} should pass with the correct fix");
    }
});

it('flags a fix that regresses the happy path as a false fix', function () {
    $case = (new CaseRepository)->only(['off-by-one'])[0];

    // "Fix" the target (10/3 -> 4) by hardcoding 4 — breaks 9/3 (should be 3).
    $result = (new EvalRunner)->run($case, function (string $dir) {
        file_put_contents($dir.'/EvalPaginator.php', "<?php\nclass EvalPaginator { public function lastPage(int \$t, int \$p): int { return 4; } }\n");

        return [];
    });

    expect($result->status())->toBe('false-fix');
});

function applyCanonicalFix(string $dir, string $id): array
{
    match ($id) {
        'div-by-zero' => file_put_contents($dir.'/EvalOrderStats.php',
            "<?php\nclass EvalOrderStats { public function averageCents(int \$t, int \$c): int { return \$c === 0 ? 0 : intdiv(\$t, \$c); } }\n"),
        'off-by-one' => file_put_contents($dir.'/EvalPaginator.php',
            "<?php\nclass EvalPaginator { public function lastPage(int \$t, int \$p): int { return (int) ceil(\$t / \$p); } }\n"),
        'discount-math' => file_put_contents($dir.'/EvalDiscount.php',
            "<?php\nclass EvalDiscount { public function apply(float \$price, float \$percent): float { return \$price - (\$price * \$percent / 100); } }\n"),
        'nullable-relation' => file_put_contents($dir.'/EvalReceipt.php',
            "<?php\nclass EvalReceipt { public function customerName(\$order): string { return \$order->customer?->name ?? 'Guest'; } }\n"),
        'cross-file-total' => file_put_contents($dir.'/EvalLineItem.php',
            "<?php\nclass EvalLineItem { public function __construct(public int \$quantity, public int \$unitPriceCents) {} public function subtotalCents(): int { return \$this->quantity * \$this->unitPriceCents; } }\n"),
        'boundary-empty' => file_put_contents($dir.'/EvalAverage.php',
            "<?php\nclass EvalAverage { public function mean(array \$n): float { return \$n === [] ? 0.0 : array_sum(\$n) / count(\$n); } }\n"),
        'tax-rounding' => file_put_contents($dir.'/EvalTax.php',
            "<?php\nclass EvalTax { public function withTax(int \$c, int \$r): int { return \$c + (int) round(\$c * \$r / 100); } }\n"),
        'slugify' => file_put_contents($dir.'/EvalSlug.php',
            "<?php\nclass EvalSlug { public function make(string \$t): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(\$t)), '-'); } }\n"),
        'status-label' => file_put_contents($dir.'/EvalStatus.php',
            "<?php\nclass EvalStatus { public function label(string \$s): string { return match (\$s) { 'pending' => 'Pending', 'paid' => 'Paid', 'shipped' => 'Shipped', 'refunded' => 'Refunded', default => 'Unknown' }; } }\n"),
        'factorial-base-case' => file_put_contents($dir.'/EvalMath.php',
            "<?php\nclass EvalMath { public function factorial(int \$n): int { return \$n <= 1 ? 1 : \$n * \$this->factorial(\$n - 1); } }\n"),
        // Navigation cases: the fix is trivial once the right file is found,
        // which is the point — what they grade is the finding.
        'locate-among-decoys' => file_put_contents($dir.'/EvalInvoiceTotal.php',
            "<?php\nclass EvalInvoiceTotal { public function total(array \$lineCents, int \$taxPercent): int { \$s = array_sum(\$lineCents); return \$s + intdiv(\$s * \$taxPercent, 100); } }\n"),
        'locate-by-behaviour' => file_put_contents($dir.'/EvalUserNameFormatter.php',
            "<?php\nclass EvalUserNameFormatter { public function initials(string \$first, string \$last): string { \$out = strtoupper(\$first[0] ?? '').'.'; return \$last === '' ? \$out : \$out.strtoupper(\$last[0]).'.'; } }\n"),
        default => null,
    };

    return [];
}

// ---------------------------------------------------------------------------
// User-defined cases loaded from the project evals directory
// ---------------------------------------------------------------------------

it('loads user cases from the evals path and can run them without built-ins', function () {
    $dir = sys_get_temp_dir().'/tackle-user-evals-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/my-case.php', <<<'PHP'
    <?php
    use Tackle\Evals\EvalCase;
    use Tackle\Evals\Probe;

    return new EvalCase(
        id: 'user-square',
        title: 'square() should square its input',
        category: 'bug',
        files: ['EvalSquare.php' => "<?php\nclass EvalSquare { public function square(int \$n): int { return \$n + \$n; } }\n"],
        prompt: 'square() doubles instead of squaring; fix it.',
        grader: Probe::subprocess('EvalSquare.php', '
            $s = new EvalSquare();
            $target = $s->square(3) === 9;
            $happy = $s->square(0) === 0;
        '),
    );
    PHP);

    config()->set('tackle.evals.path', $dir);
    config()->set('tackle.evals.include_builtin', false);

    $repo = new CaseRepository;
    $cases = $repo->all();

    expect($cases)->toHaveCount(1)
        ->and($cases[0]->id)->toBe('user-square');

    $runner = new EvalRunner;
    // Broken as seeded.
    expect($runner->run($cases[0], fn () => [])->grade->fixed)->toBeFalse();
    // Correct fix passes.
    $fixed = $runner->run($cases[0], function (string $d) {
        file_put_contents($d.'/EvalSquare.php', "<?php\nclass EvalSquare { public function square(int \$n): int { return \$n * \$n; } }\n");

        return [];
    });
    expect($fixed->grade->isClean())->toBeTrue();

    @unlink($dir.'/my-case.php');
    @rmdir($dir);
});

it('merges user cases with built-ins, user id overriding', function () {
    $dir = sys_get_temp_dir().'/tackle-user-evals-'.uniqid();
    mkdir($dir, 0755, true);
    // Override the built-in 'div-by-zero' id + add a new one.
    file_put_contents($dir.'/cases.php', <<<'PHP'
    <?php
    use Tackle\Evals\EvalCase;

    $g = fn () => new Tackle\Evals\EvalGrade(fixed: true);

    return [
        new EvalCase('div-by-zero', 'overridden', 'bug', [], 'x', $g),
        new EvalCase('extra', 'extra', 'bug', [], 'x', $g),
    ];
    PHP);

    config()->set('tackle.evals.path', $dir);
    config()->set('tackle.evals.include_builtin', true);

    $ids = array_map(fn ($c) => $c->id, (new CaseRepository)->all());

    expect($ids)->toContain('off-by-one', 'discount-math', 'div-by-zero', 'extra');
    // div-by-zero appears once (overridden, not duplicated).
    expect(array_count_values($ids)['div-by-zero'])->toBe(1);

    @unlink($dir.'/cases.php');
    @rmdir($dir);
});

it('returns no user cases when the evals path does not exist', function () {
    config()->set('tackle.evals.path', sys_get_temp_dir().'/definitely-not-here-'.uniqid());

    expect((new CaseRepository)->userCases())->toBe([]);
});

// ---------------------------------------------------------------------------
// The harder cases discriminate: a lazy "fix" that regresses the happy path
// is caught as a false-fix, not counted as fixed.
// ---------------------------------------------------------------------------

it('flags a nullable-relation fix that always returns Guest as a false fix', function () {
    $case = (new CaseRepository)->only(['nullable-relation'])[0];

    // Passes the null case by hardcoding 'Guest' — breaks the real-name path.
    $result = (new EvalRunner)->run($case, function (string $dir) {
        file_put_contents($dir.'/EvalReceipt.php',
            "<?php\nclass EvalReceipt { public function customerName(\$order): string { return 'Guest'; } }\n");

        return [];
    });

    expect($result->status())->toBe('false-fix');
});

it('does not credit a cross-file fix that hardcodes the expected total', function () {
    $case = (new CaseRepository)->only(['cross-file-total'])[0];

    // "Fixes" the target total by hardcoding 1300 — breaks the single-item case.
    $result = (new EvalRunner)->run($case, function (string $dir) {
        file_put_contents($dir.'/EvalCart.php',
            "<?php\nclass EvalCart { public function totalCents(array \$items): int { return 1300; } }\n");

        return [];
    });

    expect($result->status())->toBe('false-fix');
});

it('reports the whole context a case carried, not just the fresh part', function () {
    // With caching on, fresh input is a rounding error — the first run of this
    // suite reported "input_tokens: 10" for a case that pushed thousands of
    // tokens through the model. An eval whose job is comparing two agents'
    // context volume could not see 99% of it.
    $report = new EvalReport([
        new EvalResult('a', 'bug', new EvalGrade(true), 10, 700, 0.04, 500, null, 8000, 2000),
        new EvalResult('b', 'bug', new EvalGrade(true), 20, 800, 0.05, 600, null, 9000, 1000),
    ]);

    expect($report->totalInputTokens())->toBe(30)
        ->and($report->totalCacheReadTokens())->toBe(17000)
        ->and($report->totalCacheWriteTokens())->toBe(3000)
        ->and($report->totalContextTokens())->toBe(20030);

    $json = $report->toArray();

    expect($json['context_tokens'])->toBe(20030)
        ->and($json['cases'][0]['context_tokens'])->toBe(10010);
});

it('defaults cache counts to zero for a result built without them', function () {
    $report = new EvalReport([new EvalResult('a', 'bug', new EvalGrade(true), 100, 10, 0.01, 500)]);

    expect($report->totalContextTokens())->toBe(100);
});
