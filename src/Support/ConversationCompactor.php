<?php

namespace Tackle\Support;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tackle\Agents\SummarizerAgent;
use Tackle\Contracts\CodingAgent;
use Throwable;

/**
 * Replaces the older part of an agent's conversation with an AI-written
 * summary, keeping the most recent exchanges verbatim. Long sessions stay
 * inside the context window and stop paying to re-send their whole history
 * on every turn.
 */
class ConversationCompactor
{
    public function __construct(private readonly SummarizerAgent $summarizer) {}

    /**
     * Whether the agent exposes the conversation-management methods compaction
     * needs (DefaultCodingAgent and subclasses do).
     */
    public function supports(CodingAgent $agent): bool
    {
        return method_exists($agent, 'conversationSize')
            && method_exists($agent, 'replaceConversation');
    }

    public function shouldCompact(CodingAgent $agent): bool
    {
        if (! $this->supports($agent)) {
            return false;
        }

        return $agent->conversationSize() > (int) config('tackle.compaction.threshold_chars', 60000);
    }

    /**
     * @return bool whether a compaction happened
     */
    public function compact(CodingAgent $agent): bool
    {
        if (! $this->supports($agent)) {
            return false;
        }

        $messages = collect($agent->messages())->values();
        $keep = max(2, (int) config('tackle.compaction.keep_recent', 4));

        // Nothing meaningful to fold away.
        if ($messages->count() <= $keep + 2) {
            return false;
        }

        $older = $messages->slice(0, $messages->count() - $keep);
        $recent = $messages->slice(-$keep);

        $transcript = $older->map(function ($message) {
            $role = $message->role instanceof \BackedEnum ? $message->role->value : (string) $message->role;

            return strtoupper($role).":\n".(string) $message->content;
        })->implode("\n\n");

        try {
            $summary = $this->summarizer->summarize(Utf8::clean($transcript));
        } catch (Throwable) {
            return false;
        }

        if ($summary === '') {
            return false;
        }

        $agent->replaceConversation([
            new UserMessage("[Earlier context, compacted]\n\n".$summary),
            new AssistantMessage('Understood — I have that context and will continue from there.'),
            ...$recent->all(),
        ]);

        return true;
    }
}
