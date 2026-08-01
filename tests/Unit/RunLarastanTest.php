<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\RunLarastan;

function makeRunLarastanTool(?string $workspace = null): RunLarastan
{
    $workspace ??= base_path();
    config()->set('tackle.workspace', $workspace);

    return new RunLarastan(new PathGuard($workspace));
}

it('returns a not-installed message when phpstan binary is absent', function () {
    $workspace = sys_get_temp_dir().'/tackle-larastan-test-'.uniqid();
    @mkdir($workspace, 0755, true);

    $result = makeRunLarastanTool($workspace)->handle(new Request([]));

    expect($result)->toContain('PHPStan is not installed');

    @rmdir($workspace);
});

it('runs phpstan when the binary is present', function () {
    // Uses the real phpstan installed in the package's own vendor/
    if (! file_exists(base_path('vendor/bin/phpstan'))) {
        $this->markTestSkipped('phpstan not installed in vendor/');
    }

    $result = makeRunLarastanTool()->handle(new Request([]));

    expect($result)->toBeString()->not->toContain('PHPStan is not installed');
});

it('passes a path argument when provided', function () {
    if (! file_exists(base_path('vendor/bin/phpstan'))) {
        $this->markTestSkipped('phpstan not installed in vendor/');
    }

    $result = makeRunLarastanTool()->handle(new Request(['path' => 'src/Tools/RunLarastan.php']));

    expect($result)->toBeString()->not->toContain('PHPStan is not installed');
});

it('passes a level argument when provided', function () {
    if (! file_exists(base_path('vendor/bin/phpstan'))) {
        $this->markTestSkipped('phpstan not installed in vendor/');
    }

    $result = makeRunLarastanTool()->handle(new Request(['level' => 0]));

    expect($result)->toBeString()->not->toContain('PHPStan is not installed');
});
