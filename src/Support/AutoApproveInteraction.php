<?php

namespace Tackle\Support;

/**
 * Approves every confirmation. Only ever bound behind an explicit --yes, since
 * it green-lights destructive Artisan commands, shell execution under
 * shell=approve, and pushes — with nobody watching.
 */
class AutoApproveInteraction extends NonInteractiveInteraction
{
    public function confirm(string $label, bool $default = true, ?string $hint = null): bool
    {
        return true;
    }
}
