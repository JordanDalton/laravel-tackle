<?php

it('links to the deployed html documentation paths', function () {
    $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');

    preg_match_all(
        '#https://tackle\.jordandalton\.com(?<path>/[^)\s"<>]*)?#',
        $readme,
        $matches,
    );

    $invalid = collect($matches['path'])
        ->filter()
        ->map(fn (string $path) => explode('#', $path, 2)[0])
        ->reject(fn (string $path) => str_ends_with($path, '.html'))
        ->values()
        ->all();

    expect($invalid)->toBe([]);
});
