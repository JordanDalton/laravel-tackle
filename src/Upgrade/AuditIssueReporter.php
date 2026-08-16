<?php

namespace Tackle\Upgrade;

use RuntimeException;
use Tackle\Support\GitHubClient;

/**
 * Maintains exactly one GitHub issue mirroring the major-upgrade audit, so a
 * scheduled `ai:upgrade --audit --issue` never spams the tracker: the issue is
 * created when majors first appear, updated in place when the audit changes,
 * left alone when it hasn't, and closed when no major upgrades remain.
 */
class AuditIssueReporter
{
    public const TITLE = 'Composer major upgrades available';

    public const LABEL = 'tackle-upgrade-audit';

    private const MARKER = '<!-- tackle-upgrade-audit -->';

    public function __construct(private GitHubClient $client) {}

    /**
     * Reconcile the audit issue with the given audit result. Returns a
     * one-line summary of what happened.
     *
     * @param  list<array{name: string, version: string, latest: string, blockers: string}>  $majors
     */
    public function sync(array $majors): string
    {
        if (! $this->client->configured()) {
            throw new RuntimeException(
                'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.'
            );
        }

        $repo = $this->client->repo();
        $existing = $this->findOpenIssue($repo);

        if ($majors === []) {
            if ($existing === null) {
                return 'No major upgrades available and no open audit issue — nothing to do.';
            }

            $this->client->post("repos/{$repo}/issues/{$existing['number']}/comments", [
                'body' => 'The dependency audit found no major upgrades remaining — closing.',
            ]);

            $response = $this->client->patch("repos/{$repo}/issues/{$existing['number']}", ['state' => 'closed']);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to close issue: '.$response->json('message', 'unknown error'));
            }

            return "Closed issue #{$existing['number']} — no major upgrades remain.";
        }

        $body = $this->buildBody($majors);

        if ($existing !== null) {
            if (trim((string) ($existing['body'] ?? '')) === trim($body)) {
                return "Issue #{$existing['number']} is already up to date.";
            }

            $response = $this->client->patch("repos/{$repo}/issues/{$existing['number']}", ['body' => $body]);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to update issue: '.$response->json('message', 'unknown error'));
            }

            return "Updated issue #{$existing['number']} with the latest audit.";
        }

        $response = $this->client->post("repos/{$repo}/issues", [
            'title' => self::TITLE,
            'body' => $body,
            'labels' => [self::LABEL],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create issue: '.$response->json('message', 'unknown error'));
        }

        return "Created issue #{$response->json('number')}: {$response->json('html_url')}";
    }

    /**
     * The issue body for an audit result. Deliberately timestamp-free so an
     * unchanged audit produces a byte-identical body and sync() can skip the
     * write.
     *
     * @param  list<array{name: string, version: string, latest: string, blockers: string}>  $majors
     */
    public function buildBody(array $majors): string
    {
        $body = self::MARKER."\n\nThe Tackle dependency audit found direct Composer dependencies with a new major version available.\n";

        foreach ($majors as $major) {
            $body .= "\n### {$major['name']}: `{$major['version']}` → `{$major['latest']}`\n";

            if (($major['blockers'] ?? '') !== '') {
                $body .= "\n```\n{$major['blockers']}\n```\n";
            }
        }

        return $body."\n---\nStart an upgrade with `php artisan ai:upgrade <package>` (or `php artisan ai:upgrade` to pick interactively). "
            ."This issue is maintained by `ai:upgrade --audit --issue`: it updates when the audit changes and closes when no major upgrades remain.\n";
    }

    private function findOpenIssue(string $repo): ?array
    {
        $response = $this->client->get("repos/{$repo}/issues", [
            'labels' => self::LABEL,
            'state' => 'open',
            'per_page' => 10,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to list issues: '.$response->json('message', 'unknown error'));
        }

        foreach ($response->json() ?? [] as $issue) {
            // The issues endpoint also returns pull requests — skip them.
            if (! isset($issue['pull_request'])) {
                return $issue;
            }
        }

        return null;
    }
}
