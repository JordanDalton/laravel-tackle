<?php

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tackle\Support\Reporting\JsonReporter;

function reporter(?BufferedOutput &$buffer = null): JsonReporter
{
    $buffer = new BufferedOutput;

    return new JsonReporter(new OutputStyle(new ArrayInput([]), $buffer));
}

function reportedText(JsonReporter $reporter, BufferedOutput $buffer): string
{
    $reporter->finish(['ok' => true]);

    return json_decode($buffer->fetch(), true)['text'];
}

it('separates prose from consecutive turns', function () {
    // Seen on a real run: "Let me read the file first.The method already has a
    // docblock". Two turns, concatenated mid-sentence.
    $reporter = reporter($buffer);

    $reporter->text('Let me read the file first.');
    $reporter->toolCall('ReadFile', ['path' => 'a.php']);
    $reporter->toolResult('ReadFile', '...');
    $reporter->text('The method already has a docblock.');

    expect(reportedText($reporter, $buffer))
        ->toBe("Let me read the file first.\n\nThe method already has a docblock.");
});

it('leaves a single turn untouched', function () {
    $reporter = reporter($buffer);

    $reporter->text('One turn, ');
    $reporter->text('streamed in pieces.');

    expect(reportedText($reporter, $buffer))->toBe('One turn, streamed in pieces.');
});

it('does not open with a blank line when a tool call comes first', function () {
    $reporter = reporter($buffer);

    $reporter->toolCall('Glob', ['pattern' => '*.php']);
    $reporter->text('Found it.');

    expect(reportedText($reporter, $buffer))->toBe('Found it.');
});

it('does not double a break the model already wrote', function () {
    $reporter = reporter($buffer);

    $reporter->text("Reading.\n\n");
    $reporter->toolCall('ReadFile', ['path' => 'a.php']);
    $reporter->text('Done.');

    expect(reportedText($reporter, $buffer))->toBe("Reading.\n\nDone.");
});

it('does not spend the break on a whitespace delta', function () {
    $reporter = reporter($buffer);

    $reporter->text('First.');
    $reporter->toolCall('ReadFile', ['path' => 'a.php']);
    $reporter->text(' ');
    $reporter->text('Second.');

    // The stray space is absorbed by the break rather than stranded at the
    // start of the next paragraph.
    expect(reportedText($reporter, $buffer))->toBe("First.\n\nSecond.");
});
