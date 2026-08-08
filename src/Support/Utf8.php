<?php

namespace Tackle\Support;

/**
 * Guarantees that text entering the conversation is valid UTF-8.
 *
 * Anything a tool returns (file contents, process output, log lines) or a
 * command feeds into a prompt (diffs, project instructions) ends up in the
 * JSON body of a provider request. One invalid byte — a latin-1 source file,
 * binary output from a process — and json_encode refuses the whole payload,
 * crashing the session. Invalid sequences are substituted rather than the
 * request aborted.
 */
class Utf8
{
    public static function clean(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
