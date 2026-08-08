<?php

namespace Tackle\Review;

class Finding
{
    public function __construct(
        public readonly string $path,
        public readonly int $line,
        public readonly string $severity, // critical | warning | suggestion
        public readonly string $message,
    ) {}

    public function label(): string
    {
        return match ($this->severity) {
            'critical' => '🔴 **Critical**',
            'warning' => '🟡 **Warning**',
            default => '🟢 **Suggestion**',
        };
    }

    public function toComment(): string
    {
        return "{$this->label()} — {$this->message}";
    }
}
