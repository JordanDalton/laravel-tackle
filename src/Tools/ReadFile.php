<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\ToolOutput;
use Tackle\Support\Utf8;

class ReadFile extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Read the contents of a text file. Provide a path relative to the workspace root or an absolute path. Binary files are refused; very large files are truncated — use SearchCode to locate the part you need.';
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
        $path = $this->arg($request, 'path', '');

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

        $handle = @fopen($absolute, 'rb');
        $head = $handle ? (string) fread($handle, 8192) : '';
        if ($handle) {
            fclose($handle);
        }

        if (str_contains($head, "\0")) {
            return "'{$path}' is a binary file (".number_format((int) File::size($absolute)).' bytes) — not readable as text.';
        }

        return ToolOutput::cap(Utf8::clean(File::get($absolute)), 'ReadFile');
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->guard->workspace().DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }
}
