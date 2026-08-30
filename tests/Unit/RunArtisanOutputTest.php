<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Tools\RunArtisan;

function artisanTool(string $workspace): RunArtisan
{
    return new RunArtisan(new PathGuard($workspace), new CommandGuard, new AutoApproveInteraction);
}

function artisanWorkspace(): string
{
    $dir = sys_get_temp_dir().'/tackle-artisan-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app/Http/Controllers', 0755, true);

    return $dir;
}

beforeEach(fn () => config()->set('tackle.artisan_allowlist', ['make:*', 'route:*']));

it('reports what artisan printed to stdout when a command fails', function () {
    // Artisan writes most of its errors to stdout — an unknown option, a
    // rendered exception. Reporting stderr alone produced a blank failure.
    Process::fake(['*' => Process::result(output: '  The "--filter" option does not exist.', errorOutput: '', exitCode: 1)]);

    $result = artisanTool(artisanWorkspace())->handle(new Request(['command' => 'route:list --filter api']));

    expect($result)->toContain('failed (exit 1)')->toContain('"--filter" option does not exist');
});

it('returns the contents of a file a make command created', function () {
    // Otherwise the next step is a guess at the stub, an EditFile that
    // misses, and a ReadFile — three steps to learn what was just created.
    $dir = artisanWorkspace();
    file_put_contents($dir.'/app/Http/Controllers/PingController.php', "<?php\n\nclass PingController {}\n");
    Process::fake(['*' => Process::result(output: "\n   INFO  Controller [app/Http/Controllers/PingController.php] created successfully.\n")]);

    $result = artisanTool($dir)->handle(new Request(['command' => 'make:controller PingController']));

    expect($result)
        ->toContain('created successfully')
        ->toContain('--- app/Http/Controllers/PingController.php ---')
        ->toContain('class PingController {}');

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('leaves output alone when the created file is not on disk', function () {
    Process::fake(['*' => Process::result(output: 'INFO  Controller [app/Http/Controllers/Ghost.php] created successfully.')]);

    expect(artisanTool(artisanWorkspace())->handle(new Request(['command' => 'make:controller Ghost'])))
        ->not->toContain('---');
});
