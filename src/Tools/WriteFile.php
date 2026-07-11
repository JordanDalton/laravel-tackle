<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class WriteFile extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Create a NEW file with the given content. Refuses if the file already exists — use EditFile to modify existing files.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path'    => $schema->string()->description('Path for the new file (relative or absolute).')->required(),
            'content' => $schema->string()->description('Full content to write to the new file.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $path    = $request->string('path', '');
        $content = $request->string('content', '');

        if ($refusal = $this->guard->checkWrite($path)) {
            return $refusal;
        }

        $absolute = $this->absolute($path);

        if (File::isDirectory($absolute)) {
            return "'{$path}' is a directory, not a file.";
        }

        if (File::exists($absolute)) {
            return "File '{$path}' already exists. Use EditFile to modify it.";
        }

        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $content);

        return "Created '{$path}'. The new file is unstaged — review it with 'git diff' before committing.";
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->guard->workspace() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}
