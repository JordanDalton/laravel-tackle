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

    private function directory(): string
    {
        return $this->guard->workspace().'/.tackle/commands';
    }
}
