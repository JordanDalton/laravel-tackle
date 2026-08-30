<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Healing\SentryReader;

class ReadSentryIssue extends AbstractTool
{
    public function __construct(private SentryReader $reader) {}

    public function description(): string
    {
        return 'Fetch a Sentry issue by ID and return the exception class, message, stacktrace, breadcrumbs, and request context — everything needed to diagnose and fix the error. Omit issue_id to list recent unresolved issues. Returns an empty result if SENTRY_AUTH_TOKEN or SENTRY_ORG is not configured.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_id' => $schema->string()
                ->description('Sentry issue ID (the numeric ID, e.g. "4821"). Omit to list recent unresolved issues.'),
            'limit' => $schema->integer()
                ->description('Number of recent issues to return when no issue_id is given. Defaults to 10.'),
        ];
    }

    public function handle(Request $request): string
    {
        $issueId = $this->arg($request, 'issue_id', '');

        if ($issueId !== '') {
            $result = $this->reader->forIssue($issueId);

            return $result !== ''
                ? $result
                : 'Could not fetch Sentry issue. Check that SENTRY_AUTH_TOKEN and SENTRY_ORG are set and the issue ID is correct.';
        }

        $limit = max(1, min(25, (int) $request->integer('limit', 10)));
        $result = $this->reader->recent($limit);

        return $result !== ''
            ? $result
            : 'No Sentry issues found. Check that SENTRY_AUTH_TOKEN, SENTRY_ORG, and SENTRY_PROJECT are set.';
    }
}
