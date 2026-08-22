<?php

use Illuminate\Support\Facades\File;
use Tackle\Evals\CaseRepository;
use Tackle\Evals\EvalRunner;

beforeEach(function () {
    File::deleteDirectory(app_path('Ai'));
});

afterEach(function () {
    File::deleteDirectory(app_path('Ai'));
});

// ---------------------------------------------------------------------------
// tackle:tool
// ---------------------------------------------------------------------------

it('generates a tool class at app/Ai/Tools/', function () {
    $this->artisan('tackle:tool', ['name' => 'ReadDatabase'])
        ->assertSuccessful();

    expect(file_exists(app_path('Ai/Tools/ReadDatabase.php')))->toBeTrue();
});

it('converts tool name to StudlyCase', function () {
    $this->artisan('tackle:tool', ['name' => 'read-database'])
        ->assertSuccessful();

    expect(file_exists(app_path('Ai/Tools/ReadDatabase.php')))->toBeTrue();
});

it('tool stub extends AbstractTool', function () {
    $this->artisan('tackle:tool', ['name' => 'MyTool'])->assertSuccessful();

    $contents = file_get_contents(app_path('Ai/Tools/MyTool.php'));

    expect($contents)
        ->toContain('extends AbstractTool')
        ->toContain('public function description()')
        ->toContain('public function schema(')
        ->toContain('public function handle(');
});

it('refuses to overwrite an existing tool', function () {
    $this->artisan('tackle:tool', ['name' => 'MyTool'])->assertSuccessful();
    $this->artisan('tackle:tool', ['name' => 'MyTool'])->assertFailed();
});

// ---------------------------------------------------------------------------
// tackle:agent (extend mode — default)
// ---------------------------------------------------------------------------

it('generates an agent class at app/Ai/', function () {
    $this->artisan('tackle:agent', ['name' => 'BillingAgent'])
        ->assertSuccessful();

    expect(file_exists(app_path('Ai/BillingAgent.php')))->toBeTrue();
});

it('converts agent name to StudlyCase', function () {
    $this->artisan('tackle:agent', ['name' => 'billing-agent'])
        ->assertSuccessful();

    expect(file_exists(app_path('Ai/BillingAgent.php')))->toBeTrue();
});

it('default agent stub extends DefaultCodingAgent', function () {
    $this->artisan('tackle:agent', ['name' => 'MyAgent'])->assertSuccessful();

    $contents = file_get_contents(app_path('Ai/MyAgent.php'));

    expect($contents)
        ->toContain('extends DefaultCodingAgent')
        ->toContain('public function tools()');
});

it('refuses to overwrite an existing agent', function () {
    $this->artisan('tackle:agent', ['name' => 'MyAgent'])->assertSuccessful();
    $this->artisan('tackle:agent', ['name' => 'MyAgent'])->assertFailed();
});

// ---------------------------------------------------------------------------
// tackle:agent --full
// ---------------------------------------------------------------------------

it('--full stub implements CodingAgent directly', function () {
    $this->artisan('tackle:agent', ['name' => 'MyFullAgent', '--full' => true])
        ->assertSuccessful();

    $contents = file_get_contents(app_path('Ai/MyFullAgent.php'));

    expect($contents)
        ->toContain('implements CodingAgent')
        ->toContain('use Promptable')
        ->toContain('public function instructions()')
        ->toContain('public function messages()')
        ->toContain('public function tools()');
});

// ---------------------------------------------------------------------------
// tackle:eval (case generator)
// ---------------------------------------------------------------------------

it('scaffolds an eval case into the evals path', function () {
    $dir = sys_get_temp_dir().'/tackle-gen-evals-'.uniqid();
    config()->set('tackle.evals.path', $dir);

    $this->artisan('tackle:eval', ['name' => 'refund rounding'])
        ->expectsOutputToContain('Eval case created')
        ->assertSuccessful();

    $path = $dir.'/refund-rounding.php';
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain("id: 'refund-rounding'")
        ->toContain('class EvalRefundRounding')
        ->toContain('Probe::subprocess');

    File::deleteDirectory($dir);
});

it('the generated case loads and is broken as seeded', function () {
    $dir = sys_get_temp_dir().'/tackle-gen-evals-'.uniqid();
    config()->set('tackle.evals.path', $dir);
    config()->set('tackle.evals.include_builtin', false);

    $this->artisan('tackle:eval', ['name' => 'sample bug'])->assertSuccessful();

    $cases = (new CaseRepository)->all();
    expect($cases)->toHaveCount(1)
        ->and($cases[0]->id)->toBe('sample-bug');

    // As generated, the seeded code fails the target (broken) but the happy
    // path holds — the correct starting state for a case.
    $runner = new EvalRunner;
    $grade = $runner->run($cases[0], fn () => [])->grade;
    expect($grade->fixed)->toBeFalse()
        ->and($grade->isFalseFix())->toBeFalse();

    // The intended fix (double the input) makes it pass cleanly — the stub is
    // self-consistent, so an unedited generated case demonstrates a real fix.
    $fixed = $runner->run($cases[0], function (string $d) {
        $file = glob($d.'/*.php')[0];
        file_put_contents($file, str_replace('return $value;', 'return $value * 2;', file_get_contents($file)));

        return [];
    });
    expect($fixed->grade->isClean())->toBeTrue();

    File::deleteDirectory($dir);
});

it('refuses to overwrite an existing eval case without --force', function () {
    $dir = sys_get_temp_dir().'/tackle-gen-evals-'.uniqid();
    config()->set('tackle.evals.path', $dir);

    $this->artisan('tackle:eval', ['name' => 'dup'])->assertSuccessful();
    $this->artisan('tackle:eval', ['name' => 'dup'])->assertFailed();
    $this->artisan('tackle:eval', ['name' => 'dup', '--force' => true])->assertSuccessful();

    File::deleteDirectory($dir);
});
