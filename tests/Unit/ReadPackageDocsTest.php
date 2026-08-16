<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\ReadPackageDocs;

function makeDocsWorkspace(): string
{
    $workspace = sys_get_temp_dir().'/tackle-docs-test-'.uniqid();
    $package = $workspace.'/vendor/acme/widget';

    mkdir($package.'/src', 0755, recursive: true);
    file_put_contents($package.'/CHANGELOG.md', "# 2.0.0\n\n- Renamed Widget::spin() to Widget::rotate()");
    file_put_contents($package.'/UPGRADE-2.0.md', 'Replace spin() with rotate().');
    file_put_contents($package.'/composer.json', '{"name": "acme/widget"}');
    file_put_contents($package.'/src/Widget.php', '<?php class Widget {}');

    config()->set('tackle.workspace', $workspace);

    return $workspace;
}

function makeReadPackageDocsTool(string $workspace): ReadPackageDocs
{
    return new ReadPackageDocs(new PathGuard($workspace));
}

it('lists documentation files when no file is given', function () {
    $workspace = makeDocsWorkspace();

    $result = makeReadPackageDocsTool($workspace)->handle(new Request(['package' => 'acme/widget']));

    expect($result)
        ->toContain('CHANGELOG.md')
        ->toContain('UPGRADE-2.0.md')
        ->toContain('composer.json')
        ->not->toContain('Widget.php');
});

it('reads a documentation file', function () {
    $workspace = makeDocsWorkspace();

    $result = makeReadPackageDocsTool($workspace)->handle(new Request([
        'package' => 'acme/widget',
        'file' => 'UPGRADE-2.0.md',
    ]));

    expect($result)->toBe('Replace spin() with rotate().');
});

it('refuses package code even inside vendor', function () {
    $workspace = makeDocsWorkspace();

    $result = makeReadPackageDocsTool($workspace)->handle(new Request([
        'package' => 'acme/widget',
        'file' => 'src/Widget.php',
    ]));

    expect($result)->toContain('not a readable documentation file');
});

it('refuses traversal disguised as a package name', function () {
    $workspace = makeDocsWorkspace();

    $result = makeReadPackageDocsTool($workspace)->handle(new Request(['package' => '../..']));

    expect($result)->toContain('not a valid Composer package name');
});

it('reports packages that are not installed', function () {
    $workspace = makeDocsWorkspace();

    $result = makeReadPackageDocsTool($workspace)->handle(new Request(['package' => 'acme/missing']));

    expect($result)->toContain('not installed');
});

it('pages long files by offset', function () {
    $workspace = makeDocsWorkspace();
    file_put_contents($workspace.'/vendor/acme/widget/CHANGELOG.md', str_repeat('x', 30500));

    $tool = makeReadPackageDocsTool($workspace);

    $first = $tool->handle(new Request(['package' => 'acme/widget', 'file' => 'CHANGELOG.md']));
    expect($first)->toContain('offset=30000');

    $second = $tool->handle(new Request(['package' => 'acme/widget', 'file' => 'CHANGELOG.md', 'offset' => 30000]));
    expect($second)->toBe(str_repeat('x', 500));
});
