<?php

namespace Tackle\Tests\Fakes;

use BadMethodCallException;
use Illuminate\Broadcasting\Channel;
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
        string $prompt,
        array $attachments = [],
        array|string|null $provider = null,
        ?string $model = null,
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
        string $prompt,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null,
    ): AgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function queue(
        string $prompt,
        array $attachments = [],
        array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function broadcast(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        ?string $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function broadcastNow(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }

    public function broadcastOnQueue(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new BadMethodCallException('FakeCodingAgent only supports stream().');
    }
}
