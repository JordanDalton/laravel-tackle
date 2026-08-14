<?php

namespace Tackle\Guards;

/**
 * `composer` runs arbitrary PHP through its scripts, so an allowlisted composer
 * in RunShell is an in-process code-execution path. This blocks the invocations
 * that fire scripts. Defense-in-depth, not containment.
 *
 * Register as a pre_tool hook matched to RunShell.
 * mode (tackle.guard.composer_scripts): 'block' (default) or 'off'.
 */
class ComposerScriptGuard extends AbstractGuard
{
    public function handle(array $payload): null|false|string
    {
        if ($this->mode('composer_scripts', 'block') === 'off') {
            return null;
        }

        $command = trim((string) (($payload['arguments'] ?? [])['command'] ?? ''));

        if (! preg_match('/^\S*composer\b/i', $command)) {
            return null;
        }

        // run-script / exec run project-defined PHP outright.
        if (preg_match('/\bcomposer\b.*\b(run-script|run|exec)\b/i', $command)) {
            return 'Refused by ComposerScriptGuard: `composer run-script`/`exec` executes arbitrary '
                .'project PHP, which bypasses the tool safety layer. Run the underlying command through '
                .'RunArtisan, RunTests, or RunShell directly so it is visible and gated.';
        }

        // install/update/require fire scripts unless suppressed.
        if (preg_match('/\bcomposer\b.*\b(install|update|require|remove)\b/i', $command)
            && ! preg_match('/--no-scripts\b/', $command)) {
            return 'Refused by ComposerScriptGuard: this composer command runs lifecycle scripts '
                .'(arbitrary PHP). Re-run it with --no-scripts if you only need the dependency change.';
        }

        return null;
    }
}
