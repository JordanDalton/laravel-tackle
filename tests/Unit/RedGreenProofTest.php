<?php

use Tackle\Support\PathGuard;
use Tackle\Support\RedGreenProof;

/**
 * A real (tiny) git repo; only the test-runner processes are faked. The
 * revert/restore mechanics are the part that must never lie, so git runs
 * for real.
 */
function proofRepo(): string
{
    $dir = sys_get_temp_dir().'/tackle-proof-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app', 0755, true);
    mkdir($dir.'/tests', 0755, true);
    file_put_contents($dir.'/app/Money.php', "<?php // original\n");
    shell_exec('git -C '.escapeshellarg($dir).' init -q . && git -C '.escapeshellarg($dir)
        .' add -A && git -C '.escapeshellarg($dir).' -c user.email=t@t -c user.name=t commit -qm init');

    // The uncommitted change: a fix plus a new test.
    file_put_contents($dir.'/app/Money.php', "<?php // fixed\n");
    file_put_contents($dir.'/tests/MoneyTest.php', "<?php // new test\n");

    return $dir;
}

/**
 * A scripted test runner: returns the next result each time it is called,
 * recording what the workspace looked like at that moment. Process::fake is
 * unusable here — with a pattern array it stubs UNMATCHED processes too, so
 * the real git this test depends on would silently return empty results.
 *
 * @param  list<bool>  $results
 */
function scriptedRunner(array $results, array &$seen): Closure
{
    return function (string $workspace, array $paths) use (&$seen, &$results): bool {
        $seen[] = file_get_contents($workspace.'/app/Money.php');

        return array_shift($results) ?? false;
    };
}

it('proves a real test: green with the fix, red without it', function () {
    $dir = proofRepo();
    $seen = [];

    $proof = (new RedGreenProof(new PathGuard($dir), scriptedRunner([true, false], $seen)))->run();

    expect($proof)->toMatchArray(['ran' => true, 'green' => true, 'red' => true])
        ->and($proof['tests'])->toBe(['tests/MoneyTest.php'])
        // The green run saw the fix; the red run saw the ORIGINAL — the
        // revert really happened, this is not two runs of the same code.
        ->and($seen)->toBe(["<?php // fixed\n", "<?php // original\n"])
        // And the fix was restored afterwards.
        ->and(file_get_contents($dir.'/app/Money.php'))->toBe("<?php // fixed\n");

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('flags a test that passes even without the change', function () {
    // The vacuous test — looks like verification, verifies nothing. This is
    // the case the whole feature exists to expose.
    $dir = proofRepo();
    $seen = [];

    $proof = (new RedGreenProof(new PathGuard($dir), scriptedRunner([true, true], $seen)))->run();

    expect($proof)->toMatchArray(['ran' => true, 'green' => true, 'red' => false]);
    expect(RedGreenProof::markdown($proof))->toContain('passes even without the change');

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('removes then restores a brand-new file that git cannot check out', function () {
    $dir = proofRepo();
    file_put_contents($dir.'/app/Brand.php', "<?php // new class\n");
    $gone = null;

    (new RedGreenProof(new PathGuard($dir), function (string $w, array $p) use (&$gone): bool {
        // Second call is the reverted phase: the brand-new file must be gone.
        $gone = $gone === null ? false : ! file_exists($w.'/app/Brand.php');

        return $gone === false; // green pass, red fail
    }))->run();

    expect($gone)->toBeTrue()
        ->and(file_get_contents($dir.'/app/Brand.php'))->toBe("<?php // new class\n");

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('restores the fix even when the reverted test run throws', function () {
    $dir = proofRepo();
    $calls = 0;

    $proof = (new RedGreenProof(new PathGuard($dir), function () use (&$calls): bool {
        if (++$calls === 2) {
            throw new RuntimeException('runner exploded mid-revert');
        }

        return true;
    }))->run();

    // The throw is swallowed into "not run" — but the fix MUST be back.
    expect($proof['ran'])->toBeFalse()
        ->and(file_get_contents($dir.'/app/Money.php'))->toBe("<?php // fixed\n");

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('does not run without a new test, or without a fix, or when disabled', function () {
    // No test file changed.
    $dir = proofRepo();
    unlink($dir.'/tests/MoneyTest.php');
    expect((new RedGreenProof(new PathGuard($dir)))->run()['ran'])->toBeFalse();
    shell_exec('rm -rf '.escapeshellarg($dir));

    // Config off.
    config()->set('tackle.verify.red_green', false);
    $dir = proofRepo();
    expect((new RedGreenProof(new PathGuard($dir)))->run()['ran'])->toBeFalse();
    config()->set('tackle.verify.red_green', true);
    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('reports a failing new test as green:false and says so in the markdown', function () {
    $dir = proofRepo();
    $seen = [];

    $proof = (new RedGreenProof(new PathGuard($dir), scriptedRunner([false], $seen)))->run();

    expect($proof)->toMatchArray(['ran' => true, 'green' => false, 'red' => false]);
    expect(RedGreenProof::markdown($proof))->toContain('❌ failing');

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('says nothing when the proof did not run', function () {
    expect(RedGreenProof::markdown(['ran' => false, 'red' => false, 'green' => false, 'tests' => []]))->toBe('');
});
