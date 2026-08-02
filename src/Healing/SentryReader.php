<?php

namespace Tackle\Healing;

use Illuminate\Support\Facades\Http;
use Throwable;

class SentryReader
{
    /**
     * Fetch a specific Sentry issue by ID and return its latest event
     * (exception, stacktrace, breadcrumbs, request context).
     * Returns an empty string if Sentry is not configured or the call fails.
     */
    public function forIssue(string $issueId): string
    {
        [$token, $org] = $this->credentials();

        if (! $token || ! $org) {
            return '';
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("https://sentry.io/api/0/organizations/{$org}/issues/{$issueId}/events/latest/");

            if (! $response->successful()) {
                return '';
            }

            return $this->formatEvent($response->json());
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Return the N most recent unresolved issues for the configured project.
     */
    public function recent(int $limit = 10): string
    {
        [$token, $org] = $this->credentials();
        $project = config('tackle.sentry.project');

        if (! $token || ! $org || ! $project) {
            return '';
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("https://sentry.io/api/0/projects/{$org}/{$project}/issues/", [
                    'limit' => min($limit, 25),
                    'query' => 'is:unresolved',
                    'sort' => 'date',
                ]);

            if (! $response->successful()) {
                return '';
            }

            $issues = $response->json();

            if (empty($issues)) {
                return 'No unresolved Sentry issues found.';
            }

            return collect($issues)->map(function ($issue) {
                $id = $issue['id'] ?? '?';
                $title = $issue['title'] ?? '?';
                $count = $issue['count'] ?? 0;
                $lastSeen = $issue['lastSeen'] ?? '';

                return "[{$lastSeen}] #{$id} ({$count}×) {$title}";
            })->implode("\n");
        } catch (Throwable) {
            return '';
        }
    }

    private function formatEvent(array $event): string
    {
        $exceptionEntry = collect($event['entries'] ?? [])->firstWhere('type', 'exception');
        $exception = $exceptionEntry['data']['values'][0] ?? [];
        $class = $exception['type'] ?? ($event['title'] ?? '');
        $message = $exception['value'] ?? '';
        $stacktrace = collect($exception['stacktrace']['frames'] ?? [])
            ->reverse()
            ->take(15)
            ->map(fn ($f) => ($f['filename'] ?? '?').':'.($f['lineno'] ?? '?').' in '.($f['function'] ?? '?'))
            ->implode("\n");

        $breadcrumbs = collect($event['entries'])
            ->firstWhere('type', 'breadcrumbs');
        $crumbs = collect($breadcrumbs['data']['values'] ?? [])
            ->slice(-10)
            ->map(fn ($c) => '['.($c['timestamp'] ?? '').'] '.($c['category'] ?? '').': '.($c['message'] ?? ''))
            ->implode("\n");

        $request = collect($event['entries'])->firstWhere('type', 'request');
        $requestLine = '';
        if ($request) {
            $method = $request['data']['method'] ?? '';
            $url = $request['data']['url'] ?? '';
            $requestLine = "\nRequest: {$method} {$url}";
        }

        $output = "Sentry issue #{$event['groupID']} — {$class}: {$message}";
        $output .= $requestLine;
        $output .= "\n\nStacktrace (most recent first):\n{$stacktrace}";

        if ($crumbs) {
            $output .= "\n\nBreadcrumbs:\n{$crumbs}";
        }

        return trim($output);
    }

    private function credentials(): array
    {
        return [
            config('tackle.sentry.auth_token') ?: null,
            config('tackle.sentry.org') ?: null,
        ];
    }
}
