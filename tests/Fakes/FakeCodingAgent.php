<?php

namespace Tackle\Tests\Fakes;

use BadMethodCallException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Stringable;
use Tackle\Contracts\CodingAgent;
use Throwable;

/**
 * Replays a scripted list of stream events instead of calling a provider.
 *
 * Any closure in the event list is invoked for its side effect rather than
 * yielded — that is how a test represents a tool doing something mid-stream,
 * such as asking for a confirmation.
 */
class FakeCodingAgent implements CodingAgent
{
    public function __construct(
        private array $events = [],
        private ?Throwable $throw = null,
    ) {}

    public function stream(
        mixed $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        return new StreamableAgentResponse('fake-invocation', function () {
            if ($this->throw !== null) {
                throw $this->throw;
            }

            foreach ($this->events as $event) {
                if ($event instanceof \Closure) {
                    $event();

                    continue;
                }

                yield $event;
            }
        }, new Meta('fake', 'fake-model'));
    }

    public function instructions(): Stringable|string
    {
        return 'fake agent';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [];
    }

    public function prompt(
        mixed $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function queue(
        mixed $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function broadcast(
        mixed $prompt,
        mixed $channels,
        array $attachments = [],
        bool $now = false,
        mixed $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function broadcastNow(
        mixed $prompt,
        mixed $channels,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function broadcastOnQueue(
        mixed $prompt,
        mixed $channels,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }
}
