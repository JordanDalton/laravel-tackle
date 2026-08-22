<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;
use Tackle\Exceptions\AgentInterruptedException;
use Tackle\Support\BudgetTracker;
use Throwable;

/**
 * Runs a task in a subagent — a separate agent with its own fresh context and
 * its own (usually narrower) toolset — and returns only its final report. The
 * delegating agent's context stays clean: exploration happens in the child,
 * conclusions come back.
 *
 * Subagents are declared in config('tackle.subagents'). The child shares the
 * session's BudgetTracker, so delegated work counts against the same spend
 * limit, and its tools go through the same safety layer (PathGuard, hooks,
 * events). Delegation is one level deep — a subagent cannot delegate again.
 */
class Delegate extends AbstractTool
{
    /**
     * True while a subagent is running, to refuse nested delegation. A static
     * rather than instance flag: a subagent's own Delegate tool would be a
     * different instance.
     */
    private static bool $delegating = false;

    public function __construct(private readonly BudgetTracker $budget) {}

    public function description(): string
    {
        $roster = collect($this->registry())
            ->map(fn ($spec, $name) => "- '{$name}': ".($spec['description'] ?? 'No description.'))
            ->implode("\n");

        return <<<DESCRIPTION
        Delegate a self-contained task to a subagent that works in its own fresh context
        and returns only its final report. Use it for work that would flood your context
        with intermediate output — broad codebase exploration, tracing how a feature
        works across many files — and act on the report instead of re-reading the files.
        The subagent cannot see your conversation, so write the task as a complete brief:
        what to find out or do, where to start, and what the report must include.

        Available subagents:
        {$roster}
        DESCRIPTION;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()
                ->description("The subagent to delegate to — one of the names listed in this tool's description.")
                ->required(),
            'task' => $schema->string()
                ->description('A complete, self-contained brief for the subagent.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $name = (string) $request->string('agent', '');
        $task = (string) $request->string('task', '');

        if (self::$delegating) {
            return 'Refused: subagents cannot delegate further. Finish this task yourself and include the open question in your report.';
        }

        $registry = $this->registry();

        if (! isset($registry[$name])) {
            $available = implode(', ', array_keys($registry)) ?: '(none configured)';

            return "Unknown subagent '{$name}'. Available: {$available}.";
        }

        if (trim($task) === '') {
            return 'Refused: provide a non-empty task brief for the subagent.';
        }

        $agent = app($registry[$name]['agent']);

        // The child gets a clean per-turn context counter and the parent's is
        // restored afterwards — the child's stream end would otherwise reset it.
        $parentContext = $this->budget->contextChars();
        $parentInFlight = $this->budget->inFlightCost();
        $this->budget->resetContextChars(0);
        $this->budget->resetInFlightCost(0.0);

        self::$delegating = true;

        try {
            return $this->runSubagent($name, $agent, $task);
        } catch (AgentInterruptedException) {
            return "Subagent '{$name}' was stopped: the session budget "
                .sprintf('($%.2f) was exhausted mid-task. Do not delegate again; ', $this->budget->budgetUsd())
                .'finish with what you already know.';
        } catch (Throwable $e) {
            return "Subagent '{$name}' failed: {$e->getMessage()}";
        } finally {
            self::$delegating = false;
            $this->budget->resetContextChars($parentContext);
            $this->budget->resetInFlightCost($parentInFlight);
        }
    }

    private function runSubagent(string $name, object $agent, string $task): string
    {
        $text = '';

        $agent->stream($task)->each(function ($event) use (&$text) {
            if ($event instanceof TextDelta) {
                $text .= $event->delta;
            }

            if ($event instanceof StreamEnd) {
                $this->budget->record($event->usage->promptTokens, $event->usage->completionTokens);

                if ($this->budget->overBudget()) {
                    throw new AgentInterruptedException('budget_exceeded');
                }
            }
        });

        $text = trim($text);

        if ($text === '') {
            return "Subagent '{$name}' finished without producing a report.";
        }

        return "Report from subagent '{$name}':\n\n{$text}";
    }

    /**
     * @return array<string, array{agent: class-string, description?: string}>
     */
    private function registry(): array
    {
        $registry = config('tackle.subagents', []);

        return is_array($registry)
            ? array_filter($registry, fn ($spec) => is_array($spec) && isset($spec['agent']))
            : [];
    }
}
