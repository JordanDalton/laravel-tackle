<?php

namespace Tackle\Mcp;

/**
 * A JSON-RPC protocol error returned from a handler, as opposed to a tool
 * execution failure (which is reported in-band via the isError result flag).
 */
class McpError
{
    public function __construct(
        public readonly int $code,
        public readonly string $message,
    ) {}
}
