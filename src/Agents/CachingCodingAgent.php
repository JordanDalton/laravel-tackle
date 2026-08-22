<?php

namespace Tackle\Agents;

use Laravel\Ai\Contracts\HasProviderOptions;
use Tackle\Agents\Concerns\CachesInstructions;

/**
 * The full coding agent with Anthropic prompt caching on the system prompt +
 * tool schemas. Behaviour is identical to DefaultCodingAgent; only the cost of
 * re-sending the fixed prefix each step changes. Benchmark it with
 * `ai:eval --agent=cached` against `--agent=default`.
 */
class CachingCodingAgent extends DefaultCodingAgent implements HasProviderOptions
{
    use CachesInstructions;
}
