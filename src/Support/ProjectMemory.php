<?php

namespace Tackle\Support;

class ProjectMemory
{
    /**
     * Candidate filenames in priority order. The first one found wins.
     */
    public const FILES = ['TACKLE.md', 'AGENTS.md', 'CLAUDE.md'];

    /**
     * Cap injected content so a runaway instructions file cannot blow the
     * context window or the session budget.
     */
    private const MAX_CHARS = 20000;

    public function __construct(private readonly string $workspace) {}

    public function path(): ?string
    {
        foreach (self::FILES as $file) {
            $candidate = rtrim($this->workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function content(): ?string
    {
        $path = $this->path();

        if ($path === null) {
            return null;
        }

        $content = Utf8::clean(trim((string) @file_get_contents($path)));

        if ($content === '') {
            return null;
        }

        if (strlen($content) > self::MAX_CHARS) {
            $content = substr($content, 0, self::MAX_CHARS)."\n\n[... truncated — file exceeds ".self::MAX_CHARS.' characters]';
        }

        return $content;
    }

    /**
     * A ready-to-append instructions block, or an empty string when no
     * instructions file exists.
     */
    public function section(): string
    {
        $content = $this->content();

        if ($content === null) {
            return '';
        }

        $file = basename((string) $this->path());

        return <<<SECTION


        ## Project instructions ({$file})

        The project maintainers wrote the following instructions for AI agents working in
        this codebase. Follow them — they take precedence over your general habits, but
        they never override the safety rules above.

        {$content}
        SECTION;
    }
}
