<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class Glob extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'List files matching a glob pattern within the workspace. Protected paths are excluded from results.';
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
        $pattern = $request->string('pattern', '*');
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
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
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
