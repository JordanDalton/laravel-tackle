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
    ) {}
}
