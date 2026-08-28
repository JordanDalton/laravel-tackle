<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\AppMap;
use Tackle\Support\PathGuard;
use Tackle\Tools\DescribeModels;

// ---------------------------------------------------------------------------
// A workspace with two models on disk, and the tables to match.
// ---------------------------------------------------------------------------

function mapWorkspace(): string
{
    // One fixed directory for the whole file: the fixtures are require_once'd
    // into this process, so a fresh path per test would redeclare the classes.
    $dir = sys_get_temp_dir().'/tackle-map-fixture';
    @mkdir($dir.'/app/Models', 0755, true);

    file_put_contents($dir.'/app/Models/MapArticle.php', <<<'PHP'
    <?php
    namespace App\Models;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Casts\Attribute;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Database\Eloquent\SoftDeletes;
    class MapArticle extends Model {
        use SoftDeletes;
        protected $table = 'map_articles';
        protected $fillable = ['title', 'slug', 'status'];
        protected $hidden = ['secret'];
        protected $appends = ['excerpt'];
        protected $casts = ['published_at' => 'datetime', 'meta' => 'array'];
        public function author(): BelongsTo { return $this->belongsTo(MapAuthor::class, 'map_author_id'); }
        public function revisions(): HasMany { return $this->hasMany(MapArticle::class, 'parent_id'); }
        public function scopePublished($query) { return $query->whereNotNull('published_at'); }
        public function scopeForStatus($query, $status) { return $query->where('status', $status); }
        public function excerpt(): Attribute { return Attribute::get(fn () => 'x'); }
        public function tags() { return $this->hasMany(MapAuthor::class, 'map_article_id'); }
        public function untypedThing() { return 42; }
    }
    PHP);

    file_put_contents($dir.'/app/Models/MapAuthor.php', <<<'PHP'
    <?php
    namespace App\Models;
    use Illuminate\Database\Eloquent\Model;
    class MapAuthor extends Model {
        protected $table = 'map_authors';
        protected $fillable = ['name'];
    }
    PHP);

    require_once $dir.'/app/Models/MapArticle.php';
    require_once $dir.'/app/Models/MapAuthor.php';

    config()->set('tackle.workspace', $dir);

    return $dir;
}

beforeEach(function () {
    AppMap::forget();
    @unlink(storage_path('tackle/map.json'));

    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    Schema::create('map_authors', function ($t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('map_articles', function ($t) {
        $t->id();
        $t->foreignId('map_author_id')->constrained('map_authors');
        $t->string('title');
        $t->string('slug')->unique();
        $t->string('status', 32)->default('draft');
        $t->text('meta')->nullable();
        $t->timestamp('published_at')->nullable();
        $t->softDeletes();
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// Tier one — the index
// ---------------------------------------------------------------------------

it('indexes every model with its table in one line', function () {
    $dir = mapWorkspace();

    $index = (new AppMap(new PathGuard($dir)))->index();

    expect($index)
        ->toContain('MapArticle(map_articles)')
        ->toContain('MapAuthor(map_authors)');
});

it('wraps the index as a prompt section, and drops it when switched off', function () {
    $dir = mapWorkspace();
    $map = new AppMap(new PathGuard($dir));

    expect($map->indexSection())
        ->toContain('## Application map')
        ->toContain('MapArticle(map_articles)');

    config()->set('tackle.app_map.index', false);
    AppMap::forget();

    expect($map->indexSection())->toBe('');
});

// ---------------------------------------------------------------------------
// Tier two — one model, fully described
// ---------------------------------------------------------------------------

it('describes a model from the live schema and reflection together', function () {
    $dir = mapWorkspace();

    $out = (new AppMap(new PathGuard($dir)))->model('MapArticle');

    expect($out)
        // Header
        ->toContain('MapArticle (map_articles)')
        ->toContain('SoftDeletes')
        ->toContain('App\Models\MapArticle')
        // Real columns, types and keys off the connection
        ->toContain('Columns')
        ->toContain('slug')
        ->toContain('UNIQUE')
        ->toContain('map_author_id')
        ->toContain('FK→map_authors.id')
        ->toContain("default 'draft'")
        ->toContain('published_at')
        // Model metadata
        ->toContain('published_at:datetime')
        ->toContain('title, slug, status')
        ->toContain('secret')
        ->toContain('excerpt')
        // Relations, resolved by calling only methods typed as relations
        ->toContain('author')
        ->toContain('BelongsTo')
        ->toContain('MapAuthor')
        ->toContain('(map_author_id)')
        ->toContain('revisions')
        ->toContain('HasMany')
        // Scopes, both local and global
        ->toContain('published()')
        ->toContain('forStatus($status)')
        ->toContain('SoftDeletingScope');
});

it('reports untyped methods as a gap rather than silently skipping them', function () {
    $dir = mapWorkspace();

    expect((new AppMap(new PathGuard($dir)))->model('MapArticle'))
        ->toContain('declare no return type');
});

it('never invokes an untyped method unless the project opts in', function () {
    $dir = mapWorkspace();

    // tags() is a real relation with no return type. Leaving it out is the
    // point: finding it means invoking a method blind, which is how an
    // introspection tool ends up firing sendWelcomeEmail().
    expect((new AppMap(new PathGuard($dir)))->model('MapArticle'))
        ->not->toContain('tags')
        ->not->toContain('untypedThing');
});

it('finds undeclared relations when the project opts in', function () {
    $dir = mapWorkspace();

    config()->set('tackle.app_map.probe_untyped_relations', true);
    AppMap::forget();
    @unlink(storage_path('tackle/map.json'));

    $out = (new AppMap(new PathGuard($dir)))->model('MapArticle');

    expect($out)
        ->toContain('tags')
        ->toContain('(map_article_id)')
        // untypedThing() returns an int, so probing correctly discards it.
        ->not->toContain('untypedThing')
        // And with probing on there is no gap left to report.
        ->not->toContain('declare no return type');
});

it('describes every model at once for tackle:map --all', function () {
    $dir = mapWorkspace();

    expect((new AppMap(new PathGuard($dir)))->all())
        ->toContain('MapArticle (map_articles)')
        ->toContain('MapAuthor (map_authors)');
});

it('degrades explicitly when the table has not been migrated', function () {
    $dir = mapWorkspace();
    Schema::drop('map_articles');

    $out = (new AppMap(new PathGuard($dir)))->model('MapArticle');

    expect($out)
        ->toContain('Columns  unavailable')
        ->toContain('has it been migrated?')
        // The reflection half is still there and still useful.
        ->toContain('BelongsTo');
});

it('surfaces an observer registered on the dispatcher', function () {
    $dir = mapWorkspace();

    Event::listen('eloquent.saving: App\Models\MapAuthor', 'App\Observers\MapAuthorObserver@saving');

    expect((new AppMap(new PathGuard($dir)))->model('MapAuthor'))
        ->toContain('Observer: MapAuthorObserver');
});

it('names the available models when asked for one that does not exist', function () {
    $dir = mapWorkspace();

    expect((new AppMap(new PathGuard($dir)))->model('Nope'))
        ->toContain("Model 'Nope' not found")
        ->toContain('MapArticle');
});

// ---------------------------------------------------------------------------
// Caching and invalidation
// ---------------------------------------------------------------------------

it('caches the map to disk and rebuilds it when a model changes', function () {
    $dir = mapWorkspace();
    $map = new AppMap(new PathGuard($dir));

    $map->index();

    expect(is_file($map->path()))->toBeTrue();

    $before = $map->fingerprint();

    touch($dir.'/app/Models/MapArticle.php', time() + 60);

    expect($map->fingerprint())->not->toBe($before);
});

it('flushes both halves of the cache', function () {
    $dir = mapWorkspace();
    $map = new AppMap(new PathGuard($dir));

    $map->index();
    $map->flush();

    expect(is_file($map->path()))->toBeFalse();
});

it('knows which written paths invalidate it', function () {
    expect(AppMap::invalidatedBy('app/Models/Post.php'))->toBeTrue()
        ->and(AppMap::invalidatedBy('/var/www/database/migrations/2024_01_01_create_posts.php'))->toBeTrue()
        ->and(AppMap::invalidatedBy('routes/web.php'))->toBeTrue()
        ->and(AppMap::invalidatedBy('app/Http/Controllers/PostController.php'))->toBeFalse()
        ->and(AppMap::invalidatedBy('tests/Feature/PostTest.php'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The tool on top of it
// ---------------------------------------------------------------------------

it('lists models with no argument and describes one with a name', function () {
    $dir = mapWorkspace();
    $tool = new DescribeModels(new PathGuard($dir));

    expect($tool->handle(new Request([])))
        ->toContain('App\Models\MapArticle')
        ->toContain('App\Models\MapAuthor')
        ->not->toContain('Columns');

    expect($tool->handle(new Request(['model' => 'MapArticle'])))
        ->toContain('Columns')
        ->toContain('BelongsTo');
});

it('reports an empty workspace clearly', function () {
    $dir = sys_get_temp_dir().'/tackle-map-empty-'.uniqid();
    @mkdir($dir, 0755, true);
    config()->set('tackle.workspace', $dir);

    expect((new DescribeModels(new PathGuard($dir)))->handle(new Request([])))
        ->toContain('No Eloquent models found');
});

// ---------------------------------------------------------------------------
// tackle:map
// ---------------------------------------------------------------------------

it('shows the index through tackle:map', function () {
    mapWorkspace();

    $this->artisan('tackle:map')
        ->expectsOutputToContain('MapArticle(map_articles)')
        ->assertSuccessful();
});

it('describes one model through tackle:map', function () {
    mapWorkspace();

    $this->artisan('tackle:map', ['model' => 'MapArticle'])
        ->expectsOutputToContain('Columns')
        ->assertSuccessful();
});

it('describes a route through tackle:map --route', function () {
    mapWorkspace();

    Route::get('/tackle-map-probe', fn () => '')->name('tackle-map.probe');

    $this->artisan('tackle:map', ['--route' => 'tackle-map.probe'])
        ->expectsOutputToContain('tackle-map.probe')
        ->assertSuccessful();
});

it('rebuilds on tackle:map --fresh', function () {
    $dir = mapWorkspace();
    $map = new AppMap(new PathGuard($dir));
    $map->index();

    $this->artisan('tackle:map', ['--fresh' => true])
        ->expectsOutputToContain('Cache discarded')
        ->assertSuccessful();
});
