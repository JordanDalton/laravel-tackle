<?php

use Tackle\Review\DiffLineIndex;

function sampleDiff(): string
{
    return <<<'DIFF'
    diff --git a/app/Models/User.php b/app/Models/User.php
    index 1234567..89abcde 100644
    --- a/app/Models/User.php
    +++ b/app/Models/User.php
    @@ -10,4 +10,6 @@ class User
         public function name(): string
         {
    -        return $this->first_name;
    +        return $this->first_name.' '.$this->last_name;
    +    }
    +
    @@ -30,3 +32,4 @@ class User
         public function email(): string
         {
    +        // normalized
    diff --git a/app/Removed.php b/app/Removed.php
    deleted file mode 100644
    --- a/app/Removed.php
    +++ /dev/null
    @@ -1,3 +0,0 @@
    -<?php
    -
    -class Removed {}
    DIFF;
}

it('marks added lines as commentable', function () {
    $index = new DiffLineIndex(sampleDiff());

    // First hunk: new side starts at 10; line 12 is the added replacement line.
    expect($index->isCommentable('app/Models/User.php', 12))->toBeTrue();
});

it('marks context lines as commentable', function () {
    $index = new DiffLineIndex(sampleDiff());

    expect($index->isCommentable('app/Models/User.php', 10))->toBeTrue()
        ->and($index->isCommentable('app/Models/User.php', 11))->toBeTrue();
});

it('tracks line numbers across multiple hunks', function () {
    $index = new DiffLineIndex(sampleDiff());

    // Second hunk starts at new line 32; the added comment is line 34.
    expect($index->isCommentable('app/Models/User.php', 34))->toBeTrue();
});

it('rejects lines outside the diff hunks', function () {
    $index = new DiffLineIndex(sampleDiff());

    expect($index->isCommentable('app/Models/User.php', 1))->toBeFalse()
        ->and($index->isCommentable('app/Models/User.php', 500))->toBeFalse();
});

it('rejects files not in the diff', function () {
    $index = new DiffLineIndex(sampleDiff());

    expect($index->isCommentable('app/Other.php', 12))->toBeFalse();
});

it('ignores deleted files', function () {
    $index = new DiffLineIndex(sampleDiff());

    expect($index->paths())->toBe(['app/Models/User.php'])
        ->and($index->isCommentable('app/Removed.php', 1))->toBeFalse();
});

it('handles an empty diff', function () {
    $index = new DiffLineIndex('');

    expect($index->paths())->toBe([]);
});
