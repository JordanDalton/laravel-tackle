<?php

namespace Tackle\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tackle\Healing\NightwatchIssue;
use Tackle\Jobs\HealNightwatchIssue;

/**
 * Receives Laravel Nightwatch issue webhooks and hands them to the healer.
 *
 * Nightwatch has already done the part the healer cannot do for itself: it
 * groups occurrences into a single issue and fires once when that issue opens.
 * This controller's job is to decide whether an opened issue is one worth
 * spending an agent on, then queue it.
 *
 * Every rejection returns 200. A non-2xx tells the sender to retry, and there
 * is nothing to retry about an issue we deliberately declined to heal.
 *
 * @see https://nightwatch.laravel.com/docs/webhooks
 */
class NightwatchWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        $event = is_string($body['event'] ?? null) ? $body['event'] : '';

        $allowedEvents = (array) config('tackle.nightwatch.events', ['issue.opened']);

        if (! in_array($event, $allowedEvents, strict: true)) {
            return $this->skip("event {$event} is not configured for healing");
        }

        $issue = NightwatchIssue::fromWebhook($body);

        if ($issue === null) {
            return $this->skip('payload did not contain an issue');
        }

        if ($reason = $this->rejection($issue)) {
            return $this->skip($reason, $issue);
        }

        // Nightwatch dedupes occurrences into one issue, but `issue.reopened`
        // can fire repeatedly on a flapping issue. The cooldown makes sure that
        // costs one agent run, not one per flap. Cache::add is atomic, so two
        // concurrent deliveries cannot both win.
        $cooldown = (int) config('tackle.nightwatch.cooldown', 86400);

        if ($cooldown > 0 && ! Cache::add($this->cooldownKey($issue), true, $cooldown)) {
            return $this->skip('already healed within the cooldown window', $issue);
        }

        HealNightwatchIssue::dispatch($issue);

        Log::info("Tackle Nightwatch: queued heal for issue {$issue->label()} ({$issue->subject()}).");

        return response()->json([
            'status' => 'queued',
            'issue' => $issue->label(),
        ]);
    }

    /**
     * Returns why this issue should not be healed, or null to proceed.
     */
    private function rejection(NightwatchIssue $issue): ?string
    {
        $types = (array) config('tackle.nightwatch.issue_types', ['exception', 'performance']);

        if (! in_array($issue->type, $types, strict: true)) {
            return "issue type {$issue->type} is not configured for healing";
        }

        $environments = array_filter((array) config('tackle.nightwatch.environments', []));

        if ($environments !== [] && ! in_array($issue->environment, $environments, strict: true)) {
            return 'environment '.($issue->environment ?? 'unknown').' is not configured for healing';
        }

        $minimum = (string) config('tackle.nightwatch.min_priority', 'none');

        if (! $issue->meetsPriority($minimum)) {
            return "priority {$issue->priority} is below the configured minimum of {$minimum}";
        }

        if ($issue->isException()
            && $issue->isHandled()
            && ! config('tackle.nightwatch.handled_exceptions', false)) {
            return 'exception was handled by the application';
        }

        return null;
    }

    private function cooldownKey(NightwatchIssue $issue): string
    {
        return 'tackle:nightwatch:healed:'.$issue->id;
    }

    private function skip(string $reason, ?NightwatchIssue $issue = null): JsonResponse
    {
        $label = $issue?->label() ?? 'unknown';

        Log::info("Tackle Nightwatch: skipped issue {$label} — {$reason}.");

        return response()->json([
            'status' => 'skipped',
            'reason' => $reason,
        ]);
    }
}
