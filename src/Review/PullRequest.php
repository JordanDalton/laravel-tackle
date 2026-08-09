<?php

namespace Tackle\Review;

class PullRequest
{
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly string $body,
        public readonly string $headRef,
        public readonly string $headSha,
        public readonly string $baseRef,
        public readonly string $url,
        public readonly string $diff,
        public readonly string $headRepo = '',
    ) {}

    /**
     * Whether the head branch lives in a different repository than the one
     * Tackle is configured for — pushing to it would need fork write access.
     */
    public function isFromFork(string $configuredRepo): bool
    {
        return $this->headRepo !== '' && strcasecmp($this->headRepo, $configuredRepo) !== 0;
    }
}
