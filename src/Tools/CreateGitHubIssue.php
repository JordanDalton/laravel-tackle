<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Throwable;

class CreateGitHubIssue extends AbstractTool
{
    public function __construct(private GitHubClient $client) {}

    public function description(): string
    {
        return 'Create a new GitHub issue in the configured repository. Use this to file bugs discovered while reading code, track planned work, or create issues the user asks for.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Issue title.')
                ->required(),
            'body' => $schema->string()
                ->description('Issue description — include context, reproduction steps, or any relevant details.'),
            'labels' => $schema->array()
                ->description('Optional list of label names to apply, e.g. ["bug", "enhancement"].'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! $this->client->configured()) {
            return 'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.';
        }

        $title = (string) $request->string('title', '');

        if (trim($title) === '') {
            return 'title is required.';
        }

        $body   = (string) $request->string('body', '');
        $labels = $request->array('labels', []);
        $repo   = $this->client->repo();

        $payload = ['title' => $title];

        if ($body !== '') {
            $payload['body'] = $body;
        }

        if (! empty($labels)) {
            $payload['labels'] = array_values($labels);
        }

        try {
            $response = $this->client->post("repos/{$repo}/issues", $payload);

            if (! $response->successful()) {
                $error = $response->json('message', 'unknown error');
                return "Failed to create issue: {$error}";
            }

            $number = $response->json('number');
            $url    = $response->json('html_url');

            return "Created GitHub issue #{$number}: {$url}";
        } catch (Throwable $e) {
            return 'Error creating issue: ' . $e->getMessage();
        }
    }
}
