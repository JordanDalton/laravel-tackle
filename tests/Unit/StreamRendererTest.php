<?php

use Tackle\Support\StreamRenderer;

/**
 * Feed a response through the renderer one character at a time — the worst
 * case a real stream can produce — and collect what it wants rendered.
 *
 * @return list<array<string, mixed>>
 */
function render(string $response, bool $tables = true): array
{
    $renderer = new StreamRenderer($tables);
    $ops = [];

    foreach (str_split($response) as $char) {
        foreach ($renderer->push($char) as $op) {
            $ops[] = $op;
        }
    }

    foreach ($renderer->flush() as $op) {
        $ops[] = $op;
    }

    return $ops;
}

function renderedText(array $ops): string
{
    return implode('', array_map(
        fn ($op) => $op['type'] === 'text' ? $op['text'] : '',
        $ops,
    ));
}

function renderedTables(array $ops): array
{
    return array_values(array_filter($ops, fn ($op) => $op['type'] === 'table'));
}

it('passes prose through byte for byte', function () {
    $response = "Here is what I found.\n\nThe cache is warmed on boot.\n";

    expect(renderedText(render($response)))->toBe($response);
    expect(renderedTables(render($response)))->toBe([]);
});

it('does not hold back prose while it streams', function () {
    // Every character of a plain line should be released as it arrives, not
    // pooled until the newline — that is the difference between a live stream
    // and a stuttering one.
    $renderer = new StreamRenderer;

    expect(renderedText($renderer->push('Check')))->toBe('Check');
    expect(renderedText($renderer->push('ing')))->toBe('ing');
});

it('turns a markdown table into a table operation', function () {
    $ops = render(<<<'MD'
    Your users:

    | id | name  | email           |
    |----|-------|-----------------|
    | 1  | Ada   | ada@example.com |
    | 2  | Grace | grace@test.dev  |

    That is everyone.
    MD);

    $tables = renderedTables($ops);

    expect($tables)->toHaveCount(1)
        ->and($tables[0]['headers'])->toBe(['id', 'name', 'email'])
        ->and($tables[0]['rows'])->toBe([
            ['1', 'Ada', 'ada@example.com'],
            ['2', 'Grace', 'grace@test.dev'],
        ]);

    // The prose around it survives, and none of the pipes leak into it.
    expect(renderedText($ops))
        ->toContain('Your users:')
        ->toContain('That is everyone.')
        ->not->toContain('|');
});

it('renders a table that runs to the end of the response', function () {
    $ops = render("| a | b |\n|---|---|\n| 1 | 2 |");

    expect(renderedTables($ops))->toHaveCount(1)
        ->and(renderedTables($ops)[0]['rows'])->toBe([['1', '2']]);
});

it('leaves a table inside a fenced code block alone', function () {
    $response = "Example:\n\n```markdown\n| a | b |\n|---|---|\n| 1 | 2 |\n```\n";

    expect(renderedTables(render($response)))->toBe([]);
    expect(renderedText(render($response)))->toBe($response);
});

it('does not invent a table out of a run of pipes', function () {
    // No separator row — this is not a table, and reshaping it would be
    // inventing structure the model did not write.
    $response = "| this is just\n| some piped text\n";

    expect(renderedTables(render($response)))->toBe([]);
    expect(renderedText(render($response)))->toBe($response);
});

it('pads a ragged row rather than dropping it', function () {
    $ops = render("| a | b | c |\n|---|---|---|\n| 1 |\n");

    expect(renderedTables($ops)[0]['rows'])->toBe([['1', '', '']]);
});

it('handles two tables in one response', function () {
    $ops = render("| a |\n|---|\n| 1 |\n\ntext\n\n| b |\n|---|\n| 2 |\n");

    expect(renderedTables($ops))->toHaveCount(2);
    expect(renderedText($ops))->toContain('text');
});

it('streams everything as text when table rendering is off', function () {
    $response = "| a | b |\n|---|---|\n| 1 | 2 |\n";

    expect(renderedTables(render($response, tables: false)))->toBe([]);
    expect(renderedText(render($response, tables: false)))->toBe($response);
});
