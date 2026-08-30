<?php

namespace Tackle\Support;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\RequestInterface;
use stdClass;

/**
 * Extends Anthropic prompt caching from the fixed prefix to the conversation.
 *
 * CachesInstructions marks the system block, which caches the two things that
 * never change — tool schemas and instructions — and nothing else. That is the
 * small half. The half that grows is the conversation: every file the agent
 * read, every test run, every prior tool result, all of it re-sent on the next
 * step because a step is a fresh request carrying the whole history.
 *
 * So an eleven-step run that ends in a one-line diff pays full price for the
 * same context eleven times. Marking the end of the message list on every
 * request makes each step read back what the previous step wrote (at ~10%) and
 * write only the delta, which is what caching was supposed to be doing.
 *
 * The seam is the outbound HTTP body rather than laravel/ai's gateway, on
 * purpose. laravel/ai reshapes its internals on most 0.x minors — the test
 * matrix exists because of it — while the Anthropic Messages wire format is
 * versioned and stable. Rewriting the request is the version-independent
 * option.
 *
 * Scope is exact: CachesInstructions arms this immediately before laravel/ai
 * builds the body, and the first Anthropic request after that consumes the
 * arming. An application's own Anthropic traffic is never touched.
 *
 * Measured against the live API, a step that previously sent 1,470 fresh
 * conversation tokens sent 3, reading 3,071 back and writing only the 1,459
 * of new context. Note the trade: a cache write bills at 1.25x, so a turn the
 * model finishes in a single step pays about 25% more on the conversation
 * than it would have. It comes back on the first re-read and every one after,
 * which is why this is on by default — agent turns are multi-step by nature,
 * and the runs worth worrying about are the long ones.
 */
class ConversationCache
{
    /**
     * Whether the next outbound Anthropic request came from a Tackle agent
     * that wants its conversation cached.
     */
    private static bool $armed = false;

    /**
     * The Http factories already carrying the middleware. Keyed by instance
     * rather than a flag: the factory is a singleton in production, but a new
     * one is built per application, and a process-wide flag would leave every
     * app after the first without middleware.
     *
     * @var \WeakMap<HttpFactory, true>|null
     */
    private static ?\WeakMap $registered = null;

    /**
     * Mark the next Anthropic request as ours. Called from
     * CachesInstructions::providerOptions(), which laravel/ai invokes while
     * assembling the request body — the same tick as the POST that follows.
     */
    public static function arm(): void
    {
        self::$armed = true;
    }

    public static function armed(): bool
    {
        return self::$armed;
    }

    public static function disarm(): void
    {
        self::$armed = false;
    }

    /**
     * Install the request middleware. Idempotent: the Http factory has no way
     * to remove global middleware, so registering twice would rewrite the body
     * twice.
     */
    public static function register(HttpFactory $http): void
    {
        self::$registered ??= new \WeakMap;

        if (isset(self::$registered[$http])) {
            return;
        }

        self::$registered[$http] = true;

        $http->globalRequestMiddleware(fn (RequestInterface $request) => self::handle($request));
    }

    /**
     * Rewrite an armed Anthropic messages request, and only that. Anything
     * unexpected — a different endpoint, an unparseable body, a shape we do
     * not recognise — is passed through untouched, because a missed
     * breakpoint costs money and a corrupted body costs the run.
     */
    public static function handle(RequestInterface $request): RequestInterface
    {
        if (! self::$armed) {
            return $request;
        }

        // The arming covers exactly one request whatever happens next, so a
        // build that never posts cannot leak onto someone else's call.
        self::$armed = false;

        if (! str_ends_with($request->getUri()->getPath(), '/messages')) {
            return $request;
        }

        $marked = self::mark((string) $request->getBody());

        if ($marked === null) {
            return $request;
        }

        return $request
            ->withBody(Utils::streamFor($marked))
            ->withHeader('Content-Length', (string) strlen($marked));
    }

    /**
     * Put a cache breakpoint on the final content block of the message list,
     * returning the rewritten body — or null when there is nothing to change.
     *
     * Anthropic caches the cumulative prefix up to a breakpoint and reuses the
     * longest cached prefix it can find, so a breakpoint at the very end means
     * the next step — which sends this exact history plus one more exchange —
     * reads all of it back and writes only what is new.
     *
     * One breakpoint is enough for a linear agent loop: step N's prefix is
     * always precisely what step N-1 wrote. That keeps the total at two of the
     * four Anthropic allows, leaving room for the system block.
     *
     * Prefixes below Anthropic's minimum cacheable length are ignored by the
     * API rather than rejected, so short conversations quietly cost nothing.
     *
     * Takes and returns JSON rather than an array because the round trip is
     * the dangerous part here, not the edit. Decoding to associative arrays
     * cannot tell {} from [], so a tool schema's empty `properties` object
     * comes back as an empty list and Anthropic rejects the entire request —
     * every step, before the first token. Decoding to objects keeps the two
     * apart.
     */
    public static function mark(string $json): ?string
    {
        $body = json_decode($json);

        if (! $body instanceof stdClass || ! isset($body->messages) || ! is_array($body->messages)) {
            return null;
        }

        // Someone has already placed breakpoints in the conversation. Adding
        // ours risks exceeding the limit of four and overrides a deliberate
        // choice, so leave it alone.
        if (self::alreadyMarked($body->messages)) {
            return null;
        }

        // Walk back to the last message that actually carries content: a
        // trailing message with an empty content array has no block to mark.
        foreach (array_reverse($body->messages) as $message) {
            if (! $message instanceof stdClass || ! isset($message->content)) {
                continue;
            }

            // String content is the API's shorthand for a single text block.
            // Expanding it changes nothing the model sees.
            if (is_string($message->content)) {
                if (trim($message->content) === '') {
                    continue;
                }

                $message->content = [(object) [
                    'type' => 'text',
                    'text' => $message->content,
                    'cache_control' => self::breakpoint(),
                ]];

                return self::encode($body);
            }

            if (! is_array($message->content) || $message->content === []) {
                continue;
            }

            $last = $message->content[array_key_last($message->content)];

            if (! $last instanceof stdClass) {
                continue;
            }

            $last->cache_control = self::breakpoint();

            return self::encode($body);
        }

        return null;
    }

    private static function breakpoint(): stdClass
    {
        return (object) ['type' => 'ephemeral'];
    }

    /** Null rather than a broken body if the graph will not re-encode. */
    private static function encode(stdClass $body): ?string
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /** @param  array<mixed>  $messages */
    private static function alreadyMarked(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = $message instanceof stdClass ? ($message->content ?? null) : null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if ($block instanceof stdClass && isset($block->cache_control)) {
                    return true;
                }
            }
        }

        return false;
    }
}
