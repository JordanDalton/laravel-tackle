<?php

namespace Tackle\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Commit an explicit set of repository-relative files without disturbing or
 * including unrelated changes that were already present in the workspace.
 */
class ScopedGitCommit
{
    public function __construct(private readonly PathGuard $guard) {}

    /**
     * Validate and normalise file paths supplied by the agent.
     *
     * @param  array<mixed>  $requested
     * @return list<string>
     */
    public function files(array $requested): array
    {
        $files = [];
        $workspace = $this->guard->workspace();

        foreach ($requested as $raw) {
            if (! is_string($raw)) {
                throw new InvalidArgumentException('files must contain only repository-relative file paths.');
            }

            $file = str_replace('\\', '/', trim($raw));
            $file = preg_replace('#^(?:\./)+#', '', $file) ?? $file;

            if ($file === '' || $file === '.' || str_starts_with($file, '/') || str_contains($file, "\0")) {
                throw new InvalidArgumentException("Invalid commit path '{$raw}'. Pass individual repository-relative files, not directories.");
            }

            if (preg_match('#(^|/)\.\.(/|$)#', $file)) {
                throw new InvalidArgumentException("Invalid commit path '{$raw}': parent-directory traversal is not allowed.");
            }

            if ($refusal = $this->guard->checkWrite($file)) {
                throw new InvalidArgumentException($refusal);
            }

            if (is_dir($workspace.'/'.$file)) {
                throw new InvalidArgumentException("Commit path '{$file}' is a directory. List each changed file explicitly.");
            }

            $files[] = $file;
        }

        $files = array_values(array_unique($files));

        if ($files === []) {
            throw new InvalidArgumentException('files is required and must list each file the agent changed.');
        }

        return $files;
    }

    /** @param  list<string>  $files */
    public function status(array $files): ProcessResult
    {
        return Process::path($this->guard->workspace())->run([
            'git', '--literal-pathspecs', 'status', '--porcelain', '--untracked-files=all', '--', ...$files,
        ]);
    }

    /** @param  list<string>  $files */
    public function stage(array $files): ProcessResult
    {
        return Process::path($this->guard->workspace())->run([
            'git', '--literal-pathspecs', 'add', '--', ...$files,
        ]);
    }

    /**
     * Commit only the selected paths. --only is intentional: an unrelated
     * change may already be staged, especially in symlink-based deployments.
     *
     * @param  list<string>  $files
     */
    public function commit(string $message, array $files): ProcessResult
    {
        $workspace = $this->guard->workspace();
        $identity = Process::path($workspace)->run(['git', 'var', 'GIT_AUTHOR_IDENT']);
        $command = ['git', '--literal-pathspecs'];

        if ($identity->failed()) {
            array_push(
                $command,
                '-c', 'user.name=Tackle Agent',
                '-c', 'user.email=tackle-agent@users.noreply.github.com',
            );
        }

        array_push($command, 'commit', '--only', '-m', $message, '--', ...$files);

        return Process::path($workspace)->run($command);
    }

    /** @param  list<string>  $files */
    public function diff(array $files, bool $stat = false): ProcessResult
    {
        $command = ['git', '--literal-pathspecs', 'diff', 'HEAD'];

        if ($stat) {
            $command[] = '--stat';
        }

        array_push($command, '--', ...$files);

        return Process::path($this->guard->workspace())->run($command);
    }
}
