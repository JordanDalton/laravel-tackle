<?php

namespace Tackle\Contracts;

/**
 * Decides how a tool asks the user a question.
 *
 * Tools must never call Laravel\Prompts directly — a tool that does will hang
 * forever when it runs somewhere without a terminal (a queue worker, an MCP
 * stdio session, CI). Routing every prompt through this contract lets the
 * caller decide what "asking" means for the context it is running in.
 */
interface InteractionPolicy
{
    /**
     * Ask a yes/no question. Returns the answer.
     */
    public function confirm(string $label, bool $default = true, ?string $hint = null): bool;

    /**
     * Ask the user to pick from a list.
     *
     * Returns the selection, or — when nobody is there to pick — an instruction
     * telling the agent to decide for itself. A selection prompt has no sensible
     * automatic answer, so this never fabricates one.
     */
    public function choose(string $question, array $options, bool $multiple = false): string;

    /**
     * Whether a real user is available to answer.
     */
    public function isInteractive(): bool;

    /**
     * How many confirmations have been auto-denied so far.
     */
    public function deniedCount(): int;
}
