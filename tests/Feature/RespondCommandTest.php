<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Contracts\CodingAgent;
use Tackle\Tests\Fakes\FakeCodingAgent;

function fakeRespondPr(array $overrides = []): void
{
    Http::fake(function ($request) use ($overrides) {
        if (str_contains($request->url(), '/pulls/comments/555')) {
            return Http::response([
                'id' => 555,
                'user' => ['login' => 'jordan'],
                'body' => '@tackle fix this',
                'path' => 'app/A.php',
                'line' => 5,
            ], 200);
        }

        if (str_contains($request->url(), '/pulls/42/comments')) {
            return Http::response([], 200);
        }

        if ($request->hasHeader('Accept', 'application/vnd.github.v3.diff')) {
            return Http::response("diff --git a/a.php b/a.php\n", 200);
        }

        if ($request->method() === 'POST') {
            return Http::response(['id' => 1], 201);
        }

        return Http::response(array_merge([
            'title' => 'T',
            'body' => '',
            'head' => ['ref' => 'feat', 'sha' => 'abc9999', 'repo' => ['full_name' => 'acme/app']],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/acme/app/pull/42',
        ], $overrides), 200);
    });
}

beforeEach(function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');
});

it('ai:respond command is registered', function () {
    expect(Artisan::all())->toHaveKey('ai:respond');
});

it('requires a numeric --pr', function () {
    $this->artisan('ai:respond', ['--comment-id' => '5'])
        ->expectsOutputToContain('--pr option is required')
        ->assertExitCode(1);
});

it('requires a numeric --comment-id', function () {
    $this->artisan('ai:respond', ['--pr' => '42'])
        ->expectsOutputToContain('--comment-id option is required')
        ->assertExitCode(1);
});

it('rejects an unknown --comment-type', function () {
    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '5', '--comment-type' => 'discussion'])
        ->expectsOutputToContain('must be review or issue')
        ->assertExitCode(1);
});

it('refuses fork PRs and replies instead of pushing', function () {
    fakeRespondPr(['head' => ['ref' => 'feat', 'sha' => 'abc9999', 'repo' => ['full_name' => 'stranger/fork']]]);

    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '555'])
        ->expectsOutputToContain('from a fork')
        ->assertExitCode(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'pulls/42/comments/555/replies')
        && str_contains($request->data()['body'], 'fork'));
});

it('refuses to run when the checkout does not match the PR head', function () {
    fakeRespondPr();

    Process::fake(['*' => Process::result("differentsha000\n")]);

    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '555'])
        ->expectsOutputToContain('does not match the PR head')
        ->assertExitCode(1);
});

// ---------------------------------------------------------------------------
// ai:respond — JSON output
// ---------------------------------------------------------------------------

/**
 * Decode the JSON document the command wrote to stdout. Under Artisan::call
 * both streams share one buffer, so the stderr diagnostics are trimmed off.
 */
function respondJson(string $output): ?array
{
    $start = strpos($output, '{');

    return $start === false ? null : json_decode(substr($output, $start), true);
}

it('rejects an unknown --output format', function () {
    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '555', '--output' => 'yaml'])
        ->expectsOutputToContain('Invalid --output')
        ->assertExitCode(1);
});

it('ai:respond --output=json reports a completed no-op with the reply posted', function () {
    fakeRespondPr();

    Process::fake([
        '*rev-parse*' => Process::result("abc9999\n"), // checkout matches the PR head
        '*' => Process::result(''),                    // git status: no changes
    ]);

    app()->instance(CodingAgent::class, new FakeCodingAgent([
        new TextDelta('e', 'm', 'Nothing to change — that method is already covered.', 0),
        new StreamEnd('e', 'stop', new Usage(1500, 200), 0),
    ]));

    $exit = Artisan::call('ai:respond', ['--pr' => '42', '--comment-id' => '555', '--output' => 'json']);
    $json = respondJson(Artisan::output());

    expect($exit)->toBe(0)
        ->and($json)->toBeArray()
        ->and($json['ok'])->toBeTrue()
        ->and($json['outcome'])->toBe('completed')
        ->and($json['error'])->toBeNull()
        ->and($json['pr_number'])->toBe(42)
        ->and($json['comment_id'])->toBe(555)
        ->and($json['reply_posted'])->toBeTrue()
        ->and($json['pushed'])->toBeFalse()
        ->and($json['usage']['input_tokens'])->toBe(1500)
        ->and($json['usage']['output_tokens'])->toBe(200);
});

it('ai:respond --output=json emits a document when refusing a fork PR', function () {
    fakeRespondPr(['head' => ['ref' => 'feat', 'sha' => 'abc9999', 'repo' => ['full_name' => 'stranger/fork']]]);

    $exit = Artisan::call('ai:respond', ['--pr' => '42', '--comment-id' => '555', '--output' => 'json']);
    $json = respondJson(Artisan::output());

    expect($exit)->toBe(1)
        ->and($json['ok'])->toBeFalse()
        ->and($json['outcome'])->toBe('error')
        ->and($json['error'])->toContain('from a fork')
        ->and($json['pr_number'])->toBe(42)
        ->and($json['comment_id'])->toBe(555)
        ->and($json['reply_posted'])->toBeTrue()
        ->and($json['pushed'])->toBeFalse()
        ->and($json['usage']['input_tokens'])->toBe(0);
});
