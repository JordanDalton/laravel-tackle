<?php

use Tackle\Healing\HealEvidence;

function evidence(array $overrides = []): HealEvidence
{
    $d = array_merge([
        'baselineFailures' => [],
        'afterFailures' => [],
        'baselineRan' => true,
        'afterRan' => true,
        'filesTouched' => ['app/Jobs/ProcessPayment.php'],
        'insertions' => 8,
        'deletions' => 2,
        'regressionTestAdded' => true,
        'blastRadiusViolations' => [],
    ], $overrides);

    return new HealEvidence(...$d);
}

it('reports only the failures the fix introduced as new', function () {
    $e = evidence([
        'baselineFailures' => ['A > pre-existing one', 'A > pre-existing two'],
        'afterFailures' => ['A > pre-existing one', 'A > pre-existing two', 'B > broke this'],
    ]);

    expect($e->newFailures())->toBe(['B > broke this'])
        ->and($e->testsClean())->toBeFalse();
});

it('treats a fix that only leaves pre-existing failures as clean', function () {
    $e = evidence([
        'baselineFailures' => ['A > already red'],
        'afterFailures' => ['A > already red'],
    ]);

    expect($e->newFailures())->toBe([])
        ->and($e->testsClean())->toBeTrue()
        ->and($e->gatePassed())->toBeTrue();
});

it('surfaces pre-existing failures the fix happened to clear', function () {
    $e = evidence([
        'baselineFailures' => ['A > was red', 'B > also red'],
        'afterFailures' => ['B > also red'],
    ]);

    expect($e->resolvedFailures())->toBe(['A > was red']);
});

it('is not clean when the post-fix suite could not run', function () {
    $e = evidence(['afterRan' => false]);

    expect($e->testsClean())->toBeFalse()
        ->and($e->gatePassed())->toBeFalse()
        ->and($e->render())->toContain('Tests could not be run');
});

it('fails the gate when blast-radius limits are exceeded even if tests are clean', function () {
    $e = evidence(['blastRadiusViolations' => ['modifies a protected path: config/app.php']]);

    expect($e->testsClean())->toBeTrue()
        ->and($e->gatePassed())->toBeFalse()
        ->and($e->titleTag())->toBe('[needs review] ');
});

it('tags the title for a failing heal', function () {
    expect(evidence(['afterFailures' => ['x > new']])->titleTag())->toBe('[tests failing] ');
    expect(evidence()->titleTag())->toBe('');
});

it('renders an evidence block a reviewer can scan', function () {
    $e = evidence([
        'baselineFailures' => ['old > one'],
        'afterFailures' => ['old > one'],
        'filesTouched' => ['app/Jobs/ProcessPayment.php', 'tests/Feature/ProcessPaymentTest.php'],
    ]);

    $out = $e->render();

    expect($out)
        ->toContain('No new test failures')
        ->toContain('1 test(s) were already failing before the fix')
        ->toContain('Regression test added')
        ->toContain('2 file(s), +8/-2 lines')
        ->toContain('app/Jobs/ProcessPayment.php');
});

it('warns when no regression test was added', function () {
    expect(evidence(['regressionTestAdded' => false])->render())
        ->toContain('No regression test added');
});

it('lists blast-radius violations in the rendered block', function () {
    expect(evidence(['blastRadiusViolations' => ['touches 40 files (limit 20)']])->render())
        ->toContain('Blast-radius limits exceeded')
        ->toContain('touches 40 files (limit 20)');
});
