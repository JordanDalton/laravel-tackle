<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Guards\SecretExfiltrationGuard;
use Tackle\Support\EventedTool;
use Tackle\Tools\AbstractTool;

/**
 * A stand-in WriteFile that records whether it ever executed, so the test can
 * prove the guard blocks *before* the write happens — not after.
 */
class GuardSpyWriteFile extends AbstractTool
{
    public bool $wrote = false;

    public function name(): string
    {
        return 'WriteFile';
    }

    public function description(): string
    {
        return 'Spy write file.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $this->wrote = true;

        return 'written';
    }
}

it('blocks the WriteFile->RunTests exfil path before the file is written', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['match' => ['WriteFile', 'EditFile'], 'using' => SecretExfiltrationGuard::class],
    ]);

    $spy = new GuardSpyWriteFile;
    $wrapped = new EventedTool($spy);

    $result = (string) $wrapped->handle(new Request([
        'path' => 'tests/Feature/LeakTest.php',
        'content' => "<?php it('x', fn () => dump(env('APP_KEY')));",
    ]));

    expect($spy->wrote)->toBeFalse()
        ->and($result)->toContain('SecretExfiltrationGuard');
});

it('lets an innocent write through the same pipeline', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['match' => ['WriteFile', 'EditFile'], 'using' => SecretExfiltrationGuard::class],
    ]);

    $spy = new GuardSpyWriteFile;
    $wrapped = new EventedTool($spy);

    $result = (string) $wrapped->handle(new Request([
        'path' => 'app/Models/Post.php',
        'content' => "<?php\nclass Post extends Model {}",
    ]));

    expect($spy->wrote)->toBeTrue()
        ->and($result)->toBe('written');
});
