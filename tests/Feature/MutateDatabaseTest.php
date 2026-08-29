<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\DenyInteraction;
use Tackle\Tools\MutateDatabase;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    Schema::create('widgets', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('status', 32)->default('draft');
    });

    DB::table('widgets')->insert([
        ['name' => 'one', 'status' => 'draft'],
        ['name' => 'two', 'status' => 'draft'],
        ['name' => 'three', 'status' => 'published'],
    ]);

    config()->set('tackle.database.mutations', true);
    config()->set('tackle.database.environments', ['testing']);
    config()->set('tackle.database.max_rows', 100);
});

function mutate(string $sql, bool $approve = true, string $reason = 'because'): string
{
    $tool = new MutateDatabase($approve ? new AutoApproveInteraction : new DenyInteraction);

    return $tool->handle(new Request(['statement' => $sql, 'reason' => $reason]));
}

// ---------------------------------------------------------------------------
// Off unless deliberately switched on
// ---------------------------------------------------------------------------

it('refuses every write while writes are disabled', function () {
    config()->set('tackle.database.mutations', false);

    expect(mutate("UPDATE widgets SET status = 'x' WHERE id = 1"))
        ->toContain('Database writes are disabled');

    expect(DB::table('widgets')->where('id', 1)->value('status'))->toBe('draft');
});

it('refuses in an environment the config does not list', function () {
    config()->set('tackle.database.environments', ['local']);

    expect(mutate("UPDATE widgets SET status = 'x' WHERE id = 1"))
        ->toContain('not permitted')
        ->toContain('testing');
});

// ---------------------------------------------------------------------------
// What it will not run
// ---------------------------------------------------------------------------

it('refuses an UPDATE or DELETE with no WHERE clause', function () {
    expect(mutate("UPDATE widgets SET status = 'x'"))->toContain('no WHERE clause')
        ->and(mutate('DELETE FROM widgets'))->toContain('no WHERE clause')
        ->and(DB::table('widgets')->count())->toBe(3);
});

it('refuses anything that is not a write, including DDL', function () {
    expect(mutate('SELECT * FROM widgets'))->toContain('only UPDATE, INSERT, and DELETE')
        ->and(mutate('DROP TABLE widgets'))->toContain('only UPDATE, INSERT, and DELETE')
        ->and(mutate('TRUNCATE TABLE widgets'))->toContain('only UPDATE, INSERT, and DELETE')
        ->and(Schema::hasTable('widgets'))->toBeTrue();
});

it('refuses chained statements', function () {
    // A confirmed one-row UPDATE must not be able to carry a second statement.
    expect(mutate("UPDATE widgets SET status = 'x' WHERE id = 1; DELETE FROM widgets WHERE id = 2"))
        ->toContain('one statement at a time');

    expect(DB::table('widgets')->count())->toBe(3);
});

it('allows a single trailing semicolon', function () {
    expect(mutate("UPDATE widgets SET status = 'live' WHERE id = 1;"))->toContain('Committed');
});

// ---------------------------------------------------------------------------
// The transaction is the safety net
// ---------------------------------------------------------------------------

it('commits only after the user confirms', function () {
    expect(mutate("UPDATE widgets SET status = 'live' WHERE status = 'draft'"))
        ->toContain('Committed')
        ->toContain('2 row(s)');

    expect(DB::table('widgets')->where('status', 'live')->count())->toBe(2);
});

it('rolls back everything when the user declines', function () {
    $result = mutate("UPDATE widgets SET status = 'live' WHERE status = 'draft'", approve: false);

    expect($result)->toContain('Rolled back by the user')->toContain('2 row(s)');

    // The statement ran to get an exact count, and then was undone.
    expect(DB::table('widgets')->where('status', 'draft')->count())->toBe(2)
        ->and(DB::table('widgets')->where('status', 'live')->count())->toBe(0);
});

it('rolls back rather than confirming a change over the row limit', function () {
    config()->set('tackle.database.max_rows', 1);

    expect(mutate("UPDATE widgets SET status = 'live' WHERE status = 'draft'"))
        ->toContain('Rolled back')
        ->toContain('over the limit');

    expect(DB::table('widgets')->where('status', 'draft')->count())->toBe(2);
});

it('rolls back and says so when nothing matched', function () {
    expect(mutate("UPDATE widgets SET status = 'live' WHERE id = 9999"))
        ->toContain('matched no rows');
});

it('reports a broken statement without leaving a transaction open', function () {
    expect(mutate('DELETE FROM nope WHERE id = 1'))->toContain('nothing was changed');

    // A leaked transaction would break the next write.
    expect(mutate("UPDATE widgets SET status = 'live' WHERE id = 1"))->toContain('Committed');
});

it('inserts without needing a WHERE clause', function () {
    expect(mutate("INSERT INTO widgets (name, status) VALUES ('four', 'draft')"))
        ->toContain('Committed');

    expect(DB::table('widgets')->count())->toBe(4);
});
