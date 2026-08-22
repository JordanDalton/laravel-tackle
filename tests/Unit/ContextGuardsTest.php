<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;
use Tackle\Support\BudgetTracker;
use Tackle\Support\EventedTool;
use Tackle\Support\PathGuard;
use Tackle\Support\ToolOutput;
use Tackle\Tests\Fakes\FakeCodingAgent;
use Tackle\Tools\AbstractTool;
use Tackle\Tools\Delegate;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\SearchCode;

/*
 * Regression suite for the ai:onboard maiden flight (2026-08-22): a subagent
 * pulled a ~3.5 MB tool result into context and then re-sent ~945k tokens on
 * every step — nine requests, ~8.5M input tokens, invisible to the budget
 * check because that only runs when a stream ends. These guards bound the
 * blast radius: cap every tool result, stop walking node_modules, refuse
 * further tools once a turn's context is large, and keep the subagent's
 * counter separate from the parent's.
 */

class GuardTestBigTool extends AbstractTool
{
    public static string $payload = '';

    public function description(): string
    {
        return 'Returns a configurable payload.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return self::$payload;
    }
}

class GuardTestSubagent extends FakeCodingAgent
{
    public static array $events = [];

    public function __construct()
    {
        parent::__construct(self::$events);
    }
}

function guardWorkspace(): string
{
    return sys_get_temp_dir().'/tackle-tests';
}

function guardFile(string $relative, string $content): void
{
    $path = guardWorkspace().'/'.$relative;
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
}

function guardGuard(): PathGuard
{
    config()->set('tackle.workspace', guardWorkspace());

    return new PathGuard;
}

beforeEach(function () {
    @mkdir(guardWorkspace(), 0755, true);
    config()->set('tackle.max_tool_result_chars', 48000);
    config()->set('tackle.max_context_chars', 600000);
    config()->set('tackle.ignored_directories', ['node_modules', '.git', 'vendor', 'storage', 'bootstrap/cache', 'public/build']);
    GuardTestBigTool::$payload = '';
});

afterEach(function () {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(guardWorkspace(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
});

// ---------------------------------------------------------------------------
// ToolOutput::cap
// ---------------------------------------------------------------------------

it('leaves results under the cap untouched', function () {
    expect(ToolOutput::cap('small', 'X'))->toBe('small');
});

it('truncates oversized results with a note naming the tool and the sizes', function () {
    $big = str_repeat("line of text\n", 10_000); // 130k chars

    $capped = ToolOutput::cap($big, 'Glob');

    expect(strlen($capped))->toBeLessThan(48_200)
        ->and($capped)->toContain('[Output truncated by Tackle: showing ')
        ->toContain('from Glob')
        ->toContain('130,000 characters');
});

it('honours tackle.max_tool_result_chars', function () {
    config()->set('tackle.max_tool_result_chars', 100);

    expect(strlen(ToolOutput::cap(str_repeat('a', 1000), 'X')))->toBeLessThan(300)
        ->and(ToolOutput::cap(str_repeat('a', 1000), 'X'))->toContain('1,000 characters');
});

// ---------------------------------------------------------------------------
// EventedTool applies the cap and the per-turn context ceiling
// ---------------------------------------------------------------------------

it('caps every tool result that runs through the harness', function () {
    GuardTestBigTool::$payload = str_repeat('x', 1_000_000);

    $result = (string) (new EventedTool(new GuardTestBigTool))->handle(new Request([]));

    expect(strlen($result))->toBeLessThan(48_300)
        ->and($result)->toContain('[Output truncated by Tackle');
});

it('counts tool output against the turn and refuses once the ceiling is reached', function () {
    config()->set('tackle.max_tool_result_chars', 1000);
    config()->set('tackle.max_context_chars', 2500);

    $budget = app(BudgetTracker::class);
    $budget->resetContextChars(0);
    $tool = new EventedTool(new GuardTestBigTool);
    GuardTestBigTool::$payload = str_repeat('y', 900);

    expect((string) $tool->handle(new Request([])))->toBe(str_repeat('y', 900))
        ->and($budget->contextChars())->toBe(900);

    $tool->handle(new Request([]));
    $tool->handle(new Request([]));

    expect($budget->contextChars())->toBe(2700)
        ->and($budget->contextCeilingReached())->toBeTrue();

    $refusal = (string) $tool->handle(new Request([]));

    expect($refusal)->toStartWith('Refused: this turn has already pulled 2,700 characters')
        ->and($refusal)->toContain('finish now with what you already have');
});

it('refuses further tools once the turn is projected to blow the budget', function () {
    // $3 budget, Sonnet-class $3/MTok input. One ~4M-char re-send ≈ 1M tokens
    // ≈ $3 — the runaway single turn that end-of-stream checking misses.
    config()->set('tackle.budget_usd', 3.00);
    config()->set('tackle.pricing.input_per_mtok', 3.00);
    config()->set('tackle.pricing.output_per_mtok', 15.00);
    config()->set('tackle.max_tool_result_chars', 5_000_000);
    config()->set('tackle.max_context_chars', 50_000_000); // char ceiling out of the way

    $budget = app(BudgetTracker::class);
    $budget->resetContextChars(0);
    $budget->resetInFlightCost(0.0);

    $tool = new EventedTool(new GuardTestBigTool);
    GuardTestBigTool::$payload = str_repeat('x', 4_000_000); // ~1M tokens

    // First call runs and charges the in-flight estimate for re-sending it.
    $tool->handle(new Request([]));

    expect($budget->projectedOverBudget())->toBeTrue()
        ->and($budget->inFlightCost())->toBeGreaterThan(2.9);

    $refusal = (string) $tool->handle(new Request([]));

    expect($refusal)->toStartWith('Refused: this turn is projected to have spent')
        ->and($refusal)->toContain('of the $3.00 budget');
});

it('does not apply the projected-budget stop when pricing is free', function () {
    config()->set('tackle.pricing.input_per_mtok', 0.0);
    config()->set('tackle.pricing.output_per_mtok', 0.0);

    $budget = app(BudgetTracker::class);
    $budget->recordToolOutput(10_000_000);

    expect($budget->projectedOverBudget())->toBeFalse();
});

it('clears the in-flight estimate when usage for the turn is recorded', function () {
    config()->set('tackle.pricing.input_per_mtok', 3.00);
    config()->set('tackle.pricing.output_per_mtok', 15.00);

    $budget = app(BudgetTracker::class);
    $budget->recordToolOutput(4_000_000);

    expect($budget->inFlightCost())->toBeGreaterThan(0.0);

    $budget->record(100, 10);

    expect($budget->inFlightCost())->toBe(0.0);
});

it('clears the per-turn counter when usage for the turn is recorded', function () {
    $budget = app(BudgetTracker::class);
    $budget->recordToolOutput(5000);

    expect($budget->contextChars())->toBe(5000);

    $budget->record(100, 10);

    expect($budget->contextChars())->toBe(0);
});

// ---------------------------------------------------------------------------
// Delegate keeps the subagent's counter separate from the parent's
// ---------------------------------------------------------------------------

it('gives a subagent a clean counter and restores the parent count afterwards', function () {
    config()->set('tackle.subagents', [
        'guard-sub' => ['agent' => GuardTestSubagent::class, 'description' => 'test'],
    ]);
    GuardTestSubagent::$events = [
        new TextDelta('e', 'm', 'report', 0),
        new StreamEnd('e', 'stop', new Usage(10, 1), 0), // would reset the counter
    ];

    $budget = app(BudgetTracker::class);
    $budget->resetContextChars(4242);
    $budget->resetInFlightCost(1.23);

    $result = (new Delegate($budget))->handle(new Request(['agent' => 'guard-sub', 'task' => 'go']));

    expect($result)->toStartWith('Report from subagent')
        ->and($budget->contextChars())->toBe(4242)
        ->and($budget->inFlightCost())->toBe(1.23);
});

// ---------------------------------------------------------------------------
// Glob / SearchCode skip dependency trees; ReadFile refuses binaries
// ---------------------------------------------------------------------------

it('Glob skips ignored directories on a recursive walk but lists them when asked', function () {
    guardFile('app/Models/User.php', '<?php');
    guardFile('node_modules/pkg/index.js', 'x');
    guardFile('node_modules/pkg/lib/deep.js', 'x');
    guardFile('public/build/app.js', 'x');

    $glob = new Glob(guardGuard());

    $all = $glob->handle(new Request(['pattern' => '**/*']));

    expect($all)->toContain('app/Models/User.php')
        ->not->toContain('node_modules')
        ->not->toContain('public/build');

    $inside = $glob->handle(new Request(['pattern' => 'node_modules/**/*.js']));

    expect($inside)->toContain('node_modules/pkg/index.js')
        ->toContain('node_modules/pkg/lib/deep.js');
});

it('Glob caps a huge listing and says so', function () {
    for ($i = 0; $i < 1050; $i++) {
        guardFile(sprintf('gen/f%04d.txt', $i), '');
    }

    $out = (new Glob(guardGuard()))->handle(new Request(['pattern' => 'gen/**/*']));

    expect(substr_count($out, "\n"))->toBeLessThan(1010)
        ->and($out)->toContain('[Listing capped at 1000 of 1050 files');
});

it('SearchCode skips ignored directories and clips minified lines', function () {
    guardFile('app/Svc.php', "<?php\nclass Svc { function needle() {} }\n");
    guardFile('node_modules/lib/bundle.js', 'needle '.str_repeat('m', 5000));
    guardFile('resources/js/vendor.min.js', 'needle '.str_repeat('m', 5000));

    $out = (new SearchCode(guardGuard()))->handle(new Request(['query' => 'needle']));

    expect($out)->toContain('app/Svc.php')
        ->not->toContain('node_modules')
        ->toContain('resources/js/vendor.min.js')
        ->and(strlen($out))->toBeLessThan(2000);
});

it('ReadFile refuses binary files and truncates huge text files', function () {
    guardFile('database/database.sqlite', "SQLite format 3\0".str_repeat("\0\1\2", 1000));
    guardFile('big.log', str_repeat("a line of log\n", 20_000)); // 280k

    $read = new ReadFile(guardGuard());

    expect($read->handle(new Request(['path' => 'database/database.sqlite'])))->toContain('binary file')
        ->and(strlen($read->handle(new Request(['path' => 'big.log']))))->toBeLessThan(48_300)
        ->and($read->handle(new Request(['path' => 'big.log'])))->toContain('[Output truncated by Tackle');
});
