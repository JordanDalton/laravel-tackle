<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;

use function Laravel\Prompts\confirm;

class RunShell extends AbstractTool
{
    public function __construct(
        private PathGuard $pathGuard,
        private CommandGuard $commandGuard,
    ) {}

    public function description(): string
    {
        return 'Run an arbitrary shell command. Behaviour is controlled by the shell config: off (refused), allowlist (only approved commands), approve (requires user confirmation each time), or yolo (unrestricted). Prefer RunArtisan, RunTests, or RunPint for Laravel-specific operations.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The shell command to execute.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $command = trim($request->string('command', ''));

        if ($command === '') {
            return 'A non-empty command is required.';
        }

        $mode = $this->resolveShellMode();

        return match ($mode) {
            'off'       => $this->refuseAll($command),
            'allowlist' => $this->runIfAllowed($command),
            'approve'   => $this->runWithApproval($command),
            'yolo'      => $this->runUnrestricted($command),
            default     => "Unknown shell mode '{$mode}'. Check your tackle config.",
        };
    }

    private function resolveShellMode(): string
    {
        $config = config('tackle.shell', 'approve');

        if (is_array($config)) {
            $env = app()->environment();
            return $config[$env] ?? $config['*'] ?? 'approve';
        }

        return $config;
    }

    private function refuseAll(string $command): string
    {
        return "Shell execution is disabled (shell=off). Command refused: '{$command}'. Use RunArtisan, RunTests, or RunPint instead.";
    }

    private function runIfAllowed(string $command): string
    {
        $allowlist = config('tackle.shell_allowlist', []);

        if ($refusal = $this->commandGuard->check($command, $allowlist)) {
            return $refusal;
        }

        return $this->execute($command);
    }

    private function runWithApproval(string $command): string
    {
        $approved = confirm(
            label: 'The agent wants to run a shell command. Allow it?',
            hint: $command,
            default: false,
        );

        if (! $approved) {
            return "User denied execution of: '{$command}'";
        }

        return $this->execute($command);
    }

    private function runUnrestricted(string $command): string
    {
        return $this->execute($command);
    }

    private function execute(string $command): string
    {
        $result = Process::path($this->pathGuard->workspace())
            ->timeout(60)
            ->run($command);

        if ($result->failed()) {
            return "Command failed (exit {$result->exitCode()}):\n" . $result->errorOutput();
        }

        return $result->output() ?: "(Command ran successfully with no output.)";
    }
}
