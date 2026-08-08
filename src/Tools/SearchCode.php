<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

class SearchCode extends AbstractTool
{
    private const MAX_RESULTS = 50;

    private const CONTEXT_LINES = 2;

    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Search for a string or pattern within files in the workspace. Returns file path, line number, and a short snippet. Results capped at '.self::MAX_RESULTS.'. Prefer this over reading whole files when locating a symbol or string.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The string or regex to search for.')
                ->required(),
            'path' => $schema->string()
                ->description('Optional subdirectory or file to search within (relative to workspace root).'),
            'regex' => $schema->boolean()
                ->description('Treat query as a regex pattern. Defaults to false.'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = $request->string('query', '');
        $subpath = $request->string('path', '');
        $isRegex = $request->boolean('regex', false);

        if ($query === '') {
            return 'A non-empty query is required.';
        }

        $workspace = $this->guard->workspace();
        $searchDir = $subpath !== ''
            ? $workspace.DIRECTORY_SEPARATOR.ltrim($subpath, DIRECTORY_SEPARATOR)
            : $workspace;

        if (! is_dir($searchDir) && ! is_file($searchDir)) {
            return "Path '{$subpath}' does not exist within the workspace.";
        }

        $files = is_file($searchDir) ? [$searchDir] : $this->collectFiles($searchDir);
        $results = [];
        $count = 0;

        foreach ($files as $file) {
            if ($count >= self::MAX_RESULTS) {
                break;
            }

            $relative = ltrim(substr($file, strlen($workspace)), DIRECTORY_SEPARATOR);

            if ($this->guard->isProtected($relative)) {
                continue;
            }

            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $lineNo => $line) {
                if ($count >= self::MAX_RESULTS) {
                    break;
                }

                $matched = $isRegex
                    ? @preg_match('/'.$query.'/', $line)
                    : str_contains($line, $query);

                if ($matched) {
                    $snippet = array_slice($lines, max(0, $lineNo - self::CONTEXT_LINES), self::CONTEXT_LINES * 2 + 1);
                    $results[] = sprintf("%s:%d\n%s", $relative, $lineNo + 1, implode("\n", $snippet));
                    $count++;
                }
            }
        }

        if (empty($results)) {
            return "No matches found for '{$query}'.";
        }

        $output = implode("\n---\n", $results);

        if ($count >= self::MAX_RESULTS) {
            $output .= "\n\n[Results capped at ".self::MAX_RESULTS.'. Narrow your search if needed.]';
        }

        return Utf8::clean($output);
    }

    private function collectFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
