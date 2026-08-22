<?php

namespace Tackle\Agents;

/**
 * A focused coding agent: the fix-task essentials only, so the per-step system
 * prompt carries a handful of tool schemas instead of the full ~two dozen. The
 * schemas are re-sent on every step (no prompt caching yet), so a smaller
 * toolset is markedly cheaper per task — measured at ~37% lower cost than the
 * full DefaultCodingAgent on a fix case, with the same result.
 *
 * Opt-in: bind it in place of the default agent
 *
 *     $this->app->bind(CodingAgent::class, LeanCodingAgent::class);
 *
 * or benchmark it against your own cases with `ai:eval --agent=lean` before
 * switching. Keep the full agent for interactive `ai:code`, where breadth
 * (GitHub, Sentry, Telescope, routes, PRs) earns its place; reach for this on
 * narrow, unattended fix tasks.
 */
class LeanCodingAgent extends DefaultCodingAgent
{
    /** Tools a self-contained fix task actually needs. */
    public const KEEP = ['ReadFile', 'EditFile', 'WriteFile', 'Glob', 'SearchCode', 'RunTests'];

    public function tools(): iterable
    {
        return array_values(array_filter(
            [...parent::tools()],
            fn ($tool) => in_array(
                is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool),
                self::KEEP,
                true,
            ),
        ));
    }
}
