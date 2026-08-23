<?php

use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Contracts\CodingAgent;
use Tackle\Healing\GitHubTokenReader;
use Tackle\Healing\SandboxRunner;
use Tackle\Jobs\AbstractHealJob;
use Tackle\Tests\Fakes\FakeCodingAgent;

/**
 * Drives AbstractHealJob::handle end-to-end with a scripted agent and a fake
 * sandbox — no git, no provider, no network — to lock the verification gate:
 * clean heals get applied, dirty ones open a flagged PR, and the PR carries
 * the evidence.
 */

// A SandboxRunner that returns canned results and records what it was asked to
// do. testFailures() is called twice per heal (baseline, then after), so it
// pops from a queue.
class FakeSandbox extends SandboxRunner
{
    /** @var array<int, array{ran: bool, ok: bool, failures: list<string>}> */
    public array $testRuns = [];

    public array $diffResult = ['files' => [], 'insertions' => 0, 'deletions' => 0];

    public bool $applied = false;

    public bool $pushed = false;

    public ?string $prTitle = null;

    public ?string $prBody = null;

    public function prepare(string $branchName): string
    {
        return sys_get_temp_dir().'/fake-worktree';
    }

    public function testFailures(string $worktreePath): array
    {
        return array_shift($this->testRuns) ?? ['ran' => true, 'ok' => true, 'failures' => []];
    }

    public function diff(string $worktreePath): array
    {
        return $this->diffResult;
    }

    public function commit(string $worktreePath, string $message): void {}

    public function push(string $branchName, string $worktreePath): void
    {
        $this->pushed = true;
    }

    public function applyToMain(string $branchName): void
    {
        $this->applied = true;
    }

    public function createPullRequest(string $branchName, string $title, string $body, ?string $token): ?string
    {
        $this->prTitle = $title;
        $this->prBody = $body;

        return 'https://github.com/acme/app/pull/1';
    }

    public array $analysisResult = ['ran' => false, 'ok' => true, 'summary' => ''];

    public bool $formatted = false;

    public function format(string $worktreePath): void
    {
        $this->formatted = true;
    }

    public function analyzeChanged(string $worktreePath, array $paths): array
    {
        return $this->analysisResult;
    }

    public array $redGreenResult = ['ran' => false, 'red' => false, 'green' => false];

    public function redGreenCheck(string $worktreePath, array $newTestPaths, array $fixPaths): array
    {
        return $this->redGreenResult;
    }

    public function cleanup(string $worktreePath, string $branchName): void {}
}

class FakeTokenReader extends GitHubTokenReader
{
    public function token(): ?string
    {
        return 'ghp_faketoken';
    }
}

// Minimal concrete heal job whose agent is scripted.
class TestHealJob extends AbstractHealJob
{
    public function __construct(public CodingAgent $agent) {}

    protected function makeAgent(string $worktreePath): CodingAgent
    {
        return $this->agent;
    }

    protected function subjectType(): string
    {
        return 'job';
    }

    protected function subjectClass(): string
    {
        return 'App\\Jobs\\ProcessPayment';
    }

    protected function branchSuffix(): string
    {
        return 'test';
    }

    protected function agentPrompt(): string
    {
        return 'fix it';
    }

    protected function commitMessage(): string
    {
        return 'tackle: fix';
    }

    protected function onPatched(): void {}

    protected function prTitle(bool $testsPassed): string
    {
        return 'fix ProcessPayment';
    }

    protected function prBody(string $agentSummary, bool $testsPassed): string
    {
        return "## Fix\n\n{$agentSummary}";
    }

    protected function getExceptionClass(): string
    {
        return 'RuntimeException';
    }

    protected function getExceptionMessage(): string
    {
        return 'boom';
    }

    protected function getExceptionTrace(): string
    {
        return '#0 ...';
    }
}

function scriptedAgent(string $summary = 'Fixed the null deref and added a regression test.'): FakeCodingAgent
{
    return new FakeCodingAgent([
        new TextDelta('e', 'm', $summary, 0),
        new StreamEnd('e', 'stop', new Usage(500, 50), 0),
    ]);
}

function runHeal(FakeSandbox $sandbox, ?FakeCodingAgent $agent = null): TestHealJob
{
    $job = new TestHealJob($agent ?? scriptedAgent());
    $job->handle($sandbox, new FakeTokenReader);

    return $job;
}

beforeEach(function () {
    config()->set('tackle.healing.baseline', true);
    config()->set('tackle.healing.max_files', 20);
    config()->set('tackle.healing.max_diff_lines', 400);
});

it('auto-applies a clean heal in patch mode', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],  // baseline
        ['ran' => true, 'ok' => true, 'failures' => []],  // after
    ];
    $sandbox->diffResult = [
        'files' => ['app/Jobs/ProcessPayment.php' => 'M', 'tests/Feature/ProcessPaymentTest.php' => 'A'],
        'insertions' => 12, 'deletions' => 3,
    ];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeTrue()
        ->and($sandbox->prTitle)->toBeNull();
});

it('does not auto-apply when the fix introduces a new failure; opens a flagged PR with evidence', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => false, 'failures' => ['Old > pre-existing']],                      // baseline
        ['ran' => true, 'ok' => false, 'failures' => ['Old > pre-existing', 'New > broke this']],  // after
    ];
    $sandbox->diffResult = ['files' => ['app/Jobs/ProcessPayment.php' => 'M'], 'insertions' => 5, 'deletions' => 1];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeFalse()
        ->and($sandbox->prTitle)->toStartWith('[tests failing] ')
        ->and($sandbox->prBody)
        ->toContain('## Heal evidence')
        ->toContain('New failures introduced:')
        ->toContain('New > broke this')
        ->and($sandbox->prBody)->not->toContain('pre-existing" is new'); // pre-existing not counted as new
});

it('treats a heal that only leaves pre-existing failures as clean and applies it', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => false, 'failures' => ['Old > pre-existing']],  // baseline
        ['ran' => true, 'ok' => false, 'failures' => ['Old > pre-existing']],  // after: same
    ];
    $sandbox->diffResult = ['files' => ['app/Jobs/ProcessPayment.php' => 'M'], 'insertions' => 4, 'deletions' => 2];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeTrue();
});

it('holds back a clean but oversized heal from auto-apply and flags it for review', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    // Edits an already-run migration — a blast-radius violation.
    $sandbox->diffResult = [
        'files' => ['database/migrations/2026_01_01_000000_create_orders_table.php' => 'M'],
        'insertions' => 3, 'deletions' => 3,
    ];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeFalse()
        ->and($sandbox->prTitle)->toStartWith('[needs review] ')
        ->and($sandbox->prBody)
        ->toContain('Blast-radius limits exceeded')
        ->toContain('database/migrations/2026_01_01_000000_create_orders_table.php');
});

it('records whether a regression test was added in the PR evidence', function () {
    config()->set('tackle.healing.mode', 'pr');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    $sandbox->diffResult = [
        'files' => ['app/Jobs/ProcessPayment.php' => 'M'], // no test file added
        'insertions' => 6, 'deletions' => 1,
    ];

    runHeal($sandbox);

    expect($sandbox->prBody)->toContain('No regression test added');
});

it('marks the heal unverified when the post-fix suite cannot run', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],   // baseline ran
        ['ran' => false, 'ok' => false, 'failures' => []], // after could not run
    ];
    $sandbox->diffResult = ['files' => ['app/Jobs/ProcessPayment.php' => 'M'], 'insertions' => 2, 'deletions' => 0];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeFalse()
        ->and($sandbox->prBody)->toContain('Tests could not be run');
});

it('does not auto-apply a test-only heal; flags it [incomplete]', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    // The agent added a passing test and changed no application code.
    $sandbox->diffResult = [
        'files' => ['tests/Feature/SlowOrdersControllerTest.php' => 'A'],
        'insertions' => 51, 'deletions' => 0,
    ];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeFalse()
        ->and($sandbox->prTitle)->toStartWith('[incomplete] ')
        ->and($sandbox->prBody)->toContain('No application code changed');
});

it('holds back a heal with static-analysis errors and flags it for review', function () {
    config()->set('tackle.healing.mode', 'patch');
    config()->set('tackle.healing.static_analysis', true);

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    $sandbox->diffResult = ['files' => ['app/Support/OrderStats.php' => 'M'], 'insertions' => 6, 'deletions' => 1];
    $sandbox->analysisResult = ['ran' => true, 'ok' => false, 'summary' => 'Found 1 error'];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeFalse()
        ->and($sandbox->prTitle)->toStartWith('[needs review] ')
        ->and($sandbox->prBody)->toContain('Static analysis errors');
});

it('auto-applies a heal that passes static analysis and formats the change', function () {
    config()->set('tackle.healing.mode', 'patch');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    $sandbox->diffResult = [
        'files' => ['app/Support/OrderStats.php' => 'M', 'tests/Unit/OrderStatsTest.php' => 'A'],
        'insertions' => 12, 'deletions' => 0,
    ];
    $sandbox->analysisResult = ['ran' => true, 'ok' => true, 'summary' => ''];

    runHeal($sandbox);

    expect($sandbox->applied)->toBeTrue()
        ->and($sandbox->formatted)->toBeTrue()
        ->and($sandbox->prBody)->toBeNull();
});

it('records a proven red→green regression test in the evidence', function () {
    config()->set('tackle.healing.mode', 'pr');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    $sandbox->diffResult = [
        'files' => ['app/Jobs/ProcessPayment.php' => 'M', 'tests/Feature/ProcessPaymentTest.php' => 'A'],
        'insertions' => 12, 'deletions' => 1,
    ];
    $sandbox->redGreenResult = ['ran' => true, 'red' => true, 'green' => true];

    runHeal($sandbox);

    expect($sandbox->prBody)->toContain('Regression test proven');
});

it('flags a regression test that passes even without the fix', function () {
    config()->set('tackle.healing.mode', 'pr');

    $sandbox = new FakeSandbox;
    $sandbox->testRuns = [
        ['ran' => true, 'ok' => true, 'failures' => []],
        ['ran' => true, 'ok' => true, 'failures' => []],
    ];
    $sandbox->diffResult = [
        'files' => ['app/Jobs/ProcessPayment.php' => 'M', 'tests/Feature/ProcessPaymentTest.php' => 'A'],
        'insertions' => 12, 'deletions' => 1,
    ];
    // Test passed even with the fix reverted → not a real reproduction.
    $sandbox->redGreenResult = ['ran' => true, 'red' => false, 'green' => true];

    runHeal($sandbox);

    expect($sandbox->prBody)->toContain('did not fail without the fix');
});
