<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\Utf8;
use Tackle\Tools\ReadFile;

it('passes valid UTF-8 through untouched', function () {
    expect(Utf8::clean('héllo wörld 🎉'))->toBe('héllo wörld 🎉')
        ->and(Utf8::clean(''))->toBe('');
});

it('substitutes invalid byte sequences so the result json_encodes', function () {
    $latin1 = "caf\xE9 cr\xE8me"; // "café crème" in ISO-8859-1

    $clean = Utf8::clean($latin1);

    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue()
        ->and(json_encode($clean))->not->toBeFalse()
        ->and($clean)->toContain('caf');
});

it('cleans binary garbage into encodable text', function () {
    $binary = "start\x80\xFF\xFEend";

    $clean = Utf8::clean($binary);

    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue()
        ->and(json_encode($clean))->not->toBeFalse();
});

it('ReadFile returns json-encodable output for non-UTF-8 files', function () {
    $workspace = config('tackle.workspace');
    $path = $workspace.'/latin1.php';
    file_put_contents($path, "<?php // r\xE9sum\xE9\n");

    try {
        $result = app(ReadFile::class)->handle(new Request(['path' => 'latin1.php']));

        expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue()
            ->and(json_encode($result))->not->toBeFalse();
    } finally {
        @unlink($path);
    }
});
