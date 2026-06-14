<?php

use Illuminate\Support\Facades\File;

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
