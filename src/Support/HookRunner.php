<?php

namespace Tackle\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tackle\Contracts\ToolHook;
use Throwable;

/**
 * Runs user-defined hooks from config('tackle.hooks') — deterministic shell
 * commands or PHP classes that observe, block, or rewrite agent tool calls.
 *
 * Unlike ToolCalling event listeners (which can also veto), hooks are declared
 * in config, run in declaration order, and speak a stable JSON protocol that
 * non-PHP tooling can implement: the payload arrives on stdin, exit 0 allows
 * (pre_tool stdout may rewrite arguments), exit 2 blocks with stderr as the
 * refusal message. Any other failure — including a timeout or a crashed hook —
 * is logged and ignored so a broken hook never bricks a session.
 */
class HookRunner
{
    public const BLOCK_EXIT_CODE = 2;

    private const DEFAULT_TIMEOUT_SECONDS = 10;

    /**
     * Run pre_tool hooks. The first block wins; argument rewrites chain, so a
     * later hook sees (and may further rewrite) an earlier hook's arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function preTool(string $tool, array $arguments): HookResult
    {
        foreach ($this->hooksFor('pre_tool', $tool) as $hook) {
            $result = $this->run($hook, 'pre_tool', [
                'event' => 'pre_tool',
                'tool' => $tool,
                'arguments' => $arguments,
            ]);

            if ($result->blocked) {
                return $result;
            }

            if ($result->arguments !== null) {
                $arguments = $result->arguments;
            }
        }

        return HookResult::allow($arguments);
    }

    /**
     * Run post_tool hooks. Observers only — the call already happened, so
     * block and rewrite results are ignored.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function postTool(string $tool, array $arguments, string $result, float $durationMs): void
    {
        foreach ($this->hooksFor('post_tool', $tool) as $hook) {
            $this->run($hook, 'post_tool', [
                'event' => 'post_tool',
                'tool' => $tool,
                'arguments' => $arguments,
                'result' => $result,
                'duration_ms' => $durationMs,
            ]);
        }
    }

    /**
     * Run session_start / session_end hooks. Observers only.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sessionEvent(string $event, array $payload): void
    {
        foreach ($this->hooksFor($event, null) as $hook) {
            $this->run($hook, $event, ['event' => $event, ...$payload]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hooksFor(string $event, ?string $tool): array
    {
        $hooks = config("tackle.hooks.{$event}", []);

        if (! is_array($hooks)) {
            return [];
        }

        return array_values(array_filter(
            $hooks,
            fn ($hook) => is_array($hook)
                && ($tool === null || Str::is($hook['match'] ?? '*', $tool)),
        ));
    }

    /**
     * @param  array<string, mixed>  $hook
     * @param  array<string, mixed>  $payload
     */
    private function run(array $hook, string $event, array $payload): HookResult
    {
        try {
            if (isset($hook['using'])) {
                return $this->runClass($hook['using'], $event, $payload);
            }

            if (isset($hook['run'])) {
                return $this->runCommand($hook, $event, $payload);
            }
        } catch (Throwable $e) {
            logger()->warning("Tackle {$event} hook failed and was skipped: {$e->getMessage()}", [
                'hook' => $hook['run'] ?? $hook['using'] ?? $hook,
            ]);
        }

        return HookResult::allow();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runClass(string $class, string $event, array $payload): HookResult
    {
        $instance = app($class);

        $decision = $instance instanceof ToolHook
            ? $instance->handle($payload)
            : $instance($payload);

        return match (true) {
            $decision === false => HookResult::block(
                sprintf('Refused: a %s hook (%s) blocked this call.', $event, class_basename($class)),
            ),
            is_string($decision) => HookResult::block($decision),
            is_array($decision) && $event === 'pre_tool' => HookResult::allow($decision),
            default => HookResult::allow(),
        };
    }

    /**
     * @param  array<string, mixed>  $hook
     * @param  array<string, mixed>  $payload
     */
    private function runCommand(array $hook, string $event, array $payload): HookResult
    {
        $command = (string) $hook['run'];

        $process = Process::path($this->workspace())
            ->timeout((int) ($hook['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS))
            ->input(json_encode($payload, JSON_UNESCAPED_SLASHES)."\n")
            ->run($command);

        if ($process->exitCode() === self::BLOCK_EXIT_CODE) {
            $message = trim($process->errorOutput()) ?: trim($process->output());

            return HookResult::block(
                $message !== ''
                    ? $message
                    : sprintf('Refused: a %s hook (%s) blocked this call.', $event, $command),
            );
        }

        if (! $process->successful()) {
            logger()->warning(
                sprintf('Tackle %s hook `%s` exited with code %d and was ignored.', $event, $command, (int) $process->exitCode()),
                ['stderr' => $process->errorOutput()],
            );

            return HookResult::allow();
        }

        if ($event === 'pre_tool') {
            $output = json_decode(trim($process->output()), true);

            if (is_array($output) && isset($output['arguments']) && is_array($output['arguments'])) {
                return HookResult::allow($output['arguments']);
            }
        }

        return HookResult::allow();
    }

    private function workspace(): string
    {
        return (string) (config('tackle.workspace') ?: base_path());
    }
}
