<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tackle\Support\RouteMap;
use Tackle\Tools\DescribeRoute;

// ---------------------------------------------------------------------------
// Fixtures: a model, two form requests, and a controller to hang routes on.
// ---------------------------------------------------------------------------

class RouteMapArticle extends Model
{
    protected $table = 'route_map_articles';
}

class StoreRouteMapArticleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => 'required|string',
        ];
    }
}

class UpdateRouteMapArticleRequest extends FormRequest
{
    public function rules(): array
    {
        // Depends on the request it will never have here — the realistic case,
        // and the one the map has to be honest about.
        return [
            'slug' => ['required', 'unique:articles,slug,'.$this->route('article')->id],
        ];
    }
}

class RouteMapArticleController
{
    public function store(StoreRouteMapArticleRequest $request) {}

    public function update(UpdateRouteMapArticleRequest $request, RouteMapArticle $article)
    {
        $this->authorize('update', $article);
    }
}

beforeEach(function () {
    Route::middlewareGroup('portal', [StartSession::class]);

    Route::post('/route-map/articles', [RouteMapArticleController::class, 'store'])
        ->middleware('portal')
        ->name('route-map.articles.store');

    Route::put('/route-map/articles/{article}', [RouteMapArticleController::class, 'update'])
        ->middleware(['portal', 'can:update,article'])
        ->name('route-map.articles.update');
});

it('resolves the middleware stack, expanding groups', function () {
    $out = (new RouteMap)->describe('route-map.articles.store');

    expect($out)
        ->toContain('route-map.articles.store')
        ->toContain('Middleware   portal')
        ->toContain('resolves to: StartSession');
});

it('returns the form request and its rules', function () {
    $out = (new RouteMap)->describe('route-map.articles.store');

    expect($out)
        ->toContain('FormRequest  StoreRouteMapArticleRequest')
        ->toContain('required|string|max:255')
        ->toContain('required|string');
});

it('falls back to the rules() source when they depend on the request', function () {
    $out = (new RouteMap)->describe('route-map.articles.update');

    expect($out)
        ->toContain('UpdateRouteMapArticleRequest')
        ->toContain('could not be evaluated here')
        ->toContain("\$this->route('article')");
});

it('reports authorization from both the middleware and the controller', function () {
    $out = (new RouteMap)->describe('route-map.articles.update');

    expect($out)
        ->toContain('can:update,article (middleware)')
        ->toContain('update (called in the controller');
});

it('names the route-model bindings', function () {
    expect((new RouteMap)->describe('route-map.articles.update'))
        ->toContain('{article}→RouteMapArticle');
});

it('lists the candidates when a query matches many routes', function () {
    for ($i = 0; $i < 8; $i++) {
        Route::get("/route-map/wide-{$i}", fn () => '')->name("route-map.wide.{$i}");
    }

    expect((new RouteMap)->describe('route-map'))
        ->toContain('matches')
        ->toContain('Narrow it down');
});

it('says so when nothing matches', function () {
    expect((new RouteMap)->describe('nothing-here'))
        ->toContain("No route matches 'nothing-here'");
});

it('asks for an argument when given none', function () {
    expect((new DescribeRoute(new RouteMap))->handle(new ToolRequest([])))
        ->toContain('Pass a route URI, name, or action fragment');
});

it('exposes the same thing through the tool', function () {
    expect((new DescribeRoute(new RouteMap))->handle(new ToolRequest(['route' => 'route-map.articles.store'])))
        ->toContain('StoreRouteMapArticleRequest');
});

it('boots the HTTP kernel so middleware groups actually exist', function () {
    // Laravel 11+ registers the `web` and `api` groups in the HTTP kernel's
    // constructor. Tackle always runs from the console, where that kernel is
    // never instantiated — so without this, `web` expanded to whatever a
    // service provider happened to add and every route looked almost
    // unprotected. Found on a real app: `web` resolved to one class instead
    // of nine.
    app()->forgetInstance(Kernel::class);

    (new RouteMap)->describe('route-map.articles.store');

    expect(app()->resolved(Kernel::class))->toBeTrue();
});
