<?php

namespace Tackle\Support;

use Tackle\Attributes\Workspace;

/**
 * Project-defined slash commands: markdown prompt templates in
 * .tackle/commands/*.md, invoked as /name in ai:code or as the prompt of
 * ai:run. `$ARGUMENTS` in the template is replaced with whatever follows the
 * command name.
 */
class CustomCommands
{
    public function __construct(
        #[Workspace] private readonly PathGuard $guard,
    ) {}

    /** @return array<string, string> name => absolute path */
    public function all(): array
    {
        $files = glob($this->directory().'/*.md') ?: [];
        $commands = [];

        foreach ($files as $file) {
            $commands[basename($file, '.md')] = $file;
        }

        ksort($commands);

        return $commands;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    /** Where a command lives, whether or not it exists yet. */
    public function path(string $name): string
    {
        return $this->directory().'/'.$name.'.md';
    }

    /**
     * Write a command and return its path.
     *
     * Deliberately no .gitignore here, unlike Tackle's state directories:
     * these are prompts a team shares and reviews, so they belong in the repo.
     * The file is left unstaged — creating one is a change to be looked at,
     * not a side effect to be hidden.
     */
    public function save(string $name, string $content): string
    {
        @mkdir($this->directory(), 0755, true);

        file_put_contents($this->path($name), rtrim($content)."\n");

        return $this->path($name);
    }

    public function delete(string $name): bool
    {
        return $this->has($name) && @unlink($this->path($name));
    }

    /**
     * Whether a name can be a command. It becomes a filename and a slash
     * command, so it has to survive both — parse() only recognises word
     * characters, colons, and hyphens.
     */
    public static function validName(string $name): bool
    {
        return preg_match('/^[\w-]+$/', $name) === 1;
    }

    /**
     * Render a command's template with its arguments, or null when the
     * command does not exist.
     */
    public function render(string $name, string $arguments = ''): ?string
    {
        $path = $this->all()[$name] ?? null;

        if ($path === null) {
            return null;
        }

        $template = Utf8::clean(trim((string) @file_get_contents($path)));

        if (str_contains($template, '$ARGUMENTS')) {
            return str_replace('$ARGUMENTS', trim($arguments), $template);
        }

        return trim($arguments) === '' ? $template : $template."\n\n".trim($arguments);
    }

    /**
     * Split "/name the rest" into [name, arguments], or null when the input
     * is not a slash command.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);

        if (! str_starts_with($input, '/') || strlen($input) < 2) {
            return null;
        }

        $parts = preg_split('/\s+/', substr($input, 1), 2);

        if ($parts === false || $parts[0] === '' || ! preg_match('/^[\w:-]+$/', $parts[0])) {
            return null;
        }

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * The last thing the user actually asked for, given the session's input
     * history — skipping the slash commands typed in between.
     *
     * `/plan <task>` counts: the task is the prompt, and it is usually exactly
     * the one worth keeping. A custom command invocation does not — saving it
     * would store a pointer to another command.
     *
     * @param  list<string>  $history
     */
    public static function lastPrompt(array $history): ?string
    {
        foreach (array_reverse($history) as $entry) {
            $slash = self::parse((string) $entry);

            if ($slash === null) {
                return trim((string) $entry) === '' ? null : $entry;
            }

            if ($slash[0] === 'plan' && trim($slash[1]) !== '') {
                return $slash[1];
            }
        }

        return null;
    }

    private function directory(): string
    {
        return $this->guard->workspace().'/.tackle/commands';
    }
}
