<?php

namespace Tackle\Support;

use Tackle\Contracts\InteractionPolicy;

/**
 * Shared behaviour for policies with no user attached. Subclasses decide only
 * what a yes/no question resolves to.
 */
abstract class NonInteractiveInteraction implements InteractionPolicy
{
    protected int $denied = 0;

    /**
     * Returned in place of a selection. A multi-way choice has no automatic
     * answer, so the agent is told to make the call itself rather than being
     * handed a refusal it would treat as a dead end — the system prompt directs
     * it to AskUser at every branch point, and a refusal there stalls the run.
     */
    final public function choose(string $question, array $options, bool $multiple = false): string
    {
        $list = implode(', ', array_map(
            fn ($option) => is_scalar($option) ? (string) $option : json_encode($option),
            $options,
        ));

        return 'No interactive user is available in this session, so this question cannot be answered: '
            ."\"{$question}\" (options: {$list}). Select the option you judge best, state which you chose "
            .'and why, then continue without asking again.';
    }

    final public function isInteractive(): bool
    {
        return false;
    }

    final public function deniedCount(): int
    {
        return $this->denied;
    }
}
