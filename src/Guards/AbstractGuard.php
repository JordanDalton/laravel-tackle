<?php

namespace Tackle\Guards;

use Tackle\Contracts\ToolHook;

/**
 * Base for the guard pack — first-party pre_tool hooks that block the concrete
 * exfiltration/circumvention paths described in the README's Safety section.
 *
 * These are DEFENSE-IN-DEPTH, not containment. A guard runs in-process at the
 * same privilege as the agent, so it raises the cost of an attack and catches
 * mistakes and unsophisticated prompt injection — it does not make an agent
 * running as your user safe to distrust. That guarantee comes from OS-level
 * isolation, not from these checks. See "What the guards do and don't stop".
 */
abstract class AbstractGuard implements ToolHook
{
    /**
     * The text a WriteFile/EditFile/RunShell call would introduce — the new
     * file content or the shell command — concatenated for scanning.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function candidateText(array $arguments): string
    {
        return trim(implode("\n", array_filter([
            $arguments['content'] ?? null,   // WriteFile
            $arguments['new_str'] ?? null,    // EditFile
            $arguments['command'] ?? null,    // RunShell
        ], fn ($value) => is_string($value) && $value !== '')));
    }

    /**
     * The target path of a file-writing tool, or '' for shell calls.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function path(array $arguments): string
    {
        return is_string($arguments['path'] ?? null) ? $arguments['path'] : '';
    }

    /**
     * @param  array<int, string>  $patterns  regex bodies (without delimiters)
     */
    protected function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match('/'.$pattern.'/i', $text, $matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    protected function mode(string $key, string $default): string
    {
        $mode = config("tackle.guard.{$key}", $default);

        return is_string($mode) ? $mode : $default;
    }
}
