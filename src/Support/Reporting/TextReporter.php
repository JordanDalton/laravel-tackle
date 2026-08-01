<?php

namespace Tackle\Support\Reporting;

use Illuminate\Console\OutputStyle;
use Tackle\Support\ToolSummary;

/**
 * Plain-text run log. No ANSI styling and no Laravel\Prompts — both assume a
 * terminal, and this renderer exists precisely because there isn't one.
 */
class TextReporter implements RunReporter
{
    private bool $inText = false;

    public function __construct(private OutputStyle $output) {}

    public function starting(array $context): void
    {
        foreach ($context as $key => $value) {
            $this->output->writeln("# {$key}: {$value}");
        }

        $this->output->writeln('');
    }

    public function toolCall(string $tool, array $args): void
    {
        $this->breakText();

        $this->output->writeln('  '.ToolSummary::for($tool, $args));
    }

    public function toolResult(string $tool, string $result): void
    {
        // Tool output is already summarised by the agent's own narration; only
        // refusals are worth surfacing, since they change what the run can do.
        if (str_contains($result, 'refused') || str_contains($result, 'Cancelled by user')) {
            $this->output->writeln('  ! '.trim(explode("\n", $result)[0]));
        }
    }

    public function text(string $delta): void
    {
        $this->inText = true;

        $this->output->write($delta);
    }

    public function finish(array $summary): void
    {
        $this->breakText();

        $this->output->writeln('');
        $this->output->writeln('# outcome: '.$summary['outcome']);

        if (! empty($summary['diff_stat'])) {
            $this->output->writeln('# changes:');
            foreach (explode("\n", trim($summary['diff_stat'])) as $line) {
                $this->output->writeln('#   '.trim($line));
            }
        }

        if (! empty($summary['pr_url'])) {
            $this->output->writeln('# pull request: '.$summary['pr_url']);
        }

        $this->output->writeln(sprintf(
            '# usage: %d in / %d out · $%.4f of $%.2f budget · %d steps',
            $summary['usage']['input_tokens'],
            $summary['usage']['output_tokens'],
            $summary['usage']['estimated_cost_usd'],
            $summary['budget_usd'],
            $summary['steps'],
        ));

        if ($summary['interactions_denied'] > 0) {
            $this->output->writeln(sprintf(
                '# %d confirmation(s) auto-denied — no interactive user was available.',
                $summary['interactions_denied'],
            ));
        }
    }

    public function note(string $message): void
    {
        $this->breakText();

        $this->output->writeln('# '.$message);
    }

    private function breakText(): void
    {
        if ($this->inText) {
            $this->output->writeln('');
            $this->inText = false;
        }
    }
}
