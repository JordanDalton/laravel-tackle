<?php

namespace Tackle\Commands\Concerns;

use Symfony\Component\Console\Output\OutputInterface;
use Tackle\Support\BudgetTracker;

/**
 * --output=json handling shared by ai:review and ai:respond. In JSON mode
 * every human-readable line moves to stderr and exactly one JSON document
 * lands on stdout — the same discipline ai:run applies via JsonReporter.
 */
trait EmitsJsonDocument
{
    protected bool $jsonOutput = false;

    /**
     * Validate --output and remember the choice. Returns false if the value
     * was invalid (an error is printed).
     */
    protected function resolveOutputFormat(): bool
    {
        $format = (string) $this->option('output');

        if (! in_array($format, ['text', 'json'], strict: true)) {
            $this->error("Invalid --output value '{$format}'. Must be one of: text, json.");

            return false;
        }

        $this->jsonOutput = $format === 'json';

        return true;
    }

    /**
     * In JSON mode every human-readable line is rerouted to stderr so stdout
     * stays a single parseable document. info(), warn(), and error() all
     * funnel through here, so overriding line() covers them all.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        if (! $this->jsonOutput) {
            parent::line($string, $style, $verbosity);

            return;
        }

        $styled = $style ? "<{$style}>{$string}</{$style}>" : $string;

        $this->output->getErrorStyle()->writeln($styled, $this->parseVerbosity($verbosity));
    }

    public function newLine($count = 1)
    {
        if (! $this->jsonOutput) {
            return parent::newLine($count);
        }

        $this->output->getErrorStyle()->newLine($count);

        return $this;
    }

    /**
     * Emit the final JSON document on stdout. Called exactly once per JSON
     * run, whatever the outcome — mirrors JsonReporter::finish().
     */
    protected function emitJsonDocument(array $document): void
    {
        // OUTPUT_RAW so Symfony does not try to parse '<' in the payload as markup.
        $this->output->writeln(
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * The usage block ai:run reports — the same one, now that BudgetTracker
     * owns the shape. Null-tolerant because a run can fail before a tracker
     * exists.
     */
    protected function usageSummary(?BudgetTracker $budget): ?array
    {
        if ($budget === null) {
            return null;
        }

        return $budget->usageSummary();
    }
}
