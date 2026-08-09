<?php

use Laravel\Ai\Files\LocalImage;
use Tackle\Support\ImageAttachments;

function makeImage(string $relative): string
{
    $path = config('tackle.workspace').'/'.$relative;
    @mkdir(dirname($path), 0755, true);
    // Minimal PNG header bytes — content is irrelevant for extraction.
    file_put_contents($path, "\x89PNG\r\n\x1a\n");

    return $path;
}

it('extracts a plain image path and replaces it with a marker', function () {
    $path = makeImage('shot.png');

    [$prompt, $attachments] = ImageAttachments::extract("Fix the layout in {$path} please", config('tackle.workspace'));

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0])->toBeInstanceOf(LocalImage::class)
        ->and($prompt)->toBe('Fix the layout in [attached image: shot.png] please')
        ->and($prompt)->not->toContain('.png ');
});

it('extracts drag-and-drop paths with backslash-escaped spaces', function () {
    makeImage('Screen Shot 1.png');
    $escaped = config('tackle.workspace').'/Screen\\ Shot\\ 1.png';

    [$prompt, $attachments] = ImageAttachments::extract("look at {$escaped}", config('tackle.workspace'));

    expect($attachments)->toHaveCount(1)
        ->and($prompt)->toContain('[attached image: Screen Shot 1.png]');
});

it('extracts quoted paths with spaces', function () {
    $path = makeImage('error page.jpeg');

    [$prompt, $attachments] = ImageAttachments::extract("this errors: '{$path}'", config('tackle.workspace'));

    expect($attachments)->toHaveCount(1)
        ->and($prompt)->toContain('[attached image: error page.jpeg]');
});

it('resolves workspace-relative and @-mentioned paths', function () {
    makeImage('docs/mock.webp');

    [$prompt, $attachments] = ImageAttachments::extract('build this UI @docs/mock.webp', config('tackle.workspace'));

    expect($attachments)->toHaveCount(1)
        ->and($prompt)->toBe('build this UI [attached image: mock.webp]');
});

it('reports unreadable absolute image paths instead of failing silently', function () {
    [$prompt, $attachments, $unreadable] = ImageAttachments::extract('see /nope/missing.png', config('tackle.workspace'));

    expect($attachments)->toBe([])
        ->and($prompt)->toBe('see /nope/missing.png')
        ->and($unreadable)->toBe(['/nope/missing.png']);
});

it('treats unresolvable relative image mentions as ordinary prose', function () {
    [$prompt, $attachments, $unreadable] = ImageAttachments::extract('rename logo.png to brand.png', config('tackle.workspace'));

    expect($attachments)->toBe([])
        ->and($unreadable)->toBe([])
        ->and($prompt)->toBe('rename logo.png to brand.png');
});

it('ignores prompts with no image paths', function () {
    [$prompt, $attachments] = ImageAttachments::extract('add a slug to the Post model', config('tackle.workspace'));

    expect($attachments)->toBe([])
        ->and($prompt)->toBe('add a slug to the Post model');
});

it('handles the narrow no-break space macOS puts in screenshot filenames', function () {
    $nnbsp = "\u{202F}";
    makeImage("Screenshot 2026-08-09 at 11.18.03{$nnbsp}AM.png");
    $escaped = config('tackle.workspace')."/Screenshot\\ 2026-08-09\\ at\\ 11.18.03{$nnbsp}AM.png";

    [$prompt, $attachments, $unreadable] = ImageAttachments::extract("see {$escaped}", config('tackle.workspace'));

    expect($attachments)->toHaveCount(1)
        ->and($unreadable)->toBe([])
        ->and($prompt)->toContain('[attached image: Screenshot 2026-08-09 at 11.18.03');
});

it('extracts multiple images from one prompt', function () {
    $a = makeImage('before.png');
    $b = makeImage('after.png');

    [$prompt, $attachments] = ImageAttachments::extract("compare {$a} with {$b}", config('tackle.workspace'));

    expect($attachments)->toHaveCount(2)
        ->and($prompt)->toContain('[attached image: before.png]')
        ->and($prompt)->toContain('[attached image: after.png]');
});
