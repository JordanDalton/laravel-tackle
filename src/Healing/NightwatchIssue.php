<?php

namespace Tackle\Healing;

/**
 * A normalised view of a Laravel Nightwatch issue as delivered by webhook.
 *
 * Nightwatch models exceptions and performance problems as the same kind of
 * object — an issue with an open/resolved lifecycle — and only the `details`
 * payload differs between them. This value object keeps that shape intact
 * while giving the healer safe accessors, because a webhook body is remote
 * input and every field is treated as optional.
 *
 * @see https://nightwatch.laravel.com/docs/webhooks
 */
class NightwatchIssue
{
    /** Priorities in ascending order, used by the `min_priority` gate. */
    public const PRIORITIES = ['none', 'low', 'medium', 'high'];

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $event,
        public readonly string $id,
        public readonly ?int $ref,
        public readonly string $type,
        public readonly string $title,
        public readonly string $status,
        public readonly string $priority,
        public readonly string $url,
        public readonly array $details,
        public readonly ?string $environment,
        public readonly ?string $applicationId,
    ) {}

    /**
     * Build an issue from a decoded webhook body.
     *
     * Returns null when the body is not shaped like a Nightwatch issue event —
     * a missing issue id means there is nothing to act on or dedupe against.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromWebhook(array $body): ?self
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];

        $id = $issue['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $environment = is_array($payload['environment'] ?? null)
            ? ($payload['environment']['name'] ?? null)
            : null;

        return new self(
            event: (string) ($body['event'] ?? ''),
            id: $id,
            ref: isset($issue['ref']) ? (int) $issue['ref'] : null,
            type: (string) ($issue['type'] ?? 'exception'),
            title: (string) ($issue['title'] ?? 'Untitled issue'),
            status: (string) ($issue['status'] ?? 'open'),
            priority: (string) ($issue['priority'] ?? 'none'),
            url: (string) ($issue['url'] ?? ''),
            details: is_array($issue['details'] ?? null) ? $issue['details'] : [],
            environment: is_string($environment) ? $environment : null,
            applicationId: isset($payload['application_id']) ? (string) $payload['application_id'] : null,
        );
    }

    public function isException(): bool
    {
        return $this->type === 'exception';
    }

    public function isPerformance(): bool
    {
        return $this->type === 'performance';
    }

    /**
     * The performance subtype — slow-route, slow-job, slow-command,
     * slow-scheduled-task — or null for exceptions.
     */
    public function subtype(): ?string
    {
        $subtype = $this->details['type'] ?? null;

        return $this->isPerformance() && is_string($subtype) ? $subtype : null;
    }

    /**
     * Whether Laravel's handler caught this exception. Handled exceptions are
     * often deliberate, so the healer gates on this separately.
     */
    public function isHandled(): bool
    {
        return (bool) ($this->details['handled'] ?? false);
    }

    public function exceptionClass(): string
    {
        $class = $this->details['class'] ?? null;

        if (is_string($class) && $class !== '') {
            return $class;
        }

        return $this->isPerformance()
            ? 'performance:'.($this->subtype() ?? 'unknown')
            : 'unknown';
    }

    public function exceptionMessage(): string
    {
        $message = $this->details['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $this->title;
    }

    /**
     * A human-readable name for what broke, used in the audit log and PR title.
     */
    public function subject(): string
    {
        if ($this->isPerformance()) {
            return trim(($this->subtype() ?? 'performance').' '.$this->target());
        }

        return $this->exceptionClass();
    }

    /**
     * The route, job, command, or task a performance issue points at.
     */
    public function target(): string
    {
        $methods = $this->details['methods'] ?? null;

        if (is_array($methods) && $methods !== []) {
            return implode('|', $methods).' '.($this->details['path'] ?? '');
        }

        foreach (['path', 'name', 'action'] as $key) {
            if (is_string($this->details[$key] ?? null) && $this->details[$key] !== '') {
                return $this->details[$key];
            }
        }

        return $this->title;
    }

    /**
     * A stable, short, human-readable branch suffix for this issue.
     */
    public function branchSuffix(): string
    {
        $ref = $this->ref !== null ? (string) $this->ref : substr(md5($this->id), 0, 6);

        return 'nw-'.$ref.'-'.substr(md5($this->id), 0, 6);
    }

    public function label(): string
    {
        return $this->ref !== null ? "#{$this->ref}" : $this->id;
    }

    /**
     * Whether this issue's priority meets the configured floor.
     */
    public function meetsPriority(string $minimum): bool
    {
        $floor = array_search($minimum, self::PRIORITIES, strict: true);
        $actual = array_search($this->priority, self::PRIORITIES, strict: true);

        // An unrecognised priority on either side is never a reason to drop a
        // real production issue on the floor.
        if ($floor === false || $actual === false) {
            return true;
        }

        return $actual >= $floor;
    }

    /**
     * The facts Nightwatch sent, rendered as a markdown block. Shared by the
     * agent prompt and the PR body so both describe the same evidence.
     */
    public function describe(): string
    {
        $lines = [
            "**Nightwatch issue:** {$this->label()} — {$this->title}",
        ];

        if ($this->url !== '') {
            $lines[] = "**Dashboard:** {$this->url}";
        }

        if ($this->environment !== null) {
            $lines[] = "**Environment:** {$this->environment}";
        }

        $lines[] = "**Priority:** {$this->priority}";
        $lines[] = '';

        if ($this->isPerformance()) {
            $lines[] = '**Problem type:** '.($this->subtype() ?? 'performance');
            $lines[] = "**Affected:** {$this->target()}";

            if (is_string($this->details['action'] ?? null)) {
                $lines[] = "**Action:** {$this->details['action']}";
            }

            if (is_string($this->details['cron'] ?? null)) {
                $lines[] = "**Schedule:** {$this->details['cron']}".
                    (is_string($this->details['timezone'] ?? null) ? " ({$this->details['timezone']})" : '');
            }

            if (isset($this->details['duration'], $this->details['threshold'])) {
                $lines[] = "**Measured duration:** {$this->details['duration']}ms".
                    " (threshold: {$this->details['threshold']}ms)";
            }

            return implode("\n", $lines);
        }

        $lines[] = '**Exception:** `'.$this->exceptionClass().'`';
        $lines[] = '**Message:** '.$this->exceptionMessage();

        if (is_string($this->details['file'] ?? null)) {
            $line = isset($this->details['line']) ? ':'.$this->details['line'] : '';
            $lines[] = "**Location:** {$this->details['file']}{$line}";
        }

        $lines[] = '**Handled by Laravel:** '.($this->isHandled() ? 'yes' : 'no');

        $versions = array_filter([
            is_string($this->details['laravel_version'] ?? null) ? "Laravel {$this->details['laravel_version']}" : null,
            is_string($this->details['php_version'] ?? null) ? "PHP {$this->details['php_version']}" : null,
        ]);

        if ($versions !== []) {
            $lines[] = '**Runtime:** '.implode(', ', $versions);
        }

        return implode("\n", $lines);
    }
}
