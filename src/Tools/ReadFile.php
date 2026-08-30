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
        return 'Read a text file, or a range of lines from it. Provide a path relative to the workspace root or an absolute path. For a large file, use SearchCode to find the line you need and read a range around it with offset and limit — the whole file is re-sent on every following step, so read only what you need. Binary files are refused; very large reads are truncated.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Path to the file to read.')
                ->required(),
            'offset' => $schema->integer()
                ->description('First line to read, 1-based. Omit to start at the top.'),
            'limit' => $schema->integer()
                ->description('How many lines to read from offset. Omit to read to the end.'),
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

        $contents = Utf8::clean(File::get($absolute));

        $offset = max(0, $request->integer('offset', 0));
        $limit = max(0, $request->integer('limit', 0));

        if ($offset <= 1 && $limit === 0) {
            return ToolOutput::cap($contents, 'ReadFile');
        }

        return ToolOutput::cap($this->slice($contents, $path, max(1, $offset), $limit), 'ReadFile');
    }

    /**
     * A line range, numbered, with a note saying where it sits in the file.
     *
     * A whole file is the most expensive thing the agent can pull into
     * context by accident — and the description told it to use SearchCode to
     * find the part it needs, then gave it no way to read only that part.
     * Numbering the lines means the next EditFile can be aimed without a
     * second read.
     */
    private function slice(string $contents, string $path, int $offset, int $limit): string
    {
        $lines = explode("\n", $contents);
        $total = count($lines);

        if ($offset > $total) {
            return "'{$path}' has {$total} lines; offset {$offset} is past the end.";
        }

        $end = $limit > 0 ? min($total, $offset + $limit - 1) : $total;
        $numbered = [];

        for ($i = $offset; $i <= $end; $i++) {
            $numbered[] = sprintf('%'.strlen((string) $total).'d| %s', $i, $lines[$i - 1]);
        }

        $header = "'{$path}' lines {$offset}-{$end} of {$total}";

        if ($end < $total) {
            $header .= ' (more below — raise offset to continue)';
        }

        return $header."\n".implode("\n", $numbered);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->guard->workspace().DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }
}
