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
            $dir = dirname($this->path($name));
            @mkdir($dir, 0755, true);

            // Keep transcripts out of git AND out of build-tool file watchers:
            // Tailwind 4's Vite plugin treats every non-gitignored file as a
            // content source and full-reloads the app's pages when one changes.
            if (! is_file($dir.'/.gitignore')) {
                file_put_contents($dir.'/.gitignore', "*\n");
            }

            file_put_contents($this->path($name), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // Persistence is a convenience — never let it break the session.
        }
    }

    public function forget(string $name): void
    {
        @unlink($this->path($name));
    }

    /**
     * Every saved session and how many messages it holds.
     *
     * @return array<string, int> name => message count
     */
    public function all(): array
    {
        $sessions = [];

        foreach (glob(storage_path('ai-code/*.json')) ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);

            $sessions[basename($file, '.json')] = is_array($data) ? count($data) : 0;
        }

        ksort($sessions);

        return $sessions;
    }

    public function path(string $name): string
    {
        $safe = (string) preg_replace('/[^\w-]+/', '-', $name);
        $safe = trim($safe, '-') ?: 'default';

        return storage_path('ai-code/'.$safe.'.json');
    }
}
