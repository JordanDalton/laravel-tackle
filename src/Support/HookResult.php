<?php

namespace Tackle\Support;

/**
 * The outcome of running one or more hooks: allow (optionally with rewritten
 * tool arguments) or block (with the refusal message shown to the agent).
 */
final class HookResult
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    private function __construct(
        public readonly bool $blocked,
        public readonly ?string $message = null,
        public readonly ?array $arguments = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public static function allow(?array $arguments = null): self
    {
        return new self(false, null, $arguments);
    }

    public static function block(string $message): self
    {
        return new self(true, $message);
    }
}
