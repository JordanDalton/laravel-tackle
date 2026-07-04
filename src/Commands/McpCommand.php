<?php

namespace Tackle\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Tool;
use Tackle\Mcp\McpServer;
use Tackle\Tools\AskUser;
use Tackle\Tools\ConfirmAction;

class McpCommand extends Command
{
    protected $signature = 'tackle:mcp';

    protected $description = 'Serve Tackle tools over the Model Context Protocol (stdio) for clients like Claude Code, Cursor, and Zed.';

    /**
     * Tools that require an interactive terminal and can never be served
     * over MCP — a client has no TTY to answer their prompts.
     */
    private const INTERACTIVE_TOOLS = [
        AskUser::class,
        ConfirmAction::class,
    ];

    public function handle(): int
    {
        $tools = $this->resolveTools();

        if ($tools === []) {
            fwrite(STDERR, "tackle:mcp: no tools configured — check config('tackle.mcp.tools').\n");

            return self::FAILURE;
        }

        $server = new McpServer($tools, 'laravel-tackle', $this->packageVersion());

        // STDOUT belongs to the protocol; anything else corrupts the stream.
        $server->run(STDIN, STDOUT);

        return self::SUCCESS;
    }

    /**
     * @return list<Tool>
     */
    private function resolveTools(): array
    {
        $tools = [];

        foreach (config('tackle.mcp.tools', []) as $class) {
            if (in_array($class, self::INTERACTIVE_TOOLS, true)) {
                fwrite(STDERR, "tackle:mcp: skipping {$class} — interactive tools cannot be served over MCP.\n");

                continue;
            }

            if (! class_exists($class) || ! is_subclass_of($class, Tool::class)) {
                fwrite(STDERR, "tackle:mcp: skipping {$class} — not a Tackle tool class.\n");

                continue;
            }

            $tools[] = $this->laravel->make($class);
        }

        return $tools;
    }

    private function packageVersion(): string
    {
        if (class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled('jordandalton/laravel-tackle')) {
            return InstalledVersions::getPrettyVersion('jordandalton/laravel-tackle') ?? 'dev';
        }

        return 'dev';
    }
}
