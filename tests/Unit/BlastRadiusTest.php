<?php

use Tackle\Support\BlastRadius;

beforeEach(function () {
    config()->set('tackle.healing.max_files', 20);
    config()->set('tackle.healing.max_diff_lines', 400);
    config()->set('tackle.healing.protected_from_healing', BlastRadius::defaultProtected());
});

it('passes a small, ordinary fix', function () {
    $files = ['app/Jobs/ProcessPayment.php' => 'M', 'tests/Feature/ProcessPaymentTest.php' => 'A'];

    expect(BlastRadius::violations($files, 30))->toBe([]);
});

it('flags too many files', function () {
    $files = [];
    for ($i = 0; $i < 25; $i++) {
        $files["app/File{$i}.php"] = 'M';
    }

    expect(BlastRadius::violations($files, 30))->toContain('touches 25 files (limit 20)');
});

it('flags too large a diff', function () {
    expect(BlastRadius::violations(['app/Foo.php' => 'M'], 900))
        ->toContain('changes 900 lines (limit 400)');
});

it('flags editing an already-run migration', function () {
    $files = ['database/migrations/2026_01_01_000000_create_orders_table.php' => 'M'];

    expect(BlastRadius::violations($files, 10))
        ->toContain('modifies a protected path: database/migrations/2026_01_01_000000_create_orders_table.php (matches "database/migrations/*")');
});

it('allows ADDING a new migration', function () {
    $files = ['database/migrations/2026_09_09_000000_add_index_to_orders.php' => 'A'];

    expect(BlastRadius::violations($files, 10))->toBe([]);
});

it('flags modifying config and composer.json', function () {
    expect(BlastRadius::violations(['config/app.php' => 'M'], 5))
        ->toContain('modifies a protected path: config/app.php (matches "config/*")');

    expect(BlastRadius::violations(['composer.json' => 'M'], 5))
        ->toContain('modifies a protected path: composer.json (matches "composer.json")');
});

it('respects a raised limit from config', function () {
    config()->set('tackle.healing.max_files', 50);
    $files = [];
    for ($i = 0; $i < 25; $i++) {
        $files["app/File{$i}.php"] = 'M';
    }

    expect(BlastRadius::violations($files, 30))->toBe([]);
});
