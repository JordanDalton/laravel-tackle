<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tackle\Commands\CodeCommand;
use Tackle\Support\StreamRenderer;

function codeOutputCommand(): array
{
    $buffer = new BufferedOutput;
    $command = app(CodeCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return [$command, $buffer];
}

it('writes streamed prose once including literal markup and long responses', function () {
    [$command, $buffer] = codeOutputCommand();
    $renderer = new StreamRenderer;
    $text = "These aren't untracked — here is the explanation.\n\n".str_repeat("<info>Literal content</info> ✅\n", 80);
    foreach (mb_str_split($text, 7) as $delta) {
        (new ReflectionMethod($command, 'render'))->invoke($command, $renderer->push($delta));
    }
    (new ReflectionMethod($command, 'render'))->invoke($command, $renderer->flush());
    (new ReflectionMethod($command, 'closeStream'))->invoke($command);
    (new ReflectionMethod($command, 'closeStream'))->invoke($command);

    expect($buffer->fetch())->toBe("\n".$text);
});

it('closes prose before a tool and resumes without replaying it', function () {
    [$command, $buffer] = codeOutputCommand();
    $render = new ReflectionMethod($command, 'render');
    $close = new ReflectionMethod($command, 'closeStream');
    $render->invoke($command, [['type' => 'text', 'text' => 'Checking...']]);
    $close->invoke($command);
    $command->line('Tool finished');
    $render->invoke($command, [['type' => 'text', 'text' => 'Done!']]);
    $close->invoke($command);

    expect($buffer->fetch())->toBe("\nChecking...\nTool finished\n\nDone!\n");
});

it('suppresses the diff summary when existing modifications did not change', function () {
    Process::fake(['*' => Process::result(output: 'pre-existing patch')]);
    [$command, $buffer] = codeOutputCommand();
    $before = (new ReflectionMethod($command, 'gitDiffFingerprint'))->invoke($command);
    (new ReflectionMethod($command, 'showGitDiff'))->invoke($command, $before);

    expect($buffer->fetch())->toBe('');
    Process::assertNotRan(fn ($process) => in_array('--stat=80,60,10', $process->command, true));
});

it('detects content changes even when diff line counts would match', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: "-old\n+first"))
        ->push(Process::result(output: "-old\n+other"))]);
    [$command] = codeOutputCommand();
    $fingerprint = new ReflectionMethod($command, 'gitDiffFingerprint');

    expect($fingerprint->invoke($command))->not->toBe($fingerprint->invoke($command));
});

it('does not print a diff for non-git directories', function () {
    Process::fake(['*' => Process::result(exitCode: 128)]);
    [$command, $buffer] = codeOutputCommand();
    $before = (new ReflectionMethod($command, 'gitDiffFingerprint'))->invoke($command);
    (new ReflectionMethod($command, 'showGitDiff'))->invoke($command, $before);

    expect($before)->toBeNull()->and($buffer->fetch())->toBe('');
});

it('prints a bounded summary when the tracked diff changes during a turn', function () {
    Prompt::fake();
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: 'after turn'))
        ->push(Process::result(output: ' app.php | 2 +-'))]);
    [$command] = codeOutputCommand();
    (new ReflectionMethod($command, 'showGitDiff'))->invoke($command, hash('sha256', 'before turn'));

    Process::assertRan(fn ($process) => in_array('--stat=80,60,10', $process->command, true));
    Prompt::assertOutputContains('Uncommitted changes (includes earlier edits)');
    Prompt::assertOutputContains('app.php');
});

it('does not fail the conversation if git cannot run', function () {
    Process::fake(fn () => throw new RuntimeException('Process unavailable'));
    [$command] = codeOutputCommand();

    expect((new ReflectionMethod($command, 'gitDiffFingerprint'))->invoke($command))->toBeNull();
});
