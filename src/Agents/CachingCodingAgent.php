<?php

namespace Tackle\Agents;

/**
 * @deprecated Caching is on by default now (DefaultCodingAgent implements it,
 * gated by config `tackle.prompt_cache`). This remains as a back-compat alias
 * and for the `ai:eval --agent=cached` shorthand; it behaves identically to
 * DefaultCodingAgent.
 */
class CachingCodingAgent extends DefaultCodingAgent {}
