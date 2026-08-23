<?php

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\AppInfo;
use Tackle\Tools\DescribeModels;
use Tackle\Tools\DescribeSchema;

// ---------------------------------------------------------------------------
// DescribeSchema — against the testbench sqlite connection
// ---------------------------------------------------------------------------

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    Schema::connection('testing')->create('widgets', function ($t) {
        $t->id();
        $t->string('name');
        $t->integer('qty')->nullable();
        $t->timestamps();
    });
});

it('lists tables and describes columns from the live connection', function () {
    $tool = new DescribeSchema;

    expect($tool->handle(new Request([])))->toContain('widgets');

    $desc = $tool->handle(new Request(['table' => 'widgets']));
    expect($desc)
        ->toContain('Table: widgets')
        ->toContain('name')
        ->toContain('qty')
        ->toContain('nullable');
});

it('reports a missing table clearly', function () {
    expect((new DescribeSchema)->handle(new Request(['table' => 'nope'])))
        ->toContain("Table 'nope' does not exist");
});

// ---------------------------------------------------------------------------
// DescribeModels — reflection over a model on disk
// ---------------------------------------------------------------------------

it('describes Eloquent models: table, fillable, casts, relations', function () {
    $dir = sys_get_temp_dir().'/tackle-models-'.uniqid();
    @mkdir($dir.'/app/Models', 0755, true);
    file_put_contents($dir.'/app/Models/Gadget.php', <<<'PHP'
    <?php
    namespace App\Models;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    class Gadget extends Model {
        protected $fillable = ['name', 'price_cents'];
        protected $casts = ['price_cents' => 'integer'];
        public function parts(): HasMany { return $this->hasMany(Gadget::class); }
    }
    PHP);
    require $dir.'/app/Models/Gadget.php';

    config()->set('tackle.workspace', $dir);
    $out = (new DescribeModels(new PathGuard($dir)))->handle(new Request([]));

    expect($out)
        ->toContain('Gadget')
        ->toContain('fillable: name, price_cents')
        ->toContain('casts:')->toContain('price_cents')
        ->toContain('parts (HasMany)');
});

it('returns a clear message when there are no models', function () {
    $dir = sys_get_temp_dir().'/tackle-empty-'.uniqid();
    @mkdir($dir, 0755, true);
    config()->set('tackle.workspace', $dir);

    expect((new DescribeModels(new PathGuard($dir)))->handle(new Request([])))
        ->toContain('No Eloquent models found');
});

// ---------------------------------------------------------------------------
// AppInfo — notable packages from composer.lock
// ---------------------------------------------------------------------------

it('surfaces notable packages from composer.lock', function () {
    $dir = sys_get_temp_dir().'/tackle-app-'.uniqid();
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/composer.lock', json_encode([
        'packages' => [
            ['name' => 'livewire/livewire', 'version' => 'v3.5.0'],
            ['name' => 'some/other', 'version' => '1.0'],
        ],
        'packages-dev' => [
            ['name' => 'pestphp/pest', 'version' => 'v3.0.0'],
        ],
    ]));
    config()->set('tackle.workspace', $dir);

    $out = (new AppInfo(new PathGuard($dir)))->handle(new Request([]));

    expect($out)
        ->toContain('livewire/livewire v3.5.0')
        ->toContain('pestphp/pest v3.0.0')
        ->not->toContain('some/other');
});
