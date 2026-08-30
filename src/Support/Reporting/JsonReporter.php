<?php

namespace Tackle\Support\Reporting;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Buffers the run and emits a single JSON document on stdout at the end.
 *
 * Everything a human would want to read goes to stderr, so a caller can pipe
 * stdout straight into jq without filtering. Same discipline tackle:mcp applies
 * to its protocol stream.
 */
class JsonReporter implements RunReporter
{
    private array $events = [];

    private string $text = '';

    /**
     * Whether the next prose starts a new turn.
     *
     * The reporter is told about deltas and tool calls, never about turns, so
     * text from consecutive turns ran together: "Let me read the file
     * first.The method already has a docblock". A tool call between two runs
     * of prose is exactly where one turn ended and the next began, which makes
     * it a reliable boundary without widening the interface.
     */
    private bool $newTurn = false;

    public function __construct(private OutputStyle $output) {}

    public function starting(array $context): void
    {
        foreach ($context as $key => $value) {
            $this->note("{$key}: {$value}");
        }
    }

    public function toolCall(string $tool, array $args): void
    {
        if ($this->text !== '') {
            $this->newTurn = true;
        }

        $this->events[] = [
            'type' => 'tool_call',
            'tool' => $tool,
            'args' => $args,
        ];
    }

    public function toolResult(string $tool, string $result): void
    {
        $this->events[] = [
            'type' => 'tool_result',
            'tool' => $tool,
            'result' => $result,
        ];
    }

    public function text(string $delta): void
    {
        // Only once actual prose arrives, so a stray whitespace delta after a
        // tool call does not spend the break.
        if ($this->newTurn && trim($delta) !== '') {
            $this->newTurn = false;

            if (! str_ends_with($this->text, "\n\n")) {
                $this->text = rtrim($this->text)."\n\n";
            }
        }

        $this->text .= $delta;
    }

    public function finish(array $summary): void
    {
        $document = array_merge($summary, [
            'text' => trim($this->text),
            'events' => $this->events,
        ]);

        // OUTPUT_RAW so Symfony does not try to parse '<' in the payload as markup.
        $this->output->writeln(
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW,
        );
    }

    public function note(string $message): void
    {
        $this->output->getErrorStyle()->writeln('# '.$message);
    }
}
