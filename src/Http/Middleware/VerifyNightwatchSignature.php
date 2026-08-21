<?php

namespace Tackle\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the `Nightwatch-Signature` header on an inbound webhook.
 *
 * Nightwatch signs the raw request body with HMAC-SHA256 using the signing
 * secret from the application's webhook settings. This route dispatches an
 * autonomous agent, so an unverifiable request is refused rather than trusted.
 *
 * @see https://nightwatch.laravel.com/docs/webhooks
 */
class VerifyNightwatchSignature
{
    public const HEADER = 'Nightwatch-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('tackle.nightwatch.secret');

        if (! is_string($secret) || $secret === '') {
            // Refusing beats healing on unauthenticated input: without a secret
            // anyone who finds the URL could dispatch an agent at the codebase.
            logger()->error('Tackle Nightwatch: webhook rejected — no signing secret configured (TACKLE_NIGHTWATCH_SECRET).');

            abort(500, 'Nightwatch webhook signing secret is not configured.');
        }

        $provided = $request->header(self::HEADER);

        if (! is_string($provided) || $provided === '') {
            abort(403, 'Missing Nightwatch signature.');
        }

        // Tolerate an algorithm prefix ("sha256=...") so a change in how the
        // header is formatted does not silently start dropping every webhook.
        if (str_contains($provided, '=')) {
            $provided = substr($provided, strrpos($provided, '=') + 1);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $provided)) {
            logger()->warning('Tackle Nightwatch: webhook rejected — signature mismatch.');

            abort(403, 'Invalid Nightwatch signature.');
        }

        return $next($request);
    }
}
