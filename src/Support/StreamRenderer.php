<?php

namespace Tackle\Support;

/**
 * Turns a stream of assistant text into a stream of render operations, so a
 * markdown table arrives in the terminal as a table instead of as pipes.
 *
 * The naive approach — buffer the whole response, parse it, render it — costs
 * you live streaming, which is most of what makes a session feel alive. This
 * does not buffer the response. Markdown tables are line-based, so it holds
 * back only consecutive lines that begin with a pipe, and flushes them as a
 * table the moment the block ends. Everything else streams through
 * character by character exactly as it arrived: a line is released as soon as
 * its first non-space character proves it is not a table row.
 *
 * The alternative design was a ShowTable tool the model calls with the rows.
 * That makes the model re-emit every row as tool arguments — output tokens at
 * the most expensive rate, latency, and a fresh opportunity to mistype a value
 * it had already read correctly — and costs a tool schema on every step of
 * every session. Rendering what the model already wrote costs nothing and
 * works for every agent that emits markdown.
 *
 * Pure by design: no terminal, no I/O. Give it deltas, get back operations.
 */
class StreamRenderer
{
    /** Text of the current line that has not been emitted yet. */
    private string $current = '';

    /**
     * The full text of the current line, emitted or not. Classification needs
     * the whole line — a fence opener is only recognisable from its start, and
     * that start may already have streamed out.
     */
    private string $lineText = '';

    /** Bytes of the current delta not yet consumed into a line. */
    private string $partial = '';

    /** True once part of the current line has been emitted — it cannot be a table row. */
    private bool $lineOpen = false;

    /** True inside a fenced code block, where a pipe is just a pipe. */
    private bool $fenced = false;

    /** @var list<string> Consecutive candidate table lines. */
    private array $block = [];

    public function __construct(private readonly bool $tables = true) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function push(string $delta): array
    {
        if (! $this->tables) {
            return $delta === '' ? [] : [self::text($delta)];
        }

        $ops = [];
        $this->partial .= $delta;

        while (($pos = strpos($this->partial, "\n")) !== false) {
            $chunk = substr($this->partial, 0, $pos);
            $this->partial = substr($this->partial, $pos + 1);
            $this->current .= $chunk;
            $this->lineText .= $chunk;

            foreach ($this->completeLine() as $op) {
                $ops[] = $op;
            }
        }

        if ($this->partial === '') {
            return $ops;
        }

        $this->current .= $this->partial;
        $this->lineText .= $this->partial;
        $this->partial = '';

        // A line already proven to be prose keeps streaming as it arrives.
        if ($this->lineOpen) {
            $ops[] = self::text($this->current);
            $this->current = '';

            return $ops;
        }

        // Mid-table, hold: this line may continue the block or end it.
        if ($this->block !== []) {
            return $ops;
        }

        $probe = ltrim($this->lineText);

        // The first non-space character settles it. Not a pipe, not a table —
        // release the line and stream the rest of it freely.
        if ($probe !== '' && ! str_starts_with($probe, '|')) {
            $ops[] = self::text($this->current);
            $this->current = '';
            $this->lineOpen = true;
        }

        return $ops;
    }

    /**
     * Finish the turn: close any half-written line and flush a table that ran
     * to the end of the response.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $ops = [];

        if ($this->current !== '' || $this->lineText !== '') {
            $line = $this->lineText;
            $pending = $this->current;
            $wasOpen = $this->lineOpen;

            $this->current = '';
            $this->lineText = '';
            $this->lineOpen = false;

            if (! $wasOpen && str_starts_with(ltrim($line), '|')) {
                $this->block[] = rtrim(ltrim($line));
            } elseif ($pending !== '') {
                $ops = array_merge($this->flushBlock(), [self::text($pending)]);
            }
        }

        return array_merge($ops, $this->flushBlock());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completeLine(): array
    {
        $line = $this->lineText;
        $pending = $this->current;
        $wasOpen = $this->lineOpen;

        $this->current = '';
        $this->lineText = '';
        $this->lineOpen = false;

        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '```')) {
            $this->fenced = ! $this->fenced;

            return array_merge($this->flushBlock(), [$this->emit($pending)]);
        }

        if (! $this->fenced && ! $wasOpen && str_starts_with($trimmed, '|')) {
            $this->block[] = rtrim($trimmed);

            return [];
        }

        return array_merge($this->flushBlock(), [$this->emit($pending)]);
    }

    /**
     * Render the held block — as a table if it really is one, and verbatim if
     * it is not. A run of pipe-prefixed lines with no separator row is not a
     * table, and turning it into one would be inventing structure.
     *
     * @return list<array<string, mixed>>
     */
    private function flushBlock(): array
    {
        if ($this->block === []) {
            return [];
        }

        $block = $this->block;
        $this->block = [];

        if (count($block) < 2 || ! self::isSeparator($block[1])) {
            return array_map(fn ($line) => self::text($line."\n"), $block);
        }

        $headers = self::cells($block[0]);
        $rows = [];

        foreach (array_slice($block, 2) as $line) {
            $cells = self::cells($line);

            // Ragged rows are the model's, not ours to silently reshape —
            // pad short ones and keep every cell of long ones.
            $rows[] = array_pad($cells, count($headers), '');
        }

        return [['type' => 'table', 'headers' => $headers, 'rows' => $rows]];
    }

    /**
     * Close out a line. Whatever of it already streamed is gone; only the
     * unemitted remainder and the newline are left to write.
     *
     * @return array<string, mixed>
     */
    private function emit(string $pending): array
    {
        return self::text($pending."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * The `|---|:--:|` row that separates a markdown header from its body, and
     * the only thing that distinguishes a table from a run of pipes.
     */
    private static function isSeparator(string $line): bool
    {
        return str_contains($line, '-')
            && preg_match('/^\|?[\s:|-]+\|?$/', trim($line)) === 1;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line);
        $line = preg_replace('/\|$/', '', (string) $line);

        return array_map('trim', explode('|', (string) $line));
    }
}
