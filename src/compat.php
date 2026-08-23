<?php

/*
 * Compatibility shims for the laravel/ai version range Tackle supports
 * (>=0.1 <0.11). Loaded via composer autoload "files" so the symbols exist
 * before any agent class is declared.
 *
 * HasProviderOptions (used by the prompt-caching trait) only appeared in a
 * later laravel/ai; on older versions an agent's `implements HasProviderOptions`
 * would be a compile-time fatal. Declare a stub with the exact FQCN when the
 * real interface is absent — inert there (those versions never check for it),
 * satisfied by the real one everywhere else.
 */

namespace Laravel\Ai\Contracts;

if (! interface_exists(HasProviderOptions::class)) {
    interface HasProviderOptions
    {
        /**
         * @return array<string, mixed>
         */
        public function providerOptions($provider): array;
    }
}
