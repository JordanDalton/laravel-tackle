<?php

namespace Tackle\Evals;

use Closure;
use Illuminate\Support\Facades\Process;

/**
 * Builds subprocess graders for eval cases — the ergonomic way to write your
 * own. Give it the file the agent was asked to fix and a snippet that sets two
 * booleans against the (possibly fixed) code:
 *
 *   - $target: the bug is fixed (the behaviour the case is about is now correct)
 *   - $happy:  a previously-correct behaviour still holds (guards against a
 *              "fix" that regresses something else)
 *
 * The snippet runs in a fresh PHP process that requires the file, so a fix that
 * leaves the file unparseable or throws is scored as a failure rather than
 * crashing the run, and cases never collide in one process.
 *
 *   Probe::subprocess('Calculator.php', '
 *       $c = new Calculator();
 *       $target = $c->divide(1, 0) === null;   // no longer throws
 *       $happy  = $c->divide(10, 2) === 5;      // still correct
 *   ');
 */
class Probe
{
    /**
     * @param  string|list<string>  $files  the source file(s) to require before
     *                                      the probe runs, in order — for multi-file cases where a bug lives
     *                                      in a collaborator, not the obvious file.
     */
    public static function subprocess(string|array $files, string $probe): Closure
    {
        $files = is_array($files) ? $files : [$files];

        return function (string $dir) use ($files, $probe): EvalGrade {
            $requires = '';
            foreach ($files as $file) {
                $requires .= 'require '.var_export($dir.'/'.ltrim($file, '/'), true).";\n";
            }

            $script = '<?php '.$requires
                .$probe."\n"
                .'echo "TARGET:".(($target ?? false) ? 1 : 0)." HAPPY:".(($happy ?? false) ? 1 : 0);';

            $scriptPath = $dir.'/__probe_'.substr(md5(implode(',', $files).$probe), 0, 8).'.php';
            file_put_contents($scriptPath, $script);

            $result = Process::timeout(30)->run('php '.escapeshellarg($scriptPath));
            @unlink($scriptPath);

            $out = trim($result->output().$result->errorOutput());

            if (! preg_match('/TARGET:([01])\s+HAPPY:([01])/', $out, $m)) {
                return new EvalGrade(fixed: false, brokeHappyPath: true, note: 'unparseable / fatal after fix');
            }

            $target = $m[1] === '1';
            $happy = $m[2] === '1';

            return new EvalGrade(
                fixed: $target,
                brokeHappyPath: ! $happy,
                note: $target ? ($happy ? '' : 'fixed but broke happy path') : 'not fixed',
            );
        };
    }
}
