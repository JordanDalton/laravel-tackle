<?php

namespace Tackle\Support\Reporting;

/**
 * Renders a headless run. Implementations decide what lands on stdout — plain
 * lines for humans, or a single JSON document for machines.
 */
interface RunReporter
{
    public function starting(array $context): void;

    public function toolCall(string $tool, array $args): void;

    public function toolResult(string $tool, string $result): void;

    public function text(string $delta): void;

    /**
     * Emit the final result. Called exactly once, whatever the outcome.
     */
    public function finish(array $summary): void;

    /**
     * A diagnostic for the operator. Never lands on stdout in JSON mode.
     */
    public function note(string $message): void;
}
