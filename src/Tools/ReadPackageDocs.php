<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

/**
 * A deliberate, narrow carve-out of the vendor/* protected path: upgrade
 * guides, changelogs, and composer.json of an installed package. Only
 * top-level documentation filenames are readable — never package code.
 */
class ReadPackageDocs extends AbstractTool
{
    private const DOC_PATTERNS = [
        'UPGRADE*', 'UPGRADING*', 'CHANGELOG*', 'BREAKING*', 'RELEASE*', 'README*', 'composer.json',
    ];

    private const MAX_CHARS = 30000;

    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Read the upgrade guide, changelog, or composer.json of an installed Composer package '
            .'from vendor/. Call with just the package name to list which documentation files exist, '
            .'then again with a file to read one. Long files are paged — pass the offset from the '
            .'previous response to continue. Use this to learn the breaking changes of a new major '
            .'before touching code.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()
                ->description('The package name, e.g. "laravel/framework".')
                ->required(),
            'file' => $schema->string()
                ->description('A documentation filename from the listing, e.g. "CHANGELOG.md". Omit to list available files.'),
            'offset' => $schema->integer()
                ->description('Character offset to continue reading a long file from. Defaults to 0.'),
        ];
    }

    public function handle(Request $request): string
    {
        $package = trim($this->arg($request, 'package', ''));

        if (! preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#i', $package)) {
            return "'{$package}' is not a valid Composer package name (expected vendor/name).";
        }

        $packageDir = $this->locatePackage($package);

        if ($packageDir === null) {
            return "Package '{$package}' is not installed in vendor/.";
        }

        $docs = $this->docFiles($packageDir);

        if ($docs === []) {
            return "No documentation files found in vendor/{$package}. Check the package's repository or website for its upgrade guide.";
        }

        $file = trim($this->arg($request, 'file', ''));

        if ($file === '') {
            return "Documentation files in vendor/{$package}:\n- ".implode("\n- ", $docs)
                ."\n\nCall again with one of these as `file` to read it.";
        }

        if (! in_array($file, $docs, strict: true)) {
            return "'{$file}' is not a readable documentation file of {$package}. Available: ".implode(', ', $docs);
        }

        $content = Utf8::clean((string) file_get_contents($packageDir.'/'.$file));
        $offset = max(0, $request->integer('offset', 0));
        $chunk = substr($content, $offset, self::MAX_CHARS);

        if ($chunk === '') {
            return "(Offset {$offset} is past the end of {$file} — the file is ".strlen($content).' characters long.)';
        }

        $next = $offset + strlen($chunk);

        if ($next < strlen($content)) {
            $chunk .= "\n\n… [truncated — call again with offset={$next} to continue; ".strlen($content).' characters total] …';
        }

        return $chunk;
    }

    /**
     * Find the package's vendor directory, preferring the active workspace. A
     * fresh worktree checkout has no vendor/ until composer runs, so fall back
     * to the live application's vendor — reading docs there is harmless.
     */
    private function locatePackage(string $package): ?string
    {
        $roots = array_unique([
            $this->guard->workspace(),
            config('tackle.workspace') ?? base_path(),
        ]);

        foreach ($roots as $root) {
            $vendorDir = realpath($root.'/vendor');
            $packageDir = $vendorDir === false ? false : realpath($vendorDir.'/'.$package);

            if ($packageDir !== false
                && str_starts_with($packageDir.DIRECTORY_SEPARATOR, $vendorDir.DIRECTORY_SEPARATOR)) {
                return $packageDir;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function docFiles(string $packageDir): array
    {
        $files = [];

        foreach (scandir($packageDir) ?: [] as $entry) {
            if (! is_file($packageDir.'/'.$entry)) {
                continue;
            }

            foreach (self::DOC_PATTERNS as $pattern) {
                if (fnmatch($pattern, $entry, FNM_CASEFOLD)) {
                    $files[] = $entry;
                    break;
                }
            }
        }

        sort($files);

        return $files;
    }
}
