<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Tools\EditFile;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\RunShell;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

// ──────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────

function workspace(): string
{
    return sys_get_temp_dir() . '/tackle-tests';
}

function makeGuard(): PathGuard
{
    config()->set('tackle.workspace', workspace());
    config()->set('tackle.protected_paths', ['.env', '.env.*', 'storage/*', 'vendor/*', '.git/*']);
    return new PathGuard();
}

function req(array $args): Request
{
    return new Request($args);
}

function ensureFile(string $relative, string $content = '<?php // test'): string
{
    $path = workspace() . '/' . $relative;
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
    return $path;
}

beforeEach(function () {
    @mkdir(workspace(), 0755, true);
});

afterEach(function () {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(workspace(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
});

// ──────────────────────────────────────────────────────────────────────
// ReadFile
// ──────────────────────────────────────────────────────────────────────

it('ReadFile returns file contents', function () {
    ensureFile('app/Foo.php', '<?php echo "hello";');
    $result = (new ReadFile(makeGuard()))->handle(req(['path' => 'app/Foo.php']));
    expect($result)->toContain('hello');
});

it('ReadFile refuses .env', function () {
    ensureFile('.env', 'APP_KEY=abc');
    $result = (new ReadFile(makeGuard()))->handle(req(['path' => '.env']));
    expect($result)->toContain('protected');
});

it('ReadFile refuses paths outside workspace', function () {
    $result = (new ReadFile(makeGuard()))->handle(req(['path' => '/etc/passwd']));
    expect($result)->toContain('outside the workspace');
});

it('ReadFile returns error for non-existent file', function () {
    $result = (new ReadFile(makeGuard()))->handle(req(['path' => 'does-not-exist.php']));
    expect($result)->toContain('does not exist');
});

// ──────────────────────────────────────────────────────────────────────
// Glob
// ──────────────────────────────────────────────────────────────────────

it('Glob lists matching files', function () {
    ensureFile('app/Foo.php');
    ensureFile('app/Bar.php');

    $result = (new Glob(makeGuard()))->handle(req(['pattern' => 'app/*.php']));
    expect($result)->toContain('app/Foo.php')->toContain('app/Bar.php');
});

it('Glob excludes protected paths from results', function () {
    ensureFile('.env', 'secret');
    ensureFile('app/Foo.php');

    $result = (new Glob(makeGuard()))->handle(req(['pattern' => 'app/*.php']));
    expect($result)->not->toContain('.env');
});

// ──────────────────────────────────────────────────────────────────────
// SearchCode
// ──────────────────────────────────────────────────────────────────────

it('SearchCode finds a string in a file', function () {
    ensureFile('app/Foo.php', '<?php class Foo { public function bar() {} }');

    $result = (new SearchCode(makeGuard()))->handle(req(['query' => 'function bar']));
    expect($result)->toContain('app/Foo.php')->toContain('function bar');
});

it('SearchCode excludes protected paths', function () {
    ensureFile('vendor/foo.php', 'secret-string');

    $result = (new SearchCode(makeGuard()))->handle(req(['query' => 'secret-string']));
    expect($result)->not->toContain('vendor/');
});

// ──────────────────────────────────────────────────────────────────────
// EditFile
// ──────────────────────────────────────────────────────────────────────

it('EditFile replaces a unique string', function () {
    ensureFile('app/Foo.php', '<?php function hello() { return "world"; }');

    $result = (new EditFile(makeGuard()))->handle(req([
        'path'    => 'app/Foo.php',
        'old_str' => '"world"',
        'new_str' => '"universe"',
    ]));

    expect($result)->toContain('Successfully edited');
    expect(file_get_contents(workspace() . '/app/Foo.php'))->toContain('"universe"');
});

it('EditFile refuses when old_str not found', function () {
    ensureFile('app/Foo.php', '<?php echo "hello";');

    $result = (new EditFile(makeGuard()))->handle(req([
        'path'    => 'app/Foo.php',
        'old_str' => 'this string does not exist',
        'new_str' => 'replacement',
    ]));

    expect($result)->toContain('not found');
});

it('EditFile refuses when old_str is not unique', function () {
    ensureFile('app/Foo.php', '<?php $a = "foo"; $b = "foo";');

    $result = (new EditFile(makeGuard()))->handle(req([
        'path'    => 'app/Foo.php',
        'old_str' => '"foo"',
        'new_str' => '"bar"',
    ]));

    expect($result)->toContain('appears')->toContain('unique');
});

it('EditFile refuses writes to .env', function () {
    ensureFile('.env', 'APP_KEY=abc');

    $result = (new EditFile(makeGuard()))->handle(req([
        'path'    => '.env',
        'old_str' => 'ABC',
        'new_str' => 'XYZ',
    ]));

    expect($result)->toContain('protected');
});

// ──────────────────────────────────────────────────────────────────────
// WriteFile
// ──────────────────────────────────────────────────────────────────────

it('WriteFile creates a new file', function () {
    $result = (new WriteFile(makeGuard()))->handle(req([
        'path'    => 'app/NewFile.php',
        'content' => '<?php // new file',
    ]));

    expect($result)->toContain('Created');
    expect(file_exists(workspace() . '/app/NewFile.php'))->toBeTrue();
});

it('WriteFile refuses to overwrite an existing file', function () {
    ensureFile('app/Existing.php', '<?php // existing');

    $result = (new WriteFile(makeGuard()))->handle(req([
        'path'    => 'app/Existing.php',
        'content' => '<?php // overwrite attempt',
    ]));

    expect($result)->toContain('already exists');
});

it('WriteFile refuses writes outside workspace', function () {
    $result = (new WriteFile(makeGuard()))->handle(req([
        'path'    => '/tmp/evil.php',
        'content' => '<?php // evil',
    ]));

    expect($result)->toContain('outside the workspace');
});

// ──────────────────────────────────────────────────────────────────────
// RunShell — shell mode behaviour
// ──────────────────────────────────────────────────────────────────────

it('RunShell refuses everything in off mode', function () {
    config()->set('tackle.shell', 'off');

    $result = (new RunShell(makeGuard(), new CommandGuard()))->handle(req(['command' => 'echo hello']));
    expect($result)->toContain('disabled');
});

it('RunShell allows allowlisted commands in allowlist mode', function () {
    config()->set('tackle.shell', 'allowlist');
    config()->set('tackle.shell_allowlist', ['echo']);

    $result = (new RunShell(makeGuard(), new CommandGuard()))->handle(req(['command' => 'echo hello']));
    expect($result)->toContain('hello');
});

it('RunShell refuses non-allowlisted commands in allowlist mode', function () {
    config()->set('tackle.shell', 'allowlist');
    config()->set('tackle.shell_allowlist', ['composer', 'npm']);

    $result = (new RunShell(makeGuard(), new CommandGuard()))->handle(req(['command' => 'rm -rf /']));
    expect($result)->toContain('not in the allowlist');
});

it('RunShell runs commands in yolo mode', function () {
    config()->set('tackle.shell', 'yolo');

    $result = (new RunShell(makeGuard(), new CommandGuard()))->handle(req(['command' => 'echo yolo-works']));
    expect($result)->toContain('yolo-works');
});

// ──────────────────────────────────────────────────────────────────────
// Directory / empty paths must refuse, never throw
//
// A model that reaches for EditFile with a directory path (or an empty
// one, which resolves to the workspace root) used to hit File::get() on
// a directory. That throws FileNotFoundException — an uncaught exception
// inside a tool aborts the whole agent run instead of handing the model
// a message it can recover from.
// ──────────────────────────────────────────────────────────────────────

it('refuses to edit a directory instead of throwing', function () {
    @mkdir(workspace() . '/app', 0755, true);

    $result = (new EditFile(makeGuard()))->handle(req([
        'path'    => 'app',
        'old_str' => 'foo',
        'new_str' => 'bar',
    ]));

    expect($result)->toContain('is a directory, not a file');
});

it('refuses an empty path on edit', function () {
    $result = (new EditFile(makeGuard()))->handle(req([
        'path'    => '',
        'old_str' => 'foo',
        'new_str' => 'bar',
    ]));

    expect($result)->toContain('No path was provided');
});

it('refuses an empty path on read', function () {
    $result = (new ReadFile(makeGuard()))->handle(req(['path' => '']));

    expect($result)->toContain('No path was provided');
});

it('refuses to write over a directory', function () {
    @mkdir(workspace() . '/app', 0755, true);

    $result = (new WriteFile(makeGuard()))->handle(req([
        'path'    => 'app',
        'content' => 'nope',
    ]));

    expect($result)->toContain('is a directory, not a file');
});
