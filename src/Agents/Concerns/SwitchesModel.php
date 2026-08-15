<?php

namespace Tackle\Agents\Concerns;

/**
 * Lets an already-constructed agent be pointed at a different provider and
 * model mid-session. Conversation history is untouched — only subsequent
 * requests use the new values. Agents resolved fresh from the container
 * pick up config changes through the AiProvider/AiModel attributes instead,
 * so only long-lived instances (the ai:code session agent and planner) need
 * this.
 */
trait SwitchesModel
{
    public function useModel(string $provider, string $model): void
    {
        $this->provider = $provider;
        $this->model = $model;
    }
}
