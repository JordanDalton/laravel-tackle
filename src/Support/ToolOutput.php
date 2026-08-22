<?php

namespace Tackle\Support;

/**
 * Hard size cap for what a single tool call may put into the model's context.
 *
 * A tool result is re-sent on every subsequent step of the turn, so one
 * oversized result — a recursive listing that walks node_modules, a search
 * whose "snippet" is a 500 KB minified line, a binary read — is paid for
 * again and again until the turn ends. A ~1 MB result is ~300k tokens per
 * step; ten steps later that is a few million tokens the budget never saw
 * coming. Capping at the source bounds the blast radius of any tool, built-in
 * or user-defined.
 */
class ToolOutput
{
    public const DEFAULT_MAX_CHARS = 48_000;

    public static function maxChars(): int
    {
        $configured = config('tackle.max_tool_result_chars', self::DEFAULT_MAX_CHARS);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::DEFAULT_MAX_CHARS;
    }

    /**
     * Truncate an oversized result, telling the model how much it is missing
     * and how to ask for less.
     */
    public static function cap(string $result, string $tool = 'tool', ?int $maxChars = null): string
    {
        $max = $maxChars ?? self::maxChars();
        $length = strlen($result);

        if ($length <= $max) {
            return $result;
        }

        // Cut on a line boundary where one exists in the last 10% so the model
        // does not see half a line, then repair any split UTF-8 sequence.
        $cut = $max;
        $newline = strrpos(substr($result, 0, $max), "\n");

        if ($newline !== false && $newline > (int) ($max * 0.9)) {
            $cut = $newline;
        }

        $head = Utf8::clean(substr($result, 0, $cut));

        return rtrim($head)
            ."\n\n[Output truncated by Tackle: showing ".number_format($cut).' of '.number_format($length)
            ." characters from {$tool}. Narrow the request — a smaller path, a tighter pattern, a specific file — to see the rest.]";
    }
}
