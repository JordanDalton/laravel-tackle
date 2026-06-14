<?php

namespace Tackle\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

class GitHubClient
{
    private ?string $resolvedToken = null;

    public function token(): ?string
    {
        if ($this->resolvedToken !== null) {
            return $this->resolvedToken ?: null;
        }

        $token = config('ai-code.github.token') ?: $this->resolveGhToken();
        $this->resolvedToken = $token ?? '';

        return $token ?: null;
    }

    public function repo(): ?string
    {
        return config('ai-code.github.repo') ?: null;
    }

    public function configured(): bool
    {
        return $this->token() !== null && $this->repo() !== null;
    }

    public function get(string $path, array $params = []): Response
    {
        return Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->get("https://api.github.com/{$path}", $params);
    }

    public function post(string $path, array $data = []): Response
    {
        return Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->post("https://api.github.com/{$path}", $data);
    }

    private function resolveGhToken(): ?string
    {
        try {
            $result = Process::run(['gh', 'auth', 'token']);
            $token  = trim($result->output());
            return ($result->successful() && $token !== '') ? $token : null;
        } catch (Throwable) {
            return null;
        }
    }
}
