<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

/**
 * Composer with the dangerous parts fenced off. Read-only subcommands run
 * freely; dependency mutations always run with --no-scripts (lifecycle
 * scripts are arbitrary project PHP — the same path ComposerScriptGuard
 * blocks on RunShell). Scripts can only be re-enabled by a human answering
 * a terminal prompt, after the lockfile change has been reviewed.
 */
class RunComposer extends AbstractTool
{
    private const READ_ONLY = [
        'show', 'outdated', 'why', 'why-not', 'depends', 'prohibits',
        'licenses', 'validate', 'audit', 'check-platform-reqs', 'suggests',
    ];

    private const MUTATING = [
        'install', 'update', 'require', 'remove', 'dump-autoload',
    ];

    private const MAX_OUTPUT = 30000;

    public function __construct(
        private PathGuard $guard,
        private ?InteractionPolicy $interaction = null,
    ) {}

    public function description(): string
    {
        return 'Run a composer subcommand in the workspace. Read-only subcommands ('
            .implode(', ', self::READ_ONLY).') run freely. Dependency mutations ('
            .implode(', ', self::MUTATING).') always run with --no-scripts — lifecycle scripts '
            .'(post-update-cmd, package:discover, …) are suppressed. To run them after the lockfile '
            .'change has been reviewed, call again with allow_scripts=true; the user is asked to '
            .'approve in the terminal. Use why-not to diagnose a failed resolution: it names the '
            .'packages whose constraints block the upgrade.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subcommand' => $schema->string()
                ->description('The composer subcommand, e.g. "update", "why-not", "show".')
                ->required(),
            'args' => $schema->string()
                ->description('Arguments and flags, e.g. "laravel/framework ^12.0" or "phpunit/phpunit --with-all-dependencies".'),
            'allow_scripts' => $schema->boolean()
                ->description('Ask the user for permission to run lifecycle scripts with this command. Only honoured when a user is present to approve; otherwise scripts stay suppressed.'),
        ];
    }

    public function handle(Request $request): string
    {
        $subcommand = trim($request->string('subcommand', ''));

        if (! in_array($subcommand, self::READ_ONLY, strict: true)
            && ! in_array($subcommand, self::MUTATING, strict: true)) {
            return "Subcommand '{$subcommand}' is not permitted. Allowed read-only: "
                .implode(', ', self::READ_ONLY).'. Allowed mutating (scripts suppressed): '
                .implode(', ', self::MUTATING).'.';
        }

        $args = preg_split('/\s+/', trim($request->string('args', '')), flags: PREG_SPLIT_NO_EMPTY);

        foreach ($args as $arg) {
            if (preg_match('/^(--working-dir(=.*)?|-d|--global|-g)$/', $arg)) {
                return "Flag '{$arg}' is not permitted — composer must run inside the workspace.";
            }
        }

        $command = ['composer', $subcommand, ...$args, '--no-interaction', '--no-ansi'];
        $scriptsSuppressed = false;

        if (in_array($subcommand, self::MUTATING, strict: true)) {
            $scriptsSuppressed = ! ($request->boolean('allow_scripts', false) && $this->userApprovedScripts($subcommand, $args));

            if ($scriptsSuppressed) {
                $command[] = '--no-scripts';
            }
        }

        $result = Process::path($this->guard->workspace())
            ->timeout(600)
            ->run($command);

        // Composer writes progress and errors to stderr — always include it.
        $output = trim($result->output()."\n".$result->errorOutput());

        if ($result->failed()) {
            $output = "Command failed (exit {$result->exitCode()}).\n".$output;
        }

        if ($scriptsSuppressed) {
            $output = "(Lifecycle scripts were suppressed with --no-scripts. Re-run with allow_scripts=true once the change is reviewed if scripts such as package:discover need to run.)\n".$output;
        }

        return $this->truncate(Utf8::clean($output)) ?: '(Composer ran with no output.)';
    }

    private function userApprovedScripts(string $subcommand, array $args): bool
    {
        $interaction = $this->interaction();

        if (! $interaction->isInteractive()) {
            return false;
        }

        return $interaction->confirm(
            label: 'The agent wants to run composer WITH lifecycle scripts (arbitrary project PHP). Allow it?',
            default: false,
            hint: trim("composer {$subcommand} ".implode(' ', $args)),
        );
    }

    private function truncate(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT) {
            return $output;
        }

        return substr($output, 0, 15000)
            ."\n\n… [output truncated] …\n\n"
            .substr($output, -10000);
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
