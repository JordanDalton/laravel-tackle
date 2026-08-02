<?php

namespace Tackle\Support;

/**
 * Refuses every confirmation. The safe default wherever there is no terminal:
 * headless runs, the healer's queue worker, and MCP stdio sessions.
 *
 * Nothing that would have needed a human "yes" happens without one.
 */
class DenyInteraction extends NonInteractiveInteraction
{
    public function confirm(string $label, bool $default = true, ?string $hint = null): bool
    {
        $this->denied++;

        return false;
    }
}
