<?php

namespace Tackle\Support;

/**
 * Turns a tool call into a one-line human summary. Shared by the interactive
 * session renderer and the headless text reporter so the two cannot drift.
 */
class ToolSummary
{
    public static function for(string $tool, array $args): string
    {
        return match ($tool) {
            'ReadFile' => '📖 reading '.($args['path'] ?? '?'),
            'Glob' => '🔍 listing '.($args['pattern'] ?? '?'),
            'SearchCode' => '🔍 searching for '.($args['query'] ?? '?'),
            'EditFile' => '✏️  editing '.($args['path'] ?? '?'),
            'WriteFile' => '📝 creating '.($args['path'] ?? '?'),
            'RunArtisan' => '⚡ artisan '.($args['command'] ?? '?'),
            'RunTests' => '🧪 running tests'.(! empty($args['filter']) ? ' (filter: '.$args['filter'].')' : ''),
            'RunPint' => '✨ formatting with pint',
            'RunLarastan' => '🔎 running larastan'.(! empty($args['path']) ? ' on '.$args['path'] : ''),
            'RunShell' => '💻 shell: '.($args['command'] ?? '?'),
            'QueryDatabase' => '🗄️  querying database',
            'ReadLog' => '📋 reading log'.(! empty($args['filter']) ? ' (filter: '.$args['filter'].')' : ''),
            'GitDiff' => '🔀 git diff'.(! empty($args['path']) ? ' '.$args['path'] : ''),
            'ListRoutes' => '🗺️  listing routes',
            'ReadTelescopeEntry' => '🔭 reading telescope',
            'ReadSentryIssue' => '🪲 reading sentry',
            'ReadGitHubIssue' => '🐙 reading github issue',
            'ReadPullRequest' => '🐙 reading pull request',
            'CreateGitHubIssue' => '🐙 creating github issue',
            'CreatePullRequest' => '🚀 opening pull request',
            'CommitAndPush' => '📤 committing and pushing',
            default => '→ '.$tool,
        };
    }
}
