<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

class ReadFile extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Read the contents of a file. Provide a path relative to the workspace root or an absolute path.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Path to the file to read.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $path = $request->string('path', '');

        if ($refusal = $this->guard->checkRead($path)) {
            return $refusal;
        }

        $absolute = $this->absolute($path);

        if (! File::exists($absolute)) {
            return "File '{$path}' does not exist.";
        }

        if (! File::isFile($absolute)) {
            return "'{$path}' is a directory, not a file.";
        }

        return Utf8::clean(File::get($absolute));
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->guard->workspace().DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }
}
