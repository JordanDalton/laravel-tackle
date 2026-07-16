<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class EditFile extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Edit an existing file by replacing an exact, unique string (old_str) with a new string (new_str). The old_str must appear exactly once in the file — if it is absent or duplicated, the edit is refused. Read the file first to get the exact text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path'    => $schema->string()->description('Path to the file to edit (relative or absolute).')->required(),
            'old_str' => $schema->string()->description('The exact string to replace. Must appear exactly once in the file.')->required(),
            'new_str' => $schema->string()->description('The string to replace old_str with.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $path   = $request->string('path', '');
        $oldStr = $request->string('old_str', '');
        $newStr = $request->string('new_str', '');

        if ($refusal = $this->guard->checkWrite($path)) {
            return $refusal;
        }

        $absolute = $this->absolute($path);

        if (! File::exists($absolute)) {
            return "File '{$path}' does not exist. Use WriteFile to create a new file.";
        }

        if (! File::isFile($absolute)) {
            return "'{$path}' is a directory, not a file.";
        }

        $contents = File::get($absolute);
        $count    = substr_count($contents, $oldStr);

        if ($count === 0) {
            return "old_str not found in '{$path}'. Read the file to get the exact current text before editing.";
        }

        if ($count > 1) {
            return "old_str appears {$count} times in '{$path}'; it must be unique. Add more surrounding context to make it unique.";
        }

        File::put($absolute, str_replace($oldStr, $newStr, $contents));

        return "Successfully edited '{$path}'. The change is unstaged — review it with 'git diff' before committing.";
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->guard->workspace() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}
