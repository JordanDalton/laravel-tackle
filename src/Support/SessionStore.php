<?php

namespace Tackle\Support;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Throwable;

/**
 * Persists conversation transcripts to storage/ai-code/{session}.json so a
 * session survives exiting the REPL. Text only — image attachments are not
 * persisted and re-attach on demand.
 */
class SessionStore
{
    public function enabled(): bool
    {
        return config('tackle.memory', 'file') === 'file';
    }

    /** @return array<int, UserMessage|AssistantMessage> */
    public function load(string $name): array
    {
        $raw = @file_get_contents($this->path($name));

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return [];
        }

        $messages = [];

        foreach ($data as $entry) {
            $role = (string) ($entry['role'] ?? '');
            $content = (string) ($entry['content'] ?? '');

            if ($content === '') {
                continue;
            }

            $messages[] = $role === 'user'
                ? new UserMessage($content)
                : new AssistantMessage($content);
        }

        return $messages;
    }

    /** @param  iterable<mixed>  $messages */
    public function save(string $name, iterable $messages): void
    {
        $data = [];

        foreach ($messages as $message) {
            $role = $message->role instanceof \BackedEnum ? $message->role->value : (string) $message->role;

            $data[] = [
                'role' => $role,
                'content' => (string) ($message->content ?? ''),
            ];
        }

        try {
            @mkdir(dirname($this->path($name)), 0755, true);
            file_put_contents($this->path($name), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // Persistence is a convenience — never let it break the session.
        }
    }

    public function forget(string $name): void
    {
        @unlink($this->path($name));
    }

    public function path(string $name): string
    {
        $safe = (string) preg_replace('/[^\w-]+/', '-', $name);
        $safe = trim($safe, '-') ?: 'default';

        return storage_path('ai-code/'.$safe.'.json');
    }
}
