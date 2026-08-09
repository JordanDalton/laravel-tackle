<?php

namespace Tackle\Agents;

use Laravel\Ai\Promptable;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;

/**
 * Tool-less agent that condenses a session transcript into the context that
 * still matters. Used by ConversationCompactor.
 */
class SummarizerAgent
{
    use Promptable;

    public function __construct(
        #[AiProvider] private string $provider = 'anthropic',
        #[AiModel] private string $model = 'claude-sonnet-4-6',
    ) {}

    public function summarize(string $transcript): string
    {
        return trim((string) $this->prompt(
            "Summarize this coding session transcript:\n\n".$transcript
        )->text);
    }

    protected function provider(): string
    {
        return $this->provider;
    }

    protected function model(): string
    {
        return $this->model;
    }

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You summarize an AI coding session transcript so the session can continue with
        less context. Preserve, compactly:

        - What the user asked for, and what has been delivered so far
        - Files created or modified, and the nature of each change
        - Decisions made and constraints discovered (conventions, gotchas, test setup)
        - Anything explicitly unfinished or deferred

        Omit pleasantries, tool-call narration, and dead ends that no longer matter.
        Write plain prose and short lists. Do not invent details.
        INSTRUCTIONS;
    }
}
