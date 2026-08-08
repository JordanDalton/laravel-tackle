<?php

use Tackle\Review\FindingsParser;

it('parses a findings block into typed findings', function () {
    $text = <<<'TEXT'
    Here is my review.

    ```tackle-findings
    {"verdict": "needs_changes", "findings": [{"path": "app/Models/User.php", "line": 12, "severity": "critical", "message": "Null deref."}, {"path": "app/Http/Controllers/UserController.php", "line": 40, "severity": "warning", "message": "Missing validation."}]}
    ```
    TEXT;

    $review = (new FindingsParser)->parse($text);

    expect($review)->not->toBeNull()
        ->and($review->verdict)->toBe('needs_changes')
        ->and($review->findings)->toHaveCount(2)
        ->and($review->findings[0]->path)->toBe('app/Models/User.php')
        ->and($review->findings[0]->line)->toBe(12)
        ->and($review->findings[0]->severity)->toBe('critical')
        ->and($review->findings[0]->message)->toBe('Null deref.')
        ->and($review->findings[1]->severity)->toBe('warning');
});

it('returns null when no findings block is present', function () {
    expect((new FindingsParser)->parse('LGTM, no issues found.'))->toBeNull();
});

it('returns null when the block contains invalid JSON', function () {
    $text = "```tackle-findings\n{not json}\n```";

    expect((new FindingsParser)->parse($text))->toBeNull();
});

it('parses an empty findings array', function () {
    $text = "```tackle-findings\n{\"verdict\": \"lgtm\", \"findings\": []}\n```";

    $review = (new FindingsParser)->parse($text);

    expect($review->verdict)->toBe('lgtm')
        ->and($review->findings)->toBe([]);
});

it('drops malformed findings and normalizes unknown severities', function () {
    $json = json_encode([
        'verdict' => 'shipit',
        'findings' => [
            ['path' => '', 'line' => 3, 'severity' => 'critical', 'message' => 'no path'],
            ['path' => 'app/A.php', 'line' => 0, 'severity' => 'critical', 'message' => 'bad line'],
            ['path' => 'app/A.php', 'line' => 5, 'severity' => 'blocker', 'message' => 'odd severity'],
        ],
    ]);

    $review = (new FindingsParser)->parse("```tackle-findings\n{$json}\n```");

    expect($review->verdict)->toBe('lgtm_with_notes')
        ->and($review->findings)->toHaveCount(1)
        ->and($review->findings[0]->severity)->toBe('suggestion');
});

it('strips the findings block from display text', function () {
    $text = "The review body.\n\n```tackle-findings\n{\"verdict\": \"lgtm\", \"findings\": []}\n```";

    expect((new FindingsParser)->strip($text))->toBe('The review body.');
});

it('reports severity presence via has()', function () {
    $json = json_encode([
        'verdict' => 'needs_changes',
        'findings' => [
            ['path' => 'app/A.php', 'line' => 5, 'severity' => 'warning', 'message' => 'edge case'],
        ],
    ]);

    $review = (new FindingsParser)->parse("```tackle-findings\n{$json}\n```");

    expect($review->has('warning'))->toBeTrue()
        ->and($review->has('critical'))->toBeFalse();
});
