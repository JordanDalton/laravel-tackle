<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Support\PermissionStore;
use Tackle\Support\Utf8;

class RunShell extends AbstractTool
{
    public function __construct(
        private PathGuard $pathGuard,
        private CommandGuard $commandGuard,
        private ?InteractionPolicy $interaction = null,
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
        $command = trim($this->arg($request, 'command', ''));

        if ($command === '') {
            return 'A non-empty command is required.';
        }

        $mode = $this->resolveShellMode();

        return match ($mode) {
            'off' => $this->refuseAll($command),
            'allowlist' => $this->runIfAllowed($command),
            'approve' => $this->runWithApproval($command),
            'yolo' => $this->runUnrestricted($command),
            default => "Unknown shell mode '{$mode}'. Check your tackle config.",
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

        if ($refusal = $this->checkArtisan($command)) {
            return $refusal;
        }

        return $this->execute($command);
    }

    /**
     * Hold an artisan invocation to the artisan allowlist, not just the shell
     * one.
     *
     * `php artisan` sits in the default shell_allowlist and is matched by
     * prefix, so `shell=allowlist` would otherwise run any artisan command
     * unattended — including the `migrate:fresh` and `db:wipe` that
     * artisan_destructive exists to gate. RunArtisan refuses those; running
     * the same thing through a shell should not be the way around it. The
     * narrower guard wins.
     *
     * Only `allowlist` mode is affected. `approve` already puts a human in
     * front of every command, and `yolo` is unrestricted by definition and
     * documented as such.
     */
    private function checkArtisan(string $command): ?string
    {
        if (! preg_match('#^(?:php\s+)?(?:\./)?artisan\s+(.+)$#i', trim($command), $match)) {
            return null;
        }

        $artisan = trim($match[1]);

        $destructive = $this->commandGuard->resolveList(config('tackle.artisan_destructive', []));

        if ($this->commandGuard->matches($artisan, $destructive)) {
            return "Refused: 'php artisan {$artisan}' is in artisan_destructive, which requires terminal "
                .'confirmation. Shell mode is allowlist, so there is nobody to confirm. Use RunArtisan in an '
                .'interactive session, or run it yourself.';
        }

        $allowlist = $this->commandGuard->resolveList(config('tackle.artisan_allowlist', []));

        if ($this->commandGuard->check($artisan, $allowlist) !== null) {
            return "Refused: 'php artisan {$artisan}' is not in the artisan allowlist. Being able to run a shell "
                .'does not widen what artisan may do. Allowed: '.(implode(', ', $allowlist) ?: '(none)');
        }

        return null;
    }

    private function runWithApproval(string $command): string
    {
        $permissions = app(PermissionStore::class);

        // The user has previously said "always allow" to this exact command.
        if ($permissions->allows($command)) {
            return $this->execute($command);
        }

        $interaction = $this->interaction();

        if (method_exists($interaction, 'confirmWithAlways')) {
            $choice = $interaction->confirmWithAlways(
                label: 'The agent wants to run a shell command. Allow it?',
                hint: $command,
            );

            if ($choice === 'always') {
                $permissions->allow($command);
            }

            if ($choice === 'always' || $choice === 'yes') {
                return $this->execute($command);
            }

            return "User denied execution of: '{$command}'";
        }

        $approved = $interaction->confirm(
            label: 'The agent wants to run a shell command. Allow it?',
            default: false,
            hint: $command,
        );

        if ($approved) {
            return $this->execute($command);
        }

        // With no terminal, shell=approve has no one to approve it. Refusing is
        // the only safe reading — say why, rather than blaming a user who is not there.
        return $interaction->isInteractive()
            ? "User denied execution of: '{$command}'"
            : 'Shell execution requires per-command approval (shell=approve) but no interactive user is '
              ."available, so it was refused: '{$command}'. For unattended runs use shell=allowlist, or pass "
              .'--yes to approve automatically.';
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
            return "Command failed (exit {$result->exitCode()}):\n".$result->errorOutput();
        }

        return Utf8::clean($result->output()) ?: '(Command ran successfully with no output.)';
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
