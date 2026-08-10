<?php

namespace Tackle\Support;

use Tackle\Attributes\Workspace;
use Throwable;

/**
 * Commands the user has said "always allow" to, persisted per-project in
 * .tackle/permissions.json (commit it to share with the team, ignore it to
 * keep it personal).
 *
 * Matching is EXACT: approving "npm run build" allows that command and
 * nothing else — no prefix rules that quietly widen over time.
 */
class PermissionStore
{
    public function __construct(
        #[Workspace] private readonly PathGuard $guard,
    ) {}

    public function allows(string $command): bool
    {
        return in_array(trim($command), $this->all(), strict: true);
    }

    public function allow(string $command): void
    {
        $command = trim($command);

        if ($command === '' || $this->allows($command)) {
            return;
        }

        $all = [...$this->all(), $command];

        try {
            @mkdir(dirname($this->path()), 0755, true);
            file_put_contents($this->path(), json_encode(
                ['shell_allow' => array_values($all)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } catch (Throwable) {
            // A failed write means the user gets asked again next time.
        }
    }

    /** @return array<int, string> */
    public function all(): array
    {
        $raw = @file_get_contents($this->path());

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return array_values(array_filter(
            (array) ($data['shell_allow'] ?? []),
            fn ($entry) => is_string($entry) && $entry !== '',
        ));
    }

    private function path(): string
    {
        return $this->guard->workspace().'/.tackle/permissions.json';
    }
}
