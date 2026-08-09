<?php

namespace Tackle\Review;

class CommentThread
{
    /** @param  string[]  $thread earlier comments in the thread, formatted as "author: text" */
    public function __construct(
        public readonly string $type, // review | issue
        public readonly int $commentId,
        public readonly string $author,
        public readonly string $instruction,
        public readonly array $thread = [],
        public readonly string $path = '',
        public readonly ?int $line = null,
        public readonly string $diffHunk = '',
    ) {}
}
