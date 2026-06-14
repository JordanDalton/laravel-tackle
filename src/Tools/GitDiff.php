<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class GitDiff extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Show a git diff for the workspace. Use to inspect what has changed before or after editing files. Supports staged changes, a specific commit, or a range between branches.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'staged' => $schema->boolean()
                ->description('Show only staged changes (git diff --staged). Defaults to false.'),
            'commit' => $schema->string()
                ->description('Show the diff for a specific commit SHA.'),
            'against' => $schema->string()
                ->description('Show all changes not yet in this branch, e.g. "main".'),
            'path' => $schema->string()
                ->description('Limit the diff to a specific file or directory (relative to workspace root).'),
            'stat' => $schema->boolean()
                ->description('Return a summary stat instead of the full diff. Defaults to false.'),
        ];
    }

    public function handle(Request $request): string
    {
        $workspace = $this->guard->workspace();

        $cmd = ['git', 'diff'];

        if ($request->boolean('stat', false)) {
            $cmd[] = '--stat';
        }

        if ($request->boolean('staged', false)) {
            $cmd[] = '--staged';
        } elseif ($commit = $request->string('commit', '')) {
            array_push($cmd, "{$commit}^", $commit);
        } elseif ($against = $request->string('against', '')) {
            $cmd[] = "{$against}...HEAD";
        } else {
            $cmd[] = 'HEAD';
        }

        if ($path = $request->string('path', '')) {
            $cmd[] = '--';
            $cmd[] = $path;
        }

        $result = Process::path($workspace)->timeout(30)->run($cmd);

        if (! $result->successful() && $result->exitCode() !== 1) {
            return 'git diff failed: ' . trim($result->errorOutput());
        }

        $output = trim($result->output());

        return $output !== '' ? $output : 'No differences found.';
    }
}
