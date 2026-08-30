<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\IgnoredDirectories;
use Tackle\Support\PathGuard;

class Glob extends AbstractTool
{
    private const MAX_RESULTS = 1000;

    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'List files matching a glob pattern within the workspace. Protected paths are excluded, dependency and build directories (node_modules, vendor, storage, …) are skipped unless the pattern starts inside one, and results are capped at '.self::MAX_RESULTS.' — use a specific directory prefix rather than "**/*" from the root.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()
                ->description('Glob pattern relative to the workspace root, e.g. "app/**/*.php".')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $pattern = $this->arg($request, 'pattern', '*');
        $workspace = $this->guard->workspace();

        $files = str_contains($pattern, '**')
            ? $this->globRecursive($workspace, $pattern)
            : (glob($workspace.DIRECTORY_SEPARATOR.ltrim($pattern, DIRECTORY_SEPARATOR), GLOB_BRACE | GLOB_NOSORT) ?: []);

        $results = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $relative = ltrim(substr($file, strlen($workspace)), DIRECTORY_SEPARATOR);
            if (! $this->guard->isProtected($relative)) {
                $results[] = $relative;
            }
        }

        sort($results);

        if (empty($results)) {
            return "No files matched the pattern '{$pattern}'.";
        }

        $total = count($results);

        if ($total > self::MAX_RESULTS) {
            $results = array_slice($results, 0, self::MAX_RESULTS);
            $results[] = '';
            $results[] = '[Listing capped at '.self::MAX_RESULTS." of {$total} files. Use a narrower pattern.]";
        }

        return implode("\n", $results);
    }

    private function globRecursive(string $workspace, string $pattern): array
    {
        [$prefix, $suffix] = explode('**', $pattern, 2);
        $baseDir = rtrim($workspace.DIRECTORY_SEPARATOR.ltrim($prefix, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);

        if (! is_dir($baseDir)) {
            return [];
        }

        $suffix = ltrim($suffix, DIRECTORY_SEPARATOR);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            IgnoredDirectories::filter($workspace, $baseDir)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($suffix === '' || fnmatch($suffix, basename($file->getPathname()))) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
