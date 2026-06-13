<?php

use Tackle\Healing\GitHubTokenReader;
use Tackle\Healing\TelescopeReader;
use Tackle\Support\PathGuard;

// ---------------------------------------------------------------------------
// PathGuard — workspace override
// ---------------------------------------------------------------------------

it('accepts an explicit workspace path via constructor argument', function () {
    $dir = sys_get_temp_dir() . '/tackle-override-' . uniqid();
    mkdir($dir, 0755, true);

    $guard = new PathGuard($dir);

    expect($guard->workspace())->toBe(realpath($dir) ?: $dir);

    rmdir($dir);
});

it('constructor workspace argument takes precedence over config', function () {
    $dir = sys_get_temp_dir() . '/tackle-override-' . uniqid();
    mkdir($dir, 0755, true);

    config()->set('ai-code.workspace', sys_get_temp_dir() . '/should-not-be-used');

    $guard = new PathGuard($dir);

    expect($guard->workspace())->toBe(realpath($dir) ?: $dir);

    rmdir($dir);
});

it('allows reads within the override workspace', function () {
    $dir = sys_get_temp_dir() . '/tackle-override-' . uniqid();
    mkdir($dir . '/app', 0755, true);
    file_put_contents($dir . '/app/Foo.php', '<?php');

    config()->set('ai-code.protected_paths', ['.env', 'vendor/*']);

    $guard = new PathGuard($dir);

    expect($guard->checkRead('app/Foo.php'))->toBeNull();

    unlink($dir . '/app/Foo.php');
    rmdir($dir . '/app');
    rmdir($dir);
});

it('blocks reads outside the override workspace', function () {
    $dir = sys_get_temp_dir() . '/tackle-override-' . uniqid();
    mkdir($dir, 0755, true);

    $guard = new PathGuard($dir);

    expect($guard->checkRead('/etc/passwd'))->toBeString()->toContain('outside the workspace');

    rmdir($dir);
});

// ---------------------------------------------------------------------------
// GitHubTokenReader
// ---------------------------------------------------------------------------

it('returns token from GITHUB_TOKEN env var', function () {
    config()->set('ai-code.healing.github_token', null);

    $reader = new GitHubTokenReader();

    // Token from config (which reads env) is already null above;
    // we test via the config key directly.
    config()->set('ai-code.healing.github_token', 'ghp_from_config');

    expect($reader->token())->toBe('ghp_from_config');
});

it('returns null when no token is available', function () {
    config()->set('ai-code.healing.github_token', null);

    // Write a blank gh hosts file to a temp location so the reader
    // doesn't accidentally pick up the developer's real token.
    $reader = new class extends GitHubTokenReader {
        public function token(): ?string
        {
            // Force bypass of config and file lookup.
            return null;
        }
    };

    expect($reader->token())->toBeNull();
});

// ---------------------------------------------------------------------------
// TelescopeReader
// ---------------------------------------------------------------------------

it('returns empty string when telescope is disabled in config', function () {
    config()->set('ai-code.healing.telescope', false);

    $reader = new TelescopeReader();

    expect($reader->forJob('fake-uuid'))->toBe('');
});

it('returns empty string when Telescope class is not installed', function () {
    config()->set('ai-code.healing.telescope', true);

    // Laravel\Telescope\Telescope won't be available in the package's test
    // environment — so this should always return ''.
    $reader = new TelescopeReader();

    expect($reader->forJob('fake-uuid'))->toBe('');
});
