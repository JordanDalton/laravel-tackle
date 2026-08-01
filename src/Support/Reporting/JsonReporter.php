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

    public function __construct(private OutputStyle $output) {}

    public function starting(array $context): void
    {
        foreach ($context as $key => $value) {
            $this->note("{$key}: {$value}");
        }
    }

    public function toolCall(string $tool, array $args): void
    {
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
