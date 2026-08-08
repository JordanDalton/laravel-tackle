<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

class RunTests extends AbstractTool
{
    public function __construct(private PathGuard $guard, private CommandGuard $commandGuard) {}

    public function description(): string
    {
        return 'Run the test suite (Pest or PHPUnit via php artisan test) as a subprocess and return the full output. Use after modifying code to verify correctness. Requires "test" to be in the artisan allowlist for this environment.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Optional test name filter to run only a subset of tests.'),
        ];
    }

    public function handle(Request $request): string
    {
        $allowlist = $this->commandGuard->resolveList(config('tackle.artisan_allowlist', []));
        if ($refusal = $this->commandGuard->check('test', $allowlist)) {
            return $refusal;
        }

        $workspace = $this->guard->workspace();

        if (app()->environment('production') && ! file_exists($workspace.'/.env.testing')) {
            return 'RunTests is disabled: the application is running in the production environment '
                .'and no .env.testing file was found. Running tests without an isolated test '
                .'database could modify or destroy production data. Create a .env.testing file '
                .'that points to a separate test database before running tests here.';
        }

        $filter = $request->string('filter', '');
        $filterArg = $filter !== '' ? ' --filter='.escapeshellarg($filter) : '';

        $binary = file_exists($workspace.'/vendor/bin/pest')
            ? "./vendor/bin/pest{$filterArg}"
            : "php artisan test{$filterArg}";

        $result = Process::path($workspace)
            ->env(['APP_ENV' => 'testing'])
            ->timeout(120)
            ->run($binary);

        return Utf8::clean($result->output().$result->errorOutput()) ?: '(Tests ran with no output.)';
    }
}
