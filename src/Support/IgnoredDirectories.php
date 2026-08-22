<?php

namespace Tackle\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use SplFileInfo;

/**
 * Directories that tree-walking tools (Glob, SearchCode) skip by default:
 * node_modules, vendor, storage, build output — tens of thousands of files
 * that are never what the agent means by "the codebase", and that turn one
 * careless "**\/*" into a multi-megabyte tool result. Configured via
 * tackle.ignored_directories.
 *
 * This is a relevance filter, not a security boundary — protected_paths is
 * the boundary. A walk that *starts* inside an ignored directory is allowed:
 * the agent asked for it explicitly.
 */
class IgnoredDirectories
{
    /**
     * @return list<string>
     */
    public static function list(): array
    {
        $configured = config('tackle.ignored_directories', []);

        return array_values(array_filter(array_map(
            fn ($dir) => is_string($dir) ? trim($dir, '/\\') : '',
            is_array($configured) ? $configured : [],
        ), fn ($dir) => $dir !== ''));
    }

    /**
     * A recursive iterator over $baseDir that does not descend into ignored
     * directories (paths relative to $workspace), unless $baseDir itself is
     * inside one.
     */
    public static function filter(string $workspace, string $baseDir): RecursiveCallbackFilterIterator
    {
        $workspace = rtrim($workspace, '/\\');
        $ignored = self::list();
        $inner = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS);

        $baseRelative = self::relative($workspace, $baseDir);

        if ($baseRelative !== null && self::matches($baseRelative, $ignored)) {
            // Explicitly asked for — walk it all.
            $ignored = [];
        }

        return new RecursiveCallbackFilterIterator($inner, function (SplFileInfo $current) use ($workspace, $ignored) {
            if (! $current->isDir() || $ignored === []) {
                return true;
            }

            $relative = self::relative($workspace, $current->getPathname());

            return $relative === null || ! self::matches($relative, $ignored);
        });
    }

    /**
     * @param  list<string>  $ignored
     */
    public static function matches(string $relative, array $ignored): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        foreach ($ignored as $dir) {
            $dir = str_replace('\\', '/', $dir);

            if ($relative === $dir || str_starts_with($relative, $dir.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function relative(string $workspace, string $path): ?string
    {
        $path = rtrim($path, '/\\');

        if ($path === $workspace) {
            return '';
        }

        if (! str_starts_with($path, $workspace.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return substr($path, strlen($workspace) + 1);
    }
}
