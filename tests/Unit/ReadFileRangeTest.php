<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\ReadFile;

function rangeWorkspace(): string
{
    $dir = sys_get_temp_dir().'/tackle-readfile-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/big.php', implode("\n", array_map(fn ($i) => "line {$i}", range(1, 120))));

    return $dir;
}

function readRange(string $dir, array $params): string
{
    return (new ReadFile(new PathGuard($dir)))->handle(new Request(['path' => 'big.php', ...$params]));
}

it('reads the whole file when no range is given', function () {
    $dir = rangeWorkspace();

    expect(readRange($dir, []))->toStartWith('line 1')->toContain('line 120')->not->toContain('|');
});

it('reads a numbered range so the next edit can be aimed', function () {
    // SearchCode gives a line number; this is how the agent reads around it
    // instead of pulling the whole file into every following step.
    $dir = rangeWorkspace();

    $out = readRange($dir, ['offset' => 40, 'limit' => 3]);

    expect($out)
        ->toContain("'big.php' lines 40-42 of 120")
        ->toContain(' 40| line 40')
        ->toContain(' 42| line 42')
        ->not->toContain('line 39')
        ->not->toContain('line 43')
        ->toContain('more below');
});

it('reads from offset to the end when only offset is given', function () {
    $dir = rangeWorkspace();

    $out = readRange($dir, ['offset' => 118]);

    expect($out)->toContain('lines 118-120 of 120')->toContain('line 120')->not->toContain('more below');
});

it('clamps a limit that runs past the end', function () {
    $dir = rangeWorkspace();

    expect(readRange($dir, ['offset' => 115, 'limit' => 50]))->toContain('lines 115-120 of 120');
});

it('says so when the offset is past the end', function () {
    $dir = rangeWorkspace();

    expect(readRange($dir, ['offset' => 500]))->toContain('has 120 lines')->toContain('offset 500 is past the end');
});

it('treats offset 1 with no limit as the whole file', function () {
    $dir = rangeWorkspace();

    expect(readRange($dir, ['offset' => 1]))->toStartWith('line 1')->not->toContain('|');
});
