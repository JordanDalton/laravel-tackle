<?php

use Illuminate\Support\Facades\Artisan;
use Tackle\Agents\ExplainAgent;
use Tackle\Agents\HealingAgent;
use Tackle\Agents\ReviewAgent;
use Tackle\Agents\TestWriterAgent;
use Tackle\Support\ProjectMemory;

function memoryWorkspace(): string
{
    return sys_get_temp_dir().'/tackle-tests';
}

beforeEach(function () {
    @mkdir(memoryWorkspace(), 0755, true);

    foreach (ProjectMemory::FILES as $file) {
        @unlink(memoryWorkspace().'/'.$file);
    }
});

afterEach(function () {
    foreach (ProjectMemory::FILES as $file) {
        @unlink(memoryWorkspace().'/'.$file);
    }
});

// ---------------------------------------------------------------------------
// ProjectMemory
// ---------------------------------------------------------------------------

it('returns null content when no instructions file exists', function () {
    $memory = new ProjectMemory(memoryWorkspace());

    expect($memory->path())->toBeNull()
        ->and($memory->content())->toBeNull()
        ->and($memory->section())->toBe('');
});

it('loads TACKLE.md content', function () {
    file_put_contents(memoryWorkspace().'/TACKLE.md', '- All money values are integer cents.');

    $memory = new ProjectMemory(memoryWorkspace());

    expect($memory->content())->toBe('- All money values are integer cents.')
        ->and($memory->section())->toContain('Project instructions (TACKLE.md)')
        ->and($memory->section())->toContain('integer cents');
});

it('falls back to AGENTS.md then CLAUDE.md', function () {
    file_put_contents(memoryWorkspace().'/CLAUDE.md', 'claude rules');
    file_put_contents(memoryWorkspace().'/AGENTS.md', 'agents rules');

    $memory = new ProjectMemory(memoryWorkspace());

    expect(basename((string) $memory->path()))->toBe('AGENTS.md')
        ->and($memory->content())->toBe('agents rules');
});

it('prefers TACKLE.md over the fallbacks', function () {
    file_put_contents(memoryWorkspace().'/AGENTS.md', 'agents rules');
    file_put_contents(memoryWorkspace().'/TACKLE.md', 'tackle rules');

    expect((new ProjectMemory(memoryWorkspace()))->content())->toBe('tackle rules');
});

it('treats an empty instructions file as absent', function () {
    file_put_contents(memoryWorkspace().'/TACKLE.md', "   \n\n  ");

    $memory = new ProjectMemory(memoryWorkspace());

    expect($memory->content())->toBeNull()
        ->and($memory->section())->toBe('');
});

it('truncates oversized instructions files', function () {
    file_put_contents(memoryWorkspace().'/TACKLE.md', str_repeat('a', 30000));

    $content = (new ProjectMemory(memoryWorkspace()))->content();

    expect(strlen($content))->toBeLessThan(30000)
        ->and($content)->toContain('truncated');
});

// ---------------------------------------------------------------------------
// Agents pick up project memory
// ---------------------------------------------------------------------------

it('injects TACKLE.md into agent instructions', function (string $agentClass) {
    file_put_contents(memoryWorkspace().'/TACKLE.md', 'Never touch app/Legacy/.');

    $agent = $agentClass === HealingAgent::class
        ? new HealingAgent(memoryWorkspace())
        : app($agentClass);

    expect($agent->instructions())
        ->toContain('Project instructions (TACKLE.md)')
        ->toContain('Never touch app/Legacy/.');
})->with([
    ReviewAgent::class,
    ExplainAgent::class,
    TestWriterAgent::class,
    HealingAgent::class,
]);

it('omits the project instructions section when no file exists', function () {
    expect(app(ReviewAgent::class)->instructions())
        ->not->toContain('Project instructions');
});

// ---------------------------------------------------------------------------
// tackle:init
// ---------------------------------------------------------------------------

it('tackle:init command is registered', function () {
    expect(Artisan::all())->toHaveKey('tackle:init');
});

it('tackle:init creates TACKLE.md in the workspace', function () {
    $this->artisan('tackle:init')->assertSuccessful();

    expect(file_exists(memoryWorkspace().'/TACKLE.md'))->toBeTrue();

    $content = file_get_contents(memoryWorkspace().'/TACKLE.md');

    expect($content)
        ->toContain('## Stack')
        ->toContain('## Conventions')
        ->toContain('## Boundaries');
});

it('tackle:init refuses to overwrite without --force', function () {
    file_put_contents(memoryWorkspace().'/TACKLE.md', 'existing content');

    $this->artisan('tackle:init')->assertFailed();

    expect(file_get_contents(memoryWorkspace().'/TACKLE.md'))->toBe('existing content');
});

it('tackle:init overwrites with --force', function () {
    file_put_contents(memoryWorkspace().'/TACKLE.md', 'existing content');

    $this->artisan('tackle:init', ['--force' => true])->assertSuccessful();

    expect(file_get_contents(memoryWorkspace().'/TACKLE.md'))->toContain('## Conventions');
});

it('tackle:init detects Pest and Pint from composer.json', function () {
    file_put_contents(memoryWorkspace().'/composer.json', json_encode([
        'name' => 'acme/shop',
        'description' => 'An example shop.',
        'require' => ['php' => '^8.3', 'laravel/framework' => '^12.0'],
        'require-dev' => ['pestphp/pest' => '^3.0', 'laravel/pint' => '^1.0'],
    ]));

    $this->artisan('tackle:init')->assertSuccessful();

    $content = file_get_contents(memoryWorkspace().'/TACKLE.md');

    expect($content)
        ->toContain('# acme/shop')
        ->toContain('An example shop.')
        ->toContain('Pest')
        ->toContain('Pint');

    @unlink(memoryWorkspace().'/composer.json');
});
