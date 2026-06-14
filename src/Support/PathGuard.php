<?php

namespace Tackle\Support;

use Tackle\Support\WorktreeManager;

class PathGuard
{
    private string $configuredWorkspace;

    /** @var list<string> */
    private array $protectedPatterns;

    public function __construct(?string $workspace = null)
    {
        $raw = $workspace ?? config('tackle.workspace') ?? base_path();
        $this->configuredWorkspace = rtrim(
            (is_dir($raw) ? (realpath($raw) ?: $raw) : $raw),
            DIRECTORY_SEPARATOR
        );

        $this->protectedPatterns = config('tackle.protected_paths', [
            '.env', '.env.*', 'storage/*', 'vendor/*', '.git/*',
        ]);
    }

    /**
     * Returns null if the path is allowed to be read.
     * Returns a refusal message string if it is not.
     */
    public function checkRead(string $path): ?string
    {
        return $this->check($path);
    }

    /**
     * Returns null if the path is allowed to be written.
     * Returns a refusal message string if it is not.
     */
    public function checkWrite(string $path): ?string
    {
        return $this->check($path);
    }

    private function check(string $path): ?string
    {
        $ws       = $this->workspace();
        $resolved = $this->resolve($path, $ws);

        if ($resolved === null) {
            return "Path '{$path}' could not be resolved to a real location.";
        }

        if (! str_starts_with($resolved, $ws . DIRECTORY_SEPARATOR)
            && $resolved !== $ws) {
            return "Path '{$path}' is outside the workspace root '{$ws}'.";
        }

        $relative = ltrim(
            substr($resolved, strlen($ws)),
            DIRECTORY_SEPARATOR
        );

        foreach ($this->protectedPatterns as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return "Path '{$relative}' matches protected pattern '{$pattern}' and cannot be accessed.";
            }
        }

        return null;
    }

    /**
     * Resolve a path that may not exist yet by walking up to the
     * nearest existing ancestor and joining the remaining segments.
     */
    private function resolve(string $path, string $ws): ?string
    {
        // Absolute path provided directly.
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = $ws . DIRECTORY_SEPARATOR . $path;
        }

        // realpath() only works for existing paths.
        if (file_exists($path)) {
            return realpath($path) ?: null;
        }

        // Walk up to find the nearest existing ancestor, then canonicalise.
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $suffix = [];

        while (count($parts) > 1) {
            $candidate = implode(DIRECTORY_SEPARATOR, $parts);
            if (file_exists($candidate)) {
                $real = realpath($candidate);
                if ($real === false) {
                    return null;
                }
                return $real . (empty($suffix) ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_reverse($suffix)));
            }
            $suffix[] = array_pop($parts);
        }

        return null;
    }

    public function workspace(): string
    {
        if (app()->bound(WorktreeManager::class) && app(WorktreeManager::class)->active()) {
            return app(WorktreeManager::class)->path();
        }

        return $this->configuredWorkspace;
    }

    /**
     * Whether a relative path matches any protected glob pattern.
     * Used by Glob / SearchCode to filter results.
     */
    public function isProtected(string $relativePath): bool
    {
        foreach ($this->protectedPatterns as $pattern) {
            if (fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
                return true;
            }
        }
        return false;
    }
}
