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
        default => null,
    };

    return [];
}
