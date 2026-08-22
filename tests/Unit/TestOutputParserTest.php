<?php

use Tackle\Support\TestOutputParser;

// Realistic `php artisan test` (Pest) output samples. Pest marks failures with
// ⨯ in the per-file list and repeats them as "• Group > test" detail blocks.

function pestFailingOutput(): string
{
    return <<<'OUT'

   PASS  Tests\Unit\ExampleTest
  ✓ it adds numbers

   FAIL  Tests\Feature\BillingTest
  ✓ it loads the page
  ⨯ it charges the customer
  ⨯ it emails a receipt

  ───────────────────────────────────────────────────
   FAILED  Tests\Feature\BillingTest > it charges the customer
  Failed asserting that 0 matches expected 1000.
  at tests/Feature/BillingTest.php:42
     38▕     $service->charge($order);
     39▕
  ───────────────────────────────────────────────────
   FAILED  Tests\Feature\BillingTest > it emails a receipt
  Expected the Mail fake to have sent ReceiptMail.
  at tests/Feature/BillingTest.php:57

  Tests:    2 failed, 2 passed (14 assertions)
  Duration: 1.83s

OUT;
}

function pestPassingOutput(): string
{
    return <<<'OUT'

   PASS  Tests\Unit\ExampleTest
  ✓ it adds numbers
  ✓ it subtracts

  Tests:    2 passed (5 assertions)
  Duration: 0.21s

OUT;
}

it('leads with the summary and duration for a failing run', function () {
    $out = TestOutputParser::summarize(pestFailingOutput());

    expect($out)
        ->toContain('Tests:    2 failed, 2 passed (14 assertions)')
        ->toContain('Duration: 1.83s');
});

it('lists each failure with its name, file:line, and assertion', function () {
    $out = TestOutputParser::summarize(pestFailingOutput());

    expect($out)
        ->toContain('Tests\Feature\BillingTest > it charges the customer')
        ->toContain('at tests/Feature/BillingTest.php:42')
        ->toContain('Failed asserting that 0 matches expected 1000.')
        ->toContain('Tests\Feature\BillingTest > it emails a receipt')
        ->toContain('at tests/Feature/BillingTest.php:57');
});

it('does not duplicate a failure that appears in both the list and the detail block', function () {
    $out = TestOutputParser::summarize(pestFailingOutput());

    expect(substr_count($out, 'it charges the customer'))->toBe(1);
});

it('reports the failure count', function () {
    expect(TestOutputParser::summarize(pestFailingOutput()))->toContain('Failures (2)');
});

it('collapses a passing run to a short confirmation', function () {
    $out = TestOutputParser::summarize(pestPassingOutput());

    expect($out)
        ->toContain('Tests:    2 passed (5 assertions)')
        ->toContain('All tests passed.')
        ->not->toContain('Failures');
});

it('is dramatically smaller than the raw output it summarizes', function () {
    $raw = pestFailingOutput()."\n".str_repeat("     40▕ some source context line echoed by pest\n", 400);

    $out = TestOutputParser::summarize($raw);

    expect(strlen($out))->toBeLessThan(strlen($raw) / 4)
        ->and($out)->toContain('it charges the customer'); // signal survives
});

it('handles PHPUnit-style failure output', function () {
    $phpunit = <<<'OUT'
PHPUnit 11.0.0

..F

Time: 00:00.120, Memory: 20.00 MB

There was 1 failure:

1) Tests\Feature\OrderTest::test_it_totals_the_cart
Failed asserting that 0 is identical to 500.

/app/tests/Feature/OrderTest.php:31

FAILURES!
Tests: 3, Assertions: 8, Failures: 1.
OUT;

    $out = TestOutputParser::summarize($phpunit);

    expect($out)
        ->toContain('Tests\Feature\OrderTest::test_it_totals_the_cart')
        ->toContain('/app/tests/Feature/OrderTest.php:31')
        ->toContain('Failures (1)');
});

it('falls back to head and tail for output it cannot parse as a test run', function () {
    $noise = "boot error\n".str_repeat("stack frame line\n", 200)."fatal at the end\n";

    $out = TestOutputParser::summarize($noise);

    expect($out)
        ->toContain('boot error')
        ->toContain('fatal at the end')
        ->toContain('lines omitted');
});

it('returns short unparseable output verbatim', function () {
    expect(TestOutputParser::summarize('could not connect to database'))
        ->toBe('could not connect to database');
});

it('handles empty output', function () {
    expect(TestOutputParser::summarize(''))->toBe('(Tests ran with no output.)');
});
