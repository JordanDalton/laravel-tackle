<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class RunLarastan extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Run PHPStan / Larastan static analysis and return the findings. Use after modifying code to catch type errors, undefined variables, and other issues before finishing a task. Requires phpstan/phpstan or nunomaduro/larastan to be installed.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Optional file or directory to analyse. Defaults to the paths defined in phpstan.neon / phpstan.neon.dist.'),
            'level' => $schema->integer()
                ->description('Optional analysis level (0–9). Overrides the level in the config file. Omit to use the project default.'),
            'memory_limit' => $schema->string()
                ->description('Optional PHP memory limit (e.g. "1G", "512M"). Use when analysis fails with a memory exhaustion error.'),
        ];
    }

    public function handle(Request $request): string
    {
        $workspace = $this->guard->workspace();
        $binary    = $workspace . '/vendor/bin/phpstan';

        if (! file_exists($binary)) {
            return "PHPStan is not installed. Run 'composer require --dev phpstan/phpstan' or "
                . "'composer require --dev nunomaduro/larastan' to enable static analysis.";
        }

        $memoryLimit = $request->string('memory_limit', '');
        $phpBin      = $memoryLimit !== ''
            ? 'php -d memory_limit=' . escapeshellarg($memoryLimit)
            : 'php';

        $args = [$phpBin, './vendor/bin/phpstan', 'analyse', '--no-progress', '--no-interaction', '--no-ansi'];

        $level = $request->integer('level', -1);
        if ($level >= 0) {
            $args[] = '--level=' . $level;
        }

        $path = $request->string('path', '');
        if ($path !== '') {
            $args[] = escapeshellarg($path);
        }

        $result = Process::path($workspace)
            ->timeout(120)
            ->run(implode(' ', $args));

        $output = trim($result->output() . $result->errorOutput());

        if ($output === '') {
            return '(PHPStan ran with no output.)';
        }

        // Auto-retry with a higher memory limit when the process runs out of memory.
        if ($memoryLimit === '' && str_contains($output, 'memory_limit')) {
            $args[0] = 'php -d memory_limit=1G';
            $result  = Process::path($workspace)
                ->timeout(120)
                ->run(implode(' ', $args));
            $output = trim($result->output() . $result->errorOutput());
        }

        return $output !== '' ? $output : '(PHPStan ran with no output.)';
    }
}
